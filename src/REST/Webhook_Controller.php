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

use LEAStudios\Payments\Database\Webhook_Event_Repository;
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
	 * @param Stripe_Client            $stripe_client The Stripe client.
	 * @param Webhook_Event_Repository $event_repo    Idempotency tracker for processed event IDs.
	 */
	public function __construct(
		private readonly Stripe_Client $stripe_client,
		private readonly Webhook_Event_Repository $event_repo,
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
		} catch ( \Stripe\Exception\SignatureVerificationException | \UnexpectedValueException $e ) {
			$is_signature = $e instanceof \Stripe\Exception\SignatureVerificationException;

			$message = $is_signature
				/* translators: %s: signature verification error detail */
				? sprintf( __( 'Invalid webhook signature: %s', 'leastudios-payments' ), $e->getMessage() )
				/* translators: %s: payload parse error detail */
				: sprintf( __( 'Invalid webhook payload: %s', 'leastudios-payments' ), $e->getMessage() );

			return new \WP_Error(
				$is_signature ? 'invalid_signature' : 'invalid_payload',
				$message,
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

		if ( ! is_array( $payload ) || empty( $payload['type'] ) || empty( $payload['id'] ) ) {
			return new WP_REST_Response( [ 'error' => 'Invalid payload' ], 400 );
		}

		$event_type = sanitize_text_field( $payload['type'] );
		$event_id   = sanitize_text_field( $payload['id'] );

		// Stripe may redeliver any event. The event_repo claim is atomic on
		// the UNIQUE index of stripe_event_id; if we've already processed
		// this id, return 200 OK so Stripe does not keep retrying, but skip
		// the action hooks entirely.
		if ( ! $this->event_repo->try_claim( $event_id, $event_type ) ) {
			return new WP_REST_Response(
				[
					'received'  => true,
					'duplicate' => true,
				],
				200
			);
		}

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
