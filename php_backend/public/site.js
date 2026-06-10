const $ = (selector, root = document) => root.querySelector(selector);
const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));

const navToggle = $('.menu-toggle');
const navLinks = $('#nav-links');

if (navToggle && navLinks) {
  navToggle.addEventListener('click', () => {
    const isOpen = navLinks.classList.toggle('show');
    navToggle.setAttribute('aria-expanded', String(isOpen));
    document.body.classList.toggle('menu-open', isOpen);
  });
}

$$('[data-issue-choice]').forEach((choice) => {
  choice.addEventListener('change', () => {
    const target = choice.dataset.issueChoice;
    const device = $('#repairDevice');
    if (!device) return;
    device.className = 'repair-device';
    device.classList.add(`issue-${target}`);
  });
});

$$('[data-slot]').forEach((slot) => {
  slot.addEventListener('click', () => {
    $$('[data-slot]').forEach((item) => item.classList.remove('active'));
    slot.classList.add('active');
    const input = $('#preferred_time_slot');
    if (input) input.value = slot.dataset.slot || slot.textContent.trim();
  });
});

document.addEventListener('click', (event) => {
  const openCart = event.target.closest('[data-open-cart]');
  const closeCart = event.target.closest('[data-close-cart]');

  if (openCart) $('#cartDrawer')?.classList.add('open');
  if (closeCart) $('#cartDrawer')?.classList.remove('open');
});

$$('[data-payment-method]').forEach((method) => {
  method.addEventListener('change', () => {
    $$('[data-payment-panel]').forEach((panel) => {
      panel.hidden = panel.dataset.paymentPanel !== method.value;
    });
  });
});

const trackForm = $('#trackForm');
if (trackForm) {
  trackForm.addEventListener('submit', (event) => {
    event.preventDefault();
    $('#trackingResult')?.removeAttribute('hidden');
  });
}

// ── Phase 2: Live search debounce ────────────────────────────────────────────
(function () {
  const searchInput = document.getElementById('productSearch');
  if (!searchInput || !document.querySelector('[data-server-products]')) return;

  let debounceTimer;
  searchInput.addEventListener('input', function () {
    clearTimeout(debounceTimer);
    const query = this.value.trim();

    // If server-rendered products exist, submit the form after 380ms pause
    debounceTimer = setTimeout(() => {
      const form = this.closest('form');
      if (form) form.submit();
    }, 380);
  });
})();

// ── Phase 2: Nav cart badge count sync ──────────────────────────────────────
(function () {
  function updateNavBadge() {
    const badge = document.getElementById('navCartBadge');
    if (!badge) return;

    // Count items from any cart-v2-count or cartCountBadge on the page
    const cartCountEl = document.getElementById('cartCountBadge');
    if (cartCountEl) {
      const count = parseInt(cartCountEl.textContent || '0', 10);
      badge.textContent = count > 0 ? String(count) : '';
      badge.dataset.count = String(count);
    }
  }

  // Run on load and after any form submission that updates the page
  updateNavBadge();
  document.addEventListener('click', function (e) {
    const btn = e.target.closest('[type="submit"]');
    if (btn) setTimeout(updateNavBadge, 100);
  });
})();

// ── Phase 2: Payment option highlight (no-JS fallback works too) ─────────────
(function () {
  document.querySelectorAll('.pay-option').forEach(function (option) {
    option.addEventListener('click', function () {
      const radio = this.querySelector('input[type=radio]');
      if (radio) radio.checked = true;
    });
  });
})();

// ── Phase 2: Add-to-cart button feedback ─────────────────────────────────────
(function () {
  document.querySelectorAll('.card-add-btn[type=submit]').forEach(function (btn) {
    btn.closest('form') && btn.closest('form').addEventListener('submit', function () {
      btn.textContent = '✓ Added';
      btn.classList.add('added');
    });
  });
})();

// ── Phase 2: Cart drawer open/close ──────────────────────────────────────────
(function () {
  const toggle  = document.getElementById('cartDrawerToggle');
  const drawer  = document.getElementById('cartDrawer');
  const overlay = document.getElementById('cartDrawerOverlay');
  const closeBtn= document.getElementById('cartDrawerClose');
  const goCheckout = document.getElementById('cartGoCheckout');

  if (!toggle || !drawer) return;

  function openDrawer() {
    drawer.classList.add('open');
    overlay && overlay.classList.add('open');
    drawer.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  }

  function closeDrawer() {
    drawer.classList.remove('open');
    overlay && overlay.classList.remove('open');
    drawer.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }

  toggle.addEventListener('click', openDrawer);
  closeBtn && closeBtn.addEventListener('click', closeDrawer);
  overlay && overlay.addEventListener('click', closeDrawer);

  // Close on Escape key
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeDrawer();
  });

  // If cart has items on page load, auto-open if URL hash is #cart
  if (window.location.hash === '#cart') openDrawer();

  // Close drawer and scroll to checkout when "Checkout" button is clicked
  goCheckout && goCheckout.addEventListener('click', function () {
    closeDrawer();
  });

  // Auto-open cart drawer after add-to-cart form submits
  // (the page reloads with #cart hash due to PHP redirect)
  if (window.location.hash === '#cart' || document.referrer.includes('accessories.php')) {
    const count = parseInt(
      (document.getElementById('cartCountBadge') || {}).textContent || '0', 10
    );
    if (count > 0) openDrawer();
  }
})();
