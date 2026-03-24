const mongoose = require("mongoose");

const inventoryTransactionSchema = new mongoose.Schema(
  {
    inventoryItemId: {
      type: mongoose.Schema.Types.ObjectId,
      ref: "InventoryItem",
      required: true
    },
    brand: { type: String, required: true, trim: true },
    model: { type: String, required: true, trim: true },
    partType: { type: String, required: true, trim: true },
    quantity: { type: Number, required: true, min: 1 },
    unitBuyPrice: { type: Number, default: 0, min: 0 },
    unitSellPrice: { type: Number, default: 0, min: 0 },
    totalCost: { type: Number, default: 0, min: 0 },
    totalRevenue: { type: Number, default: 0, min: 0 },
    profit: { type: Number, default: 0 },
    source: { type: String, default: "website_checkout", trim: true }
  },
  { timestamps: true }
);

module.exports = mongoose.model("InventoryTransaction", inventoryTransactionSchema);
