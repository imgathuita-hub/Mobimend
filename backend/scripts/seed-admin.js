const path = require("path");
const mongoose = require("mongoose");
const bcrypt = require("bcryptjs");
const dotenv = require("dotenv");
const User = require("../src/models/User");

// Load local environment variables for seeding.
dotenv.config({ path: path.join(__dirname, "..", ".env") });

const {
  MONGO_URI,
  ADMIN_EMAIL,
  ADMIN_PASSWORD,
  ADMIN_NAME = "Admin"
} = process.env;

const run = async () => {
  if (!MONGO_URI) {
    console.error("MONGO_URI is not set");
    process.exit(1);
  }
  if (!ADMIN_EMAIL || !ADMIN_PASSWORD) {
    console.error("ADMIN_EMAIL and ADMIN_PASSWORD are required");
    process.exit(1);
  }

  // Use a direct connection to avoid relying on app startup.
  await mongoose.connect(MONGO_URI);

  const existing = await User.findOne({ email: ADMIN_EMAIL });
  if (existing) {
    console.log("Admin already exists:", ADMIN_EMAIL);
    await mongoose.disconnect();
    return;
  }

  // Store a hashed password for admin login.
  const passwordHash = await bcrypt.hash(ADMIN_PASSWORD, 10);
  await User.create({
    name: ADMIN_NAME,
    email: ADMIN_EMAIL,
    passwordHash,
    role: "admin"
  });

  console.log("Admin user created:", ADMIN_EMAIL);
  await mongoose.disconnect();
};

run().catch((err) => {
  console.error("Failed to seed admin:", err.message);
  process.exit(1);
});
