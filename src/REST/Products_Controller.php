<?php
/**
 * REST API controller for listing products (used by the block editor).
 *
 * @package LEAStudios\Payments\REST
 */

declare(strict_types=1);

namespace LEAStudios\Payments\REST;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Payments\Database\Price_Repository;
use LEAStudios\Payments\Database\Product_Repository;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Returns active products and their prices for the block editor product picker.
 */
class Products_Controller extends WP_REST_Controller {

	/**
	 * The REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'leastudios-payments/v1';

	/**
	 * The REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'products';

	/**
	 * Constructor.
	 *
	 * @param Product_Repository $product_repository The product repository.
	 * @param Price_Repository   $price_repository   The price repository.
	 */
	public function __construct(
		private readonly Product_Repository $product_repository,
		private readonly Price_Repository $price_repository,
	) {}

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ $this, 'get_items_permissions_check' ],
				],
			]
		);
	}

	/**
	 * Check permissions — only editors and above can list products.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return bool True if allowed.
	 */
	public function get_items_permissions_check( $request ): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Get all active products with their active prices.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response The response.
	 */
	public function get_items( $request ): WP_REST_Response {
		$products = $this->product_repository->get_all( 'active' );
		$data     = [];

		foreach ( $products as $product ) {
			$prices      = $this->price_repository->get_by_product( (int) $product->id, 'active' );
			$prices_data = [];

			foreach ( $prices as $price ) {
				$prices_data[] = [
					'id'              => (int) $price->id,
					'stripe_price_id' => $price->stripe_price_id,
					'amount'          => (int) $price->amount,
					'currency'        => $price->currency,
					'type'            => $price->type,
					'interval'        => $price->recurring_interval ?? null,
					'interval_count'  => (int) ( $price->recurring_interval_count ?? 1 ),
				];
			}

			$data[] = [
				'id'          => (int) $product->id,
				'name'        => $product->name,
				'description' => $product->description ?? '',
				'prices'      => $prices_data,
			];
		}

		return new WP_REST_Response( $data, 200 );
	}
}
