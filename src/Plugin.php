<?php
/**
 * Main plugin bootstrap class.
 *
 * @package LEAStudios\Payments
 */

declare(strict_types=1);

namespace LEAStudios\Payments;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Payments\Admin\Customers_Page;
use LEAStudios\Payments\Admin\Dashboard_Widget;
use LEAStudios\Payments\Admin\Orders_Page;
use LEAStudios\Payments\Admin\Products_Page;
use LEAStudios\Payments\Admin\Settings_Page;
use LEAStudios\Payments\Admin\Subscriptions_Page;
use LEAStudios\Payments\Admin\Tags_Reference_Page;
use LEAStudios\Payments\Checkout\Checkout_Handler;
use LEAStudios\Payments\Checkout\Refund_Handler;
use LEAStudios\Payments\Checkout\Session_Factory;
use LEAStudios\Payments\Checkout\Subscription_Handler;
use LEAStudios\Payments\Database\Migration;
use LEAStudios\Payments\Database\Order_Repository;
use LEAStudios\Payments\Database\Price_Repository;
use LEAStudios\Payments\Database\Product_Repository;
use LEAStudios\Payments\Database\Subscription_Repository;
use LEAStudios\Payments\Encryption\Options_Encryptor;
use LEAStudios\Payments\Render\Account;
use LEAStudios\Payments\Render\Block;
use LEAStudios\Payments\Render\Confirmation;
use LEAStudios\Payments\Render\Shortcode;
use LEAStudios\Payments\REST\Checkout_Controller;
use LEAStudios\Payments\REST\Portal_Controller;
use LEAStudios\Payments\REST\Products_Controller;
use LEAStudios\Payments\REST\Refund_Controller;
use LEAStudios\Payments\REST\Webhook_Controller;
use LEAStudios\Payments\Stripe\Customer_Manager;
use LEAStudios\Payments\Stripe\Product_Sync;
use LEAStudios\Payments\Stripe\Stripe_Client;

/**
 * Wires all plugin components together.
 */
final class Plugin {

	/**
	 * Initialize the plugin.
	 *
	 * @return void
	 */
	public function init(): void {
		// Run migrations.
		$migration = new Migration();
		$migration->maybe_migrate();

		// Core services.
		$encryptor     = new Options_Encryptor();
		$stripe_client = new Stripe_Client( $encryptor );

		// Repositories.
		$product_repo      = new Product_Repository();
		$price_repo        = new Price_Repository();
		$order_repo        = new Order_Repository();
		$subscription_repo = new Subscription_Repository();

		// Stripe services.
		$product_sync     = new Product_Sync( $stripe_client, $product_repo, $price_repo );
		$customer_manager = new Customer_Manager( $stripe_client );

		// Checkout and webhook handlers.
		$session_factory  = new Session_Factory( $stripe_client, $customer_manager, $price_repo, $product_repo );
		$checkout_handler = new Checkout_Handler( $stripe_client, $order_repo );
		$checkout_handler->init();

		$refund_handler = new Refund_Handler( $order_repo );
		$refund_handler->init();

		$subscription_handler = new Subscription_Handler( $subscription_repo, $customer_manager, $stripe_client );
		$subscription_handler->init();

		// REST API.
		$webhook_controller  = new Webhook_Controller( $stripe_client );
		$checkout_controller = new Checkout_Controller( $session_factory );
		$products_controller = new Products_Controller( $product_repo, $price_repo );
		$refund_controller   = new Refund_Controller( $stripe_client, $order_repo );
		$portal_controller   = new Portal_Controller( $stripe_client, $customer_manager );

		add_action( 'rest_api_init', [ $webhook_controller, 'register_routes' ] );
		add_action( 'rest_api_init', [ $checkout_controller, 'register_routes' ] );
		add_action( 'rest_api_init', [ $products_controller, 'register_routes' ] );
		add_action( 'rest_api_init', [ $refund_controller, 'register_routes' ] );
		add_action( 'rest_api_init', [ $portal_controller, 'register_routes' ] );

		// Frontend rendering.
		$shortcode    = new Shortcode( $stripe_client, $product_repo, $price_repo );
		$block        = new Block( $shortcode, $product_repo, $price_repo );
		$confirmation = new Confirmation( $stripe_client );

		$account = new Account( $order_repo, $subscription_repo, $stripe_client, $customer_manager );

		add_action( 'init', [ $shortcode, 'register' ] );
		add_action( 'init', [ $block, 'register' ] );
		add_action( 'init', [ $confirmation, 'register' ] );
		add_action( 'init', [ $account, 'register' ] );

		// Admin.
		if ( is_admin() ) {
			$settings_page = new Settings_Page( $encryptor );
			$settings_page->init();

			$products_page = new Products_Page( $product_repo, $price_repo, $product_sync );
			$products_page->init();

			$orders_page = new Orders_Page( $order_repo, $stripe_client );
			$orders_page->init();

			$subscriptions_page = new Subscriptions_Page( $subscription_repo, $stripe_client );
			$subscriptions_page->init();

			$customers_page = new Customers_Page( $order_repo, $subscription_repo, $stripe_client );
			$customers_page->init();

			$tags_page = new Tags_Reference_Page();
			$tags_page->init();

			$dashboard_widget = new Dashboard_Widget( $order_repo, $subscription_repo, $stripe_client );
			$dashboard_widget->init();
		}

		/**
		 * Fires after the leaStudios Payments plugin has fully initialized.
		 *
		 * @since 1.0.0
		 *
		 * @param Stripe_Client $stripe_client The Stripe client instance.
		 */
		do_action( 'leastudios_payments_initialized', $stripe_client );
	}
}
