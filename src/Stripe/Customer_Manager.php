<?php
/**
 * Stripe customer management.
 *
 * @package LEAStudios\Payments\Stripe
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Stripe;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Manages Stripe Customers linked to WordPress users.
 *
 * Every checkout requires a logged-in WordPress user. Each WP user maps
 * to exactly one Stripe Customer, stored in user meta.
 */
class Customer_Manager {

	/**
	 * User meta key for storing the Stripe Customer ID.
	 */
	public const META_KEY = 'leastudios_payments_stripe_customer_id';

	/**
	 * Constructor.
	 *
	 * @param Stripe_Client $stripe_client The Stripe client.
	 */
	public function __construct(
		private readonly Stripe_Client $stripe_client,
	) {}

	/**
	 * Get or create a Stripe Customer for a WordPress user.
	 *
	 * Checks user meta first, verifies the customer still exists in Stripe,
	 * and creates a new one if needed.
	 *
	 * @param int $user_id The WordPress user ID.
	 * @return string|null The Stripe Customer ID, or null on failure.
	 */
	public function get_or_create( int $user_id ): ?string {
		if ( $user_id <= 0 || ! $this->stripe_client->initialize() ) {
			return null;
		}

		// Check for existing Stripe Customer ID in user meta.
		$existing_id = get_user_meta( $user_id, self::META_KEY, true );

		if ( is_string( $existing_id ) && '' !== $existing_id ) {
			// Verify the customer still exists in Stripe.
			try {
				$customer = \Stripe\Customer::retrieve( $existing_id );

				if ( ! $customer->isDeleted() ) {
					return $existing_id;
				}
			} catch ( \Stripe\Exception\ApiErrorException $e ) {
				// Customer no longer exists in Stripe, create a new one.
				delete_user_meta( $user_id, self::META_KEY );
			}
		}

		// Create a new Stripe Customer for this WP user.
		$customer_id = $this->create_customer( $user_id );

		if ( null !== $customer_id ) {
			update_user_meta( $user_id, self::META_KEY, $customer_id );
		}

		return $customer_id;
	}

	/**
	 * Get the Stripe Customer ID for a user without creating one.
	 *
	 * @param int $user_id The WordPress user ID.
	 * @return string|null The Stripe Customer ID, or null if not linked.
	 */
	public function get_customer_id( int $user_id ): ?string {
		$customer_id = get_user_meta( $user_id, self::META_KEY, true );

		return is_string( $customer_id ) && '' !== $customer_id ? $customer_id : null;
	}

	/**
	 * Create a new Stripe Customer from a WordPress user.
	 *
	 * @param int $user_id The WordPress user ID.
	 * @return string|null The Stripe Customer ID, or null on failure.
	 */
	private function create_customer( int $user_id ): ?string {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return null;
		}

		$args = [
			'name'     => trim( $user->first_name . ' ' . $user->last_name ),
			'metadata' => [
				'source'     => 'leastudios-payments',
				'site_url'   => get_site_url(),
				'wp_user_id' => $user_id,
			],
		];

		// Use display_name as fallback if first/last name are empty.
		if ( '' === trim( $args['name'] ) ) {
			$args['name'] = $user->display_name;
		}

		/**
		 * Filters the Stripe Customer creation arguments.
		 *
		 * @since 1.0.0
		 *
		 * @param array $args    The Stripe Customer arguments.
		 * @param int   $user_id The WordPress user ID.
		 * @return array Filtered arguments.
		 */
		$args = apply_filters( 'leastudios_payments_stripe_customer_args', $args, $user_id );

		try {
			$customer = \Stripe\Customer::create( $args );
			return $customer->id;
		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[leaStudios Payments] Customer creation error: ' . $e->getMessage() );
			}

			return null;
		}
	}
}
