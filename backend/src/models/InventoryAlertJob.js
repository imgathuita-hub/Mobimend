const mongoose = require("mongoose");

const inventoryAlertJobSchema = new mongoose.Schema(
  {
    inventoryItemId: {
      type: mongoose.Schema.Types.ObjectId,
      ref: "InventoryItem",
      required: true
    },
    jobType: { type: String, default: "low_stock", trim: true },
    payload: { type: mongoose.Schema.Types.Mixed, required: true },
    status: {
      type: String,
      enum: ["pending", "processing", "completed", "failed"],
      default: "pending"
    },
    attempts: { type: Number, default: 0, min: 0 },
    availableAt: { type: Date, default: Date.now },
    processedAt: { type: Date, default: null }
  },
  { timestamps: true }
);

inventoryAlertJobSchema.index({ status: 1, availableAt: 1 });
inventoryAlertJobSchema.index({ inventoryItemId: 1, createdAt: -1 });

module.exports = mongoose.model("InventoryAlertJob", inventoryAlertJobSchema);
