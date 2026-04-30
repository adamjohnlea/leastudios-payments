<?php
/**
 * Handles completed checkout sessions.
 *
 * @package LEAStudios\Payments\Checkout
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Checkout;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Payments\Database\Order_Repository;
use LEAStudios\Payments\Stripe\Customer_Manager;
use LEAStudios\Payments\Stripe\Stripe_Client;

/**
 * Processes the checkout.session.completed webhook event and records orders.
 */
class Checkout_Handler {

	/**
	 * Constructor.
	 *
	 * @param Stripe_Client    $stripe_client    The Stripe client.
	 * @param Order_Repository $order_repository The order repository.
	 * @param Customer_Manager $customer_manager Maps Stripe customers to WP users.
	 */
	public function __construct(
		private readonly Stripe_Client $stripe_client,
		private readonly Order_Repository $order_repository,
		private readonly Customer_Manager $customer_manager,
	) {}

	/**
	 * Register the webhook handler.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'leastudios_payments_webhook_checkout_session_completed', [ $this, 'handle_session_completed' ] );
	}

	/**
	 * Handle the checkout.session.completed event.
	 *
	 * @param array<string, mixed> $payload The full event payload.
	 * @return void
	 */
	public function handle_session_completed( array $payload ): void {
		$session_data = $payload['data']['object'] ?? [];

		if ( empty( $session_data['id'] ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[leaStudios Payments] checkout.session.completed: missing session ID' );
			return;
		}

		$session_id = sanitize_text_field( $session_data['id'] );

		// Prevent duplicate processing.
		$existing = $this->order_repository->get_by_session_id( $session_id );

		if ( null !== $existing ) {
			return;
		}

		// Initialize Stripe to fetch line items.
		if ( ! $this->stripe_client->initialize() ) {
			return;
		}

		// Fetch expanded line items from the session.
		$line_items_json = '[]';

		try {
			$line_items      = \Stripe\Checkout\Session::allLineItems( $session_id, [ 'limit' => 100 ] );
			$line_items_data = [];

			foreach ( $line_items->data as $item ) {
				$line_items_data[] = [
					'description' => $item->description ?? '',
					'quantity'    => $item->quantity ?? 1,
					'amount'      => $item->amount_total ?? 0,
					'currency'    => $item->currency ?? 'usd',
					'price_id'    => $item->price->id ?? '',
					'product_id'  => $item->price->product ?? '',
				];
			}

			$line_items_json = (string) wp_json_encode( $line_items_data );
		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			// Non-fatal: we still record the order without detailed line items.
			$line_items_json = '[]';
		}

		// Extract customer details.
		$customer_id    = is_string( $session_data['customer'] ?? null ) ? $session_data['customer'] : '';
		$customer_email = $session_data['customer_details']['email'] ?? ( $session_data['customer_email'] ?? '' );
		$customer_name  = $session_data['customer_details']['name'] ?? '';

		// Determine order type from the session mode.
		$order_type = 'subscription' === ( $session_data['mode'] ?? 'payment' ) ? 'subscription' : 'one_time';

		// Resolve the WP user id via the verified customer mapping. The
		// `metadata.wp_user_id` claim is attacker-influenceable, so we only
		// trust it if Customer_Manager confirms the local mapping; otherwise
		// we fall back to a reverse lookup, and finally to null.
		$metadata        = $session_data['metadata'] ?? [];
		$claimed_user_id = isset( $metadata['wp_user_id'] ) ? (int) $metadata['wp_user_id'] : null;
		$wp_user_id      = $this->customer_manager->resolve_user_id( $customer_id, $claimed_user_id );

		// Create the order record.
		$order_id = $this->order_repository->create(
			[
				'stripe_session_id'        => $session_id,
				'stripe_payment_intent_id' => $session_data['payment_intent'] ?? '',
				'stripe_customer_id'       => $customer_id,
				'customer_email'           => sanitize_email( $customer_email ),
				'customer_name'            => sanitize_text_field( $customer_name ),
				'wp_user_id'               => $wp_user_id,
				'amount_total'             => (int) ( $session_data['amount_total'] ?? 0 ),
				'currency'                 => sanitize_text_field( $session_data['currency'] ?? 'usd' ),
				'payment_status'           => 'paid',
				'order_type'               => $order_type,
				'line_items_json'          => $line_items_json,
			]
		);

		if ( 0 === $order_id ) {
			// On many shared hosts the PHP error log is web-accessible, so we
			// log only a stable error code in production. The verbose form,
			// including $wpdb->last_error, is gated on WP_DEBUG.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				global $wpdb;
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[leaStudios Payments] Failed to create order for session: ' . $session_id . ' | Last DB error: ' . $wpdb->last_error );
			} else {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[leaStudios Payments] order_create_failed for session_id=' . $session_id );
			}
		}

		if ( $order_id > 0 ) {
			/**
			 * Fires after an order is recorded from a completed checkout session.
			 *
			 * @since 1.0.0
			 *
			 * @param int   $order_id     The local order ID.
			 * @param array $session_data The Stripe session data.
			 */
			do_action( 'leastudios_payments_order_created', $order_id, $session_data );
		}
	}
}
