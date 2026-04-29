<?php
/**
 * Orders admin page.
 *
 * @package LEAStudios\Payments\Admin
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Admin;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Payments\Database\Order_Repository;
use LEAStudios\Payments\Stripe\Stripe_Client;

/**
 * Coordinates the Orders admin page — registers menu, routes requests to the renderer.
 */
class Orders_Page {

	/**
	 * The page slug.
	 */
	private const PAGE_SLUG = 'leastudios-payments-orders';

	/**
	 * The required capability.
	 */
	private const CAPABILITY = 'manage_options';

	/**
	 * Renderer for list and detail views.
	 *
	 * @var Orders_Renderer
	 */
	private readonly Orders_Renderer $renderer;

	/**
	 * Asset enqueue helper.
	 *
	 * @var Orders_Assets
	 */
	private readonly Orders_Assets $assets;

	/**
	 * Constructor.
	 *
	 * @param Order_Repository $order_repository The order repository.
	 * @param Stripe_Client    $stripe_client    The Stripe client.
	 */
	public function __construct(
		Order_Repository $order_repository,
		Stripe_Client $stripe_client,
	) {
		$this->renderer = new Orders_Renderer( $order_repository, $stripe_client, self::PAGE_SLUG );
		$this->assets   = new Orders_Assets( self::PAGE_SLUG );
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'add_submenu_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this->assets, 'enqueue' ] );
	}

	/**
	 * Add the Orders submenu page.
	 *
	 * @return void
	 */
	public function add_submenu_page(): void {
		add_submenu_page(
			'leastudios-payments',
			__( 'Orders', 'leastudios-payments' ),
			__( 'Orders', 'leastudios-payments' ),
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
