<?php
/**
 * REST API controller for creating Checkout Sessions.
 *
 * @package LEAStudios\Payments\REST
 */

declare(strict_types=1);

namespace LEAStudios\Payments\REST;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Payments\Checkout\Session_Factory;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Handles POST requests to create Stripe Embedded Checkout Sessions.
 * Requires the user to be logged in.
 */
class Checkout_Controller extends WP_REST_Controller {

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
	protected $rest_base = 'checkout-session';

	/**
	 * Constructor.
	 *
	 * @param Session_Factory $session_factory The session factory.
	 */
	public function __construct(
		private readonly Session_Factory $session_factory,
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
					'args'                => $this->get_endpoint_args(),
				],
			]
		);
	}

	/**
	 * Check permissions — user must be logged in.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return bool True if user is logged in.
	 */
	public function create_item_permissions_check( $request ): bool {
		return is_user_logged_in();
	}

	/**
	 * Create a Checkout Session and return the client secret.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response The response.
	 */
	public function create_item( $request ): WP_REST_Response {
		// Rate limit: 10 checkout sessions per IP per minute.
		$ip            = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		$rate_key      = 'leastudios_pay_co_' . hash( 'sha256', $ip );
		$rate_attempts = (int) get_transient( $rate_key );

		if ( $rate_attempts >= 10 ) {
			return new WP_REST_Response(
				[
					'success' => false,
					'message' => __( 'Too many requests. Please try again in a moment.', 'leastudios-payments' ),
				],
				429
			);
		}

		set_transient( $rate_key, $rate_attempts + 1, 60 );

		$price_id   = (int) $request->get_param( 'price_id' );
		$return_url = esc_url_raw( (string) $request->get_param( 'return_url' ) );
		$user_id    = get_current_user_id();

		$result = $this->session_factory->create( $price_id, $return_url, $user_id );

		if ( ! $result['success'] ) {
			return new WP_REST_Response(
				[
					'success' => false,
					'message' => $result['error'],
				],
				400
			);
		}

		return new WP_REST_Response(
			[
				'success'       => true,
				'client_secret' => $result['client_secret'],
			],
			200
		);
	}

	/**
	 * Get endpoint argument definitions.
	 *
	 * @return array The args schema.
	 */
	private function get_endpoint_args(): array {
		return [
			'price_id'   => [
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'validate_callback' => static function ( $value ): bool {
					return is_numeric( $value ) && (int) $value > 0;
				},
			],
			'return_url' => [
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
			],
		];
	}
}
