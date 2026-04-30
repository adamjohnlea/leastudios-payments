<?php
/**
 * Subscription repository for local database operations.
 *
 * @package LEAStudios\Payments\Database
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Database;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Payments\Shared\Datetime_Util;

/**
 * CRUD operations for the subscriptions table.
 */
class Subscription_Repository {

	/**
	 * Get the subscriptions table name.
	 *
	 * @return string
	 */
	private function table(): string {
		return Migration::table( 'subscriptions' );
	}

	/**
	 * Create or update a subscription record (upsert by Stripe subscription ID).
	 *
	 * @param array<string, mixed> $data Subscription data.
	 * @return int The row ID, or 0 on failure.
	 */
	public function upsert( array $data ): int {
		$stripe_sub_id = $data['stripe_subscription_id'] ?? '';

		if ( '' === $stripe_sub_id ) {
			return 0;
		}

		$existing = $this->get_by_stripe_id( $stripe_sub_id );

		if ( null !== $existing ) {
			$this->update( (int) $existing->id, $data );
			return (int) $existing->id;
		}

		return $this->create( $data );
	}

	/**
	 * Create a subscription record.
	 *
	 * @param array<string, mixed> $data Subscription data.
	 * @return int The inserted row ID, or 0 on failure.
	 */
	public function create( array $data ): int {
		global $wpdb;

		$now_utc = Datetime_Util::utc_now_mysql();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$this->table(),
			[
				'stripe_subscription_id' => $data['stripe_subscription_id'] ?? '',
				'stripe_customer_id'     => $data['stripe_customer_id'] ?? '',
				'stripe_price_id'        => $data['stripe_price_id'] ?? '',
				'customer_email'         => $data['customer_email'] ?? '',
				'wp_user_id'             => $data['wp_user_id'] ?? null,
				'status'                 => $data['status'] ?? 'active',
				'current_period_start'   => $data['current_period_start'] ?? null,
				'current_period_end'     => $data['current_period_end'] ?? null,
				'cancel_at_period_end'   => $data['cancel_at_period_end'] ?? 0,
				'created_at'             => $now_utc,
				'updated_at'             => $now_utc,
			],
			[ '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' ]
		);

		return false !== $result ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Get a subscription by local ID.
	 *
	 * @param int $id The local subscription ID.
	 * @return object|null The subscription row or null.
	 */
	public function get( int $id ): ?object {
		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE id = %d",
				$id
			)
		);

		return null !== $row ? $row : null;
	}

	/**
	 * Get a subscription by Stripe subscription ID.
	 *
	 * @param string $stripe_subscription_id The Stripe subscription ID.
	 * @return object|null The subscription row or null.
	 */
	public function get_by_stripe_id( string $stripe_subscription_id ): ?object {
		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE stripe_subscription_id = %s",
				$stripe_subscription_id
			)
		);

		return null !== $row ? $row : null;
	}

	/**
	 * Get all subscriptions with optional status filter and pagination.
	 *
	 * @param string $status Filter by status, or '' for all.
	 * @param int    $limit  Maximum results.
	 * @param int    $offset Offset for pagination.
	 * @return array<int, \stdClass> Array of subscription objects.
	 */
	public function get_all( string $status = '', int $limit = 20, int $offset = 0 ): array {
		global $wpdb;

		$table = $this->table();

		if ( '' !== $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$table} WHERE status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
					$status,
					$limit,
					$offset
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);
	}

	/**
	 * Count subscriptions with optional status filter.
	 *
	 * @param string $status Filter by status, or '' for all.
	 * @return int The total count.
	 */
	public function count( string $status = '' ): int {
		global $wpdb;

		$table = $this->table();

		if ( '' !== $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT COUNT(*) FROM {$table} WHERE status = %s",
					$status
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(*) FROM {$table}"
		);
	}

	/**
	 * Update a subscription.
	 *
	 * @param int                  $id   The local subscription ID.
	 * @param array<string, mixed> $data Columns to update.
	 * @return bool True on success.
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		$data['updated_at'] = Datetime_Util::utc_now_mysql();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$this->table(),
			$data,
			[ 'id' => $id ],
			self::format_for_columns( array_keys( $data ) ),
			[ '%d' ]
		);

		return false !== $result;
	}

	/**
	 * Map subscription column names to wpdb format placeholders.
	 *
	 * @param array<int, string> $columns Column names being written.
	 * @return array<int, string>
	 */
	private static function format_for_columns( array $columns ): array {
		static $map = [
			'id'                     => '%d',
			'stripe_subscription_id' => '%s',
			'stripe_customer_id'     => '%s',
			'stripe_price_id'        => '%s',
			'customer_email'         => '%s',
			'wp_user_id'             => '%d',
			'status'                 => '%s',
			'current_period_start'   => '%s',
			'current_period_end'     => '%s',
			'cancel_at_period_end'   => '%d',
			'created_at'             => '%s',
			'updated_at'             => '%s',
		];

		$formats = [];
		foreach ( $columns as $column ) {
			$formats[] = $map[ $column ] ?? '%s';
		}

		return $formats;
	}
}
