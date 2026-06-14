<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Mobimend Spares | Phone Repair, Parts, Accessories</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&family=Montserrat:wght@700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="style.css">
  <link rel="stylesheet" href="shop_overhaul.css">
</head>
<body>
  <nav class="site-nav">
    <a class="nav-left" href="index.php">
      <img src="assets/LOGO FINAL MOBIMEND WH BG.png" alt="Mobimend Spares" class="logo">
      <div class="brand">
        <h1>MOBIMEND</h1>
        <p class="tagline">Phone care OS</p>
      </div>
    </a>
    <button class="menu-toggle" id="menu-toggle" aria-expanded="false" aria-label="Toggle navigation"><i class="fa-solid fa-bars"></i></button>
    <ul class="nav-links" id="nav-links">
      <li><a class="active" href="index.php">Home</a></li>
      <li><a href="repair.php">Repair</a></li>
      <li><a href="accessories.php">Shop</a></li>
      <li><a href="wholesale.php">Wholesale</a></li>
      <li><a href="blog.php">Blog</a></li>
      <li><a href="track.php">Track</a></li>
      <li><a href="account.php">Account</a></li>
      <li>
        <a class="nav-cart-btn" href="accessories.php#cart" aria-label="View cart">
          <i class="fa-solid fa-cart-shopping"></i>
          Cart
          <span class="nav-cart-badge" id="navCartBadge"></span>
        </a>
      </li>
    </ul>
  </nav>

  <header class="hero-os">
    <div class="container hero-grid">
      <div class="hero-copy">
        <p class="section-kicker"><i class="fa-solid fa-bolt"></i> Phone repairs, parts, accessories, and tracking</p>
        <h1>Your phone care platform, not just a repair counter.</h1>
        <p>Diagnose the problem, check parts, book a repair, buy accessories, pay with M-Pesa or card, and track every status from one clean Mobimend experience.</p>
        <div class="hero-actions">
          <a class="btn-secondary" href="repair.php#repair-booking"><i class="fa-solid fa-screwdriver-wrench"></i> Start Diagnosis</a>
          <a class="btn-light" href="accessories.php"><i class="fa-solid fa-bag-shopping"></i> Shop Accessories</a>
          <a class="btn-ghost" href="wholesale.php"><i class="fa-solid fa-boxes-stacked"></i> Wholesale Parts</a>
        </div>
        <div class="trust-strip">
          <div class="trust-item"><strong>90 days</strong><span>repair warranty</span></div>
          <div class="trust-item"><strong>M-Pesa</strong><span>STK ready UI</span></div>
          <div class="trust-item"><strong>Live stock</strong><span>inventory-backed parts</span></div>
          <div class="trust-item"><strong>Juja</strong><span>walk-in repair desk</span></div>
        </div>
      </div>

      <div class="phone-stage" aria-label="Mobimend diagnostic phone preview">
        <div class="floating-chip"><i class="fa-solid fa-mobile-screen"></i> Screen detected</div>
        <div class="floating-chip"><i class="fa-solid fa-battery-quarter"></i> Battery quote</div>
        <div class="floating-chip"><i class="fa-solid fa-money-bill-wave"></i> STK pending</div>
        <div class="css-phone">
          <div class="phone-screen">
            <div class="screen-content">
              <div class="phone-status">
                <span>Mobimend OS</span>
                <span class="battery-glyph"></span>
              </div>
              <div class="diagnostic-card">
                <small>Current diagnostic</small>
                <h3>iPhone 13 screen + battery</h3>
              </div>
              <div class="diagnostic-grid">
                <div class="mini-metric"><span>Estimate</span><strong>KES 7,500</strong></div>
                <div class="mini-metric"><span>Turnaround</span><strong>2 hrs</strong></div>
                <div class="mini-metric"><span>Part stock</span><strong>Available</strong></div>
                <div class="mini-metric"><span>Status</span><strong>Ready</strong></div>
              </div>
              <div class="status-feed">
                <div class="feed-pill"><span>Technician slot</span><i class="fa-solid fa-check"></i></div>
                <div class="feed-pill"><span>Compatible display</span><i class="fa-solid fa-check"></i></div>
                <div class="feed-pill"><span>Payment verification</span><i class="fa-solid fa-spinner"></i></div>
              </div>
            </div>
            <div class="screen-gloss"></div>
          </div>
        </div>
      </div>
    </div>
  </header>

  <main>
    <section class="section alt">
      <div class="section-inner">
        <p class="section-kicker"><i class="fa-solid fa-layer-group"></i> Choose your path</p>
        <h2 class="section-title">A connected flow for every phone problem.</h2>
        <p class="section-copy">The design borrows the polish of modern phone launches, the usefulness of repair guides, and the speed of checkout-first commerce.</p>

        <div class="path-grid">
          <article class="path-card">
            <div class="path-icon"><i class="fa-solid fa-stethoscope"></i></div>
            <h3>Fix my phone</h3>
            <p>Guided device and issue selection with a visual phone diagnostic, available slots, and repair tracking.</p>
            <a class="btn-dark" href="repair.php">Book a repair</a>
          </article>
          <article class="path-card">
            <div class="path-icon"><i class="fa-solid fa-headphones"></i></div>
            <h3>Buy accessories</h3>
            <p>Search, category filters, quick cart, checkout states, and payment interface for retail customers.</p>
            <a class="btn-dark" href="accessories.php">Open shop</a>
          </article>
          <article class="path-card">
            <div class="path-icon"><i class="fa-solid fa-boxes-packing"></i></div>
            <h3>Source wholesale</h3>
            <p>MOQ-aware ordering, pricing tiers, stock visibility, and sales support for resellers and repair shops.</p>
            <a class="btn-dark" href="wholesale.php">Build order</a>
          </article>
        </div>
      </div>
    </section>

    <section class="section comparison-band">
      <div class="section-inner">
        <p class="section-kicker"><i class="fa-solid fa-microchip"></i> Mobimend command layer</p>
        <h2 class="section-title">A futuristic operating layer for repairs, parts, payments, and tracking.</h2>

        <div class="command-grid">
          <div class="command-device" aria-label="Mobimend system core">
            <div class="core-ring ring-one"></div>
            <div class="core-ring ring-two"></div>
            <div class="command-core">
              <i class="fa-solid fa-mobile-screen-button"></i>
              <strong>Mobimend OS</strong>
              <span>repair-commerce engine</span>
            </div>
            <div class="node node-a">diagnose</div>
            <div class="node node-b">stock</div>
            <div class="node node-c">pay</div>
            <div class="node node-d">track</div>
          </div>

          <div class="command-telemetry">
            <div class="telemetry-card scan">
              <i class="fa-solid fa-fingerprint"></i>
              <span>SCAN</span>
              <strong>Device issue</strong>
            </div>
            <div class="telemetry-card parts">
              <i class="fa-solid fa-microchip"></i>
              <span>SYNC</span>
              <strong>Parts stock</strong>
            </div>
            <div class="telemetry-card payment">
              <i class="fa-solid fa-credit-card"></i>
              <span>VERIFY</span>
              <strong>STK + card</strong>
            </div>
            <div class="telemetry-card status">
              <i class="fa-solid fa-tower-broadcast"></i>
              <span>LIVE</span>
              <strong>Status feed</strong>
            </div>
            <div class="telemetry-line horizontal"></div>
            <div class="telemetry-line vertical"></div>
          </div>
        </div>
      </div>
    </section>

    <section class="section">
      <div class="section-inner">
        <p class="section-kicker"><i class="fa-solid fa-route"></i> End-to-end status</p>
        <h2 class="section-title">From cracked screen to completed repair, every step is visible.</h2>
        <div class="tracking-grid" style="margin-top: 28px;">
          <div class="track-card">
            <h3>Customer timeline</h3>
            <p>Tracking is built around a simple repair/order timeline that can later poll the backend for live changes.</p>
            <div class="timeline">
              <div class="timeline-step done"><div class="timeline-dot"><i class="fa-solid fa-check"></i></div><div><strong>Booked</strong><p>Customer submitted device and issue.</p></div></div>
              <div class="timeline-step done"><div class="timeline-dot"><i class="fa-solid fa-check"></i></div><div><strong>Diagnosing</strong><p>Technician confirms fault and parts.</p></div></div>
              <div class="timeline-step live"><div class="timeline-dot"><i class="fa-solid fa-bolt"></i></div><div><strong>In repair</strong><p>Screen assembly is being fitted.</p></div></div>
              <div class="timeline-step"><div class="timeline-dot"><i class="fa-solid fa-flag"></i></div><div><strong>Ready</strong><p>Pickup notification and payment verification.</p></div></div>
            </div>
          </div>
          <div class="payment-card">
            <h3>Payment interface</h3>
            <p>M-Pesa STK and card payments are designed as visible states first, ready to connect to Daraja and card authorization logic.</p>
            <div class="payment-grid" style="margin-top: 18px;">
              <div class="payment-method">
                <strong><i class="fa-solid fa-mobile-screen-button"></i> M-Pesa STK Push</strong>
                <span>Send request -> Await PIN -> Confirm receipt.</span>
              </div>
              <div class="payment-method">
                <strong><i class="fa-solid fa-credit-card"></i> Card payments</strong>
                <span>Enter card -> Authorize -> Payment verified.</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  </main>

  <footer class="footer">
    <div class="container footer-grid">
      <div>
        <h3>Mobimend Spares</h3>
        <p>Phone repairs, parts, accessories, wholesale supply, and tracking in one customer-facing platform.</p>
      </div>
      <div><h3>Services</h3><ul><li><a href="repair.php">Repair booking</a></li><li><a href="accessories.php">Accessories shop</a></li><li><a href="wholesale.php">Wholesale parts</a></li></ul></div>
      <div><h3>Customer</h3><ul><li><a href="track.php">Track order</a></li><li><a href="account.php">Account dashboard</a></li><li><a href="blog.php">Repair guides</a></li></ul></div>
      <div><h3>Contact</h3><ul><li>Juja, Mum&amp;Dad business center, stall 9E</li><li>0799 183 907</li><li>mobimendspares@gmail.com</li></ul></div>
    </div>
    <div class="container footer-bottom">&copy; 2026 Mobimend Spares. All rights reserved.</div>
  </footer>

  <script src="chatbot.js"></script>
  <script src="site.js"></script>
</body>
</html>
