# Changelog

## [1.0.6] - 2026-11-06

### Changed
- Orders: the badge for a buyer with one or two delivered orders and no recent refusal reads "Limited data" (Hungarian: "Kevés adat") instead of "Limited history" ("Kevés előzmény").

## [1.0.5] - 2026-11-05

### Changed
- Orders: the badge for a buyer with one or two delivered orders and no recent refusal reads "Limited history" (Hungarian: "Kevés előzmény") instead of "Unknown".

### Fixed
- Admin quick search: moving the mouse over a result no longer breaks the dialog (a console error, and Enter or the arrow keys stopped working); the result under the pointer becomes the selected one.

## [1.0.4] - 2026-11-05

### Changed
- Orders list: without an API key the COD check column is hidden, and the orders list shows a warning with a link to the API key setting instead.
- Orders: a stored result without a score (for example one imported from an earlier tool) shows its level without a number.

### Fixed
- Backfill: with a persistent object cache (e.g. Redis) a started backfill could look stopped ("There is no running backfill") until the cache was flushed; the backfill state is now always read from the database.

## [1.0.3] - 2026-11-04

### Added
- The API key can be set with the `TRUSTORDER_API_KEY` constant (wp-config.php or Bedrock .env). It overrides the saved key; the admin screen then shows only its beginning, and the admin, REST and WP-CLI cannot change it.

### Changed
- Orders list: the column is now titled "COD check" (Hungarian: "Utánvét ellenőrzés") and is the last column, after Actions.

## [1.0.2] - 2026-11-04

### Added
- Settings: "Share country and postcode" (on by default, can be turned off) sends only the country and postcode of each new order, without any customer data, for anonymous order statistics. Also available as `wp trustorder settings set share-geo on|off`, and described in the suggested privacy policy text.

### Fixed
- A full refund now withdraws the delivered report; a refused report is never withdrawn by a refund, and partial refunds change nothing. This applies to orders refunded from 1.0.2 on; earlier refunds are not withdrawn.

## [1.0.1] - 2026-11-04

### Added
- Settings: a numbered list under the strictness control shows what happens to Blocked, Risky and other buyers, and a level guide explains the levels with their thresholds.
- Admin screen: quick search in the side navigation, a documentation link and the upTools logo in the footer.

### Changed
- Orders list: the column is titled "Parcel tracking", and a buyer with no history shows a green "New customer" badge instead of "Unknown".
- Level badges are coloured per level, with the icon and the label on one line.
- Admin screen: buttons and controls use the upTools ink instead of the admin blue, a new badge-check mark, and the active side navigation item has no yellow stripe.
- Strictness help texts say exactly which buyers lose cash on delivery.
- Exceptions: the rows per page control moved to the section header.
- Hungarian: the order status reads "Rendelést nem vette át".

### Fixed
- A cached check result (up to 10 minutes) is dropped as soon as an outcome signal is sent or withdrawn, so a buyer who just refused a parcel is checked again.

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
