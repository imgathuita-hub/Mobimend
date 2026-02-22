const express = require("express");
const InventoryItem = require("../models/InventoryItem");
const { requireAuth } = require("../middleware/auth");

const router = express.Router();

// Public: list inventory (for frontend display)
router.get("/", async (_req, res) => {
  const items = await InventoryItem.find().sort({ createdAt: -1 });
  res.json(items);
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
