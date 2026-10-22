# AneePay Crypto Gateway — OpenCart 3.x

Status: **working implementation** (mirrors the WooCommerce plugin).
Reference implementation — `../woocommerce/aneepay`. Plan — `../../docs/ROADMAP-OPENCART.md`.

## Files

```
aneepay/
  admin/controller/extension/payment/aneepay.php    # settings + AJAX + install
  admin/model/extension/payment/aneepay.php          # tables + request log
  admin/language/en-gb/extension/payment/aneepay.php
  admin/view/template/extension/payment/aneepay.twig
  catalog/controller/extension/payment/aneepay.php   # index/confirm/callback/success/fail/cron/check
  catalog/model/extension/payment/aneepay.php          # aneepay_order mapping
  catalog/language/en-gb/extension/payment/aneepay.php
  catalog/view/theme/default/template/extension/payment/aneepay.twig
  catalog/view/theme/default/template/extension/payment/aneepay_result.twig
  system/library/aneepay/aneepay.php                   # API client + webhook signature + log
  system/library/aneepay/usd-converter.php             # fiat -> USD -> token
  system/library/aneepay/webhook.php                   # resolve order + apply status
install.xml                                            # ocmod manifest (add-on files)
```

## Endpoints

- `status_url` (webhook) — `index.php?route=extension/payment/aneepay/callback`
- `success_url` — `index.php?route=extension/payment/aneepay/success`
- `fail_url` — `index.php?route=extension/payment/aneepay/fail`
- polling — `index.php?route=extension/payment/aneepay/cron&secret=<cron_secret>` (schedule it in cron; the secret is shown on the settings page)

## Notes

- Setup: Extensions → Payments → AneePay → Edit. Enter the Account ID + Webhook
  Secret (UUID + HMAC-SHA256 signature verification, fail-closed). Optionally
  toggle Test mode (Amoy/USDC).
- Amount semantics: token amount = store_total converted via Frankfurter/ECB
  (auto) + store rate fallback + manual rate; rounded **up** (ceil) in the
  merchant's favour.
- Order metadata is stored in the `aneepay_order` table; the webhook resolves the
  order by `payment_id` / `operation_id` (128-bit int, kept exact) or `Order #N`.
- Cron is not built into OpenCart: add the cron URL above to your server crontab.
- Uninstall keeps the `aneepay_order` / `aneepay_log` tables by default; set
  "On uninstall" to "Delete data" in the settings to drop them.
- The admin page upgrades an existing `aneepay_order` table in place (adds
  `stock_restored`), so no manual SQL is needed after updating.
