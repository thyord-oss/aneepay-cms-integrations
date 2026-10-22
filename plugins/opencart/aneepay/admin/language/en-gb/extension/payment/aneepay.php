<?php
// Heading
$_['heading_title']      = 'AneePay Crypto Gateway';

// Text
$_['text_extension']     = 'Extensions';
$_['text_payment']       = 'Payment';
$_['text_success']       = 'Success: You have modified AneePay!';
$_['text_edit']          = 'Edit AneePay Crypto Gateway';
$_['text_enabled']       = 'Enabled';
$_['text_disabled']      = 'Disabled';
$_['text_sandbox']       = 'SANDBOX';
$_['text_live']          = 'LIVE';
$_['text_general']       = 'General';
$_['text_connection']    = 'AneePay Connection';
$_['text_coin']          = 'Coin & Blockchain';
$_['text_statuses']      = 'Order Statuses';
$_['text_advanced']      = 'Advanced';
$_['text_always']        = 'Always';

// Entry
$_['entry_status']           = 'Status';
$_['entry_title']            = 'Title';
$_['entry_description']      = 'Description';
$_['entry_account_id']       = 'Account ID';
$_['entry_webhook_secret']   = 'Webhook Secret';
$_['entry_site_domain']      = 'Domain';
$_['entry_test_mode']        = 'Test / Sandbox Mode';
$_['entry_token']            = 'Cryptocurrency';
$_['entry_network']          = 'Blockchain';
$_['entry_rate_source']      = 'Exchange Rate Source';
$_['entry_exchange_rate']    = 'Manual Exchange Rate';
$_['entry_pending_status']   = 'Awaiting payment status';
$_['entry_paid_status']      = 'Payment confirmed status';
$_['entry_failed_status']    = 'Payment failed status';
$_['entry_cancelled_status'] = 'Cancelled status';
$_['entry_debug']            = 'Debug Log';
$_['entry_cron_interval']    = 'Status Sync Interval';
$_['entry_fee_rule']         = 'Fee rule';
$_['entry_keep_data']        = 'On uninstall';

// Selects
$_['text_select']        = '— Select —';
$_['text_auto']          = 'Auto (Frankfurter/ECB) + manual fallback';
$_['text_manual']        = 'Manual rate only';
$_['text_fee_merchant']  = 'Merchant absorbs 0.5%';
$_['text_fee_customer']  = 'Add 0.5% to customer';
$_['text_keep_data']     = 'Keep data';
$_['text_delete_data']   = 'Delete data';

// Help / description
$_['help_account_id']       = 'Your AneePay account UUID. Sent in the X-Account-Id header for every API request.';
$_['help_webhook_secret']   = 'Used to verify the X-AneePay-Signature (HMAC-SHA256) on webhook calls. Required to confirm payments.';
$_['help_site_domain']      = 'Your verified merchant domain (Origin header). Leave empty to use your site host.';
$_['help_test_mode']        = 'When enabled, payments are created on the Amoy testnet with USDC and the is_safe check is bypassed.';
$_['help_exchange_rate']    = 'Used when the auto rate is unavailable. How many units of store currency make one token (≈1 USD).';
$_['help_cron_interval']    = 'How often to poll AneePay for pending orders. Set it up in your cron as shown below.';
$_['help_keep_data']        = 'Keep AneePay orders and request logs in the database when the extension is uninstalled. Disable to delete them.';

// Errors
$_['error_permission']       = 'Warning: You do not have permission to modify AneePay!';
$_['error_account_id']       = 'AneePay Account ID is required (valid UUID).';
$_['error_webhook_secret']   = 'AneePay Webhook Secret is required to verify payment confirmations.';
$_['error_exchange_rate']    = 'Manual Exchange Rate must be a positive number.';
$_['error_warning']          = 'Warning: Please check the form carefully for errors!';

// Buttons / misc
$_['button_test']        = 'Test connection';
$_['button_logs']        = 'Request log';
$_['button_clear']       = 'Clear';
$_['text_checking']      = 'Checking…';
$_['text_connected']     = 'Connection OK. Account exists and the domain matches.';
$_['text_failed']        = 'Connection failed';
$_['text_no_logs']       = 'No requests logged yet.';
$_['text_endpoint']      = 'Set these URLs in the AneePay account panel:';
$_['text_status_url']    = 'STATUS_URL (webhook)';
$_['text_success_url']   = 'SUCCESS_URL';
$_['text_fail_url']      = 'FAIL_URL';
$_['text_copy']          = 'Copy';
$_['text_copied']        = 'Copied';

// Quick setup / helpers
$_['text_quick_setup']    = 'Quick setup';
$_['text_quick_setup_1']  = 'Create or open your account in the AneePay dashboard.';
$_['text_quick_setup_2']  = 'Set your wallet address, domain and the three URLs below in the account panel.';
$_['text_quick_setup_3']  = 'Copy the Account ID and Webhook Secret into the fields, then save.';
$_['text_quick_setup_4']  = 'Press "Test connection" to verify.';
$_['text_cron_hint']      = 'Cron URL (schedule it on your server):';
$_['text_endpoints']      = 'Endpoints';
$_['text_example']        = 'Example conversion';
