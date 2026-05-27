const jwt = require("jsonwebtoken");

const requireAuth = (req, res, next) => {
  const header = req.headers.authorization || "";
  const token = header.startsWith("Bearer ") ? header.slice(7) : null;

  if (!token) {
    return res.status(401).json({ error: "Missing auth token" });
  }

  try {
    const payload = jwt.verify(token, process.env.JWT_SECRET);
    req.user = payload;
    return next();
  } catch (err) {
    return res.status(401).json({ error: "Invalid auth token" });
  }
};

const requireRole = (...roles) => (req, res, next) => {
  if (!req.user) {
    return res.status(401).json({ error: "Authentication required" });
  }

  if (!roles.includes(req.user.role)) {
    return res.status(403).json({ error: "Insufficient permissions" });
  }

  return next();
};

const requireInventoryRole = requireRole("admin", "super_admin", "inventory_manager");

module.exports = { requireAuth, requireRole, requireInventoryRole };
