=== TrustOrder for WooCommerce ===
Contributors: uptools.io
Tags: woocommerce, cash on delivery, cod, fraud, payment gateways
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
WC requires at least: 8.2
WC tested up to: 11.2

Cash-on-delivery buyer reputation for WooCommerce from hashed identities: risky buyers don't get cash on delivery.

== Description ==

TrustOrder for WooCommerce by uptools.io checks whether a buyer has a history of refusing cash-on-delivery parcels. The buyer's e-mail, phone and address are hashed in the shop; raw personal data never leaves the shop. The one exception, on by default and easy to turn off, is the country and postcode of new orders (see "Share country and postcode").

If the TrustOrder service is slow or unavailable, nothing is hidden and every order goes through (fail-open).

= How the checkout check works =

* Works on both the block checkout and the classic (shortcode) checkout, with HPOS and with the legacy order storage.
* While the buyer fills in the checkout, the plugin hashes the e-mail, phone and address and checks them (waiting at most 1.5 seconds). For a blocked buyer it hides the payment methods you choose (default: cash on delivery); the other methods stay available, and the last remaining method is never hidden.
* The server refuses an order placed with a hidden method, even when the request skips the checkout form. Paying for an existing order uses the result stored on that order.
* Flagged buyers can still pay with every method; the order gets a private note with the level, score and reasons. Every checked order shows the result in a column of the order list and in a box on the order screen.
* Any error (timeout, network, unexpected answer, no API key) means nothing is hidden and the order goes through. The last error is shown on the Overview screen.

= Check limits =

To protect your API quota, the plugin makes at most 5 checks per checkout session while the buyer types, plus at most 3 checks per session when an order is placed. There are also network caps: 20 browsing checks and 10 place-order checks per visitor network per 30 minutes. A shop-wide cap of 300 browsing checks per hour applies too. The filters `trustorder_ip_check_limit`, `trustorder_submit_ip_check_limit` and `trustorder_hourly_check_limit` change these limits.

Without a persistent object cache, the counters are approximate when many requests arrive at the same moment.

When the place-order limit is reached for a buyer who has not been checked yet, the plugin hides the risky payment methods. It still never hides the last remaining payment method.

= Visitor IP behind a proxy =

The visitor network comes from `REMOTE_ADDR`. Cloudflare's `CF-Connecting-IP` header is used only when the request really comes from a Cloudflare edge address; the published ranges are built in and the `trustorder_cloudflare_ranges` filter changes them. `X-Forwarded-For` is never trusted. If your shop is behind another proxy or load balancer, return the real visitor address from the `trustorder_client_ip` filter. IPv4-mapped IPv6 addresses (`::ffff:a.b.c.d`) count as IPv4, and IPv6 addresses are grouped per /64. When the visitor address is private, carrier-grade NAT (100.64.0.0/10), loopback, link-local or IPv6 ULA, which usually means a proxy sits in front of the shop, the network caps are skipped until you set `trustorder_client_ip`. The per-session caps still apply.

Without a persistent object cache, the counters are approximate under concurrency. With one, every count is atomic, and a denied check takes no unit; if the object cache errors, the counters fall back to transients for that request, so the caps still apply. A place-order check that fails because the API is unreachable or returns a server error does not use up the limit, at most 3 times per session and 6 times per visitor network in 30 minutes (filter `trustorder_network_refund_limit`). The admin shows a warning when the hourly cap or the place-order network cap was reached in the last hour.

= Outcome signals =

The plugin adds the order status "Not picked up" (`wc-to-refused`), with a bulk action on both order lists. Changing an order to this status sends no e-mail to the buyer.

Every completed order, whatever the payment method, is reported to TrustOrder as delivered. An order in "Not picked up" is reported as refused. If you move a completed or "Not picked up" order back to processing, on hold or pending payment, the report is withdrawn. A fully refunded order withdraws its delivered report; a refused report is never withdrawn by a refund, and partial refunds change nothing; this applies to orders refunded from 1.0.2 on, earlier refunds are not withdrawn. Cancelled and failed orders change nothing, and deleting an order keeps its report. Only hashes are sent, in the background (Action Scheduler, group `trustorder`), with retries after 1, 5, 30 and 120 minutes when the API is busy or unreachable. Test and live keys keep separate reports. Switching keys does not withdraw reports already sent with the other key (that needs the other key); after a switch, moving an order back to processing, on hold or pending payment withdraws its report under the new key.

The backfill sends the completed and "Not picked up" orders of the last 1095 days in batches, in the background, and can be resumed.

Deactivating the plugin cancels its pending background jobs. After reactivating, start the backfill to catch up status changes made while inactive.

= Share country and postcode =

On by default; turn it off under Settings, "Share country and postcode" (or `wp trustorder settings set share-geo off`). While it is on, each order is sent once, the first time it enters processing, on hold or completed from another status: only its country and postcode, shipping address first and billing as the fallback, for anonymous order statistics. Nothing else is sent: no name, e-mail, phone, street, order number or amount. The service stores the country, the postcode, the shop and the hour, with no link to the order, and deletes it after 1095 days. Orders whose first paid status came while the setting was off, and orders from before the update, are never sent. Network errors, 429 and 5xx answers are retried; 400, 401 and 403 are not.

= Admin screen =

WooCommerce → TrustOrder (shop managers, `manage_woocommerce`) has four sections:

* Overview: connection test (shop name, test mode badge for `to_test_` keys, account status including "awaiting approval", daily quota and today's checks), the last error in plain words, and the state of PHP intl, order storage, the checkout type and the check limits.
* Settings: the API key (write-only: after saving only its first 12 characters are shown), checks on/off, strictness, the payment methods to hide and the fallback ones, the pickup shipping methods that send no address, and the "Share country and postcode" switch.
* Exceptions: always allow or always deny an e-mail, phone number or address in your shop. The value is hashed on your server and only the hash is sent; the list shows the type, rule, note, date and a short id, never the value. To remove an exception, type the value again.
* Backfill: start or resume the backfill for the environment of the saved key, with progress.

= WP-CLI =

Every admin function is also available as `wp trustorder` commands, using the same services as the admin screen. Exit status 0 means success; 1 means an API or validation error, and the message is then only the error code (or a fixed text), never your buyers' data or the API key.

* `wp trustorder status [--format=table|json]` - the key (first 12 characters only), the connection test (shop, account status, daily quota, today's checks), the last error, the intl / order storage / checkout type checks and the backfill progress of both environments. Exit 1 if the service cannot be reached.
* `wp trustorder settings get [<key>] [--format=table|json]` - show the settings. Keys: `api-key` (prefix only), `strictness`, `hidden-gateways`, `fallback-gateways`, `pickup-methods`, `enabled`, `share-geo`.
* `wp trustorder settings set <key> <value>` - change one setting (lists are comma-separated; `enabled` and `share-geo` take true/false, on/off, yes/no or 1/0), validated exactly like the admin screen. The API key is read only from piped STDIN, so it never appears in the command line or the shell history: `printf '%s' "$KEY" | wp trustorder settings set api-key -` (any other value after `api-key`, `--api-key=...` and WP-CLI's `--prompt` are refused; a terminal or no input within 5 seconds is an error). An empty STDIN removes the key.
* `wp trustorder check --email=<email> [--phone=<phone>] [--country=<ISO2>] [--postcode=<postcode>] [--address=<address>] [--strictness=lenient|normal|strict] [--format=table|json]` - ask the service about one buyer (verdict, level, score, reasons, check id). Admin tool: it skips the result cache and does not use the checkout limits. The values are hashed locally, only hashes are sent, and nothing is logged.
* `wp trustorder backfill start [--env=live|test]` - start or resume the backfill for the environment of the saved key (another environment is refused). `backfill status [--format=table|json]` shows both environments. `backfill run [--limit=<orders>]` runs it in your terminal with a progress bar instead of in the background; a pending background step is taken over, and `--limit` pauses after the 200-order page in which that many orders were processed (`backfill start` resumes). When the service is unavailable the retry goes back to the background.
* `wp trustorder signal send <order-id>... [--format=table|json]` - send the current outcome of the orders now (nothing is sent if the service already holds it); exit 1 if an order is missing or its report failed or waits for a retry. `wp trustorder signal status <order-id> [--format=table|json]` shows the stored state without a request, including the outcome wanted for the order's current status (`refund` for a refunded order).
* `wp trustorder exceptions list [--limit=<1-100>] [--cursor=<cursor>] [--format=table|json]`, `exceptions add --kind=email|phone|address --action=allow|deny [--value=<value>] [--country=<ISO2>] [--postcode=<postcode>] [--address=<address>] [--address-2=<address-2>] [--note=<note>]` and `exceptions remove --kind=... (same value flags)` - the same always-allow / always-deny exceptions as the admin screen. Use `--value` for an e-mail or phone number and `--country`, `--postcode`, `--address` for an address. The value is hashed locally; the list never shows values.

Note: `check --email/--phone` and `exceptions add|remove --value` take the buyer's value as a command-line argument, which a logging shell keeps in its history. Run them in a shell that does not log, or clear the history afterwards.

= Privacy =

The plugin adds a suggested section to Settings → Privacy → Policy guide: which buyer data is hashed and sent, the purpose, the legal basis (legitimate interest), that the shop is the contact point, and the 3-year retention, plus a paragraph about sharing the country and postcode when that setting is on.

= Translations =

English and Hungarian (PHP and the admin app) are bundled in `languages/`.

== Installation ==

1. Requirements: WordPress 6.6 or newer, WooCommerce 8.2 or newer, PHP 8.1 or newer. The PHP `intl` extension is recommended: without it (or without mbstring), values with non-ASCII characters, such as accented addresses or international e-mail domains, are not hashed, and an order whose e-mail contains such characters is not checked (the admin shows a notice).
2. Upload the `trustorder-for-woo` folder to `wp-content/plugins/` (or install the ZIP under Plugins → Add New → Upload) and activate it.
3. Under WooCommerce → TrustOrder → Settings, paste the API key you received from uptools.io (`to_test_` keys use the sandbox, `to_live_` keys the live service) and run the connection test on the Overview screen. Alternatively, set the key in `wp-config.php` with `define( 'TRUSTORDER_API_KEY', 'to_live_...' );` (on Bedrock: `TRUSTORDER_API_KEY` in `.env` and `Config::define( 'TRUSTORDER_API_KEY', env( 'TRUSTORDER_API_KEY' ) );` in `config/application.php`). The constant overrides the saved key, and the key then cannot be changed from the admin screen, REST or WP-CLI.
4. If the shop runs behind a proxy or load balancer other than Cloudflare, return the real visitor address from the `trustorder_client_ip` filter (see "Visitor IP behind a proxy").

== Frequently Asked Questions ==

= Does any personal data leave my shop? =

No raw data. E-mail addresses, phone numbers and addresses are normalised and hashed in the shop; only the hashes, the order number and the outcome are sent. The only exception is the country and postcode of new orders, on by default and easy to turn off (see "Share country and postcode").

= What happens when the TrustOrder service is down? =

Nothing is hidden and every order goes through. The checkout waits at most 1.5 seconds for the answer.

== Changelog ==

= 1.0.3 =
* New: The API key can be set with the `TRUSTORDER_API_KEY` constant (wp-config.php or Bedrock .env). It overrides the saved key; the admin screen then shows only its beginning, and the admin, REST and WP-CLI cannot change it.
* Change: Orders list: the column is now titled "COD check" (Hungarian: "Utánvét ellenőrzés") and is the last column, after Actions.

= 1.0.2 =
* New: Settings: "Share country and postcode" (on by default, can be turned off) sends only the country and postcode of each new order, without any customer data, for anonymous order statistics. Also available as `wp trustorder settings set share-geo on|off`, and described in the suggested privacy policy text.
* Fix: A full refund now withdraws the delivered report; a refused report is never withdrawn by a refund, and partial refunds change nothing. This applies to orders refunded from 1.0.2 on; earlier refunds are not withdrawn.

= 1.0.1 =
* New: Settings: a numbered list under the strictness control shows what happens to Blocked, Risky and other buyers, and a level guide explains the levels with their thresholds.
* New: Admin screen: quick search in the side navigation, a documentation link and the upTools logo in the footer.
* Change: Orders list: the column is titled "Parcel tracking", and a buyer with no history shows a green "New customer" badge instead of "Unknown".
* Change: Level badges are coloured per level, with the icon and the label on one line.
* Change: Admin screen: buttons and controls use the upTools ink instead of the admin blue, a new badge-check mark, and the active side navigation item has no yellow stripe.
* Change: Strictness help texts say exactly which buyers lose cash on delivery.
* Change: Exceptions: the rows per page control moved to the section header.
* Change: Hungarian: the order status reads "Rendelést nem vette át".
* Fix: A cached check result (up to 10 minutes) is dropped as soon as an outcome signal is sent or withdrawn, so a buyer who just refused a parcel is checked again.

= 1.0.0 =
* New: Checkout check on the block and the classic checkout from hashed e-mail, phone and address (identity hash protocol v1): the payment methods you choose (default: cash on delivery) are hidden for blocked buyers, and the server refuses an order placed with a hidden method.
* New: Fail-open everywhere: an API error, a timeout or a missing key never stops an order, and the last payment method is never hidden.
* New: Check limits per checkout session, per visitor network and per hour, results cached for 10 minutes, and a fresh check after you change an exception.
* New: Check result on the order (list column, order screen box, private note for flagged orders), with HPOS and legacy order storage; paying for an existing order uses its stored result.
* New: "Not picked up" order status and delivered/refused outcome signals in the background, with retries, and a resumable backfill of the last 1095 days.
* New: Admin screen under WooCommerce, TrustOrder (Overview, Settings, Exceptions, Backfill), suggested privacy policy text and the Hungarian translation.
* New: `wp trustorder` WP-CLI commands for the status, settings, a single check, the backfill, order signals and exceptions; the API key only from piped STDIN.
* New: Requirements: WordPress 6.6, WooCommerce 8.2, PHP 8.1; the PHP intl extension is recommended.
