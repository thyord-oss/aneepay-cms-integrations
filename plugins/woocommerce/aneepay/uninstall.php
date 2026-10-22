<?php
/**
 * Uninstall handler for AneePay Crypto Gateway for WooCommerce.
 *
 * Runs only when the plugin is deleted (not merely deactivated). Preserves the
 * gateway settings and order meta by default so a reinstall or an audit can
 * still read them. Data is only removed when the merchant opts in to deletion
 * via the `aneepay_delete_data_on_uninstall` filter.
 *
 * @package AneePay_Crypto_Gateway
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Opt-in gate: nothing is deleted unless the store explicitly flips this.
$delete_data = (bool) apply_filters( 'aneepay_delete_data_on_uninstall', false );

if ( ! $delete_data ) {
	return;
}

delete_option( 'woocommerce_aneepay_crypto_settings' );
delete_option( 'aneepay_request_log' );

// Fiat-rate transients (`aneepay_fiat_rate_<currency>`) are dynamic and
// short-lived (hourly TTL); they are left to expire on their own rather than
// guessing the set of currency codes.

// Order meta is left in place on purpose: it is historical record tied to
// orders that still exist and are owned by WooCommerce.
