<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Accessories Shop | Mobimend Spares</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&family=Montserrat:wght@700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <nav class="site-nav">
    <a class="nav-left" href="index.php">
      <img src="assets/LOGO FINAL MOBIMEND WH BG.png" alt="Mobimend Spares" class="logo">
      <div class="brand"><h1>MOBIMEND</h1><p class="tagline">Accessories shop</p></div>
    </a>
    <button class="menu-toggle" id="menu-toggle" aria-expanded="false" aria-label="Toggle navigation"><i class="fa-solid fa-bars"></i></button>
    <ul class="nav-links" id="nav-links">
      <li><a href="index.php">Home</a></li>
      <li><a href="repair.php">Repair</a></li>
      <li><a class="active" href="accessories.php">Shop</a></li>
      <li><a href="wholesale.php">Wholesale</a></li>
      <li><a href="blog.php">Blog</a></li>
      <li><a href="track.php">Track</a></li>
      <li><a href="account.php">Account</a></li>
    </ul>
  </nav>

  <main>
    <section class="section alt">
      <div class="section-inner">
        <p class="section-kicker"><i class="fa-solid fa-bag-shopping"></i> Retail accessories</p>
        <h1 class="section-title">A fast, filterable shop for phone accessories.</h1>
        <p class="section-copy">Search by accessory, compatibility, or category. The cart drawer and payment states are frontend-ready for checkout integration.</p>

        <div class="shop-toolbar">
          <input id="productSearch" type="search" placeholder="Search cases, chargers, protectors, earbuds...">
          <button class="btn-dark cart-count-button" type="button" data-open-cart><i class="fa-solid fa-cart-shopping"></i> View cart <span id="cartCount">0</span></button>
        </div>
        <div class="category-pills">
          <button class="pill active" type="button" data-category="All">All</button>
          <button class="pill" type="button" data-category="Cases">Cases</button>
          <button class="pill" type="button" data-category="Chargers">Chargers</button>
          <button class="pill" type="button" data-category="Protectors">Protectors</button>
          <button class="pill" type="button" data-category="Audio">Audio</button>
          <button class="pill" type="button" data-category="Cables">Cables</button>
          <button class="pill" type="button" data-category="Power">Power</button>
        </div>

        <div id="productGrid" class="product-grid" aria-live="polite"></div>
      </div>
    </section>

    <section class="section">
      <div class="section-inner">
        <p class="section-kicker"><i class="fa-solid fa-credit-card"></i> Checkout and payment</p>
        <h2 class="section-title">Checkout flow designed before payment code is connected.</h2>
        <div class="checkout-flow">
          <div class="payment-card">
            <h3>Customer and delivery</h3>
            <div class="form-grid" style="margin-top: 16px;">
              <div><label>Name</label><input placeholder="Jane Customer"></div>
              <div><label>Phone</label><input placeholder="07XX XXX XXX"></div>
              <div><label>Delivery option</label><select><option>Pickup at Juja shop</option><option>Local delivery</option><option>Courier delivery</option></select></div>
              <div><label>Saved address</label><select><option>Use default address</option><option>Add new address</option></select></div>
            </div>
          </div>

          <div class="payment-card">
            <h3>Payment method</h3>
            <label class="payment-method"><input type="radio" name="payment" value="mpesa" data-payment-method checked> M-Pesa STK Push</label>
            <label class="payment-method"><input type="radio" name="payment" value="card" data-payment-method> Card payment</label>

            <div data-payment-panel="mpesa">
              <div class="mpesa-phone">
                <div><i class="fa-solid fa-mobile-screen-button"></i><br><strong>Awaiting PIN</strong><br><small>STK push state</small></div>
              </div>
              <label>M-Pesa number</label>
              <input placeholder="2547XXXXXXXX">
              <button class="btn-secondary" type="button" style="margin-top: 12px;">Send STK Push</button>
            </div>

            <div data-payment-panel="card" hidden>
              <p><strong>Card payments:</strong> Visa, Mastercard, and virtual cards.</p>
              <p><strong>Status flow:</strong> Authorizing -> Verified -> Receipt issued.</p>
              <div class="form-grid">
                <div class="full"><label>Card number</label><input placeholder="4242 4242 4242 4242"></div>
                <div><label>Expiry</label><input placeholder="MM/YY"></div>
                <div><label>CVV</label><input placeholder="123"></div>
              </div>
              <button class="btn-dark" type="button" style="margin-top: 12px;">Authorize card payment</button>
            </div>
          </div>
        </div>
      </div>
    </section>
  </main>

  <aside class="cart-drawer" id="cartDrawer" aria-label="Shopping cart">
    <button class="btn-ghost" type="button" data-close-cart><i class="fa-solid fa-xmark"></i> Close</button>
    <h2>Your cart</h2>
    <div id="cartItems"></div>
    <div class="price-row" style="margin-top: 18px;"><strong>Total</strong><strong id="cartTotal">KES 0</strong></div>
    <a class="btn-primary" href="#checkout" style="width: 100%; margin-top: 16px;">Continue to checkout</a>
  </aside>

  <footer class="footer">
    <div class="container footer-grid">
      <div><h3>Mobimend Shop</h3><p>Accessories with compatibility-first product cards and fast checkout states.</p></div>
      <div><h3>Shop</h3><ul><li>Cases</li><li>Chargers</li><li>Protectors</li></ul></div>
      <div><h3>Support</h3><ul><li><a href="track.php">Track order</a></li><li><a href="contact.php">Contact team</a></li></ul></div>
      <div><h3>Payments</h3><ul><li>M-Pesa STK</li><li>Card payments</li><li>Verification status</li></ul></div>
    </div>
    <div class="container footer-bottom">&copy; 2026 Mobimend Spares.</div>
  </footer>

  <script src="chatbot.js"></script>
  <script src="site.js"></script>
</body>
</html>
