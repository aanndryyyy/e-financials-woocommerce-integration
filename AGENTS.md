# Agent instructions

WooCommerce plugin that syncs orders/products/invoices with e-Financials (e-Arveldaja).

## Cursor Cloud specific instructions

### Stack

- PHP plugin (`composer install` required — entrypoint loads `vendor/autoload.php`)
- Local WordPress via `@wordpress/env` (Docker): `npm start`
- Default site: http://localhost:8888 — WP admin `admin` / `password`
- Tests site port: http://localhost:8889
- E2E: Playwright (`npm run test:e2e`)

### Boot order

1. Docker must be running (`sudo service docker start` — also in environment `start`)
2. `npm start` (wp-env terminal usually already does this)
3. Wait until wp-env finishes before hitting the site or running e2e
4. Plugin code is mounted from the repo root; keep `vendor/` present after Composer install

### Commands

| Task | Command |
|---|---|
| Start WordPress | `npm start` |
| Stop WordPress | `npm stop` |
| Demo products | `npm run import:demo` |
| E2E (headless) | `npm run test:e2e` |
| E2E (headed) | `npm run test:e2e:headed` |
| Static analysis | `composer test:static` (after `composer bin phpstan/psalm update` if needed) |
| PHPCS | `composer test:phpcs` |

### E2E conventions (WCOM / WooCommerce)

Follow [WooCommerce Playwright e2e](https://github.com/woocommerce/woocommerce/tree/trunk/plugins/woocommerce/tests/e2e-pw) and [WCOM e2e best practices](https://developer.woocommerce.com/testing-extensions-and-maintaining-quality-code/best-practices-for-writing-and-running-end-to-end-tests/):

- Write **atomic** tests (self-contained setup/teardown; no shared mutable state)
- Prefer **end-user** flows (merchant settings, checkout/order paths that matter)
- Put admin helpers in `tests/e2e/utils/backend.js`, storefront helpers in `tests/e2e/utils/frontend.js`
- Prefer role/label selectors; add stable automation IDs in plugin UI when needed
- Use `@woocommerce/e2e-utils-playwright` for shared cart/API helpers when useful
- Fix flaky tests immediately — do not skip and forget

Older WCOM pages still mention Jest/`@woocommerce/e2e-environment`; **do not** revive that stack — use Playwright.

### Secrets

For live/demo e-Financials API calls during agent work, store credentials in Cursor Cloud Secrets (never commit):

- `EFINANCIALS_API_KEY_ID`
- `EFINANCIALS_API_KEY_PUBLIC`
- `EFINANCIALS_API_KEY_PASSWORD`

Default smoke e2e does not require those secrets.

### Verification

After changing plugin behavior that merchants or shoppers see, run `npm run test:e2e` (and static checks when touching PHP structure). Computer-use browser against http://localhost:8888 is fine for exploratory checks; Playwright is the regression gate.
