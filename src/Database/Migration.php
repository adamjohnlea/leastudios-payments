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
	private const SCHEMA_VERSION = 5;

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
		// First-call short-circuit: once we've confirmed the schema is at the
		// target version this request, skip the option-read on subsequent
		// calls. Plugin::init runs every request and currently calls this on
		// every page load.
		static $checked = false;

		if ( $checked ) {
			return;
		}

		$current = (int) get_option( self::SCHEMA_VERSION_KEY, 0 );

		if ( $current >= self::SCHEMA_VERSION ) {
			$checked = true;
			return;
		}

		$this->migrate( $current );
		update_option( self::SCHEMA_VERSION_KEY, self::SCHEMA_VERSION );
		$checked = true;
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

		if ( $from_version < 4 ) {
			$this->add_webhook_events_table();
		}

		if ( $from_version < 5 ) {
			$this->enforce_unsigned_money_columns();
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

		$products_table       = self::table( 'products' );
		$prices_table         = self::table( 'prices' );
		$orders_table         = self::table( 'orders' );
		$subscriptions_table  = self::table( 'subscriptions' );
		$webhook_events_table = self::table( 'webhook_events' );

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
			amount bigint unsigned NOT NULL,
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
			amount_total bigint unsigned NOT NULL DEFAULT 0,
			currency varchar(3) NOT NULL DEFAULT 'usd',
			payment_status varchar(20) NOT NULL DEFAULT 'paid',
			order_type varchar(20) NOT NULL DEFAULT 'one_time',
			refunded_amount bigint unsigned NOT NULL DEFAULT 0,
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
		) {$charset_collate};

		CREATE TABLE {$webhook_events_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			stripe_event_id varchar(255) NOT NULL,
			event_type varchar(100) NOT NULL,
			processed_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY stripe_event_id (stripe_event_id),
			KEY processed_at (processed_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Add the webhook_events idempotency table for installs that predate v4.
	 *
	 * @return void
	 */
	private function add_webhook_events_table(): void {
		global $wpdb;

		$charset_collate      = $wpdb->get_charset_collate();
		$webhook_events_table = self::table( 'webhook_events' );

		$sql = "CREATE TABLE {$webhook_events_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			stripe_event_id varchar(255) NOT NULL,
			event_type varchar(100) NOT NULL,
			processed_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY stripe_event_id (stripe_event_id),
			KEY processed_at (processed_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Tighten money columns to UNSIGNED to make a negative amount unrepresentable.
	 *
	 * Defense-in-depth for the revenue aggregation: a buggy update path that
	 * tried to insert a negative amount would fail at the schema level before
	 * poisoning Dashboard_Widget::get_revenue.
	 *
	 * @return void
	 */
	private function enforce_unsigned_money_columns(): void {
		global $wpdb;

		$prices_table = self::table( 'prices' );
		$orders_table = self::table( 'orders' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE {$prices_table} MODIFY COLUMN amount bigint unsigned NOT NULL" );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE {$orders_table} MODIFY COLUMN amount_total bigint unsigned NOT NULL DEFAULT 0" );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE {$orders_table} MODIFY COLUMN refunded_amount bigint unsigned NOT NULL DEFAULT 0" );
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

		$tables = [ 'webhook_events', 'subscriptions', 'orders', 'prices', 'products' ];

		foreach ( $tables as $table ) {
			$table_name = self::table( $table );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
		}

		delete_option( self::SCHEMA_VERSION_KEY );
	}
}
