<?php
/**
 * Products admin page write handler.
 *
 * @package LEAStudios\Payments\Admin
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Admin;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Payments\Database\Product_Repository;
use LEAStudios\Payments\Security\Nonce;
use LEAStudios\Payments\Stripe\Product_Sync;

/**
 * Handles POST/GET write actions for the Products admin page.
 *
 * Each handler verifies the nonce, performs the write, then redirects via wp_safe_redirect()+exit
 * (or calls redirect_with_error() to round-trip a transient error message).
 */
class Products_Handler {

	private const CAPABILITY = 'manage_options';

	/**
	 * Constructor.
	 *
	 * @param Product_Repository $product_repository The product repository.
	 * @param Product_Sync       $product_sync       The product sync service (Stripe writes).
	 * @param string             $page_slug          The owning page's slug.
	 */
	public function __construct(
		private readonly Product_Repository $product_repository,
		private readonly Product_Sync $product_sync,
		private readonly string $page_slug,
	) {}

	/**
	 * Entry point — invoked from admin_init.
	 *
	 * @return void
	 */
	public function handle_actions(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Nonce verified in handle_post_action()/handle_get_action() for the matching branch.
		if ( ! isset( $_POST['leastudios_payments_product_action'] ) && ! isset( $_GET['product_action'] ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// Handle POST actions (create, update, add_price).
		if ( isset( $_POST['leastudios_payments_product_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handle_post_action().
			$this->handle_post_action();
			return;
		}

		// Handle GET actions (toggle_status, archive_price).
		$this->handle_get_action();
	}

	/**
	 * Dispatch a POST form submission after verifying the nonce.
	 *
	 * @return void
	 */
	private function handle_post_action(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified on next line.
		$action = sanitize_text_field( wp_unslash( $_POST['leastudios_payments_product_action'] ?? '' ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This IS the nonce verification.
		if ( ! isset( $_POST['_wpnonce'] ) || ! Nonce::verify( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'product_' . $action ) ) {
			wp_die( esc_html__( 'Security check failed.', 'leastudios-payments' ) );
		}

		match ( $action ) {
			'create'    => $this->handle_create(),
			'update'    => $this->handle_update(),
			'add_price' => $this->handle_add_price(),
			default     => null,
		};
	}

	/**
	 * Dispatch a GET link action (nonce in URL) — toggle status or archive a price.
	 *
	 * @return void
	 */
	private function handle_get_action(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Nonce verified inline below via Nonce::verify().
		$action = sanitize_text_field( wp_unslash( $_GET['product_action'] ?? '' ) );

		if ( ! isset( $_GET['_wpnonce'] ) || ! Nonce::verify( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), $action ) ) {
			wp_die( esc_html__( 'Security check failed.', 'leastudios-payments' ) );
		}

		$product_id = absint( $_GET['product_id'] ?? 0 );

		match ( $action ) {
			'activate'      => $this->product_sync->set_product_status( $product_id, 'active' ),
			'deactivate'    => $this->product_sync->set_product_status( $product_id, 'inactive' ),
			'archive_price' => $this->product_sync->archive_price( absint( $_GET['price_id'] ?? 0 ) ),
			default         => null,
		};
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$redirect_url = remove_query_arg( [ 'product_action', '_wpnonce', 'product_id', 'price_id' ] );
		wp_safe_redirect( add_query_arg( 'updated', '1', $redirect_url ) );
		exit;
	}

	/**
	 * Handle product creation.
	 *
	 * @return void
	 */
	private function handle_create(): void {
		// Nonce verified in handle_post_action().
		$name        = sanitize_text_field( wp_unslash( $_POST['product_name'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$description = sanitize_textarea_field( wp_unslash( $_POST['product_description'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$image_url   = esc_url_raw( wp_unslash( $_POST['product_image_url'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( '' === $name ) {
			$this->redirect_with_error( __( 'Product name is required.', 'leastudios-payments' ) );
			return;
		}

		$prices = $this->parse_prices_from_post();

		if ( empty( $prices ) ) {
			$this->redirect_with_error( __( 'At least one price is required.', 'leastudios-payments' ) );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_post_action().
		$require_shipping = ! empty( $_POST['require_shipping'] );

		$result = $this->product_sync->create_product( $name, $description, $image_url, $prices, $require_shipping );

		if ( ! $result['success'] ) {
			$this->redirect_with_error( $result['error'] );
			return;
		}

		wp_safe_redirect(
			add_query_arg(
				[
					'page'    => $this->page_slug,
					'created' => '1',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle product update.
	 *
	 * @return void
	 */
	private function handle_update(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_post_action().
		$product_id  = absint( $_POST['product_id'] ?? 0 );
		$name        = sanitize_text_field( wp_unslash( $_POST['product_name'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$description = sanitize_textarea_field( wp_unslash( $_POST['product_description'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$image_url   = esc_url_raw( wp_unslash( $_POST['product_image_url'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( 0 === $product_id || '' === $name ) {
			$this->redirect_with_error( __( 'Product name is required.', 'leastudios-payments' ) );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_post_action().
		$require_shipping = ! empty( $_POST['require_shipping'] );

		$result = $this->product_sync->update_product( $product_id, $name, $description, $image_url );

		if ( ! $result['success'] ) {
			$this->redirect_with_error( $result['error'] );
			return;
		}

		// Update shipping flag locally (not synced to Stripe — it's a local checkout setting).
		$this->product_repository->update( $product_id, [ 'require_shipping' => $require_shipping ? 1 : 0 ] );

		wp_safe_redirect(
			add_query_arg(
				[
					'page'    => $this->page_slug,
					'action'  => 'edit',
					'id'      => $product_id,
					'updated' => '1',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle adding a price to an existing product.
	 *
	 * @return void
	 */
	private function handle_add_price(): void {
		$product_id = absint( $_POST['product_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_post_action().
		$prices     = $this->parse_prices_from_post();

		if ( 0 === $product_id || empty( $prices ) ) {
			$this->redirect_with_error( __( 'Invalid price data.', 'leastudios-payments' ) );
			return;
		}

		$result = $this->product_sync->add_price( $product_id, $prices[0] );

		if ( ! $result['success'] ) {
			$this->redirect_with_error( $result['error'] );
			return;
		}

		wp_safe_redirect(
			add_query_arg(
				[
					'page'    => $this->page_slug,
					'action'  => 'edit',
					'id'      => $product_id,
					'updated' => '1',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Parse price data from POST.
	 *
	 * @return array<int, array<string, mixed>> Array of parsed price definitions.
	 */
	private function parse_prices_from_post(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_post_action.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in handle_post_action(). Individual values sanitized below.
		$raw_prices = isset( $_POST['prices'] ) ? (array) wp_unslash( $_POST['prices'] ) : [];
		$prices     = [];

		foreach ( $raw_prices as $price_data ) {
			if ( ! is_array( $price_data ) ) {
				continue;
			}

			$amount = absint( $price_data['amount'] ?? 0 );

			if ( 0 === $amount ) {
				continue;
			}

			$type = sanitize_text_field( $price_data['type'] ?? 'one_time' );

			$prices[] = [
				'amount'                   => $amount,
				'currency'                 => sanitize_text_field( $price_data['currency'] ?? 'USD' ),
				'type'                     => in_array( $type, [ 'one_time', 'recurring' ], true ) ? $type : 'one_time',
				'recurring_interval'       => sanitize_text_field( $price_data['recurring_interval'] ?? 'month' ),
				'recurring_interval_count' => max( 1, absint( $price_data['recurring_interval_count'] ?? 1 ) ),
			];
		}

		return $prices;
	}

	/**
	 * Redirect back to the referer with an error stored in a transient.
	 *
	 * @param string $message The error message.
	 * @return void
	 */
	private function redirect_with_error( string $message ): void {
		set_transient( 'leastudios_payments_product_error', $message, 30 );
		$referer = wp_get_referer();
		wp_safe_redirect( $referer ? $referer : admin_url( 'admin.php?page=' . $this->page_slug ) );
		exit;
	}
}
