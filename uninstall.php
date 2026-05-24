<?php
/**
 * Uninstall handler — runs when the plugin is deleted via WP admin.
 *
 * Default behaviour is data-retaining: the schema, payment records,
 * subscription history, and Stripe customer mappings are kept so an
 * admin who reinstalls the plugin still has their financial audit
 * trail. Set `leastudios_payments_options.delete_data_on_uninstall`
 * to `1` (or define `LEASTUDIOS_PAYMENTS_DELETE_DATA_ON_UNINSTALL`)
 * to opt into a full wipe.
 *
 * Tax / accounting / chargeback investigations can require historical
 * order data months or years after a plugin was deleted, so we err on
 * the side of preservation.
 *
 * @package LEAStudios\Payments
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Execute the uninstall routine.
 *
 * Wrapped in a function so the file introduces no unprefixed
 * variables at global scope (Plugin Check PrefixAllGlobals).
 *
 * @return void
 */
function leastudios_payments_run_uninstall(): void {
	if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
		require_once __DIR__ . '/vendor/autoload.php';
	}

	$options       = get_option( 'leastudios_payments_options', [] );
	$opt_in_wipe   = is_array( $options ) && ! empty( $options['delete_data_on_uninstall'] );
	$constant_wipe = defined( 'LEASTUDIOS_PAYMENTS_DELETE_DATA_ON_UNINSTALL' ) && constant( 'LEASTUDIOS_PAYMENTS_DELETE_DATA_ON_UNINSTALL' );

	if ( $opt_in_wipe || $constant_wipe ) {
		// Full wipe: drop all custom tables, clear user-meta mappings, delete options.
		LEAStudios\Payments\Database\Migration::drop_tables();
		delete_metadata( 'user', 0, 'leastudios_payments_stripe_customer_id', '', true );
		delete_option( 'leastudios_payments_options' );
		delete_option( 'leastudios_payments_schema_version' );
	}

	flush_rewrite_rules();
}

leastudios_payments_run_uninstall();
