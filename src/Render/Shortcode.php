<?php
/**
 * Shortcode handler for embedding Stripe Checkout.
 *
 * @package LEAStudios\Payments\Render
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Render;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Payments\Database\Price_Repository;
use LEAStudios\Payments\Database\Product_Repository;
use LEAStudios\Payments\Stripe\Stripe_Client;

/**
 * Registers and handles the [leastudios_payment] shortcode.
 */
class Shortcode {

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
	 * Register the shortcode.
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( 'leastudios_payment', [ $this, 'handle' ] );
	}

	/**
	 * Handle the shortcode.
	 *
	 * Usage:
	 *   [leastudios_payment price_id="5"]
	 *   [leastudios_payment product_id="3"]  — uses first active price.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string The rendered HTML.
	 */
	public function handle( array|string $atts ): string {
		$atts = shortcode_atts(
			[
				'price_id'   => 0,
				'product_id' => 0,
			],
			$atts,
			'leastudios_payment'
		);

		$price_id   = absint( $atts['price_id'] );
		$product_id = absint( $atts['product_id'] );

		// Resolve price_id from product_id if needed.
		if ( 0 === $price_id && $product_id > 0 ) {
			$prices = $this->price_repository->get_by_product( $product_id, 'active' );

			if ( ! empty( $prices ) ) {
				$price_id = (int) $prices[0]->id;
			}
		}

		if ( 0 === $price_id ) {
			return '<!-- leastudios-payments: no price specified -->';
		}

		// Verify the price exists and is active.
		$price = $this->price_repository->get( $price_id );

		if ( ! $price || 'active' !== $price->status ) {
			return '<!-- leastudios-payments: price not found or inactive -->';
		}

		// Login is required for checkout.
		if ( ! is_user_logged_in() ) {
			return sprintf(
				'<div class="leastudios-payments-login-required"><p>%s</p><p><a href="%s" class="button">%s</a></p></div>',
				esc_html__( 'Please log in or create an account to continue with your purchase.', 'leastudios-payments' ),
				esc_url( wp_login_url( get_permalink() ) ),
				esc_html__( 'Log In', 'leastudios-payments' )
			);
		}

		// Get the product for display.
		$product = $this->product_repository->get( (int) $price->product_id );

		// Check Stripe is configured.
		$publishable_key = $this->stripe_client->get_publishable_key();

		if ( '' === $publishable_key ) {
			return '<!-- leastudios-payments: Stripe not configured -->';
		}

		$this->enqueue_assets( $price_id, $publishable_key );

		$product_name = $product ? esc_html( $product->name ) : '';

		ob_start();
		?>
		<div
			class="leastudios-payments-checkout"
			data-price-id="<?php echo esc_attr( (string) $price_id ); ?>"
			data-product-name="<?php echo esc_attr( $product_name ); ?>"
		>
			<div id="leastudios-payments-checkout-<?php echo esc_attr( (string) $price_id ); ?>" class="leastudios-payments-checkout-mount">
				<!-- Stripe Embedded Checkout renders here -->
			</div>
			<div class="leastudios-payments-checkout-status" style="display:none;"></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Enqueue frontend assets for the checkout.
	 *
	 * @param int    $price_id        The price ID for this checkout instance.
	 * @param string $publishable_key The Stripe publishable key.
	 * @return void
	 */
	private function enqueue_assets( int $price_id, string $publishable_key ): void {
		// Stripe.js.
		if ( ! wp_script_is( 'stripe-js', 'registered' ) ) {
			wp_register_script( 'stripe-js', 'https://js.stripe.com/v3/', [], null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		}

		wp_enqueue_style(
			'leastudios-payments-frontend',
			LEASTUDIOS_PAYMENTS_URL . 'assets/css/frontend.css',
			[],
			LEASTUDIOS_PAYMENTS_VERSION
		);

		wp_enqueue_script(
			'leastudios-payments-checkout',
			LEASTUDIOS_PAYMENTS_URL . 'assets/js/frontend-checkout.js',
			[ 'stripe-js' ],
			LEASTUDIOS_PAYMENTS_VERSION,
			true
		);

		wp_localize_script(
			'leastudios-payments-checkout',
			'leastudiosPayments',
			[
				'publishableKey' => $publishable_key,
				'checkoutUrl'    => rest_url( 'leastudios-payments/v1/checkout-session' ),
				'restNonce'      => wp_create_nonce( 'wp_rest' ),
				'returnUrl'      => $this->get_return_url(),
				'loadingText'    => __( 'Loading checkout...', 'leastudios-payments' ),
				'errorText'      => __( 'Something went wrong. Please try again.', 'leastudios-payments' ),
				'completedText'  => __( 'Payment complete! Redirecting...', 'leastudios-payments' ),
			]
		);
	}

	/**
	 * Get the return URL after checkout completion.
	 *
	 * @return string The return URL.
	 */
	private function get_return_url(): string {
		$options      = get_option( 'leastudios_payments_options', [] );
		$success_page = is_array( $options ) ? absint( $options['success_page'] ?? 0 ) : 0;

		if ( $success_page > 0 ) {
			$url = get_permalink( $success_page );

			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}

		// Default: return to the current page.
		$current = get_permalink();

		return is_string( $current ) && '' !== $current ? $current : home_url();
	}
}
