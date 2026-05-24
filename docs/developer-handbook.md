# leaStudios Payments — Developer Handbook

leaStudios Payments adds Stripe-powered checkout to WordPress: one-time payments,
subscriptions, order management, refunds, and a Stripe Billing Portal — all backed
by custom database tables, a verified webhook pipeline, and 21 named hooks that let
you inject logic at every stage of the purchase lifecycle.

---

## Table of Contents

1. [Overview](#1-overview)
2. [Architecture](#2-architecture)
3. [Development Setup](#3-development-setup)
4. [Concepts](#4-concepts)
5. [Data Model](#5-data-model)
6. [Hooks Reference](#6-hooks-reference)
7. [Hook Execution Order](#7-hook-execution-order)
8. [REST API Reference](#8-rest-api-reference)
9. [Extension Recipes](#9-extension-recipes)
10. [Testing](#10-testing)
11. [Release Process](#11-release-process)
12. [Where to Read More](#12-where-to-read-more)

---

## 1. Overview

leaStudios Payments lets WordPress site owners accept Stripe payments without writing
any Stripe code themselves. Site visitors check out through Stripe's Embedded Checkout
UI — the form loads inside your page; card data never touches your server. Products and
prices are created inside WordPress and automatically mirrored to Stripe. Subscriptions
are tracked in a local database table, kept in sync by webhook events, and surfaced to
customers through a self-service account shortcode.

For extension authors the plugin exposes a rich seam of hooks:

- **Checkout hooks** — filter the Stripe Session arguments before creation or react
  immediately after a session is opened.
- **Webhook hooks** — a catch-all `leastudios_payments_webhook_event` plus a
  per-event-type dynamic action (`leastudios_payments_webhook_<event_type>`) fires for
  every verified Stripe event the plugin handles.
- **Subscription lifecycle hooks** — synced, invoice paid, payment failed, cancellation
  scheduled, and canceled-immediately.
- **Refund hooks** — filter the Stripe Refund arguments, then react after the refund
  lands either via the admin UI or Stripe Dashboard.
- **Stripe object hooks** — filter the arguments sent to Stripe when creating a
  Customer, Product, or Price.
- **Admin / content hooks** — extend the Tags Reference page or add custom merge tags
  to confirmation pages.

The plugin integrates with `leastudios-email-templates` (transactional emails) and
`leastudios-mailer` (Amazon SES transport) but degrades gracefully when those are absent.

---

## 2. Architecture

### Component map

```
leastudios-payments.php
    └── Plugin::init()
            ├── Database\Migration::maybe_migrate()   schema up-to-date check
            ├── Container (lazy service locator)
            │
            ├── Checkout\Checkout_Handler             listens: webhook_checkout_session_completed
            ├── Checkout\Subscription_Handler         listens: webhook_customer_subscription_*
            │                                                   webhook_invoice_paid/payment_failed
            ├── Checkout\Refund_Handler               listens: webhook_charge_refunded
            │
            ├── REST\Checkout_Controller              POST /checkout-session
            ├── REST\Webhook_Controller               POST /webhook  (Stripe → site)
            ├── REST\Products_Controller              GET  /products
            ├── REST\Refund_Controller                POST /refund
            ├── REST\Portal_Controller                POST /portal-session
            │
            ├── Render\Shortcode                      [leastudios_payment]
            ├── Render\Block                          Gutenberg block wrapper
            ├── Render\Confirmation                   [leastudios_payment_confirmation]
            ├── Render\Account                        [leastudios_payment_account]
            │
            ├── Stripe\Product_Sync                   admin product/price CRUD → Stripe
            ├── Stripe\Customer_Manager               WP user ↔ Stripe Customer mapping
            └── Admin\*                               admin pages, dashboard widget
```

### Checkout flow

```
Browser (logged-in WP user)
    |
    POST /wp-json/leastudios-payments/v1/checkout-session
    |
    Checkout_Controller
        |-- rate-limit check (10/min per IP)
        |-- Session_Factory::create()
                |-- [filter] leastudios_payments_checkout_session_args
                |-- Stripe API: Checkout\Session::create()
                |-- [action] leastudios_payments_checkout_session_created
        |
        returns { client_secret }
    |
    Browser renders Stripe Embedded Checkout (JS SDK)
    |
    Customer completes payment on Stripe-hosted form
    |
    Stripe calls POST /wp-json/leastudios-payments/v1/webhook
    |
    Webhook_Controller
        |-- Stripe\Webhook::constructEvent() — signature verification
        |-- Webhook_Event_Repository::try_claim() — idempotency check
        |-- [action] leastudios_payments_webhook_event
        |-- [action] leastudios_payments_webhook_checkout_session_completed
                |
                Checkout_Handler::handle_session_completed()
                    |-- create order record
                    |-- [action] leastudios_payments_order_created
```

### Key design decisions

- **Single code path**: the admin Refund UI, the REST endpoint, and webhook-driven
  refunds all write through `Order_Repository`. No parallel update paths.
- **Idempotent webhook dispatch**: the `webhook_events` table holds a UNIQUE constraint
  on `stripe_event_id`. `try_claim()` is an atomic INSERT; a duplicate Stripe delivery
  returns 200 OK without replaying the action hooks.
- **Customer mapping security**: `metadata.wp_user_id` in Stripe payloads is
  attacker-influenceable. `Customer_Manager::resolve_user_id()` only trusts the claim
  when the local `user_meta` mapping agrees; otherwise it does a reverse lookup.

---

## 3. Development Setup

```bash
cd wp-content/plugins/leastudios-payments
composer install
composer lint              # phpcs + phpstan
composer test              # PHPUnit (requires WP test library — see below)
composer phpcbf            # auto-fix WPCS issues
```

### WordPress test library (one-time, shared across all plugins)

```bash
bash ../leastudios-dev-tools/bin/install-wp-tests.sh \
    wordpress_test root '' 127.0.0.1 latest
```

The library installs to `/tmp/wordpress-tests-lib/`. All plugin `tests/bootstrap.php`
files look there automatically.

### Stripe CLI — local webhook testing

To exercise the full checkout + webhook cycle locally:

```bash
stripe listen --forward-to https://leastudios-plugins.test/wp-json/leastudios-payments/v1/webhook
```

Copy the signing secret the CLI prints and paste it into **Payments → Settings →
Webhook Signing Secret**. The CLI replays any Stripe event to your local site,
so you can test the entire webhook pipeline without a public URL.

### Sibling plugin integrations

- **leastudios-email-templates** — activate to exercise payment receipt and
  subscription emails triggered by `leastudios_payments_order_created`,
  `leastudios_payments_subscription_synced`, and the refund hooks.
- **leastudios-mailer** — activate to route those emails through Amazon SES.
  Both are optional; the payment plugin degrades gracefully without them.

---

## 4. Concepts

### Checkout Session

A Stripe Checkout Session is the server-side object that authorizes the payment form.
`Session_Factory::create()` constructs it with the selected price, the customer's
Stripe Customer ID, branding overrides from plugin settings, and any modifications
applied by the `leastudios_payments_checkout_session_args` filter. The browser receives
a `client_secret` and passes it to the Stripe JS SDK to render the embedded form.

### Embedded Checkout (embedded_page mode)

The plugin uses Stripe's `embedded_page` UI mode. The checkout form loads inside your
page via the Stripe JS SDK — there is no redirect to Stripe's hosted domain. PCI scope
is reduced because card fields render in Stripe-owned iframes; card data never reaches
your server.

### Webhook event

A Stripe webhook event is an HTTP POST Stripe sends to your site to notify it that
something happened (payment completed, subscription renewed, etc.). The plugin verifies
the `Stripe-Signature` header, checks the event has not been processed before, fires
`leastudios_payments_webhook_event` for all handled events, then fires a per-type
dynamic action (e.g. `leastudios_payments_webhook_checkout_session_completed`).

### Local subscription status

Stripe subscription statuses are mapped to a smaller set of local values stored in
`wp_leastudios_payments_subscriptions.status`:

| Stripe status        | Local status  |
|----------------------|---------------|
| `active`             | `active`      |
| `trialing`           | `trialing`    |
| `past_due` / `unpaid`| `past_due`    |
| `paused`             | `paused`      |
| `canceled` / `incomplete_expired` | `canceled` |
| `incomplete`         | `incomplete`  |
| unknown              | `incomplete`  |

### Merge tags

Merge tags are `{curly_brace}` placeholders replaced at render time in confirmation
page content. The tag map is filterable via `leastudios_payments_confirmation_tags`.
The full tag reference is available at **Payments → Tags** in the admin, and is itself
extensible via `leastudios_payments_tag_groups`.

---

## 5. Data Model

All custom tables use the WordPress table prefix followed by `leastudios_payments_`.
Use `Migration::table( 'orders' )` (the static helper) to get the fully-prefixed name
rather than hard-coding `$wpdb->prefix . 'leastudios_payments_orders'`.

### `wp_leastudios_payments_products`

Mirrors the Stripe product catalogue locally.

| Column             | Type                   | Notes                              |
|--------------------|------------------------|------------------------------------|
| `id`               | `bigint unsigned PK`   | Auto-increment local ID            |
| `stripe_product_id`| `varchar(255) UNIQUE`  | Stripe `prod_*` ID                 |
| `name`             | `varchar(255)`         |                                    |
| `description`      | `text`                 | Nullable                           |
| `image_url`        | `varchar(2048)`        | Nullable                           |
| `status`           | `varchar(20)`          | `active` or `inactive`             |
| `require_shipping` | `tinyint(1)`           | `1` = collect shipping address     |
| `created_at`       | `datetime`             |                                    |
| `updated_at`       | `datetime`             | Auto-updates on row change         |

### `wp_leastudios_payments_prices`

One price per row; multiple prices may belong to one product.

| Column                   | Type                   | Notes                          |
|--------------------------|------------------------|--------------------------------|
| `id`                     | `bigint unsigned PK`   |                                |
| `stripe_price_id`        | `varchar(255) UNIQUE`  | Stripe `price_*` ID            |
| `product_id`             | `bigint unsigned`      | FK → products.id               |
| `amount`                 | `bigint unsigned`      | In smallest currency unit (cents) |
| `currency`               | `varchar(3)`           | Lowercase ISO 4217 (e.g. `usd`)|
| `type`                   | `varchar(20)`          | `one_time` or `recurring`      |
| `recurring_interval`     | `varchar(10)`          | `day`, `week`, `month`, `year`; nullable for one-time |
| `recurring_interval_count`| `int`                 | Multiplier; `1` = every interval |
| `status`                 | `varchar(20)`          | `active` or `inactive`         |

### `wp_leastudios_payments_orders`

One row per completed Stripe Checkout Session (`mode=payment` or the initial session for subscriptions).

| Column                     | Type                  | Notes                              |
|----------------------------|-----------------------|------------------------------------|
| `id`                       | `bigint unsigned PK`  | Local order ID                     |
| `stripe_session_id`        | `varchar(255) UNIQUE` | `cs_*` Checkout Session ID         |
| `stripe_payment_intent_id` | `varchar(255)`        | `pi_*`; nullable for subscriptions |
| `stripe_customer_id`       | `varchar(255)`        | `cus_*`                            |
| `customer_email`           | `varchar(255)`        |                                    |
| `customer_name`            | `varchar(255)`        | Nullable                           |
| `wp_user_id`               | `bigint unsigned`     | Nullable (guest not possible in this plugin, but schema allows it) |
| `amount_total`             | `bigint unsigned`     | In smallest currency unit          |
| `currency`                 | `varchar(3)`          | Lowercase ISO 4217                 |
| `payment_status`           | `varchar(20)`         | `paid`, `partial_refund`, `refunded` |
| `order_type`               | `varchar(20)`         | `one_time` or `subscription`       |
| `refunded_amount`          | `bigint unsigned`     | Cumulative; `0` = no refund        |
| `line_items_json`          | `longtext`            | JSON array of `{description, quantity, amount, currency, price_id, product_id}` |

### `wp_leastudios_payments_subscriptions`

One row per Stripe Subscription, upserted on every `customer.subscription.*` webhook.

| Column                   | Type                  | Notes                            |
|--------------------------|-----------------------|----------------------------------|
| `id`                     | `bigint unsigned PK`  | Local subscription ID            |
| `stripe_subscription_id` | `varchar(255) UNIQUE` | `sub_*`                          |
| `stripe_customer_id`     | `varchar(255)`        | `cus_*`                          |
| `stripe_price_id`        | `varchar(255)`        | Primary price of the subscription|
| `customer_email`         | `varchar(255)`        |                                  |
| `wp_user_id`             | `bigint unsigned`     | Nullable                         |
| `status`                 | `varchar(20)`         | See local status map in Concepts |
| `current_period_start`   | `datetime`            | Nullable; from `items[0]` in Stripe API ≥ 2025-04-30 |
| `current_period_end`     | `datetime`            | Nullable                         |
| `cancel_at_period_end`   | `tinyint(1)`          | `1` = cancel at period end queued|

### `wp_leastudios_payments_webhook_events`

Idempotency table. One row per processed Stripe event ID.

| Column           | Type                  | Notes                       |
|------------------|-----------------------|-----------------------------|
| `id`             | `bigint unsigned PK`  |                             |
| `stripe_event_id`| `varchar(255) UNIQUE` | `evt_*`; INSERT fails on dup|
| `event_type`     | `varchar(100)`        | e.g. `checkout.session.completed` |
| `processed_at`   | `datetime`            |                             |

### Options

| Option key                        | Type    | Description                                            |
|-----------------------------------|---------|--------------------------------------------------------|
| `leastudios_payments_options`     | `array` | Plugin settings (see below)                            |
| `leastudios_payments_schema_version` | `int` | Current DB schema version; managed by `Migration`   |

The `leastudios_payments_options` array has the following keys:

| Key                  | Type     | Description                                           |
|----------------------|----------|-------------------------------------------------------|
| `test_mode`          | `bool`   | `true` = use Stripe test keys                         |
| `publishable_key`    | `string` | Stripe publishable key (pk_*)                         |
| `secret_key`         | `string` | Stripe secret key — stored encrypted via libsodium    |
| `webhook_secret`     | `string` | Stripe webhook signing secret — stored encrypted      |
| `default_currency`   | `string` | ISO 4217 uppercase (e.g. `USD`)                       |
| `success_page`       | `int`    | Post ID of the checkout success / confirmation page   |
| `cancel_page`        | `int`    | Post ID of the checkout cancelled page                |
| `branding_settings`  | `array`  | Keys: `background_color`, `button_color`, `font_family`, `border_style`, `display_name` — passed to Stripe's `branding_settings` |

### User meta

| Meta key                                    | Type     | Description                    |
|---------------------------------------------|----------|--------------------------------|
| `leastudios_payments_stripe_customer_id`    | `string` | Stripe `cus_*` ID for this WP user |

Read via `Customer_Manager::META_KEY`. Write via `Customer_Manager::get_or_create()` —
do not write this meta directly; let the manager maintain the 1:1 mapping.

---

## 6. Hooks Reference

Hooks are grouped by the phase of the payment lifecycle in which they fire. Within each
group, filters appear before actions; hooks are alphabetical within each type.

### Checkout

#### `leastudios_payments_checkout_session_args`

- **Type:** Filter
- **Location:** `src/Checkout/Session_Factory.php`
- **Since:** 1.0.0
- **Description:** Filters the argument array sent to `\Stripe\Checkout\Session::create()`
  before the session is opened. Use this to enable Stripe features the plugin does not
  expose natively: adaptive pricing, custom fields, promotion codes, tax settings,
  phone number collection, and more. The array shape matches the
  [Stripe Checkout Session API](https://stripe.com/docs/api/checkout/sessions/create).

**Parameters:**

| Parameter      | Type                  | Description                                |
|----------------|-----------------------|--------------------------------------------|
| `$session_args`| `array<string, mixed>`| Full Checkout Session creation arguments   |
| `$price_id`    | `int`                 | Local price ID being purchased             |
| `$user_id`     | `int`                 | WordPress user ID initiating checkout      |

**Returns:** `array<string, mixed>` — the filtered session arguments.

**Example:**

```php
// Enable promotion codes and phone number collection for all checkouts.
add_filter(
    'leastudios_payments_checkout_session_args',
    function ( array $session_args, int $price_id, int $user_id ): array {
        $session_args['allow_promotion_codes'] = true;
        $session_args['phone_number_collection'] = [ 'enabled' => true ];
        return $session_args;
    },
    10,
    3
);
```

---

#### `leastudios_payments_confirmation_tags`

- **Type:** Filter
- **Location:** `src/Render/Confirmation.php`
- **Since:** 1.0.0
- **Description:** Filters the array of merge-tag replacements used to render the
  `[leastudios_payment_confirmation]` shortcode. Keys are `{tag}` strings; values are
  the already-escaped replacement text. Use this to add custom tags, modify existing
  tag values, or redact fields based on user role.

**Parameters:**

| Parameter      | Type                  | Description                                         |
|----------------|-----------------------|-----------------------------------------------------|
| `$tags`        | `array<string, string>`| Tag => escaped replacement value pairs             |
| `$session_data`| `array<string, mixed>`| Raw Stripe Checkout Session data for this order    |

**Returns:** `array<string, string>` — the filtered tag map.

**Example:**

```php
// Add a {support_url} tag that links to a product-specific support page.
add_filter(
    'leastudios_payments_confirmation_tags',
    function ( array $tags, array $session_data ): array {
        $product_name = $session_data['line_items']['data'][0]['description'] ?? '';
        $support_url  = 'pro plan' === strtolower( $product_name )
            ? 'https://example.com/pro-support'
            : 'https://example.com/support';
        $tags['{support_url}'] = esc_url( $support_url );
        return $tags;
    },
    10,
    2
);
```

---

#### `leastudios_payments_shipping_countries`

- **Type:** Filter
- **Location:** `src/Checkout/Session_Factory.php`
- **Since:** 1.0.0
- **Description:** Filters the list of two-letter ISO 3166-1 country codes that are
  offered as shipping destinations when a product has `require_shipping` enabled. The
  default list covers ~20 common markets (US, CA, GB, AU, most of the EU, JP). Return
  an empty array to accept shipping to all countries that Stripe supports.

**Parameters:**

| Parameter    | Type             | Description                             |
|--------------|------------------|-----------------------------------------|
| `$countries` | `array<int, string>` | Array of two-letter country codes   |

**Returns:** `array<int, string>` — the filtered country list.

**Example:**

```php
// Restrict shipping to North America only.
add_filter(
    'leastudios_payments_shipping_countries',
    function ( array $countries ): array {
        return [ 'US', 'CA', 'MX' ];
    }
);
```

---

#### `leastudios_payments_checkout_session_created`

- **Type:** Action
- **Location:** `src/Checkout/Session_Factory.php`
- **Since:** 1.0.0
- **Description:** Fires immediately after a Stripe Checkout Session is successfully
  created, before the client secret is returned to the browser. The session object is
  the live Stripe API response. Use this to log the session, pre-populate your CRM, or
  send an "about to purchase" notification.

**Parameters:**

| Parameter  | Type                         | Description                       |
|------------|------------------------------|-----------------------------------|
| `$session` | `\Stripe\Checkout\Session`   | The newly created session object  |
| `$price_id`| `int`                        | Local price ID                    |
| `$user_id` | `int`                        | WordPress user ID                 |

**Example:**

```php
// Log every new checkout session to a custom audit table.
add_action(
    'leastudios_payments_checkout_session_created',
    function ( \Stripe\Checkout\Session $session, int $price_id, int $user_id ): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'my_checkout_audit',
            [
                'stripe_session_id' => $session->id,
                'price_id'          => $price_id,
                'user_id'           => $user_id,
                'created_at'        => current_time( 'mysql', true ),
            ],
            [ '%s', '%d', '%d', '%s' ]
        );
    },
    10,
    3
);
```

---

#### `leastudios_payments_order_created`

- **Type:** Action
- **Location:** `src/Checkout/Checkout_Handler.php`
- **Since:** 1.0.0
- **Description:** Fires after a completed checkout session has been recorded as an
  order in the local database. This is the primary hook for post-purchase automation:
  granting access to content, notifying a CRM, firing off a fulfillment workflow, or
  triggering a transactional email. The `$session_data` array is the full Stripe
  Checkout Session payload from the `checkout.session.completed` webhook event.

**Parameters:**

| Parameter      | Type                  | Description                                   |
|----------------|-----------------------|-----------------------------------------------|
| `$order_id`    | `int`                 | Local order ID in `wp_leastudios_payments_orders` |
| `$session_data`| `array<string, mixed>`| Full Stripe session data from the webhook payload |

**Example:**

```php
// Assign a WordPress role after a one-time purchase.
add_action(
    'leastudios_payments_order_created',
    function ( int $order_id, array $session_data ): void {
        $wp_user_id = (int) ( $session_data['metadata']['wp_user_id'] ?? 0 );
        if ( $wp_user_id <= 0 ) {
            return;
        }
        $user = get_user_by( 'id', $wp_user_id );
        if ( $user instanceof WP_User ) {
            $user->add_role( 'paying_customer' );
        }
    },
    10,
    2
);
```

---

### Webhook

#### `leastudios_payments_webhook_event`

- **Type:** Action
- **Location:** `src/REST/Webhook_Controller.php`
- **Since:** 1.0.0
- **Description:** Fires once for every Stripe webhook event that passes signature
  verification, deduplication, and the `Webhook_Events::is_handled()` gate. It fires
  before the per-type dynamic action (see below). Use it as a catch-all observer: audit
  logging, payload forwarding to another system, or instrumenting webhook latency. The
  hook does not fire for events that are not in the plugin's handled-events registry;
  unknown Stripe events are acknowledged but silently dropped.

**Parameters:**

| Parameter    | Type                  | Description                           |
|--------------|-----------------------|---------------------------------------|
| `$payload`   | `array<string, mixed>`| Full Stripe event payload (decoded JSON) |
| `$event_type`| `string`              | Stripe event type, e.g. `invoice.paid`|

**Example:**

```php
// Forward every verified webhook event to an internal audit endpoint.
add_action(
    'leastudios_payments_webhook_event',
    function ( array $payload, string $event_type ): void {
        wp_remote_post(
            'https://audit.internal.example.com/stripe-events',
            [
                'headers'  => [ 'Content-Type' => 'application/json' ],
                'body'     => wp_json_encode( [
                    'event_id'   => $payload['id'] ?? '',
                    'event_type' => $event_type,
                    'received'   => gmdate( 'Y-m-d H:i:s' ),
                ] ),
                'blocking' => false,
            ]
        );
    },
    10,
    2
);
```

---

#### `leastudios_payments_webhook_{event_type}`

- **Type:** Action (dynamic)
- **Location:** `src/REST/Webhook_Controller.php`
- **Since:** 1.0.0
- **Description:** A per-event-type dynamic action fires for every Stripe event after
  `leastudios_payments_webhook_event`. The hook name is built by replacing dots in the
  Stripe event type with underscores and prepending `leastudios_payments_webhook_`:

  | Stripe event type | Dynamic hook name |
  |---|---|
  | `checkout.session.completed` | `leastudios_payments_webhook_checkout_session_completed` |
  | `customer.subscription.created` | `leastudios_payments_webhook_customer_subscription_created` |
  | `customer.subscription.updated` | `leastudios_payments_webhook_customer_subscription_updated` |
  | `customer.subscription.deleted` | `leastudios_payments_webhook_customer_subscription_deleted` |
  | `invoice.paid` | `leastudios_payments_webhook_invoice_paid` |
  | `invoice.payment_failed` | `leastudios_payments_webhook_invoice_payment_failed` |
  | `charge.refunded` | `leastudios_payments_webhook_charge_refunded` |

  Only events listed in `Webhook_Events::HANDLED` will fire this hook. Events outside
  that list are acknowledged with 200 OK and dropped before dispatch. The plugin's own
  `Checkout_Handler`, `Subscription_Handler`, and `Refund_Handler` all listen on these
  dynamic hooks internally.

**Parameters:**

| Parameter  | Type                  | Description                             |
|------------|-----------------------|-----------------------------------------|
| `$payload` | `array<string, mixed>`| Full Stripe event payload (decoded JSON)|

**Example:**

```php
// Listen specifically for the invoice.paid event to run custom renewal logic.
add_action(
    'leastudios_payments_webhook_invoice_paid',
    function ( array $payload ): void {
        $invoice_id = $payload['data']['object']['id'] ?? '';
        if ( '' === $invoice_id ) {
            return;
        }
        // Log the raw invoice ID for reconciliation.
        error_log( '[my-plugin] invoice.paid received: ' . $invoice_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
    }
);
```

---

#### `leastudios_payments_webhook_refund_processed`

- **Type:** Action
- **Location:** `src/Checkout/Refund_Handler.php`
- **Since:** 1.0.0
- **Description:** Fires after a `charge.refunded` webhook event has been processed and
  the local order record updated to reflect the refund. This hook covers refunds
  initiated from the **Stripe Dashboard** — for refunds initiated from the WordPress
  admin UI see `leastudios_payments_refund_issued`. The `$charge` array is the Stripe
  Charge object from the event payload, which includes the full `refunds` list.

**Parameters:**

| Parameter         | Type                  | Description                                        |
|-------------------|-----------------------|----------------------------------------------------|
| `$order_id`       | `int`                 | Local order ID                                     |
| `$amount_refunded`| `int`                 | Total cumulative refund in smallest currency unit  |
| `$charge`         | `array<string, mixed>`| Stripe Charge object from the webhook payload      |

**Example:**

```php
// Send an internal Slack notification when a refund arrives from Stripe Dashboard.
add_action(
    'leastudios_payments_webhook_refund_processed',
    function ( int $order_id, int $amount_refunded, array $charge ): void {
        $currency = strtoupper( $charge['currency'] ?? 'usd' );
        $formatted = number_format( $amount_refunded / 100, 2 );
        wp_remote_post(
            get_option( 'my_slack_webhook_url' ),
            [
                'body'    => wp_json_encode( [
                    'text' => "Stripe refund received: order #{$order_id} — {$formatted} {$currency}",
                ] ),
                'headers' => [ 'Content-Type' => 'application/json' ],
            ]
        );
    },
    10,
    3
);
```

---

### Refund

#### `leastudios_payments_refund_args`

- **Type:** Filter
- **Location:** `src/REST/Refund_Controller.php`
- **Since:** 1.0.0
- **Description:** Filters the argument array passed to `\Stripe\Refund::create()`
  before the refund is submitted to Stripe. The base array contains `payment_intent`
  and `amount`. Use this to add a `reason` (Stripe accepts `duplicate`,
  `fraudulent`, or `requested_by_customer`), attach metadata, or override the amount
  under specific business logic.

**Parameters:**

| Parameter     | Type                  | Description                                  |
|---------------|-----------------------|----------------------------------------------|
| `$refund_args`| `array<string, mixed>`| Stripe Refund creation arguments             |
| `$order_id`   | `int`                 | Local order ID                               |
| `$order`      | `object`              | Order row from `wp_leastudios_payments_orders`|

**Returns:** `array<string, mixed>` — the filtered refund arguments.

**Example:**

```php
// Always tag refunds with a reason for Stripe Dashboard clarity.
add_filter(
    'leastudios_payments_refund_args',
    function ( array $refund_args, int $order_id, object $order ): array {
        $refund_args['reason']   = 'requested_by_customer';
        $refund_args['metadata'] = [
            'wp_order_id'   => $order_id,
            'initiated_by'  => get_current_user_id(),
            'site_url'      => get_site_url(),
        ];
        return $refund_args;
    },
    10,
    3
);
```

---

#### `leastudios_payments_refund_issued`

- **Type:** Action
- **Location:** `src/REST/Refund_Controller.php`
- **Since:** 1.0.0
- **Description:** Fires after a refund has been successfully issued through the
  WordPress admin UI and the local order record has been updated. This hook covers
  admin-initiated refunds — for refunds processed from the Stripe Dashboard see
  `leastudios_payments_webhook_refund_processed`.

**Parameters:**

| Parameter    | Type     | Description                                          |
|--------------|----------|------------------------------------------------------|
| `$order_id`  | `int`    | Local order ID                                       |
| `$amount`    | `int`    | Amount refunded in this operation (smallest currency unit) |
| `$new_status`| `string` | New `payment_status`: `refunded` or `partial_refund` |

**Example:**

```php
// Revoke content access when a full refund is issued from the admin.
add_action(
    'leastudios_payments_refund_issued',
    function ( int $order_id, int $amount, string $new_status ): void {
        if ( 'refunded' !== $new_status ) {
            return;
        }
        global $wpdb;
        $orders_table = \LEAStudios\Payments\Database\Migration::table( 'orders' );
        $order = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT wp_user_id FROM {$orders_table} WHERE id = %d",
                $order_id
            )
        );
        if ( $order && $order->wp_user_id ) {
            $user = get_user_by( 'id', (int) $order->wp_user_id );
            if ( $user instanceof WP_User ) {
                $user->remove_role( 'paying_customer' );
            }
        }
    },
    10,
    3
);
```

---

### Stripe Object Sync

#### `leastudios_payments_stripe_customer_args`

- **Type:** Filter
- **Location:** `src/Stripe/Customer_Manager.php`
- **Since:** 1.0.0
- **Description:** Filters the argument array sent to `\Stripe\Customer::create()` when
  a new Stripe Customer is being created for a WordPress user. The base array includes
  `name`, `email`, and a `metadata` block with `source`, `site_url`, and `wp_user_id`.
  Use this to add custom metadata, a description, default tax settings, or a preferred
  locale.

**Parameters:**

| Parameter | Type                  | Description                              |
|-----------|-----------------------|------------------------------------------|
| `$args`   | `array<string, mixed>`| Stripe Customer creation arguments       |
| `$user_id`| `int`                 | WordPress user ID for the new customer   |

**Returns:** `array<string, mixed>` — the filtered creation arguments.

**Example:**

```php
// Add the user's company from profile meta and set the preferred locale.
add_filter(
    'leastudios_payments_stripe_customer_args',
    function ( array $args, int $user_id ): array {
        $company = get_user_meta( $user_id, 'billing_company', true );
        if ( is_string( $company ) && '' !== $company ) {
            $args['description'] = $company;
        }
        $args['metadata']['wp_locale'] = get_user_locale( $user_id );
        return $args;
    },
    10,
    2
);
```

---

#### `leastudios_payments_stripe_price_args`

- **Type:** Filter
- **Location:** `src/Stripe/Product_Sync.php`
- **Since:** 1.0.0
- **Description:** Filters the argument array sent to `\Stripe\Price::create()` when a
  new price is being created for a product. The base array includes `product`,
  `unit_amount`, `currency`, and (for recurring prices) a `recurring` block. Use this
  to add tax behaviour, tier settings, or additional metadata.

**Parameters:**

| Parameter          | Type                  | Description                           |
|--------------------|-----------------------|---------------------------------------|
| `$price_args`      | `array<string, mixed>`| Stripe Price creation arguments       |
| `$local_product_id`| `int`                 | Local product ID owning this price    |

**Returns:** `array<string, mixed>` — the filtered price arguments.

**Example:**

```php
// Tag every price with the local product ID for easier Stripe Dashboard filtering.
add_filter(
    'leastudios_payments_stripe_price_args',
    function ( array $price_args, int $local_product_id ): array {
        $price_args['metadata']['local_product_id'] = $local_product_id;
        return $price_args;
    },
    10,
    2
);
```

---

#### `leastudios_payments_stripe_product_args`

- **Type:** Filter
- **Location:** `src/Stripe/Product_Sync.php`
- **Since:** 1.0.0
- **Description:** Filters the argument array sent to `\Stripe\Product::create()` when
  a new product is being created. The base array includes `name`, `description`,
  `images`, and a `metadata` block. Use this to add a `statement_descriptor`, tag
  with a unit label, or add extra metadata for Stripe Dashboard reporting.

**Parameters:**

| Parameter     | Type                  | Description                            |
|---------------|-----------------------|----------------------------------------|
| `$product_args`| `array<string, mixed>`| Stripe Product creation arguments      |
| `$name`       | `string`              | The product name                       |

**Returns:** `array<string, mixed>` — the filtered product arguments.

**Example:**

```php
// Add a statement descriptor suffix for recognizable bank statements.
add_filter(
    'leastudios_payments_stripe_product_args',
    function ( array $product_args, string $name ): array {
        $product_args['statement_descriptor'] = strtoupper( substr( $name, 0, 22 ) );
        return $product_args;
    },
    10,
    2
);
```

---

#### `leastudios_payments_product_created`

- **Type:** Action
- **Location:** `src/Stripe/Product_Sync.php`
- **Since:** 1.0.0
- **Description:** Fires after a product has been created in Stripe and its local record
  inserted. Both the local ID (for database lookups) and the Stripe ID (for direct API
  calls) are available. Use this to add the product to an external catalogue, cache
  invalidation, or notify a fulfilment system.

**Parameters:**

| Parameter          | Type     | Description                                           |
|--------------------|----------|-------------------------------------------------------|
| `$local_product_id`| `int`    | Local product ID in `wp_leastudios_payments_products` |
| `$stripe_product_id`| `string`| Stripe `prod_*` ID                                   |

**Example:**

```php
// Sync the new product to an external catalogue service.
add_action(
    'leastudios_payments_product_created',
    function ( int $local_product_id, string $stripe_product_id ): void {
        wp_remote_post(
            'https://catalogue.internal.example.com/sync',
            [
                'body'    => wp_json_encode( [
                    'local_id'  => $local_product_id,
                    'stripe_id' => $stripe_product_id,
                    'site_url'  => get_site_url(),
                ] ),
                'headers' => [ 'Content-Type' => 'application/json' ],
            ]
        );
    },
    10,
    2
);
```

---

### Subscription

#### `leastudios_payments_subscription_canceled`

- **Type:** Action
- **Location:** `src/Admin/Subscriptions_Page.php`
- **Since:** 1.0.0
- **Description:** Fires after a subscription is canceled immediately via the WordPress
  admin UI (the "Cancel Now" action on the Subscriptions list). The local record is
  already set to `canceled` when this hook fires. This is distinct from
  `leastudios_payments_subscription_cancel_scheduled` (which queues a cancellation at
  period end) and `leastudios_payments_subscription_synced` (which fires when the
  webhook confirms the cancellation on Stripe's side).

**Parameters:**

| Parameter      | Type     | Description                                                |
|----------------|----------|------------------------------------------------------------|
| `$sub_id`      | `int`    | Local subscription ID                                      |
| `$stripe_sub_id`| `string`| Stripe subscription ID (`sub_*`)                          |

**Example:**

```php
// Revoke access immediately when an admin cancels a subscription.
add_action(
    'leastudios_payments_subscription_canceled',
    function ( int $sub_id, string $stripe_sub_id ): void {
        global $wpdb;
        $subs_table = \LEAStudios\Payments\Database\Migration::table( 'subscriptions' );
        $sub = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT wp_user_id FROM {$subs_table} WHERE id = %d",
                $sub_id
            )
        );
        if ( $sub && $sub->wp_user_id ) {
            $user = get_user_by( 'id', (int) $sub->wp_user_id );
            if ( $user instanceof WP_User ) {
                $user->remove_role( 'subscriber_member' );
            }
        }
    },
    10,
    2
);
```

---

#### `leastudios_payments_subscription_cancel_scheduled`

- **Type:** Action
- **Location:** `src/Admin/Subscriptions_Page.php`
- **Since:** 1.0.0
- **Description:** Fires after a subscription is set to cancel at the end of its current
  billing period (the "Cancel at Period End" action). The Stripe subscription has been
  updated with `cancel_at_period_end = true` and the local record's
  `cancel_at_period_end` column is set to `1`. The subscription remains `active` until
  the period ends; the webhook-driven `leastudios_payments_subscription_synced` hook
  will fire with `canceled` status when Stripe processes the expiry.

**Parameters:**

| Parameter       | Type     | Description                                               |
|-----------------|----------|-----------------------------------------------------------|
| `$sub_id`       | `int`    | Local subscription ID                                     |
| `$stripe_sub_id`| `string` | Stripe subscription ID (`sub_*`)                          |

**Example:**

```php
// Email the customer a reminder that their subscription will expire.
add_action(
    'leastudios_payments_subscription_cancel_scheduled',
    function ( int $sub_id, string $stripe_sub_id ): void {
        global $wpdb;
        $subs_table = \LEAStudios\Payments\Database\Migration::table( 'subscriptions' );
        $sub = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT customer_email, current_period_end FROM {$subs_table} WHERE id = %d",
                $sub_id
            )
        );
        if ( $sub && $sub->customer_email ) {
            wp_mail(
                $sub->customer_email,
                'Your subscription will not renew',
                'Your subscription is active until ' . esc_html( $sub->current_period_end ) . '.'
            );
        }
    },
    10,
    2
);
```

---

#### `leastudios_payments_subscription_invoice_paid`

- **Type:** Action
- **Location:** `src/Checkout/Subscription_Handler.php`
- **Since:** 1.0.0
- **Description:** Fires after an `invoice.paid` webhook is processed for a subscription
  renewal. The local subscription status is set to `active`. Use this hook to extend
  access, record renewal revenue in a third-party system, or send a renewal receipt.
  The `$invoice` array is the full Stripe Invoice object from the webhook payload.

**Parameters:**

| Parameter         | Type                  | Description                                          |
|-------------------|-----------------------|------------------------------------------------------|
| `$subscription_id`| `int`                 | Local subscription ID                                |
| `$invoice`        | `array<string, mixed>`| Stripe Invoice object from the webhook payload       |

**Example:**

```php
// Write a revenue entry to a custom reporting table on each renewal.
add_action(
    'leastudios_payments_subscription_invoice_paid',
    function ( int $subscription_id, array $invoice ): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'my_revenue_log',
            [
                'subscription_id' => $subscription_id,
                'amount_paid'     => (int) ( $invoice['amount_paid'] ?? 0 ),
                'currency'        => $invoice['currency'] ?? 'usd',
                'stripe_invoice'  => $invoice['id'] ?? '',
                'recorded_at'     => current_time( 'mysql', true ),
            ],
            [ '%d', '%d', '%s', '%s', '%s' ]
        );
    },
    10,
    2
);
```

---

#### `leastudios_payments_subscription_payment_failed`

- **Type:** Action
- **Location:** `src/Checkout/Subscription_Handler.php`
- **Since:** 1.0.0
- **Description:** Fires after an `invoice.payment_failed` webhook is processed. The
  local subscription status is set to `past_due`. Use this hook to pause access, notify
  the customer, or trigger a dunning workflow.

**Parameters:**

| Parameter         | Type                  | Description                                         |
|-------------------|-----------------------|-----------------------------------------------------|
| `$subscription_id`| `int`                 | Local subscription ID                               |
| `$invoice`        | `array<string, mixed>`| Stripe Invoice object from the webhook payload      |

**Example:**

```php
// Pause access and flag the user for dunning when a renewal payment fails.
add_action(
    'leastudios_payments_subscription_payment_failed',
    function ( int $subscription_id, array $invoice ): void {
        global $wpdb;
        $subs_table = \LEAStudios\Payments\Database\Migration::table( 'subscriptions' );
        $sub = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT wp_user_id FROM {$subs_table} WHERE id = %d",
                $subscription_id
            )
        );
        if ( $sub && $sub->wp_user_id ) {
            update_user_meta( (int) $sub->wp_user_id, 'my_dunning_since', current_time( 'mysql', true ) );
        }
    },
    10,
    2
);
```

---

#### `leastudios_payments_subscription_synced`

- **Type:** Action
- **Location:** `src/Checkout/Subscription_Handler.php`
- **Since:** 1.0.0
- **Description:** Fires after a `customer.subscription.created`,
  `customer.subscription.updated`, or `customer.subscription.deleted` webhook is
  processed and the local subscription record has been upserted. This is the most
  general subscription hook — it fires for every subscription state change, including
  first creation. The `$status` parameter is the **local** status (see the status map
  in the Concepts section).

**Parameters:**

| Parameter       | Type                  | Description                                             |
|-----------------|-----------------------|---------------------------------------------------------|
| `$stripe_sub_id`| `string`              | Stripe subscription ID (`sub_*`)                        |
| `$status`       | `string`              | Local status (`active`, `past_due`, `canceled`, etc.)   |
| `$subscription` | `array<string, mixed>`| Full Stripe Subscription object from the webhook payload|

**Example:**

```php
// Grant or revoke a WordPress role based on subscription status.
add_action(
    'leastudios_payments_subscription_synced',
    function ( string $stripe_sub_id, string $status, array $subscription ): void {
        $metadata     = $subscription['metadata'] ?? [];
        $wp_user_id   = isset( $metadata['wp_user_id'] ) ? (int) $metadata['wp_user_id'] : 0;
        if ( $wp_user_id <= 0 ) {
            return;
        }
        $user = get_user_by( 'id', $wp_user_id );
        if ( ! $user instanceof WP_User ) {
            return;
        }
        if ( 'active' === $status ) {
            $user->add_role( 'subscriber_member' );
        } else {
            $user->remove_role( 'subscriber_member' );
        }
    },
    10,
    3
);
```

---

### Admin

#### `leastudios_payments_tag_groups`

- **Type:** Filter
- **Location:** `src/Admin/Tags_Reference_Page.php`
- **Since:** 1.0.0
- **Description:** Filters the tag-group definitions shown on the **Payments → Tags**
  reference page. Each group is an associative array with `title` (string), `description`
  (string), optional `shortcode` (string), `tags` (array of `{tag, description, example}`
  entries), and an optional `usage_example` (string). Use this to document custom tags
  your integration adds to confirmation content.

**Parameters:**

| Parameter | Type                        | Description                               |
|-----------|-----------------------------|-------------------------------------------|
| `$groups` | `array<int, array<string, mixed>>` | Array of tag-group definition arrays |

**Returns:** `array<int, array<string, mixed>>` — the filtered group list.

**Example:**

```php
// Register a custom {license_key} tag on the Tags Reference page.
add_filter(
    'leastudios_payments_tag_groups',
    function ( array $groups ): array {
        $groups[] = [
            'title'       => 'License Key Tags',
            'description' => 'Available in confirmation pages for products with licence keys.',
            'shortcode'   => '[leastudios_payment_confirmation]',
            'tags'        => [
                [
                    'tag'         => '{license_key}',
                    'description' => 'The license key generated for this purchase.',
                    'example'     => 'ABCD-1234-EFGH-5678',
                ],
            ],
        ];
        return $groups;
    }
);
```

---

### Lifecycle

#### `leastudios_payments_initialized`

- **Type:** Action
- **Location:** `src/Plugin.php`
- **Since:** 1.0.0
- **Description:** Fires at the end of `Plugin::init()`, after all handlers, REST
  controllers, and admin pages have been registered. The Stripe client instance is
  passed so integrations can call Stripe API methods directly without constructing
  their own client. This is the earliest safe point to interact with the plugin's
  internals; do not call REST endpoints or hook into checkout flow before this action
  fires.

**Parameters:**

| Parameter       | Type                                    | Description                  |
|-----------------|-----------------------------------------|------------------------------|
| `$stripe_client`| `LEAStudios\Payments\Stripe\Stripe_Client` | The initialized Stripe client |

**Example:**

```php
// Register an integration after the payment plugin has fully booted.
add_action(
    'leastudios_payments_initialized',
    function ( \LEAStudios\Payments\Stripe\Stripe_Client $stripe_client ): void {
        // Safe to add hooks that depend on Stripe_Client being ready.
        add_action(
            'leastudios_payments_order_created',
            function ( int $order_id, array $session_data ) use ( $stripe_client ): void {
                // Use $stripe_client here knowing it is initialized.
            },
            10,
            2
        );
    }
);
```

---

## 7. Hook Execution Order

### Checkout flow (payment or subscription)

For a typical purchase the hooks fire in this order across two HTTP requests:

```
POST /wp-json/leastudios-payments/v1/checkout-session
    |
    [filter] leastudios_payments_checkout_session_args
    [action] leastudios_payments_checkout_session_created
    |
    returns client_secret to browser
    (browser loads Stripe Embedded Checkout, customer pays)
    |
POST /wp-json/leastudios-payments/v1/webhook   (Stripe delivery)
    |
    [action] leastudios_payments_webhook_event           (catch-all)
    [action] leastudios_payments_webhook_checkout_session_completed  (dynamic)
                |
                order record created
                |
    [action] leastudios_payments_order_created
```

| Order | Hook | Type | Trigger |
|-------|------|------|---------|
| 1 | `leastudios_payments_checkout_session_args` | Filter | Before Stripe session creation |
| 2 | `leastudios_payments_checkout_session_created` | Action | After Stripe session created |
| 3 | `leastudios_payments_webhook_event` | Action | Webhook received and verified |
| 4 | `leastudios_payments_webhook_checkout_session_completed` | Action (dynamic) | checkout.session.completed event |
| 5 | `leastudios_payments_order_created` | Action | Order row inserted |

### Webhook-triggered subscription hooks

Subscription lifecycle hooks fire independently of the checkout flow, driven entirely by
Stripe webhook delivery:

```
POST /wp-json/leastudios-payments/v1/webhook
    |
    [action] leastudios_payments_webhook_event
    [action] leastudios_payments_webhook_customer_subscription_{created|updated|deleted}
                |
    [action] leastudios_payments_subscription_synced

    --- invoice.paid event ---
    [action] leastudios_payments_webhook_invoice_paid
    [action] leastudios_payments_subscription_invoice_paid

    --- invoice.payment_failed event ---
    [action] leastudios_payments_webhook_invoice_payment_failed
    [action] leastudios_payments_subscription_payment_failed

    --- charge.refunded event ---
    [action] leastudios_payments_webhook_charge_refunded
    [action] leastudios_payments_webhook_refund_processed
```

### Admin-initiated subscription cancellation

```
Admin clicks "Cancel Now" or "Cancel at Period End"
    |
    [action] leastudios_payments_subscription_canceled       (Cancel Now)
       — OR —
    [action] leastudios_payments_subscription_cancel_scheduled  (Cancel at Period End)
    |
    (Stripe later delivers customer.subscription.deleted)
    |
    [action] leastudios_payments_subscription_synced  (status: 'canceled')
```

### Admin-initiated refund

```
Admin issues refund via Payments → Orders
    |
    [filter] leastudios_payments_refund_args
    |
    Stripe\Refund::create()
    |
    [action] leastudios_payments_refund_issued

    (Stripe later delivers charge.refunded webhook)
    |
    [action] leastudios_payments_webhook_refund_processed
```

### Product / price creation (admin)

```
Admin creates product in Payments → Products
    |
    [filter] leastudios_payments_stripe_product_args
    Stripe\Product::create()
    |
    [filter] leastudios_payments_stripe_price_args    (once per price)
    Stripe\Price::create()
    |
    [action] leastudios_payments_product_created
```

---

## 8. REST API Reference

Namespace: `leastudios-payments/v1`

| Method | Route | Description | Capability |
|--------|-------|-------------|------------|
| `POST` | `/checkout-session` | Create an Embedded Checkout Session | `is_user_logged_in()` |
| `POST` | `/webhook` | Receive a verified Stripe webhook event | Stripe signature (no WP cap) |
| `GET`  | `/products` | List active products with prices (block editor) | `edit_posts` |
| `POST` | `/refund` | Issue a refund for an order | `manage_options` |
| `POST` | `/portal-session` | Create a Stripe Billing Portal session | `is_user_logged_in()` |

### `POST /checkout-session`

- **Endpoint:** `/wp-json/leastudios-payments/v1/checkout-session`
- **Controller:** `src/REST/Checkout_Controller.php`
- **Capability:** Must be logged in (`is_user_logged_in()`)
- **Query parameters:** none
- **Request body:**

  | Field | Type | Required | Description |
  |-------|------|----------|-------------|
  | `price_id` | `integer` | Yes | Local price ID to purchase |
  | `return_url` | `string` | Yes | URL to redirect to after checkout; must be same-host |

- **Response (200):**

  ```json
  {
    "success": true,
    "client_secret": "cs_test_a1b2c3..."
  }
  ```

- **Response (400):** `{ "success": false, "message": "..." }` — invalid price, guest user, Stripe not configured, or off-site `return_url`.
- **Response (429):** `{ "success": false, "message": "Too many requests..." }` — rate limit exceeded (10 requests/min/IP).
- **Example:**

  ```bash
  curl -s -X POST https://leastudios-plugins.test/wp-json/leastudios-payments/v1/checkout-session \
    -H "Content-Type: application/json" \
    -H "X-WP-Nonce: $(wp eval 'echo wp_create_nonce("wp_rest");' --user=2)" \
    -d '{"price_id": 3, "return_url": "https://leastudios-plugins.test/confirmation/"}'
  ```

---

### `POST /webhook`

- **Endpoint:** `/wp-json/leastudios-payments/v1/webhook`
- **Controller:** `src/REST/Webhook_Controller.php`
- **Capability:** None — authenticated by Stripe signature (`Stripe-Signature` header)
- **Query parameters:** none
- **Request body:** Raw Stripe event JSON (sent by Stripe, not by your code)
- **Response (200):**

  ```json
  { "received": true }
  ```

- **Response (200 — duplicate):**

  ```json
  { "received": true, "duplicate": true }
  ```

- **Response (200 — unhandled event):**

  ```json
  { "received": true, "ignored": true }
  ```

- **Response (400):** Missing or invalid signature, or missing webhook secret in plugin settings.
- **Note:** Do not call this endpoint yourself. Configure the Stripe Dashboard or Stripe
  CLI to POST Stripe events to this URL.

---

### `GET /products`

- **Endpoint:** `/wp-json/leastudios-payments/v1/products`
- **Controller:** `src/REST/Products_Controller.php`
- **Capability:** `edit_posts` (editors and above; used by the block editor)
- **Query parameters:** none
- **Request body:** none
- **Response (200):**

  ```json
  [
    {
      "id": 1,
      "name": "Pro Plan",
      "description": "Full access to all features.",
      "prices": [
        {
          "id": 2,
          "stripe_price_id": "price_1Abc123",
          "amount": 2900,
          "currency": "usd",
          "type": "recurring",
          "interval": "month",
          "interval_count": 1
        }
      ]
    }
  ]
  ```

- **Example:**

  ```bash
  curl -s https://leastudios-plugins.test/wp-json/leastudios-payments/v1/products \
    -H "X-WP-Nonce: $(wp eval 'echo wp_create_nonce("wp_rest");' --user=2)"
  ```

---

### `POST /refund`

- **Endpoint:** `/wp-json/leastudios-payments/v1/refund`
- **Controller:** `src/REST/Refund_Controller.php`
- **Capability:** `manage_options` (admin only)
- **Query parameters:** none
- **Request body:**

  | Field | Type | Required | Description |
  |-------|------|----------|-------------|
  | `order_id` | `integer` | Yes | Local order ID |
  | `amount` | `integer` | Yes | Amount to refund in smallest currency unit (e.g. cents) |

- **Response (200):**

  ```json
  { "success": true, "message": "Refund issued successfully." }
  ```

- **Response (400/404/409/500):** `{ "success": false, "message": "..." }` — order not found, invalid amount, concurrent refund lock, or Stripe error.
- **Example:**

  ```bash
  curl -s -X POST https://leastudios-plugins.test/wp-json/leastudios-payments/v1/refund \
    -H "Content-Type: application/json" \
    -H "X-WP-Nonce: $(wp eval 'echo wp_create_nonce("wp_rest");' --user=1)" \
    -d '{"order_id": 42, "amount": 2900}'
  ```

---

### `POST /portal-session`

- **Endpoint:** `/wp-json/leastudios-payments/v1/portal-session`
- **Controller:** `src/REST/Portal_Controller.php`
- **Capability:** Must be logged in (`is_user_logged_in()`)
- **Query parameters:** none
- **Request body:**

  | Field | Type | Required | Description |
  |-------|------|----------|-------------|
  | `return_url` | `string` | No | URL to return to after the portal; defaults to `home_url()`; must be same-host if provided |

- **Response (200):**

  ```json
  { "success": true, "url": "https://billing.stripe.com/session/..." }
  ```

- **Response (404):** No Stripe customer found for the current user.
- **Response (500):** Stripe not configured or API error.
- **Example:**

  ```bash
  curl -s -X POST https://leastudios-plugins.test/wp-json/leastudios-payments/v1/portal-session \
    -H "Content-Type: application/json" \
    -H "X-WP-Nonce: $(wp eval 'echo wp_create_nonce("wp_rest");' --user=2)" \
    -d '{"return_url": "https://leastudios-plugins.test/account/"}'
  ```

---

## 9. Extension Recipes

### How do I add custom metadata to every Stripe payment intent?

**Goal:** Attach business-specific metadata (e.g. affiliate code, campaign ID) to every
Stripe Payment Intent so it is visible in the Stripe Dashboard and in webhook payloads.

**Hooks used:** `leastudios_payments_checkout_session_args`.

**Walkthrough:** The `leastudios_payments_checkout_session_args` filter receives the
full Checkout Session creation arguments before the API call is made. For one-time
payments the plugin already sets `payment_intent_data.description`; you can extend that
block with a `metadata` key. For subscriptions the equivalent key is
`subscription_data.metadata`.

Reading the price type from the session args themselves lets a single filter handle both
modes. Note that the `$user_id` parameter lets you pull user-specific data (affiliate
code stored in user meta) without a separate database query later.

**Complete example:**

```php
add_filter(
    'leastudios_payments_checkout_session_args',
    function ( array $session_args, int $price_id, int $user_id ): array {
        $affiliate_code = get_user_meta( $user_id, 'my_affiliate_code', true );
        $extra_meta     = [
            'affiliate_code' => is_string( $affiliate_code ) ? $affiliate_code : '',
            'campaign'       => sanitize_text_field( $_COOKIE['utm_campaign'] ?? '' ),
        ];

        if ( 'payment' === ( $session_args['mode'] ?? '' ) ) {
            $session_args['payment_intent_data']['metadata'] = array_merge(
                $session_args['payment_intent_data']['metadata'] ?? [],
                $extra_meta
            );
        } else {
            $session_args['subscription_data']['metadata'] = array_merge(
                $session_args['subscription_data']['metadata'] ?? [],
                $extra_meta
            );
        }

        return $session_args;
    },
    10,
    3
);
```

---

### How do I send a completed purchase to a CRM?

**Goal:** Push order data (customer email, amount, product name) to an external CRM
every time a purchase is completed.

**Hooks used:** `leastudios_payments_order_created`.

**Walkthrough:** `leastudios_payments_order_created` fires after the order row is
written to the local database. The `$session_data` array is the raw Stripe Checkout
Session payload from the `checkout.session.completed` webhook event. It contains
`customer_details.email`, `customer_details.name`, `amount_total`, `currency`, and the
full `line_items` collection.

Use `wp_remote_post()` with `'blocking' => false` so the webhook handler returns 200 to
Stripe quickly. Stripe retries any webhook that does not receive a 200 within a few
seconds, so long outbound calls inside the hook body cause phantom duplicates.

**Complete example:**

```php
add_action(
    'leastudios_payments_order_created',
    function ( int $order_id, array $session_data ): void {
        $email    = sanitize_email( $session_data['customer_details']['email'] ?? '' );
        $name     = sanitize_text_field( $session_data['customer_details']['name'] ?? '' );
        $amount   = (int) ( $session_data['amount_total'] ?? 0 );
        $currency = strtoupper( $session_data['currency'] ?? 'USD' );

        $first_item    = $session_data['line_items']['data'][0] ?? [];
        $product_name  = sanitize_text_field( $first_item['description'] ?? '' );

        wp_remote_post(
            'https://crm.internal.example.com/api/contacts/purchase',
            [
                'headers'  => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . get_option( 'my_crm_api_key' ),
                ],
                'body'     => wp_json_encode( [
                    'email'        => $email,
                    'name'         => $name,
                    'product'      => $product_name,
                    'amount_cents' => $amount,
                    'currency'     => $currency,
                    'order_id'     => $order_id,
                ] ),
                'blocking' => false,
            ]
        );
    },
    10,
    2
);
```

---

### How do I block specific countries at checkout?

**Goal:** Prevent customers in certain countries from reaching the checkout form, while
still allowing them to browse the site.

**Hooks used:** `leastudios_payments_checkout_session_args`.

**Walkthrough:** The `leastudios_payments_checkout_session_args` filter fires before the
Checkout Session is created. Stripe validates IP address and card country — but that
happens after the form loads. A better UX is to reject the session creation request
server-side and return a localised error before the browser ever loads the Stripe iframe.

Return a modified array with a custom key, then abort in the same filter by throwing an
exception — but note: the filter contract expects the array back, not an error. The
correct approach is to leave the session args unmodified and instead add a validation
hook earlier. The cleanest pattern is to add a `pre_` check and use `wp_die()` or
redirect from a `template_redirect` hook keyed on the relevant page. For server-side
enforcement within the REST endpoint, use the shipping countries filter to whitelist the
markets you serve, which causes Stripe to present only the allowed countries in the
shipping form.

**Complete example:**

```php
// Restrict the list of allowed shipping destinations to supported markets.
add_filter(
    'leastudios_payments_shipping_countries',
    function ( array $countries ): array {
        // Remove Russia and Belarus from the default shipping list.
        return array_values(
            array_filter( $countries, static fn( string $code ): bool => ! in_array( $code, [ 'RU', 'BY' ], true ) )
        );
    }
);

// Block checkout session creation entirely for users whose profile country is restricted.
add_filter(
    'leastudios_payments_checkout_session_args',
    function ( array $session_args, int $price_id, int $user_id ): array {
        $blocked = [ 'RU', 'BY' ];
        $country = strtoupper( (string) get_user_meta( $user_id, 'billing_country', true ) );
        if ( in_array( $country, $blocked, true ) ) {
            // Return args with an invalid mode to force a Stripe API error,
            // which Session_Factory converts to a friendly user-facing error.
            // A cleaner approach is to add the country check before calling
            // Session_Factory::create() in your own middleware plugin.
            $session_args['_blocked'] = true;
        }
        return $session_args;
    },
    10,
    3
);
```

---

### How do I react to a successful payment from another plugin?

**Goal:** Allow a sibling or third-party plugin to hook into the payment completion
event without depending on leastudios-payments being loaded first.

**Hooks used:** `leastudios_payments_initialized`, `leastudios_payments_order_created`.

**Walkthrough:** Use `leastudios_payments_initialized` as a guard: wrap your
`add_action( 'leastudios_payments_order_created', ... )` call inside it. This ensures
the payments plugin is fully booted before you attach your listener, and provides a
clean activation-order guarantee regardless of plugin load order. The `$stripe_client`
parameter lets you make supplementary Stripe API calls (e.g. attaching a coupon to the
customer) inside the order hook if needed.

**Complete example:**

```php
// In your own plugin's main file or bootstrap:

add_action(
    'leastudios_payments_initialized',
    function ( \LEAStudios\Payments\Stripe\Stripe_Client $stripe_client ): void {

        add_action(
            'leastudios_payments_order_created',
            static function ( int $order_id, array $session_data ): void {
                // Grant a "founding member" badge to users who purchased before a cutoff date.
                $cutoff   = strtotime( '2026-07-01' );
                $purchased = strtotime( $session_data['created'] ?? 'now' );

                if ( $purchased < $cutoff ) {
                    $wp_user_id = (int) ( $session_data['metadata']['wp_user_id'] ?? 0 );
                    if ( $wp_user_id > 0 ) {
                        update_user_meta( $wp_user_id, 'my_founding_member', '1' );
                    }
                }
            },
            10,
            2
        );
    }
);
```

---

### How do I grant and revoke a membership role based on subscription status?

**Goal:** Automatically add a WordPress role when a subscription goes active and remove
it when the subscription is canceled or lapses.

**Hooks used:** `leastudios_payments_subscription_synced`,
`leastudios_payments_subscription_canceled`.

**Walkthrough:** `leastudios_payments_subscription_synced` fires for every subscription
state change coming from a Stripe webhook (created, updated, deleted). The `$status`
parameter is the mapped local status. A single handler that adds the role on `active`
and removes it on `canceled` or `past_due` covers the full lifecycle.

`leastudios_payments_subscription_canceled` covers the admin-initiated "Cancel Now"
path, which does not go through the webhook syncing flow synchronously. Wire both hooks
to the same role-management function to ensure parity between admin actions and
webhook-driven changes.

**Complete example:**

```php
function my_manage_membership_role( int $wp_user_id, string $status ): void {
    if ( $wp_user_id <= 0 ) {
        return;
    }
    $user = get_user_by( 'id', $wp_user_id );
    if ( ! $user instanceof WP_User ) {
        return;
    }
    if ( 'active' === $status || 'trialing' === $status ) {
        $user->add_role( 'member' );
    } else {
        $user->remove_role( 'member' );
    }
}

// Webhook-driven state changes.
add_action(
    'leastudios_payments_subscription_synced',
    function ( string $stripe_sub_id, string $status, array $subscription ): void {
        $wp_user_id = (int) ( $subscription['metadata']['wp_user_id'] ?? 0 );
        my_manage_membership_role( $wp_user_id, $status );
    },
    10,
    3
);

// Admin "Cancel Now" path.
add_action(
    'leastudios_payments_subscription_canceled',
    function ( int $sub_id, string $stripe_sub_id ): void {
        global $wpdb;
        $subs_table = \LEAStudios\Payments\Database\Migration::table( 'subscriptions' );
        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT wp_user_id FROM {$subs_table} WHERE id = %d",
                $sub_id
            )
        );
        if ( $row && $row->wp_user_id ) {
            my_manage_membership_role( (int) $row->wp_user_id, 'canceled' );
        }
    },
    10,
    2
);
```

---

### How do I add custom merge tags to the confirmation page?

**Goal:** Expose a `{license_key}` tag in the `[leastudios_payment_confirmation]`
shortcode that is replaced with a generated license key tied to the order.

**Hooks used:** `leastudios_payments_confirmation_tags`,
`leastudios_payments_tag_groups`.

**Walkthrough:** `leastudios_payments_confirmation_tags` receives an array of `{tag}`
keys mapped to escaped replacement strings, along with the raw Stripe session data.
Generate or look up the license key using the Stripe Session ID as a deterministic
identifier. Cache the result in a transient keyed on the session ID so the value
survives a page refresh.

Register the tag in `leastudios_payments_tag_groups` so site operators know it is
available when configuring confirmation page content.

**Complete example:**

```php
// Register the tag in the admin Tags Reference page.
add_filter(
    'leastudios_payments_tag_groups',
    function ( array $groups ): array {
        $groups[] = [
            'title'       => 'License Key Tags',
            'description' => 'Available on the confirmation page for purchases that include a license key.',
            'shortcode'   => '[leastudios_payment_confirmation]',
            'tags'        => [
                [
                    'tag'         => '{license_key}',
                    'description' => 'The unique license key generated for this purchase.',
                    'example'     => 'ABCD-1234-EFGH-5678',
                ],
            ],
        ];
        return $groups;
    }
);

// Replace {license_key} when the confirmation shortcode renders.
add_filter(
    'leastudios_payments_confirmation_tags',
    function ( array $tags, array $session_data ): array {
        $session_id  = $session_data['id'] ?? '';
        $cache_key   = 'my_license_' . md5( $session_id );
        $license_key = get_transient( $cache_key );

        if ( false === $license_key ) {
            // Generate a deterministic key from the session ID.
            $license_key = strtoupper( implode( '-', str_split( substr( md5( $session_id ), 0, 16 ), 4 ) ) );
            set_transient( $cache_key, $license_key, WEEK_IN_SECONDS );
        }

        $tags['{license_key}'] = esc_html( (string) $license_key );
        return $tags;
    },
    10,
    2
);
```

---

## 10. Testing

```bash
cd wp-content/plugins/leastudios-payments
composer test                                   # run the full suite
vendor/bin/phpunit --filter CheckoutHandlerTest # one class
vendor/bin/phpunit tests/CheckoutHandlerTest.php # one file
```

### Bootstrapping in extension tests

The plugin's test suite uses the standard WordPress test library at
`/tmp/wordpress-tests-lib/`. If you are writing tests for an extension that loads
leastudios-payments, add the following to your extension's `tests/bootstrap.php`:

```php
// Load the WordPress test library.
$wp_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';
require_once $wp_tests_dir . '/includes/functions.php';

// Activate leastudios-payments before the test suite loads.
tests_add_filter(
    'muplugins_loaded',
    static function (): void {
        require dirname( __DIR__, 2 ) . '/leastudios-payments/leastudios-payments.php';
    }
);

require_once $wp_tests_dir . '/includes/bootstrap.php';
```

Install the shared WP test library once with:

```bash
bash ../leastudios-dev-tools/bin/install-wp-tests.sh \
    wordpress_test root '' 127.0.0.1 latest
```

### Testing webhooks locally

Use the Stripe CLI to replay events into the local site:

```bash
stripe listen --forward-to https://leastudios-plugins.test/wp-json/leastudios-payments/v1/webhook

# Replay a specific event type:
stripe trigger checkout.session.completed
```

The CLI prints the signing secret; paste it into **Payments → Settings → Webhook Signing
Secret** to allow signature verification to pass.

---

## 11. Release Process

This plugin uses a tag-triggered release workflow (`.github/workflows/release.yml`)
that auto-generates release notes from the commit log between the previous and
current tag.

**To cut a release:** bump the `Version:` header in the main plugin file, commit, then:

```bash
git tag vX.Y.Z && git push origin vX.Y.Z
```

**Commit-prefix → release-notes section:**

- `feat:` → `## Added`
- `fix:` → `## Fixed`
- `refactor:` → `## Changed`
- `perf:` → `## Performance`

**Hidden from release notes:** `ci:`, `chore:`, `docs:`, `test:`, `style:`, `build:`, `release:`.

---

## 12. Where to Read More

- [`CLAUDE.md`](../CLAUDE.md) — this plugin's repo conventions and release workflow.
- [`README.md`](../README.md) — user-facing overview and installation guide.
- [`leastudios-dev-tools/CLAUDE.md`](../../leastudios-dev-tools/CLAUDE.md) — suite-wide
  coding standards, security rules, and database conventions inherited by every plugin.
- [`leastudios-email-templates` developer handbook](../../leastudios-email-templates/docs/developer-handbook.md) — how to hook into transactional email rendering; relevant because payment events drive most of those emails.
- [`leastudios-mailer` developer handbook](../../leastudios-mailer/docs/developer-handbook.md) — the Amazon SES transport layer used to deliver payment emails.
