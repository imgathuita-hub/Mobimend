<?php
// repairs/admin_view.php

// --- SIMPLE AUTH (replace credentials) ---
$ADMIN_USER = 'admin';
$ADMIN_PASS = 'StrongPasswordHere'; // change this

if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])
    || $_SERVER['PHP_AUTH_USER'] !== $ADMIN_USER
    || $_SERVER['PHP_AUTH_PW'] !== $ADMIN_PASS) {
    header('WWW-Authenticate: Basic realm="Admin Area"');
    header('HTTP/1.0 401 Unauthorized');
    echo 'Authentication required.';
    exit;
}

include 'db_connect.php';

// Handle actions: mark complete, delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['action']) && !empty($_POST['id'])) {
        $id = (int)$_POST['id'];
        if ($_POST['action'] === 'complete') {
            $stmt = $conn->prepare("UPDATE repair_bookings SET status='Completed' WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
        } elseif ($_POST['action'] === 'delete') {
            $stmt = $conn->prepare("DELETE FROM repair_bookings WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
        }
    }
    // reload to reflect changes (avoid resubmission)
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Fetch bookings
$result = $conn->query("SELECT id, customer_name, phone_number, email, device_model, repair_type, issue_description, booking_date, status FROM repair_bookings ORDER BY booking_date DESC");

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Admin - Repair Bookings</title>
  <style>
    body { font-family: Arial, sans-serif; padding: 20px; background:#f6f8fa; color:#111; }
    h1 { margin-bottom: 8px; }
    table { width: 100%; border-collapse: collapse; background:#fff; box-shadow:0 2px 8px rgba(0,0,0,0.06); }
    th, td { padding: 12px; border-bottom: 1px solid #eee; text-align:left; vertical-align:top; }
    th { background:#f3f6f9; font-weight:700; }
    .actions form { display:inline-block; margin-right:6px; }
    .btn { padding:6px 10px; border-radius:6px; text-decoration:none; color:#fff; font-weight:600; border:none; cursor:pointer; }
    .btn-complete { background:#10b981; }
    .btn-delete { background:#ef4444; }
    .status-pending { color:#065f46; font-weight:700; }
    .status-completed { color:#6b7280; font-weight:700; }
    .small { font-size:0.9rem; color:#555; }
    .container { max-width:1100px; margin:0 auto; }
  </style>
</head>
<body>
  <div class="container">
    <h1>Repair Bookings</h1>
    <p class="small">Use this panel to manage incoming repair bookings. Actions: mark complete or delete.</p>

    <?php if ($result && $result->num_rows > 0): ?>
      <table>
        <thead>
          <tr>
            <th>Booked</th>
            <th>Customer</th>
            <th>Contact</th>
            <th>Device / Repair</th>
            <th>Notes</th>
            <th>Status</th>
            <th style="width:180px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php while ($row = $result->fetch_assoc()): ?>
            <tr>
              <td><div class="small"><?=htmlspecialchars($row['booking_date'])?></div></td>
              <td><strong><?=htmlspecialchars($row['customer_name'])?></strong></td>
              <td>
                <div class="small"><?=htmlspecialchars($row['phone_number'])?></div>
                <div class="small"><?=htmlspecialchars($row['email'])?></div>
              </td>
              <td>
                <div><strong><?=htmlspecialchars($row['device_model'])?></strong></div>
                <div class="small"><?=htmlspecialchars($row['repair_type'])?></div>
              </td>
              <td><div class="small"><?=nl2br(htmlspecialchars($row['issue_description']))?></div></td>
              <td>
                <?php if ($row['status'] === 'Pending'): ?>
                  <div class="status-pending">Pending</div>
                <?php else: ?>
                  <div class="status-completed">Completed</div>
                <?php endif; ?>
              </td>
              <td class="actions">
                <?php if ($row['status'] === 'Pending'): ?>
                  <form method="post" onsubmit="return confirm('Mark booking #<?= $row['id'] ?> complete?');">
                    <input type="hidden" name="id" value="<?= $row['id'] ?>">
                    <input type="hidden" name="action" value="complete">
                    <button class="btn btn-complete" type="submit">Mark Complete</button>
                  </form>
                <?php endif; ?>

                <form method="post" onsubmit="return confirm('Delete booking #<?= $row['id'] ?>? This cannot be undone.');">
                  <input type="hidden" name="id" value="<?= $row['id'] ?>">
                  <input type="hidden" name="action" value="delete">
                  <button class="btn btn-delete" type="submit">Delete</button>
                </form>
              </td>
            </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p>No bookings found.</p>
    <?php endif; ?>

    <?php
    if ($result) { $result->free(); }
    $conn->close();
    ?>
  </div>
</body>
</html>
