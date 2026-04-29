<?php
/**
 * Customer aggregation queries used by the admin Customers page.
 *
 * @package LEAStudios\Payments\Admin
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Admin;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Payments\Database\Migration;

/**
 * Read-only queries that aggregate orders/subscriptions by customer email.
 *
 * These are admin-UI specific and do not belong on the row-level repositories.
 */
class Customers_Query {

	/**
	 * Get aggregated customer list from orders.
	 *
	 * Groups by email so customers with multiple Stripe Customer IDs appear as one row.
	 *
	 * @return array<int, \stdClass> Array of customer objects with order_count, total_spent, last_order_date.
	 */
	public function get_customers(): array {
		global $wpdb;

		$table = Migration::table( 'orders' );

		// customer_name uses a correlated subquery so the most recent variant wins
		// (Stripe lets customers edit their name during checkout; MAX() would pick lexicographically).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results(
			"SELECT
				MAX( stripe_customer_id ) AS stripe_customer_id,
				customer_email,
				(
					SELECT customer_name FROM {$table} o2
					WHERE o2.customer_email = o1.customer_email
					ORDER BY created_at DESC, id DESC LIMIT 1
				) AS customer_name,
				COUNT(*) AS order_count,
				SUM( amount_total - refunded_amount ) AS total_spent,
				MIN( currency ) AS currency,
				MAX( created_at ) AS last_order_date
			FROM {$table} o1
			WHERE customer_email != ''
			GROUP BY customer_email
			ORDER BY last_order_date DESC
			LIMIT 100"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Get all orders for a customer by email.
	 *
	 * @param string $email The customer email.
	 * @return array<int, \stdClass> Array of order objects.
	 */
	public function get_orders_by_email( string $email ): array {
		global $wpdb;

		$table = Migration::table( 'orders' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE customer_email = %s ORDER BY created_at DESC",
				$email
			)
		);
	}

	/**
	 * Get all subscriptions for a customer by email.
	 *
	 * @param string $email The customer email.
	 * @return array<int, \stdClass> Array of subscription objects.
	 */
	public function get_subscriptions_by_email( string $email ): array {
		global $wpdb;

		$table = Migration::table( 'subscriptions' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE customer_email = %s ORDER BY created_at DESC",
				$email
			)
		);
	}
}
