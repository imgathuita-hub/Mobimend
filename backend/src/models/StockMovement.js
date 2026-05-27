const mongoose = require("mongoose");

const stockMovementSchema = new mongoose.Schema(
  {
    inventoryItemId: {
      type: mongoose.Schema.Types.ObjectId,
      ref: "InventoryItem",
      required: true
    },
    movementType: {
      type: String,
      enum: ["receive", "fulfill", "return", "adjustment", "correction"],
      required: true
    },
    source: { type: String, default: "inventory", trim: true },
    quantityDelta: { type: Number, required: true },
    previousQuantity: { type: Number, required: true, min: 0 },
    newQuantity: { type: Number, required: true, min: 0 },
    unitBuyPrice: { type: Number, default: 0, min: 0 },
    unitSellPrice: { type: Number, default: 0, min: 0 },
    totalCost: { type: Number, default: 0, min: 0 },
    totalRevenue: { type: Number, default: 0, min: 0 },
    profit: { type: Number, default: 0 },
    reason: { type: String, default: "", trim: true },
    createdByUserId: { type: String, default: "", trim: true }
  },
  { timestamps: true }
);

stockMovementSchema.index({ inventoryItemId: 1, createdAt: -1 });
stockMovementSchema.index({ source: 1, createdAt: -1 });

module.exports = mongoose.model("StockMovement", stockMovementSchema);
