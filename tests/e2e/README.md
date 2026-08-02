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

## Layout

```
tests/e2e/
  playwright.config.js
  specs/           # one focused area per file when possible
  utils/backend.js # WP Admin / merchant helpers
  utils/frontend.js# storefront helpers
```
