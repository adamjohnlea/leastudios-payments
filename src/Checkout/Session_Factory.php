<?php
/**
 * Creates Stripe Checkout Sessions.
 *
 * @package LEAStudios\Payments\Checkout
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Checkout;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Payments\Database\Price_Repository;
use LEAStudios\Payments\Database\Product_Repository;
use LEAStudios\Payments\Stripe\Customer_Manager;
use LEAStudios\Payments\Stripe\Stripe_Client;

/**
 * Builds and creates Stripe Checkout Sessions with embedded UI mode.
 *
 * Requires a logged-in WordPress user. The user's Stripe Customer is
 * resolved via Customer_Manager, ensuring 1:1 mapping.
 */
class Session_Factory {

	/**
	 * Constructor.
	 *
	 * @param Stripe_Client      $stripe_client      The Stripe client.
	 * @param Customer_Manager   $customer_manager   The customer manager.
	 * @param Price_Repository   $price_repository   The price repository.
	 * @param Product_Repository $product_repository The product repository.
	 */
	public function __construct(
		private readonly Stripe_Client $stripe_client,
		private readonly Customer_Manager $customer_manager,
		private readonly Price_Repository $price_repository,
		private readonly Product_Repository $product_repository,
	) {}

	/**
	 * Create an embedded Checkout Session for a given price.
	 *
	 * @param int    $price_id   The local price ID.
	 * @param string $return_url The URL to redirect to after checkout.
	 * @param int    $user_id    The WordPress user ID (required).
	 * @return array{success: bool, client_secret: string, error: string}
	 */
	public function create( int $price_id, string $return_url, int $user_id ): array {
		if ( $user_id <= 0 ) {
			return [
				'success'       => false,
				'client_secret' => '',
				'error'         => __( 'You must be logged in to make a purchase.', 'leastudios-payments' ),
			];
		}

		if ( ! $this->stripe_client->initialize() ) {
			return [
				'success'       => false,
				'client_secret' => '',
				'error'         => __( 'Stripe is not configured.', 'leastudios-payments' ),
			];
		}

		$price = $this->price_repository->get( $price_id );

		if ( ! $price || 'active' !== $price->status ) {
			return [
				'success'       => false,
				'client_secret' => '',
				'error'         => __( 'Price not found or inactive.', 'leastudios-payments' ),
			];
		}

		// Determine checkout mode based on price type.
		$mode = 'recurring' === $price->type ? 'subscription' : 'payment';

		// Resolve product name for transaction descriptions.
		$product      = $this->product_repository->get( (int) $price->product_id );
		$product_name = $product ? $product->name : __( 'Payment', 'leastudios-payments' );

		// Get or create the Stripe Customer for this WP user.
		$customer_id = $this->customer_manager->get_or_create( $user_id );

		if ( null === $customer_id ) {
			return [
				'success'       => false,
				'client_secret' => '',
				'error'         => __( 'Unable to create customer record. Please try again.', 'leastudios-payments' ),
			];
		}

		// Build the session arguments.
		$session_args = [
			'ui_mode'         => 'embedded_page',
			'mode'            => $mode,
			'customer'        => $customer_id,
			'customer_update' => [
				'name'    => 'auto',
				'address' => 'auto',
			],
			'line_items'      => [
				[
					'price'    => $price->stripe_price_id,
					'quantity' => 1,
				],
			],
			'return_url'      => add_query_arg( 'session_id', '{CHECKOUT_SESSION_ID}', $return_url ),
			'metadata'        => [
				'source'         => 'leastudios-payments',
				'site_url'       => get_site_url(),
				'local_price_id' => $price_id,
				'wp_user_id'     => $user_id,
			],
		];

		// Collect shipping address if the product requires it.
		if ( $product && ! empty( $product->require_shipping ) ) {
			$session_args['shipping_address_collection'] = [
				'allowed_countries' => $this->get_shipping_countries(),
			];
		}

		// Add transaction descriptions so they show in Stripe Dashboard.
		if ( 'payment' === $mode ) {
			$session_args['payment_intent_data'] = [
				'description' => $product_name,
			];

			// Enable invoice creation for better record keeping.
			$session_args['invoice_creation'] = [
				'enabled' => true,
			];
		} else {
			$session_args['subscription_data'] = [
				'description' => $product_name,
			];
		}

		/**
		 * Filters the Stripe Checkout Session arguments before creation.
		 *
		 * @since 1.0.0
		 *
		 * @param array $session_args The Checkout Session arguments.
		 * @param int   $price_id     The local price ID.
		 * @param int   $user_id      The WordPress user ID.
		 * @return array Filtered arguments.
		 */
		$session_args = apply_filters( 'leastudios_payments_checkout_session_args', $session_args, $price_id, $user_id );

		try {
			// 60-second idempotency window: a double-clicked Buy button or a
			// proxy retry within the same minute returns the original session.
			// After 60s a fresh checkout attempt creates a new session.
			$idempotency_key = sprintf(
				'lss_u%d_p%d_w%d',
				$user_id,
				$price_id,
				(int) floor( time() / 60 )
			);

			$session = \Stripe\Checkout\Session::create(
				$session_args,
				[ 'idempotency_key' => $idempotency_key ]
			);
		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[leaStudios Payments] Checkout session error: ' . $e->getMessage() );
			}

			return [
				'success'       => false,
				'client_secret' => '',
				'error'         => __( 'Unable to initialize checkout. Please try again later.', 'leastudios-payments' ),
			];
		}

		/**
		 * Fires after a Checkout Session is created.
		 *
		 * @since 1.0.0
		 *
		 * @param \Stripe\Checkout\Session $session  The created session.
		 * @param int                      $price_id The local price ID.
		 * @param int                      $user_id  The WordPress user ID.
		 */
		do_action( 'leastudios_payments_checkout_session_created', $session, $price_id, $user_id );

		return [
			'success'       => true,
			'client_secret' => $session->client_secret,
			'error'         => '',
		];
	}

	/**
	 * Get the list of countries allowed for shipping.
	 *
	 * @return array<int, string> Array of two-letter country codes.
	 */
	private function get_shipping_countries(): array {
		$countries = [
			'US',
			'CA',
			'GB',
			'AU',
			'NZ',
			'IE',
			'DE',
			'FR',
			'ES',
			'IT',
			'NL',
			'BE',
			'AT',
			'CH',
			'DK',
			'FI',
			'NO',
			'SE',
			'PT',
			'JP',
		];

		/**
		 * Filters the allowed shipping countries for checkout.
		 *
		 * @since 1.0.0
		 *
		 * @param array $countries Array of two-letter country codes.
		 * @return array Filtered countries.
		 */
		return apply_filters( 'leastudios_payments_shipping_countries', $countries );
	}
}
