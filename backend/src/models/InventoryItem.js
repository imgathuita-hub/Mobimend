const mongoose = require("mongoose");

const inventorySchema = new mongoose.Schema(
  {
    brand: { type: String, required: true, trim: true },
    model: { type: String, required: true, trim: true },
    partType: { type: String, required: true, trim: true },
    quantity: { type: Number, required: true, min: 0 },
    reorderPoint: { type: Number, default: 5, min: 0 },
    lowStock: { type: Boolean, default: false },
    buyPrice: { type: Number, default: 0 },
    sellPrice: { type: Number, default: 0 },
    mediaUrl: { type: String, default: "", trim: true },
    status: { type: String, default: "in_stock", trim: true },
    notes: { type: String, default: "", trim: true }
  },
  { timestamps: true }
);

module.exports = mongoose.model("InventoryItem", inventorySchema);
