<?php
/**
 * REST API controller for Stripe Customer Portal sessions.
 *
 * @package LEAStudios\Payments\REST
 */

declare(strict_types=1);

namespace LEAStudios\Payments\REST;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Payments\Stripe\Customer_Manager;
use LEAStudios\Payments\Stripe\Stripe_Client;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Creates Stripe Billing Portal sessions for customers to manage their subscriptions.
 */
class Portal_Controller extends WP_REST_Controller {

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
	protected $rest_base = 'portal-session';

	/**
	 * Constructor.
	 *
	 * @param Stripe_Client $stripe_client The Stripe client.
	 */
	public function __construct(
		private readonly Stripe_Client $stripe_client,
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
						'return_url' => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'esc_url_raw',
							'validate_callback' => [ $this, 'validate_optional_same_host_url' ],
						],
					],
				],
			]
		);
	}

	/**
	 * Validate that an optional return_url points to the same host as this
	 * site. Empty/missing is allowed (the handler falls back to home_url);
	 * an off-site URL is rejected to prevent open-redirect abuse after
	 * Stripe sends the customer back from the Billing Portal.
	 *
	 * @param mixed $value The submitted value.
	 * @return bool True if absent/empty or same-host.
	 */
	public function validate_optional_same_host_url( $value ): bool {
		if ( null === $value || '' === $value ) {
			return true;
		}

		if ( ! is_string( $value ) ) {
			return false;
		}

		$url_host  = wp_parse_url( $value, PHP_URL_HOST );
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );

		return is_string( $url_host ) && is_string( $site_host ) && strtolower( $url_host ) === strtolower( $site_host );
	}

	/**
	 * Only logged-in users can access the customer portal.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return bool True if the user is logged in.
	 */
	public function create_item_permissions_check( $request ): bool {
		return is_user_logged_in();
	}

	/**
	 * Create a Billing Portal session and return the URL.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response The response.
	 */
	public function create_item( $request ): WP_REST_Response {
		if ( ! $this->stripe_client->initialize() ) {
			return new WP_REST_Response(
				[
					'success' => false,
					'message' => __( 'Stripe is not configured.', 'leastudios-payments' ),
				],
				500
			);
		}

		$user_id     = get_current_user_id();
		$customer_id = get_user_meta( $user_id, Customer_Manager::META_KEY, true );

		if ( ! is_string( $customer_id ) || '' === $customer_id ) {
			return new WP_REST_Response(
				[
					'success' => false,
					'message' => __( 'No Stripe customer found for your account.', 'leastudios-payments' ),
				],
				404
			);
		}

		$return_url = $request->get_param( 'return_url' );

		if ( empty( $return_url ) ) {
			$return_url = home_url();
		}

		try {
			// 60-second idempotency window: a refresh or double-click within
			// the same minute returns the original portal session URL.
			$idempotency_key = sprintf(
				'lsp_u%d_w%d',
				$user_id,
				(int) floor( time() / 60 )
			);

			$session = \Stripe\BillingPortal\Session::create(
				[
					'customer'   => $customer_id,
					'return_url' => $return_url,
				],
				[ 'idempotency_key' => $idempotency_key ]
			);
		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[leaStudios Payments] Portal session error: ' . $e->getMessage() );
			}

			return new WP_REST_Response(
				[
					'success' => false,
					'message' => __( 'Unable to open the billing portal. Please try again later.', 'leastudios-payments' ),
				],
				500
			);
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'url'     => $session->url,
			],
			200
		);
	}
}
