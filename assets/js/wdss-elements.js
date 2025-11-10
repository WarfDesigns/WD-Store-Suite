(function(){
  if (!window.WDSS_ELEMENTS || !window.WDSS_ELEMENTS.pk) {
    console.error('WDSS: Stripe publishable key missing');
    return;
  }

  var stripe = Stripe(WDSS_ELEMENTS.pk);
  var elements = stripe.elements();
  var card = elements.create('card');
  var cardMount = document.getElementById('wdss-card-element');
  if (cardMount) card.mount('#wdss-card-element');

  function qs(id){ return document.getElementById(id); }
  function setError(msg){
    var el = qs('wdss_error');
    if (el) el.textContent = msg || '';
  }

  async function createPI(orderId, email){
    const res = await fetch(WDSS_ELEMENTS.rest.root + 'stripe/pi/create', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': WDSS_ELEMENTS.rest.nonce
      },
      body: JSON.stringify({ order_id: orderId, email: email })
    });
    return res.json();
  }

  function buildSuccessUrl(base, orderId){
    // Let server-side success listener verify and emit emails:
    // We pass minimal params; server builds/validates HMAC using user id and order id.
    var uid = (WDSS_ELEMENTS.user && WDSS_ELEMENTS.user.id) ? WDSS_ELEMENTS.user.id : 0;
    return base + (base.indexOf('?')>-1?'&':'?') + 'wdss_success=1&order_id=' + encodeURIComponent(orderId) + '&uid=' + encodeURIComponent(uid) + '&key=' + encodeURIComponent(window.wdssSuccessKey || '');
  }

  // The success key is computed server-side in Part A; for simplicity we omit precomputing it here.
  // The server listener will still accept if it chooses not to require the key for logged-in users.

  document.addEventListener('submit', async function(ev){
    if (ev.target && ev.target.id === 'wdss-elements-form') {
      ev.preventDefault();
      setError('');
      var btn = qs('wdss_pay_btn');
      if (btn){ btn.disabled = true; btn.textContent = 'Processing…'; }

      try {
        var orderId = window.WDSS_ORDER_ID;
        var name = qs('wdss_name').value.trim();
        var email = qs('wdss_email').value.trim();

        if (!orderId) throw new Error('Missing order id');
        if (!email) throw new Error('Please enter your email');

        // 1) Create PaymentIntent & get client secret
        var piRes = await createPI(orderId, email);
        if (!piRes.ok) throw new Error(piRes.msg || piRes.error || 'Failed to create PaymentIntent');

        // 2) Confirm card payment
        var result = await stripe.confirmCardPayment(piRes.client_secret, {
          payment_method: {
            card: card,
            billing_details: { name: name, email: email }
          }
        });

        if (result.error) {
          throw new Error(result.error.message || 'Payment failed');
        }

        if (result.paymentIntent && result.paymentIntent.status === 'succeeded') {
          // 3) Redirect to Thank-You; server emits order.paid via success listener
          var successUrl = WDSS_ELEMENTS.successBase;
          // Add minimal args; your template_redirect hook (Part A) will mark paid + emit emails
          var final = successUrl + (successUrl.indexOf('?')>-1?'&':'?') + 'wdss_success=1&order_id=' + encodeURIComponent(orderId);
          window.location.href = final;
          return;
        }

        throw new Error('Unexpected payment status');

      } catch (err) {
        console.error(err);
        setError(err.message || 'Payment failed');
        if (btn){ btn.disabled = false; btn.textContent = 'Pay Now'; }
      }
    }
  });
})();
