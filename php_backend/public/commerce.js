const commerceState = {
  debounceTimers: new Map(),
};

function commerceUpdateCartCounts(root = document) {
  const source = root.querySelector('[data-current-cart-count]');
  if (!source) return;
  const count = source.dataset.currentCartCount || source.textContent.trim() || '0';
  document.querySelectorAll('[data-cart-count]').forEach((node) => {
    node.textContent = count;
  });
}

function commerceOpenCart() {
  document.querySelector('#cart')?.classList.add('open');
  document.body.classList.add('cart-open');
}

function commerceCloseCart() {
  document.querySelector('#cart')?.classList.remove('open');
  document.body.classList.remove('cart-open');
}

async function commerceSubmitCartForm(form) {
  const button = form.querySelector('button[type="submit"]');
  const original = button ? button.innerHTML : '';
  if (button) {
    button.disabled = true;
    button.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
  }

  try {
    const response = await fetch(form.action, {
      method: 'POST',
      body: new FormData(form),
      credentials: 'same-origin',
    });
    const html = await response.text();
    const nextDocument = new DOMParser().parseFromString(html, 'text/html');
    const nextCart = nextDocument.querySelector('#cart');
    const currentCart = document.querySelector('#cart');
    if (nextCart && currentCart) {
      currentCart.replaceWith(nextCart);
      const nextCheckout = nextDocument.querySelector('#checkout');
      const currentCheckout = document.querySelector('#checkout');
      if (nextCheckout && currentCheckout) {
        currentCheckout.replaceWith(nextCheckout);
      }
      commerceUpdateCartCounts(nextDocument);
      commerceOpenCart();
      return;
    }
    window.location.href = form.action;
  } catch (error) {
    form.submit();
  } finally {
    if (button) {
      button.disabled = false;
      button.innerHTML = original;
    }
  }
}

document.addEventListener('submit', (event) => {
  const form = event.target.closest('[data-cart-form], .cart-list');
  if (!form || !window.fetch || !window.DOMParser) return;
  event.preventDefault();
  commerceSubmitCartForm(form);
});

document.addEventListener('click', (event) => {
  if (event.target.closest('[data-open-cart]')) {
    event.preventDefault();
    commerceOpenCart();
  }
  if (event.target.closest('[data-close-cart]') || event.target.matches('.cart-backdrop')) {
    event.preventDefault();
    commerceCloseCart();
  }
});

document.querySelectorAll('[data-live-search]').forEach((input) => {
  input.addEventListener('input', () => {
    const key = input.id || input.name || 'search';
    clearTimeout(commerceState.debounceTimers.get(key));
    commerceState.debounceTimers.set(key, setTimeout(async () => {
      const target = document.querySelector(input.dataset.target || '');
      if (!target) return;

      const params = new URLSearchParams();
      params.set('channel', input.dataset.searchChannel || 'shop');
      params.set('q', input.value);

      const form = input.closest('form');
      form?.querySelectorAll('select, input[type="hidden"]').forEach((field) => {
        if (field.name && field.value) params.set(field.name, field.value);
      });

      target.classList.add('is-loading');
      try {
        const response = await fetch(`product_search.php?${params.toString()}`, { credentials: 'same-origin' });
        const payload = await response.json();
        target.innerHTML = payload.html || '';
        target.dataset.resultCount = String(payload.count || 0);
      } finally {
        target.classList.remove('is-loading');
      }
    }, 260));
  });
});

document.querySelectorAll('[data-sort-catalog]').forEach((select) => {
  select.addEventListener('change', () => {
    const target = document.querySelector(select.dataset.sortCatalog || '');
    if (!target) return;
    const cards = Array.from(target.querySelectorAll('[data-wholesale-card]'));
    const mode = select.value;
    cards.sort((a, b) => {
      if (mode === 'price') return Number(a.dataset.price || 0) - Number(b.dataset.price || 0);
      if (mode === 'stock') return Number(b.dataset.stock || 0) - Number(a.dataset.stock || 0);
      return String(a.dataset[mode] || '').localeCompare(String(b.dataset[mode] || ''));
    });
    cards.forEach((card) => target.appendChild(card));
  });
});

commerceUpdateCartCounts();
