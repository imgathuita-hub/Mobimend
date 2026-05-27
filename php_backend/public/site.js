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

const products = [
  { id: 'case-armor', name: 'Armor Mag Case', category: 'Cases', price: 1800, icon: 'case', stock: 'In stock', compatible: 'iPhone, Samsung, Tecno' },
  { id: 'fast-charger', name: '33W Fast Charger', category: 'Chargers', price: 2200, icon: 'charger', stock: 'In stock', compatible: 'USB-C phones' },
  { id: 'glass-pro', name: '9D Glass Protector', category: 'Protectors', price: 700, icon: 'case', stock: 'Low stock', compatible: 'Most models' },
  { id: 'buds-air', name: 'Mobimend Air Buds', category: 'Audio', price: 3500, icon: 'earbuds', stock: 'In stock', compatible: 'Bluetooth devices' },
  { id: 'type-c-cable', name: 'Braided Type-C Cable', category: 'Cables', price: 650, icon: 'charger', stock: 'In stock', compatible: 'USB-C phones' },
  { id: 'power-bank', name: '10000mAh Power Bank', category: 'Power', price: 3900, icon: 'charger', stock: 'In stock', compatible: 'All devices' },
  { id: 'privacy-glass', name: 'Privacy Screen Guard', category: 'Protectors', price: 1100, icon: 'case', stock: 'In stock', compatible: 'iPhone, Samsung' },
  { id: 'clear-case', name: 'Crystal Clear Case', category: 'Cases', price: 1200, icon: 'case', stock: 'In stock', compatible: 'Popular models' }
];

let cart = [];

function renderProducts(filter = 'All', search = '') {
  const grid = $('#productGrid');
  if (!grid) return;

  const term = search.toLowerCase();
  const filtered = products.filter((product) => {
    const matchesCategory = filter === 'All' || product.category === filter;
    const haystack = `${product.name} ${product.category} ${product.compatible}`.toLowerCase();
    return matchesCategory && haystack.includes(term);
  });

  grid.innerHTML = filtered.map((product) => `
    <article class="product-card" data-product-card>
      <div class="product-art">
        <div class="css-accessory ${product.icon}" aria-hidden="true"></div>
      </div>
      <div class="product-body">
        <div class="blog-meta">
          <span class="status-pill">${product.category}</span>
          <span class="status-pill">${product.stock}</span>
        </div>
        <h3>${product.name}</h3>
        <p>${product.compatible}</p>
        <div class="price-row">
          <span class="price">KES ${product.price.toLocaleString()}</span>
          <button class="btn-dark" type="button" data-add-cart="${product.id}"><i class="fa-solid fa-plus"></i> Add</button>
        </div>
      </div>
    </article>
  `).join('');
}

function renderCart() {
  const cartItems = $('#cartItems');
  const cartTotal = $('#cartTotal');
  const cartCount = $('#cartCount');
  if (!cartItems || !cartTotal || !cartCount) return;

  cartItems.innerHTML = cart.length
    ? cart.map((item) => `
      <div class="cart-item">
        <div>
          <strong>${item.name}</strong>
          <p>KES ${item.price.toLocaleString()} x ${item.qty}</p>
        </div>
        <button class="btn-ghost" type="button" data-remove-cart="${item.id}">Remove</button>
      </div>
    `).join('')
    : '<p>Your cart is waiting for accessories.</p>';

  const total = cart.reduce((sum, item) => sum + item.price * item.qty, 0);
  cartTotal.textContent = `KES ${total.toLocaleString()}`;
  cartCount.textContent = String(cart.reduce((sum, item) => sum + item.qty, 0));
}

function addCart(id) {
  const product = products.find((item) => item.id === id);
  if (!product) return;

  const existing = cart.find((item) => item.id === id);
  if (existing) {
    existing.qty += 1;
  } else {
    cart.push({ ...product, qty: 1 });
  }
  renderCart();
  $('#cartDrawer')?.classList.add('open');
}

document.addEventListener('click', (event) => {
  const addButton = event.target.closest('[data-add-cart]');
  const removeButton = event.target.closest('[data-remove-cart]');
  const openCart = event.target.closest('[data-open-cart]');
  const closeCart = event.target.closest('[data-close-cart]');

  if (addButton) addCart(addButton.dataset.addCart);
  if (removeButton) {
    cart = cart.filter((item) => item.id !== removeButton.dataset.removeCart);
    renderCart();
  }
  if (openCart) $('#cartDrawer')?.classList.add('open');
  if (closeCart) $('#cartDrawer')?.classList.remove('open');
});

const productSearch = $('#productSearch');
const categoryButtons = $$('[data-category]');
const serverProductGrid = $('#productGrid')?.dataset.serverProducts;

if ($('#productGrid') && !serverProductGrid) {
  renderProducts();
  renderCart();
}

if (!serverProductGrid) {
  categoryButtons.forEach((button) => {
    button.addEventListener('click', () => {
      categoryButtons.forEach((item) => item.classList.remove('active'));
      button.classList.add('active');
      renderProducts(button.dataset.category, productSearch?.value || '');
    });
  });

  if (productSearch) {
    productSearch.addEventListener('input', () => {
      const active = $('[data-category].active')?.dataset.category || 'All';
      renderProducts(active, productSearch.value);
    });
  }
}

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
