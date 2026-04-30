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
	 * Resolve the WP user id that owns a given Stripe customer id.
	 *
	 * Reverse-looks up users whose META_KEY user-meta value matches the
	 * given Stripe customer id. Returns null if no such user exists. This
	 * is the source of truth used to decide whether to trust an arbitrary
	 * `metadata.wp_user_id` claim arriving in a webhook payload — the claim
	 * is only trusted if it agrees with this lookup.
	 *
	 * @param string $customer_id The Stripe Customer ID.
	 * @return int|null The matching WP user ID, or null if no mapping exists.
	 */
	public function find_user_by_customer_id( string $customer_id ): ?int {
		if ( '' === $customer_id ) {
			return null;
		}

		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- targeted single-row reverse lookup; we have no other handle on the customer mapping.
		$users = get_users(
			[
				'meta_key'   => self::META_KEY,
				'meta_value' => $customer_id,
				'number'     => 1,
				'fields'     => 'ID',
			]
		);
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

		if ( empty( $users ) ) {
			return null;
		}

		return (int) $users[0];
	}

	/**
	 * Resolve a trusted WP user id for a webhook event.
	 *
	 * Webhook payloads carry a `metadata.wp_user_id` claim that is
	 * attacker-influenceable (anyone with API access to the Stripe account
	 * can set metadata). We only trust the claim if the local customer
	 * mapping agrees; otherwise we fall back to a reverse lookup keyed on
	 * the customer id, and finally to null. The returned id is safe to use
	 * as a foreign key on local tables.
	 *
	 * @param string   $customer_id      The Stripe customer id from the payload.
	 * @param int|null $claimed_user_id  The wp_user_id from event metadata, if any.
	 * @return int|null The verified WP user id, or null if none can be trusted.
	 */
	public function resolve_user_id( string $customer_id, ?int $claimed_user_id ): ?int {
		if ( null !== $claimed_user_id && $claimed_user_id > 0 ) {
			$mapped = $this->get_customer_id( $claimed_user_id );

			if ( null !== $mapped && $mapped === $customer_id ) {
				return $claimed_user_id;
			}
		}

		return $this->find_user_by_customer_id( $customer_id );
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

		// Including the email lets Stripe send receipts and improves
		// dashboard search ergonomics for accounting/refund flows. The
		// WP user_email is the canonical identifier we already trust.
		if ( ! empty( $user->user_email ) && is_email( $user->user_email ) ) {
			$args['email'] = $user->user_email;
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
			// Idempotency key keyed on the WP user id so retried creates
			// (network blip, proxy replay, double-clicked admin action)
			// return the original customer instead of duplicating it.
			$customer = \Stripe\Customer::create(
				$args,
				[ 'idempotency_key' => 'lsc_u' . $user_id ]
			);
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
