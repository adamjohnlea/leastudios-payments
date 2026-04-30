<?php
/**
 * Price repository for local database operations.
 *
 * @package LEAStudios\Payments\Database
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Database;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Payments\Shared\Datetime_Util;

/**
 * CRUD operations for the prices table.
 */
class Price_Repository {

	/**
	 * Get the prices table name.
	 *
	 * @return string
	 */
	private function table(): string {
		return Migration::table( 'prices' );
	}

	/**
	 * Create a price record.
	 *
	 * @param string $stripe_price_id         The Stripe price ID.
	 * @param int    $product_id              The local product ID.
	 * @param int    $amount                  The amount in smallest currency unit.
	 * @param string $currency                The three-letter currency code.
	 * @param string $type                    Price type: 'one_time' or 'recurring'.
	 * @param string $recurring_interval      Interval: 'day', 'week', 'month', 'year', or ''.
	 * @param int    $recurring_interval_count Interval count (default 1).
	 * @return int The inserted row ID, or 0 on failure.
	 */
	public function create(
		string $stripe_price_id,
		int $product_id,
		int $amount,
		string $currency,
		string $type = 'one_time',
		string $recurring_interval = '',
		int $recurring_interval_count = 1,
	): int {
		global $wpdb;

		$now_utc = Datetime_Util::utc_now_mysql();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$this->table(),
			[
				'stripe_price_id'          => $stripe_price_id,
				'product_id'               => $product_id,
				'amount'                   => $amount,
				'currency'                 => strtolower( $currency ),
				'type'                     => $type,
				'recurring_interval'       => '' !== $recurring_interval ? $recurring_interval : null,
				'recurring_interval_count' => $recurring_interval_count,
				'status'                   => 'active',
				'created_at'               => $now_utc,
				'updated_at'               => $now_utc,
			],
			[ '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ]
		);

		return false !== $result ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Get all prices for a product.
	 *
	 * @param int    $product_id The local product ID.
	 * @param string $status     Filter by status, or '' for all.
	 * @return array<int, \stdClass> Array of price objects.
	 */
	public function get_by_product( int $product_id, string $status = 'active' ): array {
		global $wpdb;

		$table = $this->table();

		if ( '' !== $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$table} WHERE product_id = %d AND status = %s ORDER BY amount ASC",
					$product_id,
					$status
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE product_id = %d ORDER BY amount ASC",
				$product_id
			)
		);
	}

	/**
	 * Get a price by local ID.
	 *
	 * @param int $id The local price ID.
	 * @return object|null The price row or null.
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
	 * Get a price by Stripe price ID.
	 *
	 * @param string $stripe_price_id The Stripe price ID.
	 * @return object|null The price row or null.
	 */
	public function get_by_stripe_id( string $stripe_price_id ): ?object {
		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE stripe_price_id = %s",
				$stripe_price_id
			)
		);

		return null !== $row ? $row : null;
	}

	/**
	 * Update a price.
	 *
	 * @param int                  $id   The local price ID.
	 * @param array<string, mixed> $data Columns to update.
	 * @return bool True on success.
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		$data['updated_at'] = Datetime_Util::utc_now_mysql();

		static $format_map = [
			'id'                       => '%d',
			'stripe_price_id'          => '%s',
			'product_id'               => '%d',
			'amount'                   => '%d',
			'currency'                 => '%s',
			'type'                     => '%s',
			'recurring_interval'       => '%s',
			'recurring_interval_count' => '%d',
			'status'                   => '%s',
			'created_at'               => '%s',
			'updated_at'               => '%s',
		];

		$formats = [];
		foreach ( array_keys( $data ) as $column ) {
			$formats[] = $format_map[ $column ] ?? '%s';
		}

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
	 * Delete all prices for a product.
	 *
	 * @param int $product_id The local product ID.
	 * @return int Number of rows deleted.
	 */
	public function delete_by_product( int $product_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			$this->table(),
			[ 'product_id' => $product_id ],
			[ '%d' ]
		);

		return false !== $result ? $result : 0;
	}
}
