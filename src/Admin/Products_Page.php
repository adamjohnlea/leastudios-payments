<?php
/**
 * Products admin page.
 *
 * @package LEAStudios\Payments\Admin
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Admin;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Payments\Database\Price_Repository;
use LEAStudios\Payments\Database\Product_Repository;
use LEAStudios\Payments\Stripe\Product_Sync;

/**
 * Coordinates the Products admin page — registers menu, dispatches reads to the renderer
 * and writes to the handler, and registers the asset enqueue hook.
 */
class Products_Page {

	/**
	 * The page slug.
	 */
	private const PAGE_SLUG = 'leastudios-payments-products';

	/**
	 * The required capability.
	 */
	private const CAPABILITY = 'manage_options';

	/**
	 * Renderer for list, create, and edit views.
	 *
	 * @var Products_Renderer
	 */
	private readonly Products_Renderer $renderer;

	/**
	 * Handler for POST/GET write actions.
	 *
	 * @var Products_Handler
	 */
	private readonly Products_Handler $handler;

	/**
	 * Asset enqueue helper.
	 *
	 * @var Products_Assets
	 */
	private readonly Products_Assets $assets;

	/**
	 * Constructor.
	 *
	 * @param Product_Repository $product_repository The product repository.
	 * @param Price_Repository   $price_repository   The price repository.
	 * @param Product_Sync       $product_sync       The product sync service.
	 */
	public function __construct(
		Product_Repository $product_repository,
		Price_Repository $price_repository,
		Product_Sync $product_sync,
	) {
		$this->renderer = new Products_Renderer( $product_repository, $price_repository, self::PAGE_SLUG );
		$this->handler  = new Products_Handler( $product_repository, $product_sync, self::PAGE_SLUG );
		$this->assets   = new Products_Assets( self::PAGE_SLUG );
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'add_submenu_page' ] );
		add_action( 'admin_init', [ $this->handler, 'handle_actions' ] );
		add_action( 'admin_enqueue_scripts', [ $this->assets, 'enqueue' ] );
	}

	/**
	 * Add the Products submenu page.
	 *
	 * @return void
	 */
	public function add_submenu_page(): void {
		add_submenu_page(
			'leastudios-payments',
			__( 'Products', 'leastudios-payments' ),
			__( 'Products', 'leastudios-payments' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Route to the correct view based on the action parameter.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked in handler for write operations.
		$action = sanitize_text_field( wp_unslash( $_GET['action'] ?? '' ) );

		match ( $action ) {
			'new'   => $this->renderer->render_create_form(),
			'edit'  => $this->renderer->render_edit_form(),
			default => $this->renderer->render_list(),
		};
	}
}
