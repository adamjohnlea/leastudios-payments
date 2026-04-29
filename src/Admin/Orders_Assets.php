<?php
/**
 * Orders admin page assets.
 *
 * @package LEAStudios\Payments\Admin
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Admin;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Enqueues CSS/JS for the Orders admin page (refund form).
 */
class Orders_Assets {

	/**
	 * Constructor.
	 *
	 * @param string $page_slug The owning page's slug.
	 */
	public function __construct( private readonly string $page_slug ) {}

	/**
	 * Enqueue admin assets on the orders page only.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = sanitize_text_field( wp_unslash( $_GET['page'] ?? '' ) );

		if ( $this->page_slug !== $page ) {
			return;
		}

		wp_enqueue_style(
			'leastudios-payments-admin',
			LEASTUDIOS_PAYMENTS_URL . 'assets/css/admin.css',
			[],
			LEASTUDIOS_PAYMENTS_VERSION
		);

		wp_enqueue_script(
			'leastudios-payments-admin',
			LEASTUDIOS_PAYMENTS_URL . 'assets/js/admin.js',
			[],
			LEASTUDIOS_PAYMENTS_VERSION,
			true
		);

		wp_localize_script(
			'leastudios-payments-admin',
			'leastudiosPaymentsAdmin',
			[
				'refundUrl'      => rest_url( 'leastudios-payments/v1/refund' ),
				'refundNonce'    => wp_create_nonce( 'wp_rest' ),
				'confirmText'    => __( 'Are you sure you want to issue this refund? This cannot be undone.', 'leastudios-payments' ),
				'processingText' => __( 'Processing...', 'leastudios-payments' ),
				'successText'    => __( 'Refund issued successfully. Reloading...', 'leastudios-payments' ),
				'errorText'      => __( 'Refund failed: ', 'leastudios-payments' ),
			]
		);
	}
}
