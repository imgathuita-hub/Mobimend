const express = require("express");
const InventoryItem = require("../models/InventoryItem");
const InventoryTransaction = require("../models/InventoryTransaction");
const { requireAuth } = require("../middleware/auth");

const router = express.Router();

const normalize = (value) => String(value || "").trim().toLowerCase();

// Public: list inventory (for frontend display)
router.get("/", async (req, res) => {
  const query = {};

  if (req.query.brand) {
    query.brand = new RegExp(`^${req.query.brand.trim()}$`, "i");
  }

  if (req.query.model) {
    query.model = new RegExp(`^${req.query.model.trim()}$`, "i");
  }

  if (req.query.partType) {
    query.partType = new RegExp(`^${req.query.partType.trim()}$`, "i");
  }

  if (req.query.inStock === "true") {
    query.quantity = { $gt: 0 };
  }

  const items = await InventoryItem.find(query).sort({ createdAt: -1 });
  res.json(items);
});

// Admin: monthly analytics based on realized checkout transactions
router.get("/analytics/monthly", requireAuth, async (_req, res) => {
  const now = new Date();
  const monthStart = new Date(now.getFullYear(), now.getMonth(), 1);
  const nextMonthStart = new Date(now.getFullYear(), now.getMonth() + 1, 1);

  const [monthlyTransactions, monthlyInventoryItems] = await Promise.all([
    InventoryTransaction.find({
      createdAt: { $gte: monthStart, $lt: nextMonthStart }
    }).sort({ createdAt: 1 }),
    InventoryItem.find({
      createdAt: { $gte: monthStart, $lt: nextMonthStart }
    }).sort({ createdAt: 1 })
  ]);

  const weeklySales = Array.from({ length: 5 }, (_, index) => ({
    label: `Week ${index + 1}`,
    quantitySold: 0,
    revenue: 0,
    profit: 0
  }));

  monthlyTransactions.forEach((transaction) => {
    const createdAt = new Date(transaction.createdAt);
    const bucketIndex = Math.min(4, Math.floor((createdAt.getDate() - 1) / 7));
    weeklySales[bucketIndex].quantitySold += Number(transaction.quantity || 0);
    weeklySales[bucketIndex].revenue += Number(transaction.totalRevenue || 0);
    weeklySales[bucketIndex].profit += Number(transaction.profit || 0);
  });

  const itemsAdded = monthlyInventoryItems.length;
  const stockCost = monthlyInventoryItems.reduce(
    (sum, item) => sum + (Number(item.buyPrice || 0) * Number(item.quantity || 0)),
    0
  );
  const unitsSold = monthlyTransactions.reduce((sum, tx) => sum + Number(tx.quantity || 0), 0);
  const revenue = monthlyTransactions.reduce((sum, tx) => sum + Number(tx.totalRevenue || 0), 0);
  const costOfGoodsSold = monthlyTransactions.reduce((sum, tx) => sum + Number(tx.totalCost || 0), 0);
  const profit = monthlyTransactions.reduce((sum, tx) => sum + Number(tx.profit || 0), 0);

  res.json({
    month: {
      year: now.getFullYear(),
      monthIndex: now.getMonth(),
      label: now.toLocaleString("en-US", { month: "long", year: "numeric" })
    },
    metrics: {
      itemsAdded,
      stockCost,
      unitsSold,
      revenue,
      costOfGoodsSold,
      profit,
      loss: profit < 0 ? Math.abs(profit) : 0
    },
    weeklySales
  });
});

// Public: checkout one or more inventory items and reduce stock
router.post("/checkout", async (req, res) => {
  const items = Array.isArray(req.body?.items) ? req.body.items : [];

  if (!items.length) {
    return res.status(400).json({ error: "At least one checkout item is required" });
  }

  const aggregatedItems = new Map();

  items.forEach((item) => {
    const inventoryId = String(item.inventoryId || "").trim();
    const quantity = Number(item.quantity || 0);
    const currentQuantity = aggregatedItems.get(inventoryId) || 0;
    aggregatedItems.set(inventoryId, currentQuantity + quantity);
  });

  const requestedItems = Array.from(aggregatedItems.entries()).map(([inventoryId, quantity]) => ({
    inventoryId,
    quantity
  }));

  const invalidItem = requestedItems.find((item) => !item.inventoryId || item.quantity <= 0);
  if (invalidItem) {
    return res.status(400).json({ error: "Each checkout item needs inventoryId and quantity > 0" });
  }

  const inventoryIds = [...new Set(requestedItems.map((item) => item.inventoryId))];
  const inventoryItems = await InventoryItem.find({ _id: { $in: inventoryIds } });
  const inventoryMap = new Map(inventoryItems.map((item) => [String(item._id), item]));
  const availabilityErrors = [];

  requestedItems.forEach((item) => {
    const inventoryItem = inventoryMap.get(item.inventoryId);
    if (!inventoryItem) {
      availabilityErrors.push({ inventoryId: item.inventoryId, error: "Item not found" });
      return;
    }

    if (inventoryItem.quantity < item.quantity) {
      availabilityErrors.push({
        inventoryId: item.inventoryId,
        error: "Insufficient stock",
        available: inventoryItem.quantity
      });
    }
  });

  if (availabilityErrors.length) {
    return res.status(409).json({ error: "Some items are out of stock", issues: availabilityErrors });
  }

  const updatedItems = [];
  const createdTransactions = [];

  for (const item of requestedItems) {
    const inventoryItem = inventoryMap.get(item.inventoryId);
    const updated = await InventoryItem.findOneAndUpdate(
      { _id: item.inventoryId, quantity: { $gte: item.quantity } },
      { $inc: { quantity: -item.quantity } },
      { new: true, runValidators: true }
    );

    if (!updated) {
      return res.status(409).json({
        error: "Inventory changed during checkout. Please refresh and try again."
      });
    }

    const nextStatus = updated.quantity > 0 ? "in_stock" : "sold_out";
    if (normalize(updated.status) !== nextStatus) {
      updated.status = nextStatus;
      await updated.save();
    }

    updatedItems.push(updated);

    const unitBuyPrice = Number(inventoryItem?.buyPrice || 0);
    const unitSellPrice = Number(inventoryItem?.sellPrice || 0);
    createdTransactions.push({
      inventoryItemId: updated._id,
      brand: updated.brand,
      model: updated.model,
      partType: updated.partType,
      quantity: item.quantity,
      unitBuyPrice,
      unitSellPrice,
      totalCost: unitBuyPrice * item.quantity,
      totalRevenue: unitSellPrice * item.quantity,
      profit: (unitSellPrice - unitBuyPrice) * item.quantity
    });
  }

  if (createdTransactions.length) {
    await InventoryTransaction.insertMany(createdTransactions);
  }

  return res.json({
    status: "ok",
    message: "Checkout completed",
    items: updatedItems
  });
});

// Admin: create item
router.post("/", requireAuth, async (req, res) => {
  const item = await InventoryItem.create(req.body);
  res.status(201).json(item);
});

// Admin: update item
router.put("/:id", requireAuth, async (req, res) => {
  const item = await InventoryItem.findByIdAndUpdate(req.params.id, req.body, {
    new: true,
    runValidators: true
  });
  if (!item) {
    return res.status(404).json({ error: "Item not found" });
  }
  res.json(item);
});

// Admin: delete item
router.delete("/:id", requireAuth, async (req, res) => {
  const item = await InventoryItem.findByIdAndDelete(req.params.id);
  if (!item) {
    return res.status(404).json({ error: "Item not found" });
  }
  res.json({ status: "deleted" });
});

module.exports = router;
