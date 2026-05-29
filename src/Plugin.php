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
use LEAStudios\Payments\Database\Webhook_Event_Repository;
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
use LEAStudios\Payments\Shared\Container;
use LEAStudios\Payments\Stripe\Customer_Manager;
use LEAStudios\Payments\Stripe\Product_Sync;
use LEAStudios\Payments\Stripe\Stripe_Client;
use LEAStudios\Payments\Support\Email_Context_Provider;

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
		( new Migration() )->maybe_migrate();

		$container = new Container();
		$this->register_services( $container );

		$this->register_handlers( $container );
		$this->register_rest_routes( $container );
		$this->register_frontend_render( $container );
		$this->register_email_context_provider( $container );

		if ( is_admin() ) {
			$this->init_admin( $container );
		}

		/**
		 * Fires after the leaStudios Payments plugin has fully initialized.
		 *
		 * @since 1.0.0
		 *
		 * @param Stripe_Client $stripe_client The Stripe client instance.
		 */
		do_action( 'leastudios_payments_initialized', $container->get( 'stripe_client' ) );
	}

	/**
	 * Register every service factory on the container. Each `set()` is a
	 * lazy closure — services are constructed on first `get()` and cached.
	 *
	 * @param Container $c Container to populate.
	 *
	 * @return void
	 */
	private function register_services( Container $c ): void {
		// Core.
		$c->set( 'encryptor', static fn() => new Options_Encryptor() );
		$c->set( 'stripe_client', static fn( Container $c ) => new Stripe_Client( $c->get( 'encryptor' ) ) );

		// Repositories.
		$c->set( 'product_repo', static fn() => new Product_Repository() );
		$c->set( 'price_repo', static fn() => new Price_Repository() );
		$c->set( 'order_repo', static fn() => new Order_Repository() );
		$c->set( 'subscription_repo', static fn() => new Subscription_Repository() );
		$c->set( 'webhook_event_repo', static fn() => new Webhook_Event_Repository() );

		// Stripe-side services.
		$c->set(
			'product_sync',
			static fn( Container $c ) => new Product_Sync(
				$c->get( 'stripe_client' ),
				$c->get( 'product_repo' ),
				$c->get( 'price_repo' )
			)
		);
		$c->set(
			'customer_manager',
			static fn( Container $c ) => new Customer_Manager( $c->get( 'stripe_client' ) )
		);

		// Checkout flow.
		$c->set(
			'session_factory',
			static fn( Container $c ) => new Session_Factory(
				$c->get( 'stripe_client' ),
				$c->get( 'customer_manager' ),
				$c->get( 'price_repo' ),
				$c->get( 'product_repo' )
			)
		);
		$c->set(
			'checkout_handler',
			static fn( Container $c ) => new Checkout_Handler(
				$c->get( 'stripe_client' ),
				$c->get( 'order_repo' ),
				$c->get( 'customer_manager' )
			)
		);
		$c->set(
			'refund_handler',
			static fn( Container $c ) => new Refund_Handler( $c->get( 'order_repo' ) )
		);
		$c->set(
			'subscription_handler',
			static fn( Container $c ) => new Subscription_Handler(
				$c->get( 'subscription_repo' ),
				$c->get( 'stripe_client' ),
				$c->get( 'customer_manager' )
			)
		);
	}

	/**
	 * Wire the always-on handlers (run for both REST and admin requests).
	 *
	 * @param Container $c Service container.
	 *
	 * @return void
	 */
	private function register_handlers( Container $c ): void {
		$c->get( 'checkout_handler' )->init();
		$c->get( 'refund_handler' )->init();
		$c->get( 'subscription_handler' )->init();
	}

	/**
	 * Register every REST controller on the `rest_api_init` hook.
	 *
	 * @param Container $c Service container.
	 *
	 * @return void
	 */
	private function register_rest_routes( Container $c ): void {
		$webhook_controller  = new Webhook_Controller( $c->get( 'stripe_client' ), $c->get( 'webhook_event_repo' ) );
		$checkout_controller = new Checkout_Controller( $c->get( 'session_factory' ) );
		$products_controller = new Products_Controller( $c->get( 'product_repo' ), $c->get( 'price_repo' ) );
		$refund_controller   = new Refund_Controller( $c->get( 'stripe_client' ), $c->get( 'order_repo' ) );
		$portal_controller   = new Portal_Controller( $c->get( 'stripe_client' ) );

		add_action( 'rest_api_init', [ $webhook_controller, 'register_routes' ] );
		add_action( 'rest_api_init', [ $checkout_controller, 'register_routes' ] );
		add_action( 'rest_api_init', [ $products_controller, 'register_routes' ] );
		add_action( 'rest_api_init', [ $refund_controller, 'register_routes' ] );
		add_action( 'rest_api_init', [ $portal_controller, 'register_routes' ] );
	}

	/**
	 * Register frontend shortcode / block / account renderers on `init`.
	 *
	 * @param Container $c Service container.
	 *
	 * @return void
	 */
	private function register_frontend_render( Container $c ): void {
		$shortcode    = new Shortcode( $c->get( 'stripe_client' ), $c->get( 'product_repo' ), $c->get( 'price_repo' ) );
		$block        = new Block( $shortcode, $c->get( 'product_repo' ), $c->get( 'price_repo' ) );
		$confirmation = new Confirmation( $c->get( 'stripe_client' ), $c->get( 'customer_manager' ) );
		$account      = new Account( $c->get( 'customer_manager' ) );

		add_action( 'init', [ $shortcode, 'register' ] );
		add_action( 'init', [ $block, 'register' ] );
		add_action( 'init', [ $confirmation, 'register' ] );
		add_action( 'init', [ $account, 'register' ] );
	}

	/**
	 * Register the public read seam that exposes order/subscription context as
	 * plain arrays for sibling plugins (e.g. leastudios-email-templates) via
	 * the `leastudios_payments_*_email_context` filters.
	 *
	 * @param Container $c Service container.
	 *
	 * @return void
	 */
	private function register_email_context_provider( Container $c ): void {
		( new Email_Context_Provider( $c->get( 'order_repo' ), $c->get( 'subscription_repo' ) ) )->init();
	}

	/**
	 * Initialize admin pages and the dashboard widget.
	 *
	 * @param Container $c Service container.
	 *
	 * @return void
	 */
	private function init_admin( Container $c ): void {
		( new Settings_Page( $c->get( 'encryptor' ) ) )->init();
		( new Products_Page( $c->get( 'product_repo' ), $c->get( 'price_repo' ), $c->get( 'product_sync' ) ) )->init();
		( new Orders_Page( $c->get( 'order_repo' ), $c->get( 'stripe_client' ) ) )->init();
		( new Subscriptions_Page( $c->get( 'subscription_repo' ), $c->get( 'stripe_client' ) ) )->init();
		( new Customers_Page( $c->get( 'stripe_client' ) ) )->init();
		( new Tags_Reference_Page() )->init();
		( new Dashboard_Widget( $c->get( 'order_repo' ), $c->get( 'subscription_repo' ), $c->get( 'stripe_client' ) ) )->init();
	}
}
