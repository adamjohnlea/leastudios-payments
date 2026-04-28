<?php
/**
 * REST API controller for issuing refunds.
 *
 * @package LEAStudios\Payments\REST
 */

declare(strict_types=1);

namespace LEAStudios\Payments\REST;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Payments\Database\Order_Repository;
use LEAStudios\Payments\Stripe\Stripe_Client;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Handles admin refund requests via the Stripe Refunds API.
 */
class Refund_Controller extends WP_REST_Controller {

	/**
	 * The REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'leastudios-payments/v1';

	/**
	 * The REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'refund';

	/**
	 * Constructor.
	 *
	 * @param Stripe_Client    $stripe_client    The Stripe client.
	 * @param Order_Repository $order_repository The order repository.
	 */
	public function __construct(
		private readonly Stripe_Client $stripe_client,
		private readonly Order_Repository $order_repository,
	) {}

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ $this, 'create_item_permissions_check' ],
					'args'                => [
						'order_id' => [
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						],
						'amount'   => [
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						],
					],
				],
			]
		);
	}

	/**
	 * Only admins can issue refunds.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return bool True if allowed.
	 */
	public function create_item_permissions_check( $request ): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Issue a refund via Stripe.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response The response.
	 */
	public function create_item( $request ): WP_REST_Response {
		$order_id = (int) $request->get_param( 'order_id' );
		$amount   = (int) $request->get_param( 'amount' );

		$order = $this->order_repository->get( $order_id );

		if ( ! $order ) {
			return new WP_REST_Response(
				[
					'success' => false,
					'message' => __( 'Order not found.', 'leastudios-payments' ),
				],
				404
			);
		}

		if ( empty( $order->stripe_payment_intent_id ) ) {
			return new WP_REST_Response(
				[
					'success' => false,
					'message' => __( 'No payment intent found for this order.', 'leastudios-payments' ),
				],
				400
			);
		}

		$max_refund = (int) $order->amount_total - (int) $order->refunded_amount;

		if ( $amount < 1 || $amount > $max_refund ) {
			return new WP_REST_Response(
				[
					'success' => false,
					'message' => sprintf(
						/* translators: %s: maximum refundable amount */
						__( 'Invalid refund amount. Maximum refundable: %s', 'leastudios-payments' ),
						(string) $max_refund
					),
				],
				400
			);
		}

		// Prevent duplicate refund processing via transient lock.
		$lock_key = 'leastudios_payments_refund_lock_' . $order_id;

		if ( false !== get_transient( $lock_key ) ) {
			return new WP_REST_Response(
				[
					'success' => false,
					'message' => __( 'A refund for this order is already being processed.', 'leastudios-payments' ),
				],
				409
			);
		}

		set_transient( $lock_key, 1, 30 );

		if ( ! $this->stripe_client->initialize() ) {
			return new WP_REST_Response(
				[
					'success' => false,
					'message' => __( 'Stripe is not configured.', 'leastudios-payments' ),
				],
				500
			);
		}

		try {
			$refund_args = [
				'payment_intent' => $order->stripe_payment_intent_id,
				'amount'         => $amount,
			];

			/**
			 * Filters the Stripe refund arguments before creation.
			 *
			 * @since 1.0.0
			 *
			 * @param array  $refund_args The refund arguments.
			 * @param int    $order_id    The local order ID.
			 * @param object $order       The order object.
			 * @return array Filtered arguments.
			 */
			$refund_args = apply_filters( 'leastudios_payments_refund_args', $refund_args, $order_id, $order );

			\Stripe\Refund::create( $refund_args );
		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[leaStudios Payments] Refund error: ' . $e->getMessage() );
			}

			return new WP_REST_Response(
				[
					'success' => false,
					'message' => __( 'Failed to process refund. Please try again or check the Stripe Dashboard.', 'leastudios-payments' ),
				],
				500
			);
		}

		// Release the processing lock.
		delete_transient( $lock_key );

		// Update the local order record.
		$new_refunded = (int) $order->refunded_amount + $amount;
		$new_status   = $new_refunded >= (int) $order->amount_total ? 'refunded' : 'partial_refund';

		$this->order_repository->update(
			$order_id,
			[
				'refunded_amount' => $new_refunded,
				'payment_status'  => $new_status,
			]
		);

		/**
		 * Fires after a refund is issued.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $order_id The local order ID.
		 * @param int    $amount   The refunded amount.
		 * @param string $status   The new payment status.
		 */
		do_action( 'leastudios_payments_refund_issued', $order_id, $amount, $new_status );

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => __( 'Refund issued successfully.', 'leastudios-payments' ),
			],
			200
		);
	}
}
