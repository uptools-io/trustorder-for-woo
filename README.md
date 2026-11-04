# TrustOrder for WooCommerce

Cash-on-delivery protection for WooCommerce by [uptools.io](https://uptools.io).
At checkout it hashes the buyer's e-mail, phone number and address, and if the
buyer has a history of refusing cash-on-delivery parcels, it hides cash on
delivery. Raw personal data never leaves the shop. The one exception, on by default and easy to turn off, is the country and postcode of new orders (see below).

- Requirements: WordPress 6.6+, WooCommerce 8.2+, PHP 8.1+ (the bundled libphonenumber 9.x uses enums). The PHP `intl` extension is recommended: without it (or without mbstring), values with non-ASCII characters (accented addresses, international e-mail domains) are not hashed, and an order whose e-mail contains such characters is not checked (the admin shows a notice).
- Block and classic (shortcode) checkout, HPOS and legacy order storage.
- Behind a proxy or load balancer other than Cloudflare, return the real visitor address from the `trustorder_client_ip` filter (see below).
- Release ZIP: `bin/build-zip.sh` (built from `git archive HEAD`, without development files, into `dist/`).
- Free to use with an API key from uptools.io (`to_test_` keys are for testing, `to_live_` keys for the live shop).

If the TrustOrder service is slow or unavailable (the check waits at most **1.5 seconds**) the plugin
**fails open**: nothing is hidden and the order goes through.

## Check limits and the visitor IP

- While the buyer types, at most 5 checks run per checkout session; placing an order has its own limit of at most 3 per session.
- Per visitor network, at most 20 browsing checks and 10 place-order checks run per 30 minutes. Shop-wide, at most 300 browsing checks run per hour.
- Filters: `trustorder_ip_check_limit`, `trustorder_submit_ip_check_limit`, `trustorder_hourly_check_limit`.
- Without a persistent object cache, the counters are approximate under concurrent requests. With one, every count is atomic and a denied check takes no unit. If the object cache errors, the counters fall back to transients for that request, so the limits still apply.
- A place-order check that fails because the service is unreachable does not use up the limit, at most 3 times per session and 6 times per visitor network in 30 minutes (filter: `trustorder_network_refund_limit`).
- When the place-order limit is reached for a buyer who has not been checked yet, the risky payment methods stay hidden. The last remaining payment method is never hidden.
- The visitor network comes from `REMOTE_ADDR`. Cloudflare's `CF-Connecting-IP` header is used only when the request comes from a Cloudflare edge address; the ranges are built in and the `trustorder_cloudflare_ranges` filter changes them. `X-Forwarded-For` is never trusted.
- Behind another proxy or load balancer, return the real address from the `trustorder_client_ip` filter. IPv4-mapped IPv6 addresses (`::ffff:a.b.c.d`) count as IPv4; IPv6 addresses are grouped per /64.
- When the visitor address is private, CGNAT (100.64.0.0/10), loopback, link-local or IPv6 ULA, the network limits are skipped until you set the `trustorder_client_ip` filter; the per-session limits still apply.
- The admin shows a dismissible warning when the hourly limit or the place-order network limit was reached in the last hour.

## Outcome signals and backfill

- New order status: **"Not picked up"** (`wc-to-refused`), with a bulk action on both order lists. The buyer gets no e-mail about it.
- Every completed order is reported as `delivered`, whatever the payment method. An order in "Not picked up" is reported as `refused`.
- Moving a completed or "Not picked up" order back to processing, on hold or pending payment withdraws its report. A full refund (status refunded) withdraws a sent delivered report; a refusal is never withdrawn by a refund, and partial refunds change nothing; this applies to orders refunded from 1.0.2 on, earlier refunds are not withdrawn. Deletions and cancellations change nothing.
- Signals are sent in the background (Action Scheduler, group `trustorder`), one job per order that reads the order's state when it runs. When the service is busy or unreachable, it retries after 1, 5, 30 and 120 minutes, then marks the signal `failed`. Only hashes are sent.
- Test and live keys keep separate reports: after a key switch the order is sent again to the new environment. Switching keys does not withdraw reports sent with the other key (that would need the other key); after a switch, moving an order back withdraws its report in the new key's environment.
- The backfill sends the completed and "Not picked up" orders of the last 1095 days, 200 per page, in the background. It resumes where it stopped and keeps its progress per environment.
- Deactivating the plugin cancels its pending background jobs (running and finished jobs are left alone). After reactivating, start the backfill so the status changes made while inactive are sent too.

## Share country and postcode

On by default; turn it off in the settings or with `wp trustorder settings set share-geo off`. While it is on, each order is sent once, the first time it enters processing, on hold or completed from another status: only its country and postcode, shipping address first and billing as the fallback, for anonymous order statistics. Nothing else is sent. The service stores the country, the postcode, the shop and the hour, with no link to the order, and deletes it after 1095 days. Orders whose first paid status came while the setting was off, and orders from before the update, are never sent. Network errors, 429 and 5xx answers are retried; 400, 401 and 403 are not.

## API key in the configuration

`define( 'TRUSTORDER_API_KEY', 'to_live_...' );` in `wp-config.php` (Bedrock: `TRUSTORDER_API_KEY=` in `.env` and `Config::define( 'TRUSTORDER_API_KEY', env( 'TRUSTORDER_API_KEY' ) );` in `config/application.php`) overrides the saved key and is never written to the database. While it is defined, the admin screen shows only its first 12 characters, and saving a key from the admin, REST (`trustorder_key_locked`) or `wp trustorder settings set api-key` is refused. An invalid value counts as no key; an empty or null value (for example Bedrock's `env()` of a missing variable) counts as not set, and the saved key stays in use.

## Admin screen

WooCommerce → TrustOrder (`manage_woocommerce`), a React app with a top bar, side navigation and skeleton loading. Other plugins' admin notices are hidden on this screen.

- **Overview:** connection test (shop name, "Test mode" badge for `to_test_` keys, account status including "awaiting approval", daily quota and today's checks), the last error in plain words, and the state of intl, order storage, the checkout type and the check limits.
- **Settings:** API key (write-only: after saving only its first 12 characters are shown), checks on/off, strictness (with a numbered list of what each buyer level gets and a level guide with the thresholds), the payment methods to hide and the fallback ones (from the enabled methods), the pickup shipping methods that send no address, and the "Share country and postcode" switch.
- **Exceptions:** always allow or always deny an e-mail, phone number or address in your shop. The value is hashed on your server and only the hash is sent; the list shows the type, rule, note, date and a short id, never the value. To remove an exception, type the value again.
- **Backfill:** start or resume the backfill for the environment of the saved key, with progress.

Privacy: a suggested section for Settings → Privacy → Policy guide (`wp_add_privacy_policy_content`). Translations: Hungarian (PHP + JS) in `languages/`.

## WP-CLI

Every admin function is also available as a `wp trustorder` command, using the same services as the admin screen. Exit status: 0 on success, 1 on a service or validation error (the message is then only the error code or a fixed text, never buyer data or the key).

| Command | What it does |
|---|---|
| `wp trustorder status [--format=table\|json]` | the key (first 12 characters only), the connection test (shop, account status, daily quota, today's checks), the last error, intl / order storage / checkout type, and the backfill progress of both environments; exit 1 if the service cannot be reached |
| `wp trustorder settings get [<key>] [--format=table\|json]` | the settings (`api-key` prefix only, `strictness`, `hidden-gateways`, `fallback-gateways`, `pickup-methods`, `enabled`, `share-geo`) |
| `wp trustorder settings set <key> <value>` | change one setting, validated like the admin screen (lists are comma-separated; `enabled` and `share-geo` take true/false, on/off, yes/no or 1/0). The `api-key` is read **only from piped STDIN**, so it never appears in the command line or the shell history: `printf '%s' "$KEY" \| wp trustorder settings set api-key -` (any other value, `--api-key=…` and WP-CLI's `--prompt` are refused; a terminal or no input within 5 seconds is an error); an empty STDIN removes the key |
| `wp trustorder check --email=<e> [--phone=] [--country=] [--postcode=] [--address=] [--strictness=] [--format=]` | ask the service about one buyer (verdict, level, score, reasons, check id); admin tool: skips the result cache and the checkout limits; hashes locally and logs nothing |
| `wp trustorder backfill start [--env=live\|test]` / `status` / `run [--limit=N]` | start the backfill (only for the saved key's environment), show its state, or run it in the terminal with a progress bar (takes over the pending background step; `--limit` pauses after the 200-order page that reaches it, `backfill start` resumes; when the service is unavailable the retry goes back to the background) |
| `wp trustorder signal send <order-id>... [--format=]` / `signal status <order-id>` | send the current outcome of the orders now (nothing is sent if the service already holds it; exit 1 for a missing order or a failed / retrying signal), or show the stored state without a request (the "wanted" column shows `refund` for a refunded order) |
| `wp trustorder exceptions list` / `add` / `remove` | exceptions: `add --kind=email\|phone\|address --action=allow\|deny [--value=] [--country=] [--postcode=] [--address=] [--address-2=] [--note=]`; `--value` for an e-mail or phone, `--country --postcode --address` for an address; the value is hashed locally and the list never shows values |

Note: `check --email/--phone` and `exceptions add|remove --value` take the buyer's data as a command-line argument, which a logging shell keeps in its history. Run them in a shell that does not log, or clear the history afterwards.

## Development

```bash
composer install
composer phpcs && composer analyse && composer test
npm ci && npm run build   # build/ is committed
npm run i18n              # after string changes: .pot → hu_HU .po/.mo → JSON
```

### Prefixed dependencies (Strauss)

`giggsey/libphonenumber-for-php-lite` is prefixed into the
`LightweightPlugins\TrustOrder\Vendor\` namespace in `vendor-prefixed/`, which is
**committed** (there is no `vendor/` at runtime). After a version change:

```bash
composer strauss
```

## License

GPL-2.0-or-later.
