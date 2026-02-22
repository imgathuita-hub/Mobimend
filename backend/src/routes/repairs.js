const express = require("express");
const RepairBooking = require("../models/RepairBooking");
const { requireAuth } = require("../middleware/auth");

const router = express.Router();
const ALLOWED_STATUSES = ["Pending", "In Progress", "Completed", "Cancelled"];

const sanitizeText = (value) => String(value || "").trim();

const validateCreatePayload = (body) => {
  const payload = {
    customerName: sanitizeText(body.customerName),
    phoneNumber: sanitizeText(body.phoneNumber),
    email: sanitizeText(body.email),
    deviceModel: sanitizeText(body.deviceModel),
    repairType: sanitizeText(body.repairType),
    issueDescription: sanitizeText(body.issueDescription)
  };

  const errors = {};

  if (!payload.customerName) errors.customerName = "Customer name is required";
  if (!payload.phoneNumber) errors.phoneNumber = "Phone number is required";
  if (!payload.deviceModel) errors.deviceModel = "Device model is required";
  if (!payload.repairType) errors.repairType = "Repair type is required";

  if (payload.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(payload.email)) {
    errors.email = "Email format is invalid";
  }

  return { payload, errors };
};

// Public: create a repair booking
router.post("/", async (req, res) => {
  try {
    const { payload, errors } = validateCreatePayload(req.body || {});
    if (Object.keys(errors).length) {
      return res.status(400).json({ error: "Validation failed", details: errors });
    }

    const booking = await RepairBooking.create(payload);
    return res.status(201).json({
      message: "Booking created",
      bookingId: booking._id,
      booking
    });
  } catch (err) {
    return res.status(500).json({ error: "Failed to create booking" });
  }
});

// Admin: list bookings
router.get("/", requireAuth, async (_req, res) => {
  try {
    const bookings = await RepairBooking.find().sort({ createdAt: -1 });
    return res.json(bookings);
  } catch (err) {
    return res.status(500).json({ error: "Failed to fetch bookings" });
  }
});

// Admin: update booking status
router.put("/:id/status", requireAuth, async (req, res) => {
  try {
    const status = sanitizeText(req.body?.status);
    if (!ALLOWED_STATUSES.includes(status)) {
      return res.status(400).json({
        error: "Invalid status",
        allowed: ALLOWED_STATUSES
      });
    }

    const booking = await RepairBooking.findByIdAndUpdate(
      req.params.id,
      { status },
      { new: true, runValidators: true }
    );
    if (!booking) {
      return res.status(404).json({ error: "Booking not found" });
    }
    return res.json(booking);
  } catch (err) {
    return res.status(500).json({ error: "Failed to update booking status" });
  }
});

// Admin: delete booking
router.delete("/:id", requireAuth, async (req, res) => {
  try {
    const booking = await RepairBooking.findByIdAndDelete(req.params.id);
    if (!booking) {
      return res.status(404).json({ error: "Booking not found" });
    }
    return res.json({ status: "deleted" });
  } catch (err) {
    return res.status(500).json({ error: "Failed to delete booking" });
  }
});

module.exports = router;
