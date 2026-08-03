=== e-Financials for WooCommerce ===
Contributors: aanndryyyy
Tags: accounting, invoices, bookkeeping, estonia, e-arveldaja
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.2
Stable tag: 0.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync orders, customers, products and invoices from your shop to e-Financials (e-Arveldaja) bookkeeping.

== Description ==

Background sync so checkout stays fast. Once an order reaches a configured status the plugin:

1. Upserts the customer as an e-Financials client.
2. Ensures every ordered product exists as an e-Financials product.
3. Creates and registers the sale invoice.
4. Records the payment (cash fields and/or a bank transaction), gateway-agnostic.
5. Optionally delivers the invoice to the customer by email or e-invoice.
6. Issues credit invoices on full and partial refunds.

Merchants get a settings screen, an order list column, an order metabox with the invoice PDF,
manual order actions and an optional note on customer emails.

All API traffic goes through the e-financials/php-client library and is signed with HMAC-SHA-384.

== Installation ==

1. Upload the plugin to `wp-content/plugins/` and activate it.
2. Go to WooCommerce > Settings > Integrations > e-Financials.
3. Enter your API key id, public key and password, then pick the invoice series, template and payment mode map.

== Frequently Asked Questions ==

= Does it work with the demo environment? =

Yes. Point the API base URL at the e-Financials test environment to try the sync without touching live books.

= Are prices and VAT taken from the shop? =

Yes. VAT rates come from the order lines and are mapped to e-Financials sale articles, which is how
e-Financials books VAT. Every rate used by the shop needs an entry in the VAT rate map.

== Changelog ==

= 0.0.1 =
* Initial release.
