<?php
/**
 * Gutenberg block for Stripe Embedded Checkout.
 *
 * @package LEAStudios\Payments\Render
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Render;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Payments\Database\Price_Repository;
use LEAStudios\Payments\Database\Product_Repository;

/**
 * Registers the leastudios-payments/checkout block.
 */
class Block {

	/**
	 * The shortcode renderer (reused for server-side rendering).
	 *
	 * @var Shortcode
	 */
	private Shortcode $shortcode;

	/**
	 * Constructor.
	 *
	 * @param Shortcode          $shortcode          The shortcode handler.
	 * @param Product_Repository $product_repository The product repository.
	 * @param Price_Repository   $price_repository   The price repository.
	 */
	public function __construct(
		Shortcode $shortcode,
		private readonly Product_Repository $product_repository,
		private readonly Price_Repository $price_repository,
	) {
		$this->shortcode = $shortcode;
	}

	/**
	 * Register the block type.
	 *
	 * @return void
	 */
	public function register(): void {
		wp_register_script(
			'leastudios-payments-block-editor',
			LEASTUDIOS_PAYMENTS_URL . 'assets/js/block-editor.js',
			[ 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render' ],
			LEASTUDIOS_PAYMENTS_VERSION,
			true
		);

		register_block_type(
			'leastudios-payments/checkout',
			[
				'attributes'      => [
					'priceId'   => [
						'type'    => 'number',
						'default' => 0,
					],
					'productId' => [
						'type'    => 'number',
						'default' => 0,
					],
				],
				'editor_script'   => 'leastudios-payments-block-editor',
				'render_callback' => [ $this, 'render_block' ],
			]
		);

		add_action( 'enqueue_block_editor_assets', [ $this, 'localize_block_data' ] );
	}

	/**
	 * Pass products list to the block editor.
	 *
	 * @return void
	 */
	public function localize_block_data(): void {
		$products = $this->product_repository->get_all( 'active' );
		$data     = [];

		foreach ( $products as $product ) {
			$prices      = $this->price_repository->get_by_product( (int) $product->id, 'active' );
			$prices_data = [];

			foreach ( $prices as $price ) {
				$prices_data[] = [
					'id'       => (int) $price->id,
					'amount'   => (int) $price->amount,
					'currency' => $price->currency,
					'type'     => $price->type,
					'interval' => $price->recurring_interval ?? null,
				];
			}

			$data[] = [
				'id'     => (int) $product->id,
				'name'   => $product->name,
				'prices' => $prices_data,
			];
		}

		wp_localize_script(
			'leastudios-payments-block-editor',
			'leastudiosPaymentsBlock',
			[ 'products' => $data ]
		);
	}

	/**
	 * Server-side render callback for the block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string The rendered checkout HTML.
	 */
	public function render_block( array $attributes ): string {
		return $this->shortcode->handle(
			[
				'price_id'   => $attributes['priceId'] ?? 0,
				'product_id' => $attributes['productId'] ?? 0,
			]
		);
	}
}
