<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Track Order | Mobimend Spares</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&family=Montserrat:wght@700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <nav class="site-nav">
    <a class="nav-left" href="index.php"><img src="assets/LOGO FINAL MOBIMEND WH BG.png" alt="Mobimend" class="logo"><div class="brand"><h1>MOBIMEND</h1><p class="tagline">Tracking</p></div></a>
    <button class="menu-toggle" id="menu-toggle" aria-expanded="false" aria-label="Toggle navigation"><i class="fa-solid fa-bars"></i></button>
    <ul class="nav-links" id="nav-links">
      <li><a href="index.php">Home</a></li><li><a href="repair.php">Repair</a></li><li><a href="accessories.php">Shop</a></li><li><a href="wholesale.php">Wholesale</a></li><li><a href="blog.php">Blog</a></li><li><a class="active" href="track.php">Track</a></li><li><a href="account.php">Account</a></li>
    </ul>
  </nav>

  <main class="section alt">
    <div class="section-inner tracking-grid">
      <div class="track-card">
        <p class="section-kicker"><i class="fa-solid fa-satellite-dish"></i> Real-time style status</p>
        <h1 class="section-title">Track a repair, order, or payment.</h1>
        <p class="section-copy">This frontend is ready for polling the backend every few seconds once status endpoints are connected.</p>
        <form id="trackForm" class="form-grid" style="margin-top: 20px;">
          <div class="full"><label>Reference number</label><input placeholder="MB-102938 or booking ID"></div>
          <div><label>Phone number</label><input placeholder="07XX XXX XXX"></div>
          <div><label>Type</label><select><option>Repair booking</option><option>Product order</option><option>Wholesale order</option><option>Payment</option></select></div>
          <div class="full"><button class="btn-primary" type="submit">Show status</button></div>
        </form>
      </div>

      <div class="track-card" id="trackingResult">
        <h3>Repair MB-102938</h3>
        <p>Last updated just now. Next automatic refresh in 30 seconds.</p>
        <div class="timeline">
          <div class="timeline-step done"><div class="timeline-dot"><i class="fa-solid fa-check"></i></div><div><strong>Booked</strong><p>Repair request received.</p></div></div>
          <div class="timeline-step done"><div class="timeline-dot"><i class="fa-solid fa-check"></i></div><div><strong>Payment received</strong><p>M-Pesa receipt recorded.</p></div></div>
          <div class="timeline-step live"><div class="timeline-dot"><i class="fa-solid fa-screwdriver-wrench"></i></div><div><strong>In repair</strong><p>Technician is fitting replacement screen.</p></div></div>
          <div class="timeline-step"><div class="timeline-dot"><i class="fa-solid fa-box-open"></i></div><div><strong>Ready for pickup</strong><p>Quality check and customer notification.</p></div></div>
        </div>
      </div>
    </div>
  </main>

  <script src="chatbot.js"></script>
  <script src="site.js"></script>
</body>
</html>
