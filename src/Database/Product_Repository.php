<?php
/**
 * Product repository for local database operations.
 *
 * @package LEAStudios\Payments\Database
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Database;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * CRUD operations for the products table.
 */
class Product_Repository {

	/**
	 * Get the products table name.
	 *
	 * @return string
	 */
	private function table(): string {
		return Migration::table( 'products' );
	}

	/**
	 * Create a product record.
	 *
	 * @param string $stripe_product_id The Stripe product ID.
	 * @param string $name              The product name.
	 * @param string $description       The product description.
	 * @param string $image_url         The product image URL.
	 * @param bool   $require_shipping Whether to collect shipping address.
	 * @return int The inserted row ID, or 0 on failure.
	 */
	public function create( string $stripe_product_id, string $name, string $description = '', string $image_url = '', bool $require_shipping = false ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$this->table(),
			[
				'stripe_product_id' => $stripe_product_id,
				'name'              => $name,
				'description'       => $description,
				'image_url'         => $image_url,
				'status'            => 'active',
				'require_shipping'  => $require_shipping ? 1 : 0,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%d' ]
		);

		return false !== $result ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Get a product by local ID.
	 *
	 * @param int $id The local product ID.
	 * @return object|null The product row or null.
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
	 * Get a product by Stripe product ID.
	 *
	 * @param string $stripe_product_id The Stripe product ID.
	 * @return object|null The product row or null.
	 */
	public function get_by_stripe_id( string $stripe_product_id ): ?object {
		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE stripe_product_id = %s",
				$stripe_product_id
			)
		);

		return null !== $row ? $row : null;
	}

	/**
	 * Get all products with optional status filter.
	 *
	 * @param string $status Filter by status ('active', 'inactive', or '' for all).
	 * @param int    $limit  Maximum results.
	 * @param int    $offset Offset for pagination.
	 * @return array<int, \stdClass> Array of product objects.
	 */
	public function get_all( string $status = '', int $limit = 50, int $offset = 0 ): array {
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
	 * Count products with optional status filter.
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
	 * Update a product.
	 *
	 * @param int                  $id   The local product ID.
	 * @param array<string, mixed> $data Columns to update.
	 * @return bool True on success.
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$this->table(),
			$data,
			[ 'id' => $id ],
		);

		return false !== $result;
	}

	/**
	 * Delete a product by local ID.
	 *
	 * @param int $id The local product ID.
	 * @return bool True on success.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			$this->table(),
			[ 'id' => $id ],
			[ '%d' ]
		);

		return false !== $result;
	}
}
