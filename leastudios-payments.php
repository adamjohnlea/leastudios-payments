<?php
/**
 * Plugin Name:       leaStudios Payments
 * Plugin URI:        https://leastudios.com/plugins/leastudios-payments
 * Description:       Stripe payments for WordPress. Accept one-time payments and subscriptions using Stripe Embedded Checkout.
 * Version:           1.0.3
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            leaStudios
 * Author URI:        https://leastudios.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       leastudios-payments
 * Domain Path:       /languages
 *
 * @package LEAStudios\Payments
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

// Derive the version from the plugin header so the runtime constant can
// never drift from the version shipped in the release zip.
define(
	'LEASTUDIOS_PAYMENTS_VERSION',
	get_file_data( __FILE__, [ 'Version' => 'Version' ] )['Version']
);
define( 'LEASTUDIOS_PAYMENTS_FILE', __FILE__ );
define( 'LEASTUDIOS_PAYMENTS_DIR', plugin_dir_path( __FILE__ ) );
define( 'LEASTUDIOS_PAYMENTS_URL', plugin_dir_url( __FILE__ ) );

if ( ! file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		function () {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong>: %s</p></div>',
				esc_html__( 'leaStudios Payments', 'leastudios-payments' ),
				esc_html__( 'Plugin dependencies are missing. Run "composer install" in the plugin directory.', 'leastudios-payments' )
			);
		}
	);
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

/**
 * Initialize the plugin.
 *
 * @return void
 */
function leastudios_payments_init(): void {
	if ( version_compare( PHP_VERSION, '8.2', '<' ) ) {
		add_action( 'admin_notices', 'leastudios_payments_php_version_notice' );
		return;
	}

	$plugin = new LEAStudios\Payments\Plugin();
	$plugin->init();
}
add_action( 'plugins_loaded', 'leastudios_payments_init' );

/**
 * Display PHP version notice.
 *
 * @return void
 */
function leastudios_payments_php_version_notice(): void {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'leaStudios Payments requires PHP 8.2 or higher.', 'leastudios-payments' )
	);
}

/**
 * Run on plugin activation.
 *
 * @return void
 */
function leastudios_payments_activate(): void {
	$migration = new LEAStudios\Payments\Database\Migration();
	$migration->maybe_migrate();

	if ( false === get_option( 'leastudios_payments_options' ) ) {
		update_option(
			'leastudios_payments_options',
			[
				'test_mode'        => true,
				'publishable_key'  => '',
				'secret_key'       => '',
				'webhook_secret'   => '',
				'default_currency' => 'USD',
				'success_page'     => 0,
				'cancel_page'      => 0,
			]
		);
	}

	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'leastudios_payments_activate' );

/**
 * Run on plugin deactivation.
 *
 * @return void
 */
function leastudios_payments_deactivate(): void {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'leastudios_payments_deactivate' );
