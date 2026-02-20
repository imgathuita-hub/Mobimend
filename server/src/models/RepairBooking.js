const mongoose = require("mongoose");

const repairBookingSchema = new mongoose.Schema(
  {
    customerName: { type: String, required: true, trim: true },
    phoneNumber: { type: String, required: true, trim: true },
    email: { type: String, default: "", trim: true },
    deviceModel: { type: String, required: true, trim: true },
    repairType: { type: String, required: true, trim: true },
    issueDescription: { type: String, default: "", trim: true },
    status: { type: String, default: "Pending", trim: true },
    bookingDate: { type: Date, default: Date.now }
  },
  { timestamps: true }
);

module.exports = mongoose.model("RepairBooking", repairBookingSchema);
