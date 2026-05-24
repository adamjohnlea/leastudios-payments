<?php
/**
 * Registry of Stripe webhook event types this plugin handles.
 *
 * @package LEAStudios\Payments\Webhook
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Webhook;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for which Stripe webhook events the plugin reacts to.
 *
 * Two consumers read this list:
 *
 *   1. {@see \LEAStudios\Payments\REST\Webhook_Controller::handle_webhook()}
 *      uses {@see self::is_handled()} as a gate — events not listed here are
 *      acknowledged with 200 OK but never dispatched to action hooks. A
 *      handler whose event is missing from this map will never fire, which
 *      surfaces drift the first time the handler is tested.
 *   2. {@see \LEAStudios\Payments\Admin\Settings_Page} renders the map next
 *      to the webhook URL so site operators know which events to enable on
 *      the Stripe endpoint.
 *
 * When wiring up a new `add_action( 'leastudios_payments_webhook_*', ... )`
 * handler, add the corresponding Stripe event type here.
 */
final class Webhook_Events {

	/**
	 * Map of Stripe event type => human-readable description.
	 *
	 * Descriptions are translator-ready strings used by the settings UI.
	 *
	 * @var array<string, string>
	 */
	public const HANDLED = [
		'checkout.session.completed'    => 'Records the order and triggers receipt emails.',
		'customer.subscription.created' => 'Creates the local subscription record.',
		'customer.subscription.updated' => 'Syncs status, plan, and renewal date changes.',
		'customer.subscription.deleted' => 'Marks the subscription as canceled.',
		'invoice.paid'                  => 'Records subscription renewal payments.',
		'invoice.payment_failed'        => 'Flags the subscription as past-due and notifies the customer.',
		'charge.refunded'               => 'Records refunds issued from Stripe or the WordPress admin.',
	];

	/**
	 * Whether the given Stripe event type is handled by this plugin.
	 *
	 * @param string $event_type Stripe event type (e.g. `checkout.session.completed`).
	 * @return bool True if the event has a corresponding action-hook handler.
	 */
	public static function is_handled( string $event_type ): bool {
		return isset( self::HANDLED[ $event_type ] );
	}
}
