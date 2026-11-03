# Changelog

## [1.0.0] - 2026-11-03

### Added
- Checkout check on the block and the classic checkout from hashed e-mail, phone and address (identity hash protocol v1): the payment methods you choose (default: cash on delivery) are hidden for blocked buyers, and the server refuses an order placed with a hidden method.
- Fail-open everywhere: an API error, a timeout or a missing key never stops an order, and the last payment method is never hidden.
- Check limits per checkout session, per visitor network and per hour, results cached for 10 minutes, and a fresh check after you change an exception.
- Check result on the order (list column, order screen box, private note for flagged orders), with HPOS and legacy order storage; paying for an existing order uses its stored result.
- "Not picked up" order status and delivered/refused outcome signals in the background, with retries, and a resumable backfill of the last 1095 days.
- Admin screen under WooCommerce, TrustOrder (Overview, Settings, Exceptions, Backfill), suggested privacy policy text and the Hungarian translation.
- `wp trustorder` WP-CLI commands for the status, settings, a single check, the backfill, order signals and exceptions; the API key only from piped STDIN.
- Requirements: WordPress 6.6, WooCommerce 8.2, PHP 8.1; the PHP intl extension is recommended.
