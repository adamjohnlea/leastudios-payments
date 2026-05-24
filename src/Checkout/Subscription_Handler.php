<?php
/**
 * Handles subscription-related webhook events.
 *
 * @package LEAStudios\Payments\Checkout
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Checkout;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Payments\Database\Subscription_Repository;
use LEAStudios\Payments\Stripe\Customer_Manager;
use LEAStudios\Payments\Stripe\Stripe_Client;

/**
 * Processes subscription webhook events and keeps local records in sync.
 */
class Subscription_Handler {

	/**
	 * Constructor.
	 *
	 * @param Subscription_Repository $subscription_repository The subscription repository.
	 * @param Stripe_Client           $stripe_client           The Stripe client.
	 * @param Customer_Manager        $customer_manager        Maps Stripe customers to WP users.
	 */
	public function __construct(
		private readonly Subscription_Repository $subscription_repository,
		private readonly Stripe_Client $stripe_client,
		private readonly Customer_Manager $customer_manager,
	) {}

	/**
	 * Register webhook handlers.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'leastudios_payments_webhook_customer_subscription_created', [ $this, 'handle_subscription_change' ] );
		add_action( 'leastudios_payments_webhook_customer_subscription_updated', [ $this, 'handle_subscription_change' ] );
		add_action( 'leastudios_payments_webhook_customer_subscription_deleted', [ $this, 'handle_subscription_change' ] );
		add_action( 'leastudios_payments_webhook_invoice_paid', [ $this, 'handle_invoice_paid' ] );
		add_action( 'leastudios_payments_webhook_invoice_payment_failed', [ $this, 'handle_invoice_payment_failed' ] );
	}

	/**
	 * Handle subscription created, updated, or deleted events.
	 *
	 * @param array<string, mixed> $payload The full event payload.
	 * @return void
	 */
	public function handle_subscription_change( array $payload ): void {
		$subscription = $payload['data']['object'] ?? [];

		if ( empty( $subscription['id'] ) ) {
			return;
		}

		$stripe_sub_id  = sanitize_text_field( $subscription['id'] );
		$customer_id    = sanitize_text_field( $subscription['customer'] ?? '' );
		$status         = sanitize_text_field( $subscription['status'] ?? 'active' );
		$cancel_pending = ! empty( $subscription['cancel_at_period_end'] );

		// Get the primary price ID from subscription items.
		$stripe_price_id = '';
		$items           = $subscription['items']['data'] ?? [];

		if ( ! empty( $items[0]['price']['id'] ) ) {
			$stripe_price_id = sanitize_text_field( $items[0]['price']['id'] );
		}

		// Resolve customer email by initializing Stripe and fetching the customer.
		$customer_email = '';

		if ( '' !== $customer_id && $this->stripe_client->initialize() ) {
			try {
				$customer       = \Stripe\Customer::retrieve( $customer_id );
				$customer_email = $customer->email ?? '';
			} catch ( \Stripe\Exception\ApiErrorException $e ) {
				$customer_email = '';
			}
		}

		// Resolve WP user id via the verified customer mapping. The
		// `metadata.wp_user_id` claim is attacker-influenceable, so we only
		// trust it if Customer_Manager confirms the local mapping; otherwise
		// we fall back to a reverse lookup, and finally to null.
		$metadata        = $subscription['metadata'] ?? [];
		$claimed_user_id = isset( $metadata['wp_user_id'] ) ? (int) $metadata['wp_user_id'] : null;
		$wp_user_id      = $this->customer_manager->resolve_user_id( $customer_id, $claimed_user_id );

		// Convert timestamps. As of Stripe API 2025-04-30, current_period_*
		// moved from the Subscription object to the SubscriptionItem level.
		// This plugin only creates single-item subscriptions, so we read
		// from items[0]; multi-item subscriptions would need per-item handling.
		$period_start = isset( $items[0]['current_period_start'] )
			? gmdate( 'Y-m-d H:i:s', (int) $items[0]['current_period_start'] )
			: null;

		$period_end = isset( $items[0]['current_period_end'] )
			? gmdate( 'Y-m-d H:i:s', (int) $items[0]['current_period_end'] )
			: null;

		// Map Stripe status to our local status. An unknown status falls
		// back to `incomplete` (a safe non-active default) and is logged
		// when WP_DEBUG is on so a future Stripe schema change is visible.
		$local_status = match ( $status ) {
			'active'             => 'active',
			'past_due'           => 'past_due',
			'canceled'           => 'canceled',
			'unpaid'             => 'past_due',
			'trialing'           => 'trialing',
			'incomplete'         => 'incomplete',
			'incomplete_expired' => 'canceled',
			'paused'             => 'paused',
			default              => 'incomplete',
		};

		if ( 'incomplete' === $local_status && '' !== $status && 'incomplete' !== $status && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[leaStudios Payments] Unknown Stripe subscription status: ' . $status );
		}

		$this->subscription_repository->upsert(
			[
				'stripe_subscription_id' => $stripe_sub_id,
				'stripe_customer_id'     => $customer_id,
				'stripe_price_id'        => $stripe_price_id,
				'customer_email'         => sanitize_email( $customer_email ),
				'wp_user_id'             => $wp_user_id,
				'status'                 => $local_status,
				'current_period_start'   => $period_start,
				'current_period_end'     => $period_end,
				'cancel_at_period_end'   => $cancel_pending ? 1 : 0,
			]
		);

		/**
		 * Fires after a subscription record is synced from a webhook.
		 *
		 * @since 1.0.0
		 *
		 * @param string $stripe_sub_id The Stripe subscription ID.
		 * @param string $status        The subscription status.
		 * @param array  $subscription  The Stripe subscription data.
		 */
		do_action( 'leastudios_payments_subscription_synced', $stripe_sub_id, $local_status, $subscription );
	}

	/**
	 * Handle invoice.paid — update subscription period and status.
	 *
	 * @param array<string, mixed> $payload The full event payload.
	 * @return void
	 */
	public function handle_invoice_paid( array $payload ): void {
		$invoice = $payload['data']['object'] ?? [];

		// As of Stripe API 2025-09-30 (clover), invoice.subscription moved to
		// invoice.parent.subscription_details.subscription.
		$subscription_id = $invoice['parent']['subscription_details']['subscription'] ?? '';

		if ( '' === $subscription_id ) {
			return;
		}

		$existing = $this->subscription_repository->get_by_stripe_id( sanitize_text_field( $subscription_id ) );

		if ( null === $existing ) {
			return;
		}

		// Update status to active (payment succeeded).
		$this->subscription_repository->update(
			(int) $existing->id,
			[ 'status' => 'active' ]
		);

		/**
		 * Fires after an invoice payment is recorded for a subscription.
		 *
		 * @since 1.0.0
		 *
		 * @param int   $subscription_id The local subscription ID.
		 * @param array $invoice         The Stripe invoice data.
		 */
		do_action( 'leastudios_payments_subscription_invoice_paid', (int) $existing->id, $invoice );
	}

	/**
	 * Handle invoice.payment_failed — mark subscription as past due.
	 *
	 * @param array<string, mixed> $payload The full event payload.
	 * @return void
	 */
	public function handle_invoice_payment_failed( array $payload ): void {
		$invoice = $payload['data']['object'] ?? [];

		// As of Stripe API 2025-09-30 (clover), invoice.subscription moved to
		// invoice.parent.subscription_details.subscription.
		$subscription_id = $invoice['parent']['subscription_details']['subscription'] ?? '';

		if ( '' === $subscription_id ) {
			return;
		}

		$existing = $this->subscription_repository->get_by_stripe_id( sanitize_text_field( $subscription_id ) );

		if ( null === $existing ) {
			return;
		}

		$this->subscription_repository->update(
			(int) $existing->id,
			[ 'status' => 'past_due' ]
		);

		/**
		 * Fires after a subscription payment fails.
		 *
		 * @since 1.0.0
		 *
		 * @param int   $subscription_id The local subscription ID.
		 * @param array $invoice         The Stripe invoice data.
		 */
		do_action( 'leastudios_payments_subscription_payment_failed', (int) $existing->id, $invoice );
	}
}
