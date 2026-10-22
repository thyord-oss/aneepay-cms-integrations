<?php
/**
 * AneePay pending-payment block (order-pay / thank-you page).
 *
 * Rendered while the payment is awaiting on-chain confirmation. JavaScript
 * (assets/js/aneepay-checkout.js) polls the status and reloads on success /
 * failure. The "I already paid" button triggers an immediate re-check.
 *
 * Available variable: $order (WC_Order).
 *
 * @package AneePay_Crypto_Gateway
 */

defined( 'ABSPATH' ) || exit;

if ( ! isset( $order ) ) {
	return;
}

$order_id     = $order->get_id();
$status       = (string) $order->get_meta( '_aneepay_payment_status', true );
$token_amount = (string) $order->get_meta( '_aneepay_token_amount', true );
$token        = strtoupper( (string) $order->get_meta( '_aneepay_token', true ) );
?>
<div class="aneepay-payment" data-order-id="<?php echo esc_attr( $order_id ); ?>" data-order-key="<?php echo esc_attr( $order->get_order_key() ); ?>" data-payment-status="<?php echo esc_attr( $status ); ?>">
	<div class="aneepay-payment-card">
		<div class="aneepay-payment-spinner"></div>

		<p class="aneepay-payment-title"><?php esc_html_e( 'Waiting for the payment to be confirmed on the blockchain.', 'aneepay-crypto-gateway' ); ?></p>
		<p class="aneepay-payment-sub"><?php esc_html_e( 'This page updates automatically. Once you have completed the payment, press the button below to refresh the status instantly.', 'aneepay-crypto-gateway' ); ?></p>

		<?php if ( '' !== $token_amount && '' !== $token ) : ?>
			<p class="aneepay-payment-amount">
				<strong><?php esc_html_e( 'Amount', 'aneepay-crypto-gateway' ); ?>:</strong>
				<?php echo esc_html( $token_amount . ' ' . $token ); ?>
			</p>
		<?php endif; ?>

		<div class="aneepay-payment-info">
			<p><?php esc_html_e( 'Payments are non-custodial: the funds go directly from your wallet to the merchant, minus a fixed 0.5% fee. No account or KYC is required.', 'aneepay-crypto-gateway' ); ?></p>
		</div>

		<div class="aneepay-payment-actions">
			<button type="button" class="button aneepay-i-paid"><?php esc_html_e( 'I already paid', 'aneepay-crypto-gateway' ); ?></button>
		</div>
	</div>
</div>
