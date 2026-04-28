/* global leastudiosPayments, Stripe */
(function () {
	'use strict';

	var stripe;
	var initialized = {};

	function init() {
		if (!window.leastudiosPayments || !window.leastudiosPayments.publishableKey) {
			return;
		}

		stripe = Stripe(leastudiosPayments.publishableKey);

		var containers = document.querySelectorAll('.leastudios-payments-checkout');
		containers.forEach(mountCheckout);
	}

	function mountCheckout(container) {
		var priceId = container.getAttribute('data-price-id');

		if (!priceId || initialized[priceId]) {
			return;
		}

		initialized[priceId] = true;

		var mountEl = container.querySelector('.leastudios-payments-checkout-mount');
		var statusEl = container.querySelector('.leastudios-payments-checkout-status');

		if (!mountEl) {
			return;
		}

		// Show loading state.
		mountEl.innerHTML = '<div class="leastudios-payments-loading">' + escapeHtml(leastudiosPayments.loadingText) + '</div>';

		// Create the Checkout Session via REST API.
		fetch(leastudiosPayments.checkoutUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': leastudiosPayments.restNonce
			},
			body: JSON.stringify({
				price_id: parseInt(priceId, 10),
				return_url: leastudiosPayments.returnUrl
			})
		})
			.then(function (res) { return res.json(); })
			.then(function (data) {
				if (!data.success || !data.client_secret) {
					showError(mountEl, data.message || leastudiosPayments.errorText);
					return;
				}

				// Mount the embedded checkout.
				mountEl.innerHTML = '';

				stripe.initEmbeddedCheckout({
					clientSecret: data.client_secret
				}).then(function (checkout) {
					checkout.mount(mountEl);
				}).catch(function (err) {
					showError(mountEl, err.message || leastudiosPayments.errorText);
				});
			})
			.catch(function () {
				showError(mountEl, leastudiosPayments.errorText);
			});
	}

	function showError(el, message) {
		el.innerHTML = '<div class="leastudios-payments-error">' + escapeHtml(message) + '</div>';
	}

	function escapeHtml(str) {
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(str || ''));
		return div.innerHTML;
	}

	if (document.readyState !== 'loading') {
		init();
	} else {
		document.addEventListener('DOMContentLoaded', init);
	}
})();
