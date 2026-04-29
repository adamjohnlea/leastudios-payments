<?php
/**
 * Customers admin page.
 *
 * @package LEAStudios\Payments\Admin
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Admin;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Payments\Stripe\Stripe_Client;

/**
 * Coordinates the Customers admin page — registers menu, routes requests to the renderer.
 */
class Customers_Page {

	/**
	 * The page slug.
	 */
	private const PAGE_SLUG = 'leastudios-payments-customers';

	/**
	 * The required capability.
	 */
	private const CAPABILITY = 'manage_options';

	/**
	 * Renderer for list and detail views.
	 *
	 * @var Customers_Renderer
	 */
	private readonly Customers_Renderer $renderer;

	/**
	 * Constructor.
	 *
	 * @param Stripe_Client $stripe_client The Stripe client (used for dashboard URLs in detail view).
	 */
	public function __construct( Stripe_Client $stripe_client ) {
		$this->renderer = new Customers_Renderer( new Customers_Query(), $stripe_client, self::PAGE_SLUG );
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'add_submenu_page' ] );
	}

	/**
	 * Add the Customers submenu page.
	 *
	 * @return void
	 */
	public function add_submenu_page(): void {
		add_submenu_page(
			'leastudios-payments',
			__( 'Customers', 'leastudios-payments' ),
			__( 'Customers', 'leastudios-payments' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Route to list or detail view.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing.
		$action = sanitize_text_field( wp_unslash( $_GET['action'] ?? '' ) );

		if ( 'view' === $action ) {
			$this->renderer->render_detail();
		} else {
			$this->renderer->render_list();
		}
	}
}
