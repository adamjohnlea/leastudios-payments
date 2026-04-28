<?php
/**
 * Uninstall handler — runs when the plugin is deleted via WP admin.
 *
 * @package LEAStudios\Payments
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

// Drop all plugin tables.
LEAStudios\Payments\Database\Migration::drop_tables();

// Delete options.
delete_option( 'leastudios_payments_options' );
delete_option( 'leastudios_payments_schema_version' );

// Clean up user meta.
delete_metadata( 'user', 0, 'leastudios_payments_stripe_customer_id', '', true );

flush_rewrite_rules();
