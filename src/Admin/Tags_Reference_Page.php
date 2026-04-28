<?php
/**
 * Tags reference page.
 *
 * @package LEAStudios\Payments\Admin
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Admin;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Displays available merge tags for confirmation pages, emails, etc.
 */
class Tags_Reference_Page {

	/**
	 * The page slug.
	 */
	private const PAGE_SLUG = 'leastudios-payments-tags';

	/**
	 * The required capability.
	 */
	private const CAPABILITY = 'manage_options';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'add_submenu_page' ] );
	}

	/**
	 * Add the Tags Reference submenu page.
	 *
	 * @return void
	 */
	public function add_submenu_page(): void {
		add_submenu_page(
			'leastudios-payments',
			__( 'Tags Reference', 'leastudios-payments' ),
			__( 'Tags', 'leastudios-payments' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Render the tags reference page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		/**
		 * Filters the tag groups displayed on the reference page.
		 *
		 * Each group has a 'title', 'description', and 'tags' array.
		 * Each tag has a 'tag', 'description', and optional 'example'.
		 *
		 * @since 1.0.0
		 *
		 * @param array $groups The tag groups.
		 * @return array Filtered groups.
		 */
		$groups = apply_filters( 'leastudios_payments_tag_groups', $this->get_tag_groups() );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Tags Reference', 'leastudios-payments' ); ?></h1>
			<p class="description" style="font-size:1.05em;margin-bottom:20px;">
				<?php esc_html_e( 'Use these tags in your confirmation pages and email templates. Tags are replaced with actual values when displayed to the customer.', 'leastudios-payments' ); ?>
			</p>

			<?php foreach ( $groups as $group ) : ?>
				<h2><?php echo esc_html( $group['title'] ); ?></h2>

				<?php if ( ! empty( $group['description'] ) ) : ?>
					<p class="description"><?php echo esc_html( $group['description'] ); ?></p>
				<?php endif; ?>

				<?php if ( ! empty( $group['shortcode'] ) ) : ?>
					<p>
						<strong><?php esc_html_e( 'Shortcode:', 'leastudios-payments' ); ?></strong>
						<code style="font-size:13px;padding:4px 8px;background:#f0f6fc;user-select:all;cursor:pointer;"><?php echo esc_html( $group['shortcode'] ); ?></code>
					</p>
				<?php endif; ?>

				<table class="widefat fixed striped" style="max-width:800px;margin-bottom:30px;">
					<thead>
						<tr>
							<th style="width:200px;"><?php esc_html_e( 'Tag', 'leastudios-payments' ); ?></th>
							<th><?php esc_html_e( 'Description', 'leastudios-payments' ); ?></th>
							<th style="width:200px;"><?php esc_html_e( 'Example Output', 'leastudios-payments' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $group['tags'] as $tag ) : ?>
							<tr>
								<td>
									<code style="user-select:all;cursor:pointer;"><?php echo esc_html( $tag['tag'] ); ?></code>
								</td>
								<td><?php echo esc_html( $tag['description'] ); ?></td>
								<td>
									<?php if ( ! empty( $tag['example'] ) ) : ?>
										<span style="color:#50575e;"><?php echo esc_html( $tag['example'] ); ?></span>
									<?php else : ?>
										&mdash;
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( ! empty( $group['usage_example'] ) ) : ?>
					<details style="margin-bottom:30px;max-width:800px;">
						<summary style="cursor:pointer;font-weight:600;margin-bottom:8px;">
							<?php esc_html_e( 'Usage Example', 'leastudios-payments' ); ?>
						</summary>
						<pre style="background:#f6f7f7;padding:12px 16px;border:1px solid #dcdcde;border-radius:4px;font-size:13px;overflow-x:auto;"><?php echo esc_html( $group['usage_example'] ); ?></pre>
					</details>
				<?php endif; ?>

			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Get the default tag groups.
	 *
	 * @return array Array of tag group definitions.
	 */
	private function get_tag_groups(): array {
		return [
			[
				'title'         => __( 'Confirmation Page Tags', 'leastudios-payments' ),
				'description'   => __( 'Available in the [leastudios_payment_confirmation] shortcode. Place this shortcode on your Success Page (configured in Settings).', 'leastudios-payments' ),
				'shortcode'     => '[leastudios_payment_confirmation]',
				'tags'          => [
					[
						'tag'         => '{customer_name}',
						'description' => __( 'The customer\'s full name as entered during checkout.', 'leastudios-payments' ),
						'example'     => 'John Smith',
					],
					[
						'tag'         => '{customer_email}',
						'description' => __( 'The customer\'s email address.', 'leastudios-payments' ),
						'example'     => 'john@example.com',
					],
					[
						'tag'         => '{amount}',
						'description' => __( 'The total payment amount, formatted with currency symbol.', 'leastudios-payments' ),
						'example'     => '$25.00',
					],
					[
						'tag'         => '{currency}',
						'description' => __( 'The three-letter currency code in uppercase.', 'leastudios-payments' ),
						'example'     => 'USD',
					],
					[
						'tag'         => '{product_name}',
						'description' => __( 'The name of the purchased product.', 'leastudios-payments' ),
						'example'     => 'Pro Plan',
					],
					[
						'tag'         => '{payment_status}',
						'description' => __( 'The payment status (Paid, Complete, etc.).', 'leastudios-payments' ),
						'example'     => 'Paid',
					],
					[
						'tag'         => '{order_type}',
						'description' => __( 'Whether this is a "One-time payment" or "Subscription".', 'leastudios-payments' ),
						'example'     => 'Subscription',
					],
					[
						'tag'         => '{date}',
						'description' => __( 'The current date, formatted per your WordPress date settings.', 'leastudios-payments' ),
						'example'     => 'March 31, 2026',
					],
					[
						'tag'         => '{session_id}',
						'description' => __( 'The Stripe Checkout Session ID.', 'leastudios-payments' ),
						'example'     => 'cs_test_a1b2c3...',
					],
					[
						'tag'         => '{payment_id}',
						'description' => __( 'The Stripe Payment Intent ID.', 'leastudios-payments' ),
						'example'     => 'pi_3abc123...',
					],
				],
				'usage_example' => "[leastudios_payment_confirmation]\nThank you, {customer_name}!\n\nYour {order_type} of {amount} for {product_name} has been processed.\n\nA confirmation has been sent to {customer_email}.\n[/leastudios_payment_confirmation]",
			],
			[
				'title'       => __( 'Customer Account Page', 'leastudios-payments' ),
				'description' => __( 'Place this shortcode on a page to give logged-in customers access to their order history, subscriptions, and billing management.', 'leastudios-payments' ),
				'shortcode'   => '[leastudios_payment_account]',
				'tags'        => [
					[
						'tag'         => __( '(no tags)', 'leastudios-payments' ),
						'description' => __( 'This shortcode has no configurable tags. It automatically displays the logged-in user\'s orders, active subscriptions, and a "Manage Billing" button linking to the Stripe Customer Portal.', 'leastudios-payments' ),
						'example'     => '',
					],
				],
				'usage_example' => '[leastudios_payment_account]',
			],
		];
	}
}
