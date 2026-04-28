<?php
/**
 * Database migration handler.
 *
 * @package LEAStudios\Payments\Database
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Database;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Handles table creation and schema migration.
 */
class Migration {

	/**
	 * Schema version option key.
	 */
	private const SCHEMA_VERSION_KEY = 'leastudios_payments_schema_version';

	/**
	 * Target schema version.
	 */
	private const SCHEMA_VERSION = 3;

	/**
	 * Get a table name with the WordPress prefix.
	 *
	 * @param string $table The unprefixed table name.
	 * @return string Full table name with prefix.
	 */
	public static function table( string $table ): string {
		global $wpdb;

		return $wpdb->prefix . 'leastudios_payments_' . $table;
	}

	/**
	 * Run migrations if needed.
	 *
	 * @return void
	 */
	public function maybe_migrate(): void {
		$current = (int) get_option( self::SCHEMA_VERSION_KEY, 0 );

		if ( $current >= self::SCHEMA_VERSION ) {
			return;
		}

		$this->migrate( $current );
		update_option( self::SCHEMA_VERSION_KEY, self::SCHEMA_VERSION );
	}

	/**
	 * Run the migration sequence.
	 *
	 * @param int $from_version Current schema version.
	 * @return void
	 */
	private function migrate( int $from_version ): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		if ( $from_version < 1 ) {
			$this->create_tables();
		}

		if ( $from_version >= 1 && $from_version < 2 ) {
			$this->add_order_type_column();
		}

		if ( $from_version >= 1 && $from_version < 3 ) {
			$this->add_require_shipping_column();
		}
	}

	/**
	 * Create all plugin tables.
	 *
	 * @return void
	 */
	private function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$products_table      = self::table( 'products' );
		$prices_table        = self::table( 'prices' );
		$orders_table        = self::table( 'orders' );
		$subscriptions_table = self::table( 'subscriptions' );

		$sql = "CREATE TABLE {$products_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			stripe_product_id varchar(255) NOT NULL,
			name varchar(255) NOT NULL,
			description text DEFAULT NULL,
			image_url varchar(2048) DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			require_shipping tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY stripe_product_id (stripe_product_id),
			KEY status (status)
		) {$charset_collate};

		CREATE TABLE {$prices_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			stripe_price_id varchar(255) NOT NULL,
			product_id bigint(20) unsigned NOT NULL,
			amount bigint NOT NULL,
			currency varchar(3) NOT NULL DEFAULT 'usd',
			type varchar(20) NOT NULL DEFAULT 'one_time',
			recurring_interval varchar(10) DEFAULT NULL,
			recurring_interval_count int DEFAULT 1,
			status varchar(20) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY stripe_price_id (stripe_price_id),
			KEY product_id (product_id),
			KEY status (status)
		) {$charset_collate};

		CREATE TABLE {$orders_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			stripe_session_id varchar(255) NOT NULL,
			stripe_payment_intent_id varchar(255) DEFAULT NULL,
			stripe_customer_id varchar(255) DEFAULT NULL,
			customer_email varchar(255) NOT NULL DEFAULT '',
			customer_name varchar(255) DEFAULT NULL,
			wp_user_id bigint(20) unsigned DEFAULT NULL,
			amount_total bigint NOT NULL DEFAULT 0,
			currency varchar(3) NOT NULL DEFAULT 'usd',
			payment_status varchar(20) NOT NULL DEFAULT 'paid',
			order_type varchar(20) NOT NULL DEFAULT 'one_time',
			refunded_amount bigint NOT NULL DEFAULT 0,
			line_items_json longtext DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY stripe_session_id (stripe_session_id),
			KEY stripe_payment_intent_id (stripe_payment_intent_id),
			KEY stripe_customer_id (stripe_customer_id),
			KEY wp_user_id (wp_user_id),
			KEY payment_status (payment_status),
			KEY created_at (created_at)
		) {$charset_collate};

		CREATE TABLE {$subscriptions_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			stripe_subscription_id varchar(255) NOT NULL,
			stripe_customer_id varchar(255) NOT NULL,
			stripe_price_id varchar(255) NOT NULL DEFAULT '',
			customer_email varchar(255) NOT NULL DEFAULT '',
			wp_user_id bigint(20) unsigned DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			current_period_start datetime DEFAULT NULL,
			current_period_end datetime DEFAULT NULL,
			cancel_at_period_end tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY stripe_subscription_id (stripe_subscription_id),
			KEY stripe_customer_id (stripe_customer_id),
			KEY wp_user_id (wp_user_id),
			KEY status (status)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Add order_type column to the orders table.
	 *
	 * @return void
	 */
	private function add_order_type_column(): void {
		global $wpdb;

		$table = self::table( 'orders' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'order_type'" );

		if ( empty( $column_exists ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN order_type varchar(20) NOT NULL DEFAULT 'one_time' AFTER payment_status" );
		}
	}

	/**
	 * Add require_shipping column to the products table.
	 *
	 * @return void
	 */
	private function add_require_shipping_column(): void {
		global $wpdb;

		$table = self::table( 'products' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'require_shipping'" );

		if ( empty( $column_exists ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN require_shipping tinyint(1) NOT NULL DEFAULT 0 AFTER status" );
		}
	}

	/**
	 * Drop all plugin tables. Use on uninstall only.
	 *
	 * @return void
	 */
	public static function drop_tables(): void {
		global $wpdb;

		$tables = [ 'subscriptions', 'orders', 'prices', 'products' ];

		foreach ( $tables as $table ) {
			$table_name = self::table( $table );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
		}

		delete_option( self::SCHEMA_VERSION_KEY );
	}
}
