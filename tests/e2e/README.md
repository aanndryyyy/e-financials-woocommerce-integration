# End-to-end tests

Playwright + `wp-env` suite for this WooCommerce extension, following current [WooCommerce e2e practices](https://github.com/woocommerce/woocommerce/tree/trunk/plugins/woocommerce/tests/e2e-pw) and [WCOM testing guidance](https://developer.woocommerce.com/testing-extensions-and-maintaining-quality-code/best-practices-for-writing-and-running-end-to-end-tests/).

## Principles

- **End-user perspective** — cover merchant (admin) and customer (storefront) goals.
- **Atomic tests** — each test creates its own preconditions; do not share mutable state across tests.
- **Helpers over duplication** — put repeated admin flows in `utils/backend.js` and storefront flows in `utils/frontend.js`.
- **Stable selectors** — prefer roles/labels; add `data-testid` / automation IDs in plugin UI when selectors would otherwise be brittle.
- **Fix flakes quickly** — never leave intermittent failures ignored.

> Note: older WCOM docs still mention Jest/`@woocommerce/e2e-environment`. WooCommerce core has moved to **Playwright**; this repo follows that stack, plus `@woocommerce/e2e-utils-playwright` for shared cart/API helpers when needed.

## Prerequisites

1. Docker running
2. Dependencies installed (`npm install`, `composer install` — plugin needs `vendor/`)
3. WordPress up: `npm start` (http://localhost:8888, `admin` / `password`)

## Commands

```bash
npm start                 # wp-env start
npm run test:e2e          # headless
npm run test:e2e:headed   # headed
npm run test:e2e:ui       # Playwright UI
```

Optional demo catalog:

```bash
npm run import:demo
```

## Live demo API tests

`specs/live-demo-api.spec.js` (`@live`) exercises a real connection to `demo-rmp-api.rik.ee`. Run with `npm run test:e2e:live` (excluded from default `npm run test:e2e`). It skips when secrets are missing; when secrets are present it fails if RIK rejects auth.

Required env (Cursor Cloud Secrets):

- `E_FINANCIALS_API_KEY_ID` (or `EFINANCIALS_API_KEY_ID`)
- `E_FINANCIALS_API_KEY_PUBLIC` (or `EFINANCIALS_API_KEY_PUBLIC`)
- `E_FINANCIALS_API_KEY_PASSWORD` (or `EFINANCIALS_API_KEY_PASSWORD`)

Ensure the ApiKey IP allowlist permits the runner’s egress IP (or `0.0.0.0/0`).

## Layout

```
tests/e2e/
  playwright.config.js
  specs/           # one focused area per file when possible
  utils/backend.js # WP Admin / merchant helpers
  utils/frontend.js# storefront helpers
```
