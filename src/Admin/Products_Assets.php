<?php
/**
 * Products admin page assets.
 *
 * @package LEAStudios\Payments\Admin
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Admin;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Enqueues CSS, the WP media library, and the inline image-picker script for the Products page.
 */
class Products_Assets {

	/**
	 * Constructor.
	 *
	 * @param string $page_slug The owning page's slug.
	 */
	public function __construct( private readonly string $page_slug ) {}

	/**
	 * Enqueue admin assets on the products page only.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = sanitize_text_field( wp_unslash( $_GET['page'] ?? '' ) );

		if ( $this->page_slug !== $page ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_style(
			'leastudios-payments-admin',
			LEASTUDIOS_PAYMENTS_URL . 'assets/css/admin.css',
			[],
			LEASTUDIOS_PAYMENTS_VERSION
		);

		wp_add_inline_script(
			'media-editor',
			"(function() {
				'use strict';
				document.addEventListener('DOMContentLoaded', function() {
					var selectBtn = document.getElementById('leastudios-payments-select-image');
					var removeBtn = document.getElementById('leastudios-payments-remove-image');
					var input = document.getElementById('product_image_url');
					var preview = document.getElementById('leastudios-payments-image-preview');

					if (!selectBtn || !input) return;

					var frame;

					selectBtn.addEventListener('click', function(e) {
						e.preventDefault();

						if (frame) {
							frame.open();
							return;
						}

						frame = wp.media({
							title: '" . esc_js( __( 'Select Product Image', 'leastudios-payments' ) ) . "',
							button: { text: '" . esc_js( __( 'Use This Image', 'leastudios-payments' ) ) . "' },
							multiple: false,
							library: { type: 'image' }
						});

						frame.on('select', function() {
							var attachment = frame.state().get('selection').first().toJSON();
							var url = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
							input.value = attachment.url;
							preview.innerHTML = '<img src=\"' + url + '\" style=\"max-width:200px;max-height:200px;border:1px solid #dcdcde;border-radius:4px;\" />';
							selectBtn.textContent = '" . esc_js( __( 'Change Image', 'leastudios-payments' ) ) . "';
							if (removeBtn) removeBtn.style.display = '';
						});

						frame.open();
					});

					if (removeBtn) {
						removeBtn.addEventListener('click', function(e) {
							e.preventDefault();
							input.value = '';
							preview.innerHTML = '';
							selectBtn.textContent = '" . esc_js( __( 'Select Image', 'leastudios-payments' ) ) . "';
							removeBtn.style.display = 'none';
						});
					}
				});
			})();"
		);
	}
}
