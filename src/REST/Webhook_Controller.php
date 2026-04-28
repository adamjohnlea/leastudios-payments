<?php
/**
 * Stripe webhook receiver.
 *
 * @package LEAStudios\Payments\REST
 */

declare(strict_types=1);

namespace LEAStudios\Payments\REST;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Payments\Stripe\Stripe_Client;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Receives and verifies Stripe webhook events.
 */
class Webhook_Controller extends WP_REST_Controller {

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
	protected $rest_base = 'webhook';

	/**
	 * Constructor.
	 *
	 * @param Stripe_Client $stripe_client The Stripe client.
	 */
	public function __construct(
		private readonly Stripe_Client $stripe_client,
	) {}

	/**
	 * Register the webhook route.
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
					'callback'            => [ $this, 'handle_webhook' ],
					'permission_callback' => [ $this, 'verify_request' ],
				],
			]
		);
	}

	/**
	 * Verify the webhook request signature.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return bool|\WP_Error True if valid, WP_Error otherwise.
	 */
	public function verify_request( WP_REST_Request $request ): bool|\WP_Error {
		$webhook_secret = $this->stripe_client->get_webhook_secret();

		if ( '' === $webhook_secret ) {
			return new \WP_Error(
				'webhook_not_configured',
				__( 'Webhook signing secret is not configured.', 'leastudios-payments' ),
				[ 'status' => 400 ]
			);
		}

		$payload   = $request->get_body();
		$signature = $request->get_header( 'stripe-signature' );

		if ( empty( $signature ) ) {
			return new \WP_Error(
				'missing_signature',
				__( 'Missing Stripe signature header.', 'leastudios-payments' ),
				[ 'status' => 400 ]
			);
		}

		try {
			\Stripe\Webhook::constructEvent( $payload, $signature, $webhook_secret );
		} catch ( \Stripe\Exception\SignatureVerificationException $e ) {
			return new \WP_Error(
				'invalid_signature',
				/* translators: %s: error detail */
				sprintf( __( 'Invalid webhook signature: %s', 'leastudios-payments' ), $e->getMessage() ),
				[ 'status' => 400 ]
			);
		} catch ( \UnexpectedValueException $e ) {
			return new \WP_Error(
				'invalid_payload',
				/* translators: %s: error detail */
				sprintf( __( 'Invalid webhook payload: %s', 'leastudios-payments' ), $e->getMessage() ),
				[ 'status' => 400 ]
			);
		}

		return true;
	}

	/**
	 * Handle the verified webhook event.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response The response.
	 */
	public function handle_webhook( WP_REST_Request $request ): WP_REST_Response {
		$payload = json_decode( $request->get_body(), true );

		if ( ! is_array( $payload ) || empty( $payload['type'] ) ) {
			return new WP_REST_Response( [ 'error' => 'Invalid payload' ], 400 );
		}

		$event_type = sanitize_text_field( $payload['type'] );

		/**
		 * Fires for every incoming Stripe webhook event.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $payload    The full event payload.
		 * @param string $event_type The Stripe event type.
		 */
		do_action( 'leastudios_payments_webhook_event', $payload, $event_type );

		/**
		 * Fires for a specific Stripe webhook event type.
		 *
		 * The dynamic portion of the hook name, `$event_type`, refers to the
		 * Stripe event type with dots replaced by underscores.
		 *
		 * @since 1.0.0
		 *
		 * @param array $payload The full event payload.
		 */
		$hook_name = 'leastudios_payments_webhook_' . str_replace( '.', '_', $event_type );
		do_action( $hook_name, $payload );

		return new WP_REST_Response( [ 'received' => true ], 200 );
	}
}
