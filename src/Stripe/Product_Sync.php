<?php
/**
 * Syncs products and prices between WordPress and Stripe.
 *
 * @package LEAStudios\Payments\Stripe
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Stripe;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Payments\Database\Price_Repository;
use LEAStudios\Payments\Database\Product_Repository;

/**
 * Creates and updates products and prices in Stripe, storing IDs locally.
 */
class Product_Sync {

	/**
	 * Constructor.
	 *
	 * @param Stripe_Client      $stripe_client      The Stripe client.
	 * @param Product_Repository $product_repository The product repository.
	 * @param Price_Repository   $price_repository   The price repository.
	 */
	public function __construct(
		private readonly Stripe_Client $stripe_client,
		private readonly Product_Repository $product_repository,
		private readonly Price_Repository $price_repository,
	) {}

	/**
	 * Create a product with one or more prices in Stripe and store locally.
	 *
	 * @param string                           $name        The product name.
	 * @param string                           $description The product description.
	 * @param string                           $image_url   The product image URL.
	 * @param array<int, array<string, mixed>> $prices Array of price definitions, each with keys: amount, currency, type, recurring_interval, recurring_interval_count.
	 * @param bool                             $require_shipping Whether to collect shipping address at checkout.
	 * @return array{success: bool, product_id: int, error: string}
	 */
	public function create_product( string $name, string $description, string $image_url, array $prices, bool $require_shipping = false ): array {
		if ( ! $this->stripe_client->initialize() ) {
			return [
				'success'    => false,
				'product_id' => 0,
				'error'      => __( 'Stripe is not configured. Please add your API keys in Settings.', 'leastudios-payments' ),
			];
		}

		try {
			$product_args = [
				'name'        => $name,
				'description' => $description,
				'metadata'    => [
					'source'   => 'leastudios-payments',
					'site_url' => get_site_url(),
				],
			];

			if ( '' !== $image_url ) {
				$product_args['images'] = [ $image_url ];
			}

			/**
			 * Filters the Stripe product creation arguments.
			 *
			 * @since 1.0.0
			 *
			 * @param array  $product_args The Stripe product arguments.
			 * @param string $name         The product name.
			 * @return array Filtered arguments.
			 */
			$product_args = apply_filters( 'leastudios_payments_stripe_product_args', $product_args, $name );

			$stripe_product = \Stripe\Product::create( $product_args );
		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			$this->log_stripe_error( 'create_product', $e );

			return [
				'success'    => false,
				'product_id' => 0,
				'error'      => __( 'Failed to create product in Stripe. Please check your API keys and try again.', 'leastudios-payments' ),
			];
		}

		// Store product locally.
		$local_product_id = $this->product_repository->create(
			$stripe_product->id,
			$name,
			$description,
			$image_url,
			$require_shipping
		);

		if ( 0 === $local_product_id ) {
			return [
				'success'    => false,
				'product_id' => 0,
				'error'      => __( 'Failed to save product locally.', 'leastudios-payments' ),
			];
		}

		// Create each price in Stripe and store locally.
		foreach ( $prices as $price_data ) {
			$this->create_price_for_product( $stripe_product->id, $local_product_id, $price_data );
		}

		/**
		 * Fires after a product is created in Stripe and stored locally.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $local_product_id The local product ID.
		 * @param string $stripe_product_id The Stripe product ID.
		 */
		do_action( 'leastudios_payments_product_created', $local_product_id, $stripe_product->id );

		return [
			'success'    => true,
			'product_id' => $local_product_id,
			'error'      => '',
		];
	}

	/**
	 * Update a product in Stripe and locally.
	 *
	 * @param int    $local_product_id The local product ID.
	 * @param string $name             The updated product name.
	 * @param string $description      The updated description.
	 * @param string $image_url        The updated image URL.
	 * @return array{success: bool, error: string}
	 */
	public function update_product( int $local_product_id, string $name, string $description, string $image_url ): array {
		if ( ! $this->stripe_client->initialize() ) {
			return [
				'success' => false,
				'error'   => __( 'Stripe is not configured.', 'leastudios-payments' ),
			];
		}

		$product = $this->product_repository->get( $local_product_id );

		if ( ! $product ) {
			return [
				'success' => false,
				'error'   => __( 'Product not found.', 'leastudios-payments' ),
			];
		}

		try {
			$update_args = [
				'name'        => $name,
				'description' => $description,
			];

			if ( '' !== $image_url ) {
				$update_args['images'] = [ $image_url ];
			} else {
				$update_args['images'] = [];
			}

			\Stripe\Product::update( $product->stripe_product_id, $update_args );
		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			$this->log_stripe_error( 'update_product', $e );

			return [
				'success' => false,
				'error'   => __( 'Failed to update product in Stripe. Please try again.', 'leastudios-payments' ),
			];
		}

		// Update locally.
		$this->product_repository->update(
			$local_product_id,
			[
				'name'        => $name,
				'description' => $description,
				'image_url'   => $image_url,
			]
		);

		return [
			'success' => true,
			'error'   => '',
		];
	}

	/**
	 * Add a price to an existing product.
	 *
	 * @param int                  $local_product_id The local product ID.
	 * @param array<string, mixed> $price_data       Price definition with keys: amount, currency, type, recurring_interval, recurring_interval_count.
	 * @return array{success: bool, price_id: int, error: string}
	 */
	public function add_price( int $local_product_id, array $price_data ): array {
		if ( ! $this->stripe_client->initialize() ) {
			return [
				'success'  => false,
				'price_id' => 0,
				'error'    => __( 'Stripe is not configured.', 'leastudios-payments' ),
			];
		}

		$product = $this->product_repository->get( $local_product_id );

		if ( ! $product ) {
			return [
				'success'  => false,
				'price_id' => 0,
				'error'    => __( 'Product not found.', 'leastudios-payments' ),
			];
		}

		$local_price_id = $this->create_price_for_product( $product->stripe_product_id, $local_product_id, $price_data );

		if ( 0 === $local_price_id ) {
			return [
				'success'  => false,
				'price_id' => 0,
				'error'    => __( 'Failed to create price.', 'leastudios-payments' ),
			];
		}

		return [
			'success'  => true,
			'price_id' => $local_price_id,
			'error'    => '',
		];
	}

	/**
	 * Archive (deactivate) a price in Stripe and locally.
	 *
	 * Stripe prices cannot be deleted, only deactivated.
	 *
	 * @param int $local_price_id The local price ID.
	 * @return array{success: bool, error: string}
	 */
	public function archive_price( int $local_price_id ): array {
		if ( ! $this->stripe_client->initialize() ) {
			return [
				'success' => false,
				'error'   => __( 'Stripe is not configured.', 'leastudios-payments' ),
			];
		}

		$price = $this->price_repository->get( $local_price_id );

		if ( ! $price ) {
			return [
				'success' => false,
				'error'   => __( 'Price not found.', 'leastudios-payments' ),
			];
		}

		try {
			\Stripe\Price::update( $price->stripe_price_id, [ 'active' => false ] );
		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			$this->log_stripe_error( 'archive_price', $e );

			return [
				'success' => false,
				'error'   => __( 'Failed to archive price in Stripe. Please try again.', 'leastudios-payments' ),
			];
		}

		$this->price_repository->update( $local_price_id, [ 'status' => 'inactive' ] );

		return [
			'success' => true,
			'error'   => '',
		];
	}

	/**
	 * Toggle product status (active/inactive) locally. Does not affect Stripe.
	 *
	 * @param int    $local_product_id The local product ID.
	 * @param string $status           New status: 'active' or 'inactive'.
	 * @return bool True on success.
	 */
	public function set_product_status( int $local_product_id, string $status ): bool {
		if ( ! in_array( $status, [ 'active', 'inactive' ], true ) ) {
			return false;
		}

		return $this->product_repository->update( $local_product_id, [ 'status' => $status ] );
	}

	/**
	 * Create a single price in Stripe and store locally.
	 *
	 * @param string               $stripe_product_id The Stripe product ID.
	 * @param int                  $local_product_id  The local product ID.
	 * @param array<string, mixed> $price_data        Price definition.
	 * @return int The local price ID, or 0 on failure.
	 */
	private function create_price_for_product( string $stripe_product_id, int $local_product_id, array $price_data ): int {
		$amount   = (int) ( $price_data['amount'] ?? 0 );
		$currency = strtolower( $price_data['currency'] ?? $this->stripe_client->get_default_currency() );
		$type     = $price_data['type'] ?? 'one_time';

		$price_args = [
			'product'     => $stripe_product_id,
			'unit_amount' => $amount,
			'currency'    => $currency,
			'metadata'    => [
				'source' => 'leastudios-payments',
			],
		];

		if ( 'recurring' === $type ) {
			$interval       = $price_data['recurring_interval'] ?? 'month';
			$interval_count = (int) ( $price_data['recurring_interval_count'] ?? 1 );

			$price_args['recurring'] = [
				'interval'       => $interval,
				'interval_count' => $interval_count,
			];
		}

		/**
		 * Filters the Stripe price creation arguments.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $price_args        The Stripe price arguments.
		 * @param int    $local_product_id  The local product ID.
		 * @return array Filtered arguments.
		 */
		$price_args = apply_filters( 'leastudios_payments_stripe_price_args', $price_args, $local_product_id );

		try {
			$stripe_price = \Stripe\Price::create( $price_args );
		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			return 0;
		}

		return $this->price_repository->create(
			$stripe_price->id,
			$local_product_id,
			$amount,
			$currency,
			$type,
			'recurring' === $type ? ( $price_data['recurring_interval'] ?? 'month' ) : '',
			'recurring' === $type ? (int) ( $price_data['recurring_interval_count'] ?? 1 ) : 1,
		);
	}

	/**
	 * Log a Stripe API error for debugging.
	 *
	 * @param string                              $context The operation that failed.
	 * @param \Stripe\Exception\ApiErrorException $e       The exception.
	 * @return void
	 */
	private function log_stripe_error( string $context, \Stripe\Exception\ApiErrorException $e ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'[leaStudios Payments] Stripe API error in %s: %s',
					$context,
					$e->getMessage()
				)
			);
		}
	}
}
