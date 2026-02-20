const express = require("express");
const RepairBooking = require("../models/RepairBooking");
const { requireAuth } = require("../middleware/auth");

const router = express.Router();

// Public: create a repair booking
router.post("/", async (req, res) => {
  const booking = await RepairBooking.create(req.body);
  res.status(201).json(booking);
});

// Admin: list bookings
router.get("/", requireAuth, async (_req, res) => {
  const bookings = await RepairBooking.find().sort({ createdAt: -1 });
  res.json(bookings);
});

// Admin: update booking status
router.put("/:id/status", requireAuth, async (req, res) => {
  const { status } = req.body;
  const booking = await RepairBooking.findByIdAndUpdate(
    req.params.id,
    { status },
    { new: true, runValidators: true }
  );
  if (!booking) {
    return res.status(404).json({ error: "Booking not found" });
  }
  res.json(booking);
});

// Admin: delete booking
router.delete("/:id", requireAuth, async (req, res) => {
  const booking = await RepairBooking.findByIdAndDelete(req.params.id);
  if (!booking) {
    return res.status(404).json({ error: "Booking not found" });
  }
  res.json({ status: "deleted" });
});

module.exports = router;
