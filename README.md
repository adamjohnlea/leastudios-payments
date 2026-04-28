# leaStudios Payments

Stripe payments for WordPress. Accept one-time payments and subscriptions using Stripe Embedded Checkout, manage orders and subscriptions from the admin, and process refunds without leaving WordPress.

- **Requires WordPress:** 6.4+
- **Requires PHP:** 8.1+
- **License:** GPL-2.0-or-later

## Features

- **Embedded Checkout** — Stripe's hosted checkout form embedded on your site. PCI-compliant; no card data ever touches your server.
- **One-time and subscription products** — synced to Stripe; managed from the WordPress admin.
- **Shortcode + Gutenberg block** — `[leastudios_payment price_id="..."]` or the block editor.
- **Order, subscription, and customer management** with refunds and Customer Portal access.
- **Confirmation page** with merge tags (`{customer_name}`, `{amount}`, `{product_name}`, …).
- **Account page** for customers to view orders, manage subscriptions, and update billing.
- **Dashboard widget** — 30-day revenue, orders, subscriptions, refunds.
- **Webhook handling** for checkout completion, subscription lifecycle, invoice payments, payment failures, and refunds.
- **Encrypted Stripe credentials** at rest (libsodium).
- **Rate limiting** on checkout endpoint; duplicate-processing protection on webhooks.

## Installation

1. Upload `leastudios-payments` to `/wp-content/plugins/`.
2. Activate via Plugins → Installed Plugins.
3. Go to **Payments → Settings** and enter your Stripe API keys.
4. Create a product under **Payments → Products**.
5. Add a checkout button via shortcode or the block editor.
6. Configure the Stripe webhook to point at the URL shown on the Settings page.

## Related plugins

This plugin is part of the leaStudios plugin family. It works on its own, and integrates with:

- **[leastudios-email-templates](../leastudios-email-templates)** — sends branded transactional emails (receipts, subscription created, renewals, payment failed, refund processed) for events emitted by this plugin.
- **[leastudios-mailer](../leastudios-mailer)** — routes outgoing emails through Amazon SES with delivery logging.

## Development

This plugin is self-contained — it can be cloned, linted, tested, and packaged on its own.

```bash
composer install            # install dependencies (incl. dev tools)
composer lint               # phpcs + phpstan
composer test               # phpunit (requires the WP test library)
composer phpcbf             # auto-fix WPCS issues
```

To run the test suite, install the WordPress test library once (any directory works):

```bash
bash ../leastudios-dev-tools/bin/install-wp-tests.sh wordpress_test root '' localhost latest
```

The shared scaffold, packaging script, project-wide development conventions, and config templates live in **[leastudios-dev-tools](../leastudios-dev-tools)** — start there when bootstrapping a new plugin or making cross-plugin tooling changes.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE) (or `readme.txt` for the WordPress.org-style header).
