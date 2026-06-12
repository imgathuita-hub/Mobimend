async function initiateMpesaPayment(phone, amount, reference, paymentId) {
  const btn = document.getElementById('pay-btn') || document.querySelector('[data-mpesa-trigger]');
  const status = document.querySelector('[data-mpesa-status]');

  if (btn) {
    btn.disabled = true;
    btn.textContent = 'Sending prompt...';
  }
  if (status) status.textContent = 'Sending M-Pesa prompt...';

  try {
    const res = await fetch('../stk_push.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ phone, amount, reference, payment_id: paymentId })
    });

    const data = await res.json();

    if (data.ResponseCode === '0') {
      if (btn) btn.textContent = 'Check your phone and enter PIN';
      if (status) status.textContent = 'Prompt sent. Check your phone and enter your M-Pesa PIN.';
      pollPaymentStatus(data.CheckoutRequestID);
    } else {
      if (btn) {
        btn.textContent = 'Failed. Try again.';
        btn.disabled = false;
      }
      if (status) status.textContent = data.errorMessage || data.ResponseDescription || 'Payment failed. Please retry.';
      alert(data.errorMessage || data.ResponseDescription || 'Payment failed. Please retry.');
    }
  } catch (err) {
    if (btn) {
      btn.textContent = 'Error. Retry.';
      btn.disabled = false;
    }
    if (status) status.textContent = 'Could not start payment. Please retry.';
  }
}

async function pollPaymentStatus(checkoutRequestId) {
  let tries = 0;
  const status = document.querySelector('[data-mpesa-status]');
  const checkout = document.querySelector('[data-mpesa-checkout]');
  const successUrl = checkout?.dataset.successUrl || 'track.php';

  const interval = setInterval(async () => {
    tries++;
    try {
      const res = await fetch('../check_payment.php?id=' + encodeURIComponent(checkoutRequestId), {
        headers: { 'Accept': 'application/json' },
        cache: 'no-store'
      });
      const data = await res.json();

      if (data.paid) {
        clearInterval(interval);
        const params = new URLSearchParams({
          message: 'Payment confirmed' + (data.order_number ? ' for ' + data.order_number : '') + '.',
          tone: 'success'
        });
        window.location.href = successUrl + (successUrl.includes('?') ? '&' : '?') + params.toString();
      } else if (data.status === 'failed' || data.status === 'cancelled') {
        clearInterval(interval);
        if (status) status.textContent = 'Payment was not completed. You can retry or contact support.';
        const btn = document.getElementById('pay-btn') || document.querySelector('[data-mpesa-trigger]');
        if (btn) {
          btn.disabled = false;
          btn.textContent = 'Retry M-Pesa payment';
        }
      } else if (status) {
        status.textContent = 'Waiting for M-Pesa confirmation...';
      }
    } catch (err) {
      if (status) status.textContent = 'Still checking payment confirmation...';
    }

    if (tries > 10) {
      clearInterval(interval);
      if (status) status.textContent = 'Payment is still pending. Use Track to confirm it shortly.';
    }
  }, 5000);
}

document.addEventListener('DOMContentLoaded', () => {
  const checkout = document.querySelector('[data-mpesa-checkout]');
  if (!checkout || checkout.dataset.autoStart !== '1') return;

  initiateMpesaPayment(
    checkout.dataset.phone || '',
    checkout.dataset.amount || '',
    checkout.dataset.reference || 'MobimendOrder',
    checkout.dataset.paymentId || ''
  );
});
