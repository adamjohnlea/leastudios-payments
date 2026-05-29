<?php
/**
 * Exposes order/subscription context as plain arrays for sibling plugins.
 *
 * This is the public read seam other leaStudios plugins (notably
 * leastudios-email-templates) use to render transactional emails without a
 * build-time dependency on this plugin's internal repository classes. Each
 * answer is a plain array of scalars — never a repository object — so the
 * consumer can statically type the shape on its own side.
 *
 * @package LEAStudios\Payments\Support
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Support;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Payments\Database\Order_Repository;
use LEAStudios\Payments\Database\Subscription_Repository;

/**
 * Answers the public `leastudios_payments_*` read filters with plain arrays.
 *
 * @phpstan-type OrderEmailContext array{
 *     customer_name: string,
 *     customer_email: string,
 *     amount_total: int,
 *     currency: string,
 *     line_items_json: string,
 *     order_type: string,
 *     stripe_payment_intent_id: string,
 *     payment_status: string,
 *     refunded_amount: int
 * }
 * @phpstan-type SubscriptionEmailContext array{
 *     customer_email: string,
 *     wp_user_id: int,
 *     status: string,
 *     current_period_start: string,
 *     current_period_end: string,
 *     product_name: string
 * }
 */
class Email_Context_Provider {

	/**
	 * The order repository.
	 *
	 * @var Order_Repository
	 */
	private Order_Repository $orders;

	/**
	 * The subscription repository.
	 *
	 * @var Subscription_Repository
	 */
	private Subscription_Repository $subscriptions;

	/**
	 * Constructor.
	 *
	 * @param Order_Repository        $orders        The order repository.
	 * @param Subscription_Repository $subscriptions The subscription repository.
	 */
	public function __construct( Order_Repository $orders, Subscription_Repository $subscriptions ) {
		$this->orders        = $orders;
		$this->subscriptions = $subscriptions;
	}

	/**
	 * Register the public read filters.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'leastudios_payments_order_email_context', [ $this, 'order_email_context' ], 10, 2 );
		add_filter( 'leastudios_payments_subscription_email_context', [ $this, 'subscription_email_context' ], 10, 2 );
		add_filter( 'leastudios_payments_local_subscription_id', [ $this, 'local_subscription_id' ], 10, 2 );
	}

	/**
	 * Resolve a local order into its plain email-context array.
	 *
	 * Returns the unchanged `$value` (typically `null`) when no order matches
	 * the given ID, so callers can distinguish "not found" from a real record.
	 *
	 * @param mixed $value    Filtered value to return when the order is missing.
	 * @param int   $order_id The local order ID.
	 * @return array|mixed The order context array, or `$value` if not found.
	 *
	 * @phpstan-return OrderEmailContext|mixed
	 */
	public function order_email_context( $value, int $order_id ) {
		$order = $this->orders->get( $order_id );

		if ( null === $order ) {
			return $value;
		}

		return [
			'customer_name'            => (string) ( $order->customer_name ?? '' ),
			'customer_email'           => (string) ( $order->customer_email ?? '' ),
			'amount_total'             => (int) ( $order->amount_total ?? 0 ),
			'currency'                 => (string) ( $order->currency ?? 'usd' ),
			'line_items_json'          => (string) ( $order->line_items_json ?? '[]' ),
			'order_type'               => (string) ( $order->order_type ?? '' ),
			'stripe_payment_intent_id' => (string) ( $order->stripe_payment_intent_id ?? '' ),
			'payment_status'           => (string) ( $order->payment_status ?? 'paid' ),
			'refunded_amount'          => (int) ( $order->refunded_amount ?? 0 ),
		];
	}

	/**
	 * Resolve a local subscription into its plain email-context array.
	 *
	 * The `product_name` is resolved here — against this plugin's own orders
	 * table — so the consumer never has to query a payments-owned table. It is
	 * the description of the most recent subscription order line item whose
	 * `price_id` matches the subscription's current `stripe_price_id`.
	 *
	 * @param mixed $value           Filtered value to return when missing.
	 * @param int   $subscription_id The local subscription ID.
	 * @return array|mixed The subscription context array, or `$value`.
	 *
	 * @phpstan-return SubscriptionEmailContext|mixed
	 */
	public function subscription_email_context( $value, int $subscription_id ) {
		$sub = $this->subscriptions->get( $subscription_id );

		if ( null === $sub ) {
			return $value;
		}

		return [
			'customer_email'       => (string) ( $sub->customer_email ?? '' ),
			'wp_user_id'           => (int) ( $sub->wp_user_id ?? 0 ),
			'status'               => (string) ( $sub->status ?? '' ),
			'current_period_start' => (string) ( $sub->current_period_start ?? '' ),
			'current_period_end'   => (string) ( $sub->current_period_end ?? '' ),
			'product_name'         => $this->resolve_product_name( $sub ),
		];
	}

	/**
	 * Resolve a Stripe subscription ID to its local subscription ID.
	 *
	 * @param mixed  $value         Filtered value to return when unknown.
	 * @param string $stripe_sub_id The Stripe subscription ID.
	 * @return int|mixed The local subscription ID, or `$value` if unknown.
	 */
	public function local_subscription_id( $value, string $stripe_sub_id ) {
		$local = $this->subscriptions->get_by_stripe_id( $stripe_sub_id );

		if ( null === $local || ! isset( $local->id ) ) {
			return $value;
		}

		return (int) $local->id;
	}

	/**
	 * Resolve the product name for a subscription record.
	 *
	 * Filters this customer's subscription orders by the subscription's current
	 * `stripe_price_id` so a customer with multiple subscriptions gets the
	 * right product name rather than whichever order was most recent.
	 *
	 * @param object $sub The subscription record.
	 * @return string The product description, or '' if none matched.
	 */
	private function resolve_product_name( object $sub ): string {
		if ( empty( $sub->stripe_customer_id ) || empty( $sub->stripe_price_id ) ) {
			return '';
		}

		global $wpdb;
		$table = $wpdb->prefix . 'leastudios_payments_orders';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$orders = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT line_items_json FROM %i WHERE stripe_customer_id = %s AND order_type = 'subscription' ORDER BY id DESC",
				$table,
				$sub->stripe_customer_id
			)
		);

		if ( empty( $orders ) ) {
			return '';
		}

		$price_id = (string) $sub->stripe_price_id;

		foreach ( $orders as $row ) {
			$items = json_decode( (string) ( $row->line_items_json ?? '[]' ), true );

			if ( ! is_array( $items ) ) {
				continue;
			}

			foreach ( $items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				if ( ( $item['price_id'] ?? '' ) === $price_id ) {
					return (string) ( $item['description'] ?? '' );
				}
			}
		}

		return '';
	}
}
