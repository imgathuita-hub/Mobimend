const mongoose = require("mongoose");

const repairBookingSchema = new mongoose.Schema(
  {
    customerName: { type: String, required: true, trim: true, maxlength: 120 },
    phoneNumber: { type: String, required: true, trim: true, maxlength: 40 },
    email: { type: String, default: "", trim: true, maxlength: 160 },
    deviceModel: { type: String, required: true, trim: true, maxlength: 120 },
    repairType: { type: String, required: true, trim: true, maxlength: 120 },
    issueDescription: { type: String, default: "", trim: true, maxlength: 1000 },
    status: {
      type: String,
      default: "Pending",
      trim: true,
      enum: ["Pending", "In Progress", "Completed", "Cancelled"]
    },
    bookingDate: { type: Date, default: Date.now }
  },
  { timestamps: true }
);

module.exports = mongoose.model("RepairBooking", repairBookingSchema);
