{*
 * AneePay result page (success / fail).
 *}
<div class="aneepay-result">
	{if $is_success}
		<div class="aneepay-result-icon ok">&#10003;</div>
		<h2>{l s='Payment submitted' mod='ps_aneepay'}</h2>
		<p>{l s='Waiting for the transaction to be confirmed on-chain.' mod='ps_aneepay'}</p>

		{if $order_id}
			<p>{l s='Order' mod='ps_aneepay'}: <strong>#{$order_id}</strong></p>
		{/if}

		{if $payment_id}
			<p>{l s='AneePay payment id' mod='ps_aneepay'}: <strong>{$payment_id|escape:'html':'UTF-8'}</strong></p>
		{/if}

		{if $token}
			<p>{l s='You pay' mod='ps_aneepay'}: <strong>{$pay_now|escape:'html':'UTF-8'}</strong></p>
		{/if}

		{if $order_id}
			<a href="{$check_url|escape:'html':'UTF-8'}&amp;id_order={$order_id}" class="btn btn-primary" id="aneepay-i-paid">
				{l s='I already paid' mod='ps_aneepay'}
			</a>
		{/if}
	{else}
		<div class="aneepay-result-icon err">&#10005;</div>
		<h2>{l s='Payment not completed' mod='ps_aneepay'}</h2>

		{if $checkout_url}
			<p><a href="{$checkout_url|escape:'html':'UTF-8'}" class="btn btn-primary">{l s='Try again' mod='ps_aneepay'}</a></p>
		{/if}
	{/if}

	<p><a href="{$shop_url|escape:'html':'UTF-8'}">{l s='Back to shop' mod='ps_aneepay'}</a></p>
</div>

{if $is_success && $order_id}
<script>
(function () {
	var url = '{$check_url|escape:'javascript'}&id_order={$order_id}';
	var btn = document.getElementById('aneepay-i-paid');
	function poll() {
		var b = new URLSearchParams();
		b.append('id_order', {$order_id});
		fetch('{$check_url|escape:'javascript'}', {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: b.toString()
		}).then(function (r) { return r.json(); }).then(function (d) {
			if (d && (d.status === 'success' || d.status === 'failed' || d.status === 'cancelled')) {
				window.location.reload();
			}
		}).catch(function () {});
	}
	if (btn) {
		btn.addEventListener('click', function (e) {
			e.preventDefault();
			btn.textContent = '{l s='Checking…' mod='ps_aneepay'}';
			poll();
		});
	}
	setTimeout(poll, 5000);
})();
</script>
{/if}
