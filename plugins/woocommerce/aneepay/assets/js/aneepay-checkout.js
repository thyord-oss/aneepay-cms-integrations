/**
 * AneePay Crypto Gateway - checkout status polling + "I already paid".
 */
(function () {
	'use strict';

	var MAX_ATTEMPTS = 40;
	var INTERVAL_MS = 5000;

	var block = document.querySelector('.aneepay-payment[data-order-id]');

	if (!block) {
		return;
	}

	var orderId = block.getAttribute('data-order-id');
	var orderKey = block.getAttribute('data-order-key') || '';
	var attempts = 0;
	var timer = null;
	var params = window.aneepayParams || {};

	function onTerminal(status) {
		if (status === 'success' || status === 'failed' || status === 'cancelled') {
			clearInterval(timer);
			window.location.reload();
			return true;
		}
		return false;
	}

	function checkStatus() {
		var body = new URLSearchParams();
		body.append('action', 'aneepay_check_status');
		body.append('nonce', params.nonce);
		body.append('orderId', orderId);
		body.append('orderKey', orderKey);

		return fetch(params.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (data) {
				if (data && data.success && data.data && data.data.status) {
					var status = data.data.status;
					if (onTerminal(status)) {
						return { terminal: true };
					}
					return { status: status };
				}
				return { status: '' };
			})
			.catch(function () {
				return { status: '' };
			});
	}

	timer = setInterval(function () {
		attempts += 1;
		if (attempts > MAX_ATTEMPTS) {
			clearInterval(timer);
			return;
		}
		checkStatus();
	}, INTERVAL_MS);

	var paidBtn = block.querySelector('.aneepay-i-paid');

	if (paidBtn) {
		paidBtn.addEventListener('click', function () {
			paidBtn.disabled = true;
			var original = paidBtn.textContent;
			paidBtn.textContent = params.checkingLabel || 'Checking...';

			checkStatus().then(function (result) {
				if (result && result.terminal) {
					return;
				}
				paidBtn.disabled = false;
				paidBtn.textContent = original;
			});
		});
	}
})();
