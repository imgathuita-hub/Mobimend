<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Repair Guides | Mobimend Spares</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&family=Montserrat:wght@700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <nav class="site-nav">
    <a class="nav-left" href="index.php">
      <img src="assets/LOGO FINAL MOBIMEND WH BG.png" alt="Mobimend Spares" class="logo">
      <div class="brand"><h1>MOBIMEND</h1><p class="tagline">Repair knowledge</p></div>
    </a>
    <button class="menu-toggle" id="menu-toggle" aria-expanded="false" aria-label="Toggle navigation"><i class="fa-solid fa-bars"></i></button>
    <ul class="nav-links" id="nav-links">
      <li><a href="index.php">Home</a></li>
      <li><a href="repair.php">Repair</a></li>
      <li><a href="accessories.php">Shop</a></li>
      <li><a href="wholesale.php">Wholesale</a></li>
      <li><a class="active" href="blog.php">Blog</a></li>
      <li><a href="track.php">Track</a></li>
      <li><a href="account.php">Account</a></li>
    </ul>
  </nav>

  <main class="section alt">
    <div class="section-inner">
      <p class="section-kicker"><i class="fa-solid fa-book-open"></i> Repair help center</p>
      <h1 class="section-title">Useful phone repair information before customers book.</h1>
      <p class="section-copy">The blog should behave like a searchable help desk: symptoms, parts quality, repair expectations, payment help, and wholesale buying guidance.</p>

      <div class="blog-tools">
        <input type="search" placeholder="Search: iPhone battery swelling, Tecno screen price, charging port issue...">
        <button class="btn-dark" type="button"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
      </div>
      <div class="category-pills">
        <span class="pill active">All</span>
        <span class="pill">Repair guides</span>
        <span class="pill">Parts quality</span>
        <span class="pill">Buying advice</span>
        <span class="pill">Payment help</span>
        <span class="pill">Wholesale tips</span>
      </div>

      <div class="blog-grid">
        <article class="blog-card featured">
          <div class="blog-meta"><span class="status-pill">Repair guide</span><span class="status-pill">5 min read</span></div>
          <h3>How to tell if your phone needs a screen, battery, or charging port repair</h3>
          <p>Symptoms are often confusing. This guide maps customer complaints to likely repairs and parts needed.</p>
          <a class="btn-light" href="repair.php#repair-booking">Start diagnosis</a>
        </article>
        <article class="blog-card"><div class="blog-meta"><span class="status-pill">Parts quality</span></div><h3>Original, OEM, and high-copy screens explained</h3><p>Help customers understand quality grades before they buy or book.</p></article>
        <article class="blog-card"><div class="blog-meta"><span class="status-pill">Payment help</span></div><h3>What happens after an M-Pesa STK Push?</h3><p>Show pending, paid, failed, and verification states clearly.</p></article>
        <article class="blog-card"><div class="blog-meta"><span class="status-pill">Phone care</span></div><h3>Why batteries swell and what to do immediately</h3><p>Safety-first education that also routes customers to repair booking.</p></article>
        <article class="blog-card"><div class="blog-meta"><span class="status-pill">Wholesale tips</span></div><h3>How repair shops should reorder fast-moving parts</h3><p>Use historic repairs and low-stock alerts to protect margins.</p></article>
      </div>
    </div>
  </main>

  <footer class="footer">
    <div class="container footer-grid">
      <div><h3>Mobimend Guides</h3><p>Repair content that feeds search, trust, and better booking decisions.</p></div>
      <div><h3>Topics</h3><ul><li>Diagnostics</li><li>Parts</li><li>Payments</li></ul></div>
      <div><h3>Actions</h3><ul><li><a href="repair.php">Book repair</a></li><li><a href="track.php">Track order</a></li></ul></div>
      <div><h3>Shop</h3><ul><li><a href="accessories.php">Accessories</a></li><li><a href="wholesale.php">Wholesale</a></li></ul></div>
    </div>
    <div class="container footer-bottom">&copy; 2026 Mobimend Spares.</div>
  </footer>

  <script src="chatbot.js"></script>
  <script src="site.js"></script>
</body>
</html>
