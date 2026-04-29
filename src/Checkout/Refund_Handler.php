<?php
/**
 * Handles refund webhook events.
 *
 * @package LEAStudios\Payments\Checkout
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Checkout;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Payments\Database\Order_Repository;

/**
 * Processes the charge.refunded webhook event.
 */
class Refund_Handler {

	/**
	 * Constructor.
	 *
	 * @param Order_Repository $order_repository The order repository.
	 */
	public function __construct(
		private readonly Order_Repository $order_repository,
	) {}

	/**
	 * Register the webhook handler.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'leastudios_payments_webhook_charge_refunded', [ $this, 'handle_charge_refunded' ] );
	}

	/**
	 * Handle the charge.refunded event.
	 *
	 * Updates the local order record to reflect the refund from Stripe.
	 * This handles refunds made directly in the Stripe Dashboard.
	 *
	 * @param array<string, mixed> $payload The full event payload.
	 * @return void
	 */
	public function handle_charge_refunded( array $payload ): void {
		$charge = $payload['data']['object'] ?? [];

		if ( empty( $charge['payment_intent'] ) ) {
			return;
		}

		$payment_intent_id = sanitize_text_field( $charge['payment_intent'] );

		// Find the order by payment intent.
		global $wpdb;
		$table = \LEAStudios\Payments\Database\Migration::table( 'orders' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$order = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE stripe_payment_intent_id = %s",
				$payment_intent_id
			)
		);

		if ( ! $order ) {
			return;
		}

		// Calculate total refunded from the charge object.
		$amount_refunded = (int) ( $charge['amount_refunded'] ?? 0 );
		$amount_total    = (int) $order->amount_total;
		$new_status      = $amount_refunded >= $amount_total ? 'refunded' : 'partial_refund';

		$this->order_repository->update(
			(int) $order->id,
			[
				'refunded_amount' => $amount_refunded,
				'payment_status'  => $new_status,
			]
		);

		/**
		 * Fires after a refund webhook is processed.
		 *
		 * @since 1.0.0
		 *
		 * @param int   $order_id         The local order ID.
		 * @param int   $amount_refunded  Total amount refunded.
		 * @param array $charge           The Stripe charge data.
		 */
		do_action( 'leastudios_payments_webhook_refund_processed', (int) $order->id, $amount_refunded, $charge );
	}
}
