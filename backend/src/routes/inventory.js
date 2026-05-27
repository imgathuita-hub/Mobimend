const express = require("express");
const mongoose = require("mongoose");
const InventoryItem = require("../models/InventoryItem");
const InventoryTransaction = require("../models/InventoryTransaction");
const StockMovement = require("../models/StockMovement");
const InventoryAlertJob = require("../models/InventoryAlertJob");
const { requireAuth, requireInventoryRole } = require("../middleware/auth");

const router = express.Router();

const normalize = (value) => String(value || "").trim().toLowerCase();
const inventoryWriteAuth = [requireAuth, requireInventoryRole];

const buildMovement = ({ item, previousQuantity, newQuantity, quantityDelta, source, movementType, reason, userId }) => {
  const absoluteQuantity = Math.abs(quantityDelta);
  const unitBuyPrice = Number(item.buyPrice || 0);
  const unitSellPrice = Number(item.sellPrice || 0);

  return {
    inventoryItemId: item._id,
    movementType,
    source,
    quantityDelta,
    previousQuantity,
    newQuantity,
    unitBuyPrice,
    unitSellPrice,
    totalCost: unitBuyPrice * absoluteQuantity,
    totalRevenue: quantityDelta < 0 ? unitSellPrice * absoluteQuantity : 0,
    profit: quantityDelta < 0 ? (unitSellPrice - unitBuyPrice) * absoluteQuantity : 0,
    reason,
    createdByUserId: userId || ""
  };
};

const enqueueLowStockAlert = async ({ item, session }) => {
  const reorderPoint = Number(item.reorderPoint || 0);

  if (reorderPoint <= 0 || Number(item.quantity || 0) > reorderPoint) {
    return;
  }

  await InventoryAlertJob.create(
    [
      {
        inventoryItemId: item._id,
        jobType: "low_stock",
        payload: {
          inventoryItemId: item._id,
          brand: item.brand,
          model: item.model,
          partType: item.partType,
          quantity: item.quantity,
          reorderPoint
        }
      }
    ],
    { session }
  );
};

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

// Inventory write: checkout one or more inventory items and reduce stock.
router.post("/checkout", ...inventoryWriteAuth, async (req, res) => {
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
  const session = await mongoose.startSession();
  let result;

  try {
    await session.withTransaction(async () => {
      const inventoryItems = await InventoryItem.find({ _id: { $in: inventoryIds } }).session(session);
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
        const error = new Error("Some items are out of stock");
        error.status = 409;
        error.issues = availabilityErrors;
        throw error;
      }

      const updatedItems = [];
      const createdTransactions = [];
      const stockMovements = [];

      for (const item of requestedItems) {
        const inventoryItem = inventoryMap.get(item.inventoryId);
        const previousQuantity = Number(inventoryItem.quantity || 0);
        const updated = await InventoryItem.findOneAndUpdate(
          { _id: item.inventoryId, quantity: { $gte: item.quantity } },
          {
            $inc: { quantity: -item.quantity },
            $set: {
              status: previousQuantity - item.quantity > 0 ? "in_stock" : "sold_out",
              lowStock: previousQuantity - item.quantity <= Number(inventoryItem.reorderPoint || 0)
            }
          },
          { new: true, runValidators: true, session }
        );

        if (!updated) {
          const error = new Error("Inventory changed during checkout. Please refresh and try again.");
          error.status = 409;
          throw error;
        }

        updatedItems.push(updated);

        const unitBuyPrice = Number(inventoryItem.buyPrice || 0);
        const unitSellPrice = Number(inventoryItem.sellPrice || 0);
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

        stockMovements.push(buildMovement({
          item: inventoryItem,
          previousQuantity,
          newQuantity: updated.quantity,
          quantityDelta: -item.quantity,
          source: "website_checkout",
          movementType: "fulfill",
          reason: "Inventory checkout",
          userId: req.user?.sub
        }));

        await enqueueLowStockAlert({ item: updated, session });
      }

      if (createdTransactions.length) {
        await InventoryTransaction.insertMany(createdTransactions, { session });
      }

      if (stockMovements.length) {
        await StockMovement.insertMany(stockMovements, { session });
      }

      result = updatedItems;
    });
  } catch (err) {
    const status = err.status || 500;
    return res.status(status).json({
      error: err.message || "Checkout failed",
      ...(err.issues ? { issues: err.issues } : {})
    });
  } finally {
    await session.endSession();
  }

  return res.json({
    status: "ok",
    message: "Checkout completed",
    items: result
  });
});

// Admin: create item
router.post("/", ...inventoryWriteAuth, async (req, res) => {
  const session = await mongoose.startSession();
  let item;

  try {
    await session.withTransaction(async () => {
      [item] = await InventoryItem.create(
        [{ ...req.body, lowStock: Number(req.body.quantity || 0) <= Number(req.body.reorderPoint || 0) }],
        { session }
      );

      if (Number(item.quantity || 0) > 0) {
        await StockMovement.create(
          [
            buildMovement({
              item,
              previousQuantity: 0,
              newQuantity: item.quantity,
              quantityDelta: item.quantity,
              source: "admin_inventory",
              movementType: "receive",
              reason: "Opening stock",
              userId: req.user?.sub
            })
          ],
          { session }
        );
      }
    });
  } finally {
    await session.endSession();
  }

  res.status(201).json(item);
});

// Admin: update item
router.put("/:id", ...inventoryWriteAuth, async (req, res) => {
  const session = await mongoose.startSession();
  let item;

  try {
    await session.withTransaction(async () => {
      const previous = await InventoryItem.findById(req.params.id).session(session);
      if (!previous) {
        const error = new Error("Item not found");
        error.status = 404;
        throw error;
      }

      const nextQuantity = req.body.quantity !== undefined ? Number(req.body.quantity) : Number(previous.quantity || 0);
      item = await InventoryItem.findByIdAndUpdate(
        req.params.id,
        {
          ...req.body,
          lowStock: nextQuantity <= Number(req.body.reorderPoint ?? previous.reorderPoint ?? 0),
          status: nextQuantity > 0 ? "in_stock" : "sold_out"
        },
        { new: true, runValidators: true, session }
      );

      const quantityDelta = Number(item.quantity || 0) - Number(previous.quantity || 0);
      if (quantityDelta !== 0) {
        await StockMovement.create(
          [
            buildMovement({
              item,
              previousQuantity: Number(previous.quantity || 0),
              newQuantity: Number(item.quantity || 0),
              quantityDelta,
              source: "admin_inventory",
              movementType: quantityDelta > 0 ? "receive" : "adjustment",
              reason: "Admin inventory update",
              userId: req.user?.sub
            })
          ],
          { session }
        );

        if (quantityDelta < 0) {
          await enqueueLowStockAlert({ item, session });
        }
      }
    });
  } catch (err) {
    return res.status(err.status || 500).json({ error: err.message || "Update failed" });
  } finally {
    await session.endSession();
  }

  res.json(item);
});

// Admin: delete item
router.delete("/:id", ...inventoryWriteAuth, async (req, res) => {
  const item = await InventoryItem.findByIdAndDelete(req.params.id);
  if (!item) {
    return res.status(404).json({ error: "Item not found" });
  }
  res.json({ status: "deleted" });
});

module.exports = router;
