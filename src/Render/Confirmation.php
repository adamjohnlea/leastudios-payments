<?php
/**
 * Confirmation page shortcode for displaying checkout results.
 *
 * @package LEAStudios\Payments\Render
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Render;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Payments\Stripe\Stripe_Client;

/**
 * Registers the [leastudios_payment_confirmation] shortcode that displays
 * transaction details on success/cancel pages using merge tags.
 *
 * Available tags:
 *   {customer_name}, {customer_email}, {amount}, {currency},
 *   {product_name}, {payment_status}, {order_type}, {date},
 *   {session_id}, {payment_id}
 */
class Confirmation {

	/**
	 * Cached session data to avoid multiple API calls on the same page.
	 *
	 * @var array|null
	 */
	private ?array $session_cache = null;

	/**
	 * Constructor.
	 *
	 * @param Stripe_Client $stripe_client The Stripe client.
	 */
	public function __construct(
		private readonly Stripe_Client $stripe_client,
	) {}

	/**
	 * Register the shortcode.
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( 'leastudios_payment_confirmation', [ $this, 'handle' ] );
	}

	/**
	 * Handle the shortcode.
	 *
	 * Usage:
	 *   [leastudios_payment_confirmation]Thank you, {customer_name}! Your payment of {amount} was successful.[/leastudios_payment_confirmation]
	 *   [leastudios_payment_confirmation] (with no content, renders a default confirmation)
	 *
	 * @param array|string $atts    Shortcode attributes.
	 * @param string|null  $content The content between opening and closing tags.
	 * @return string The rendered HTML.
	 */
	public function handle( array|string $atts, ?string $content = null ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only; session_id is a Stripe Checkout Session ID.
		$session_id = sanitize_text_field( wp_unslash( $_GET['session_id'] ?? '' ) );

		// If no session_id, show nothing (page was loaded directly, not from checkout).
		if ( '' === $session_id ) {
			return '';
		}

		// Validate format: Stripe session IDs start with cs_.
		if ( ! str_starts_with( $session_id, 'cs_' ) ) {
			return '';
		}

		$session_data = $this->get_session_data( $session_id );

		if ( null === $session_data ) {
			return '';
		}

		// Only show confirmation for completed sessions.
		if ( 'complete' !== ( $session_data['status'] ?? '' ) ) {
			return '';
		}

		// If no custom content provided, use default template.
		if ( empty( $content ) ) {
			$content = $this->get_default_template();
		}

		$output = $this->replace_tags( $content, $session_data );

		return '<div class="leastudios-payments-confirmation">' . wp_kses_post( $output ) . '</div>';
	}

	/**
	 * Replace merge tags in content with session data.
	 *
	 * @param string $content      The content with merge tags.
	 * @param array  $session_data The Stripe session data.
	 * @return string Content with tags replaced.
	 */
	private function replace_tags( string $content, array $session_data ): string {
		$customer_name  = $session_data['customer_details']['name'] ?? '';
		$customer_email = $session_data['customer_details']['email'] ?? '';
		$amount_total   = (int) ( $session_data['amount_total'] ?? 0 );
		$currency       = $session_data['currency'] ?? 'usd';
		$mode           = $session_data['mode'] ?? 'payment';
		$payment_status = $session_data['payment_status'] ?? '';

		// Get product name from line items.
		$product_name = '';

		if ( ! empty( $session_data['line_items']['data'][0]['description'] ) ) {
			$product_name = $session_data['line_items']['data'][0]['description'];
		}

		$tags = [
			'{customer_name}'  => esc_html( $customer_name ),
			'{customer_email}' => esc_html( $customer_email ),
			'{amount}'         => esc_html( $this->format_amount( $amount_total, $currency ) ),
			'{currency}'       => esc_html( strtoupper( $currency ) ),
			'{product_name}'   => esc_html( $product_name ),
			'{payment_status}' => esc_html( ucfirst( $payment_status ) ),
			'{order_type}'     => 'subscription' === $mode
				? esc_html__( 'Subscription', 'leastudios-payments' )
				: esc_html__( 'One-time payment', 'leastudios-payments' ),
			'{date}'           => esc_html( wp_date( get_option( 'date_format' ) ) ?? '' ),
			'{session_id}'     => esc_html( $session_data['id'] ?? '' ),
			'{payment_id}'     => esc_html( $session_data['payment_intent'] ?? '' ),
		];

		/**
		 * Filters the available confirmation merge tags.
		 *
		 * @since 1.0.0
		 *
		 * @param array $tags         Tag => value pairs.
		 * @param array $session_data The Stripe session data.
		 * @return array Filtered tags.
		 */
		$tags = apply_filters( 'leastudios_payments_confirmation_tags', $tags, $session_data );

		return str_replace( array_keys( $tags ), array_values( $tags ), $content );
	}

	/**
	 * Fetch and cache session data from Stripe.
	 *
	 * @param string $session_id The Stripe Checkout Session ID.
	 * @return array|null The session data, or null on failure.
	 */
	private function get_session_data( string $session_id ): ?array {
		if ( null !== $this->session_cache ) {
			return $this->session_cache;
		}

		if ( ! $this->stripe_client->initialize() ) {
			return null;
		}

		try {
			$session = \Stripe\Checkout\Session::retrieve(
				[
					'id'     => $session_id,
					'expand' => [ 'line_items' ],
				]
			);

			$this->session_cache = $session->toArray();

			return $this->session_cache;
		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[leaStudios Payments] Confirmation session retrieval error: ' . $e->getMessage() );
			}

			return null;
		}
	}

	/**
	 * Get the default confirmation template.
	 *
	 * @return string Default HTML template with merge tags.
	 */
	private function get_default_template(): string {
		return '<h3>' . esc_html__( 'Thank you for your purchase!', 'leastudios-payments' ) . '</h3>'
			. '<p>' . esc_html__( 'Your payment has been processed successfully.', 'leastudios-payments' ) . '</p>'
			. '<table style="width:100%;max-width:500px;border-collapse:collapse;">'
			. '<tr><td style="padding:8px;border:1px solid #ddd;font-weight:bold;">' . esc_html__( 'Name', 'leastudios-payments' ) . '</td><td style="padding:8px;border:1px solid #ddd;">{customer_name}</td></tr>'
			. '<tr><td style="padding:8px;border:1px solid #ddd;font-weight:bold;">' . esc_html__( 'Email', 'leastudios-payments' ) . '</td><td style="padding:8px;border:1px solid #ddd;">{customer_email}</td></tr>'
			. '<tr><td style="padding:8px;border:1px solid #ddd;font-weight:bold;">' . esc_html__( 'Amount', 'leastudios-payments' ) . '</td><td style="padding:8px;border:1px solid #ddd;">{amount}</td></tr>'
			. '<tr><td style="padding:8px;border:1px solid #ddd;font-weight:bold;">' . esc_html__( 'Type', 'leastudios-payments' ) . '</td><td style="padding:8px;border:1px solid #ddd;">{order_type}</td></tr>'
			. '</table>';
	}

	/**
	 * Format an amount for display.
	 *
	 * @param int    $amount   Amount in smallest currency unit.
	 * @param string $currency Currency code.
	 * @return string Formatted amount.
	 */
	private function format_amount( int $amount, string $currency ): string {
		$symbols = [
			'usd' => '$',
			'gbp' => "\xc2\xa3",
			'eur' => "\xe2\x82\xac",
			'cad' => 'CA$',
			'aud' => 'A$',
			'nzd' => 'NZ$',
			'chf' => 'CHF ',
			'jpy' => "\xc2\xa5",
		];

		$cur    = strtolower( $currency );
		$symbol = $symbols[ $cur ] ?? strtoupper( $currency ) . ' ';

		if ( 'jpy' === $cur ) {
			return $symbol . number_format( $amount );
		}

		return $symbol . number_format( $amount / 100, 2 );
	}
}
