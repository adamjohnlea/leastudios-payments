=== leaStudios Payments ===
Contributors: leastudios
Tags: stripe, payments, checkout, subscriptions, ecommerce
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Stripe payments for WordPress. Accept one-time payments and subscriptions using Stripe Embedded Checkout.

== Description ==

leaStudios Payments integrates Stripe directly into your WordPress site. Customers complete checkout without leaving your site using Stripe's Embedded Checkout, and you manage orders, subscriptions, and refunds from the WordPress admin.

**Key features:**

* **Embedded Checkout** — Stripe's hosted checkout form embedded on your site. PCI compliant with no card data touching your server.
* **One-time payments and subscriptions** — create products and prices synced to Stripe, supporting both payment modes.
* **Shortcode and Gutenberg block** — add checkout buttons anywhere with `[leastudios_payment price_id="X"]` or the block editor.
* **Order management** — view all orders, customer details, and line items. Process full or partial refunds from the admin.
* **Subscription management** — view active subscriptions, cancel immediately or at period end.
* **Customer management** — grouped by email with full order and subscription history.
* **Confirmation page** — customisable success page with merge tags for customer name, amount, product, and more.
* **Account page** — customers can view their order history, active subscriptions, and manage billing via Stripe Customer Portal.
* **Dashboard widget** — 30-day revenue, order count, subscription count, and refund summary at a glance.
* **Webhook handling** — automatic processing of checkout completions, subscription changes, invoice payments, payment failures, and refunds.

**Security:**

* Stripe credentials encrypted at rest using libsodium.
* Rate limiting on checkout endpoint.
* Duplicate processing prevention on webhooks and refunds.
* Login required for all checkouts.

== Installation ==

1. Upload the `leastudios-payments` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins menu in WordPress.
3. Go to Payments > Settings and enter your Stripe API keys.
4. Create a product under Payments > Products.
5. Add a checkout button to any page using `[leastudios_payment price_id="price_xxx"]` or the Gutenberg block.
6. Configure the Stripe webhook to point to your site's webhook endpoint (shown on the Settings page).

== Frequently Asked Questions ==

= Does this support subscriptions? =

Yes. When creating a price, choose "recurring" and set the billing interval. The checkout, order management, and customer portal all handle subscriptions automatically.

= Is a Stripe account required? =

Yes. You need a Stripe account with API keys. Both test mode and live mode are supported.

= Does the customer stay on my site during checkout? =

Yes. Stripe Embedded Checkout renders the payment form directly on your page. The customer never leaves your site.

== Changelog ==

= 1.0.0 =
* Initial release.
