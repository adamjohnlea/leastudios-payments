/* global leastudiosPaymentsAdmin */
(function () {
	'use strict';

	function init() {
		var refundForm = document.getElementById('leastudios-payments-refund-form');

		if (refundForm) {
			bindRefundForm(refundForm);
		}
	}

	function bindRefundForm(form) {
		form.addEventListener('submit', function (e) {
			e.preventDefault();

			if (!confirm(leastudiosPaymentsAdmin.confirmText)) {
				return;
			}

			var btn = document.getElementById('leastudios-payments-refund-btn');
			var status = document.getElementById('leastudios-payments-refund-status');
			var orderId = form.querySelector('[name="order_id"]').value;
			var amount = form.querySelector('[name="refund_amount"]').value;

			btn.disabled = true;
			status.textContent = leastudiosPaymentsAdmin.processingText;
			status.style.color = '#50575e';

			fetch(leastudiosPaymentsAdmin.refundUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': leastudiosPaymentsAdmin.refundNonce
				},
				body: JSON.stringify({
					order_id: parseInt(orderId, 10),
					amount: parseInt(amount, 10)
				})
			})
				.then(function (res) { return res.json(); })
				.then(function (data) {
					if (data.success) {
						status.textContent = leastudiosPaymentsAdmin.successText;
						status.style.color = '#00a32a';
						setTimeout(function () { window.location.reload(); }, 1500);
					} else {
						status.textContent = leastudiosPaymentsAdmin.errorText + (data.message || 'Unknown error');
						status.style.color = '#d63638';
						btn.disabled = false;
					}
				})
				.catch(function () {
					status.textContent = leastudiosPaymentsAdmin.errorText + 'Network error';
					status.style.color = '#d63638';
					btn.disabled = false;
				});
		});
	}

	if (document.readyState !== 'loading') {
		init();
	} else {
		document.addEventListener('DOMContentLoaded', init);
	}
})();
