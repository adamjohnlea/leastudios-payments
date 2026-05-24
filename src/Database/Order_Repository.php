<?php
/**
 * Order repository for local database operations.
 *
 * @package LEAStudios\Payments\Database
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Database;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Payments\Shared\Datetime_Util;

/**
 * CRUD operations for the orders table.
 */
class Order_Repository {

	/**
	 * Get the orders table name.
	 *
	 * @return string
	 */
	private function table(): string {
		return Migration::table( 'orders' );
	}

	/**
	 * Create an order record from a completed Checkout Session.
	 *
	 * @param array<string, mixed> $data Order data.
	 * @return int The inserted row ID, or 0 on failure.
	 */
	public function create( array $data ): int {
		global $wpdb;

		$wp_user_id = isset( $data['wp_user_id'] ) ? (int) $data['wp_user_id'] : null;

		$now_utc = Datetime_Util::utc_now_mysql();

		$insert_data = [
			'stripe_session_id'        => $data['stripe_session_id'] ?? '',
			'stripe_payment_intent_id' => $data['stripe_payment_intent_id'] ?? '',
			'stripe_customer_id'       => $data['stripe_customer_id'] ?? '',
			'customer_email'           => $data['customer_email'] ?? '',
			'customer_name'            => $data['customer_name'] ?? '',
			'amount_total'             => $data['amount_total'] ?? 0,
			'currency'                 => strtolower( $data['currency'] ?? 'usd' ),
			'payment_status'           => $data['payment_status'] ?? 'paid',
			'order_type'               => $data['order_type'] ?? 'one_time',
			'line_items_json'          => $data['line_items_json'] ?? '[]',
			'created_at'               => $now_utc,
			'updated_at'               => $now_utc,
		];

		$formats = [ '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ];

		if ( null !== $wp_user_id ) {
			$insert_data['wp_user_id'] = $wp_user_id;
			$formats[]                 = '%d';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert( $this->table(), $insert_data, $formats );

		return false !== $result ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Get an order by local ID.
	 *
	 * @param int $id The local order ID.
	 * @return object|null The order row or null.
	 */
	public function get( int $id ): ?object {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				$this->table(),
				$id
			)
		);

		return null !== $row ? $row : null;
	}

	/**
	 * Get an order by Stripe session ID.
	 *
	 * @param string $stripe_session_id The Stripe Checkout Session ID.
	 * @return object|null The order row or null.
	 */
	public function get_by_session_id( string $stripe_session_id ): ?object {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE stripe_session_id = %s',
				$this->table(),
				$stripe_session_id
			)
		);

		return null !== $row ? $row : null;
	}

	/**
	 * Get orders with optional status filter and pagination.
	 *
	 * @param string $status Filter by payment_status, or '' for all.
	 * @param int    $limit  Maximum results.
	 * @param int    $offset Offset for pagination.
	 * @return array<int, \stdClass> Array of order objects.
	 */
	public function get_all( string $status = '', int $limit = 20, int $offset = 0 ): array {
		global $wpdb;

		if ( '' !== $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE payment_status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d',
					$this->table(),
					$status,
					$limit,
					$offset
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i ORDER BY created_at DESC LIMIT %d OFFSET %d',
				$this->table(),
				$limit,
				$offset
			)
		);
	}

	/**
	 * Count orders with optional status filter.
	 *
	 * @param string $status Filter by payment_status, or '' for all.
	 * @return int The total count.
	 */
	public function count( string $status = '' ): int {
		global $wpdb;

		if ( '' !== $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE payment_status = %s',
					$this->table(),
					$status
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->table() )
		);
	}

	/**
	 * Update an order.
	 *
	 * @param int                  $id   The local order ID.
	 * @param array<string, mixed> $data Columns to update.
	 * @return bool True on success.
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		$data['updated_at'] = Datetime_Util::utc_now_mysql();

		$formats = self::format_for_columns( array_keys( $data ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$this->table(),
			$data,
			[ 'id' => $id ],
			$formats,
			[ '%d' ]
		);

		return false !== $result;
	}

	/**
	 * Map order column names to wpdb format placeholders.
	 *
	 * Without explicit formats, $wpdb->update infers everything as %s,
	 * which works because prepare() does the casting — but it loses the
	 * type signal that protects numeric columns from accidental string
	 * insertion (e.g. "5; DROP TABLE…" being silently coerced to 0).
	 *
	 * @param array<int, string> $columns Column names being written.
	 * @return array<int, string>
	 */
	private static function format_for_columns( array $columns ): array {
		static $map = [
			'id'                       => '%d',
			'stripe_session_id'        => '%s',
			'stripe_payment_intent_id' => '%s',
			'stripe_customer_id'       => '%s',
			'customer_email'           => '%s',
			'customer_name'            => '%s',
			'wp_user_id'               => '%d',
			'amount_total'             => '%d',
			'currency'                 => '%s',
			'payment_status'           => '%s',
			'order_type'               => '%s',
			'refunded_amount'          => '%d',
			'line_items_json'          => '%s',
			'created_at'               => '%s',
			'updated_at'               => '%s',
		];

		$formats = [];
		foreach ( $columns as $column ) {
			$formats[] = $map[ $column ] ?? '%s';
		}

		return $formats;
	}

	/**
	 * Get total revenue for the last N days.
	 *
	 * @param int $days Number of days to look back.
	 * @return int Total amount in smallest currency unit.
	 */
	public function get_revenue( int $days = 30 ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE( SUM( amount_total - refunded_amount ), 0 ) FROM %i WHERE payment_status IN ('paid', 'partial_refund') AND created_at >= DATE_SUB( NOW(), INTERVAL %d DAY )",
				$this->table(),
				$days
			)
		);

		return (int) $result;
	}
}
