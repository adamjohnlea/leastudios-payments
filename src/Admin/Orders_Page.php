<?php
/**
 * Orders admin page.
 *
 * @package LEAStudios\Payments\Admin
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Admin;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Payments\Database\Order_Repository;
use LEAStudios\Payments\Stripe\Stripe_Client;

/**
 * Manages the Orders admin page — list view and detail view with refund.
 */
class Orders_Page {

	/**
	 * The page slug.
	 */
	private const PAGE_SLUG = 'leastudios-payments-orders';

	/**
	 * The required capability.
	 */
	private const CAPABILITY = 'manage_options';

	/**
	 * Constructor.
	 *
	 * @param Order_Repository $order_repository The order repository.
	 * @param Stripe_Client    $stripe_client    The Stripe client.
	 */
	public function __construct(
		private readonly Order_Repository $order_repository,
		private readonly Stripe_Client $stripe_client,
	) {}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'add_submenu_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Add the Orders submenu page.
	 *
	 * @return void
	 */
	public function add_submenu_page(): void {
		add_submenu_page(
			'leastudios-payments',
			__( 'Orders', 'leastudios-payments' ),
			__( 'Orders', 'leastudios-payments' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Route to list or detail view.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing.
		$action = sanitize_text_field( wp_unslash( $_GET['action'] ?? '' ) );

		if ( 'view' === $action ) {
			$this->render_detail();
		} else {
			$this->render_list();
		}
	}

	/**
	 * Render the orders list.
	 *
	 * @return void
	 */
	private function render_list(): void {
		$table = new Orders_List_Table( $this->order_repository );
		$table->prepare_items();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Orders', 'leastudios-payments' ); ?></h1>

			<?php $table->views(); ?>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the order detail view.
	 *
	 * @return void
	 */
	private function render_detail(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view.
		$order_id = absint( $_GET['id'] ?? 0 );
		$order    = $this->order_repository->get( $order_id );

		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'leastudios-payments' ) );
		}

		$back_url   = add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) );
		$line_items = json_decode( $order->line_items_json ?? '[]', true );

		if ( ! is_array( $line_items ) ) {
			$line_items = [];
		}

		$is_test    = $this->stripe_client->is_test_mode();
		$stripe_base = $is_test ? 'https://dashboard.stripe.com/test' : 'https://dashboard.stripe.com';

		$refundable    = in_array( $order->payment_status, [ 'paid', 'partial_refund' ], true );
		$max_refund    = (int) $order->amount_total - (int) $order->refunded_amount;

		?>
		<div class="wrap">
			<h1>
				<?php
				printf(
					/* translators: %d: order ID */
					esc_html__( 'Order #%d', 'leastudios-payments' ),
					(int) $order_id
				);
				?>
			</h1>

			<?php $this->render_notices(); ?>

			<table class="widefat fixed striped" style="max-width:700px;">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'leastudios-payments' ); ?></th>
						<td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $order->payment_status ) ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Amount', 'leastudios-payments' ); ?></th>
						<td><?php echo esc_html( $this->format_amount( (int) $order->amount_total, $order->currency ) ); ?></td>
					</tr>
					<?php if ( (int) $order->refunded_amount > 0 ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Refunded', 'leastudios-payments' ); ?></th>
							<td style="color:#d63638;">
								-<?php echo esc_html( $this->format_amount( (int) $order->refunded_amount, $order->currency ) ); ?>
							</td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Customer', 'leastudios-payments' ); ?></th>
						<td>
							<?php echo esc_html( ! empty( $order->customer_name ) ? $order->customer_name : $order->customer_email ); ?>
							<?php if ( ! empty( $order->customer_name ) ) : ?>
								<br><small><?php echo esc_html( $order->customer_email ); ?></small>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Date', 'leastudios-payments' ); ?></th>
						<td>
							<?php
							$timestamp = strtotime( $order->created_at );
							echo esc_html( false !== $timestamp ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) ?? $order->created_at : $order->created_at );
							?>
						</td>
					</tr>
					<?php if ( ! empty( $order->stripe_payment_intent_id ) ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Stripe Payment', 'leastudios-payments' ); ?></th>
							<td>
								<a href="<?php echo esc_url( $stripe_base . '/payments/' . $order->stripe_payment_intent_id ); ?>" target="_blank" rel="noopener noreferrer">
									<code><?php echo esc_html( $order->stripe_payment_intent_id ); ?></code> &#8599;
								</a>
							</td>
						</tr>
					<?php endif; ?>
					<?php if ( ! empty( $order->stripe_customer_id ) ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Stripe Customer', 'leastudios-payments' ); ?></th>
							<td>
								<a href="<?php echo esc_url( $stripe_base . '/customers/' . $order->stripe_customer_id ); ?>" target="_blank" rel="noopener noreferrer">
									<code><?php echo esc_html( $order->stripe_customer_id ); ?></code> &#8599;
								</a>
							</td>
						</tr>
					<?php endif; ?>
					<?php if ( ! empty( $order->wp_user_id ) ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'WordPress User', 'leastudios-payments' ); ?></th>
							<td>
								<a href="<?php echo esc_url( get_edit_user_link( (int) $order->wp_user_id ) ); ?>">
									<?php echo esc_html( get_userdata( (int) $order->wp_user_id )->display_name ?? '#' . $order->wp_user_id ); ?>
								</a>
							</td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( ! empty( $line_items ) ) : ?>
				<h3><?php esc_html_e( 'Line Items', 'leastudios-payments' ); ?></h3>
				<table class="widefat fixed striped" style="max-width:700px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Item', 'leastudios-payments' ); ?></th>
							<th><?php esc_html_e( 'Qty', 'leastudios-payments' ); ?></th>
							<th><?php esc_html_e( 'Amount', 'leastudios-payments' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $line_items as $item ) : ?>
							<tr>
								<td><?php echo esc_html( $item['description'] ?? '' ); ?></td>
								<td><?php echo esc_html( (string) ( $item['quantity'] ?? 1 ) ); ?></td>
								<td><?php echo esc_html( $this->format_amount( (int) ( $item['amount'] ?? 0 ), $item['currency'] ?? $order->currency ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( $refundable && $max_refund > 0 ) : ?>
				<h3><?php esc_html_e( 'Refund', 'leastudios-payments' ); ?></h3>
				<form id="leastudios-payments-refund-form" style="max-width:700px;">
					<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $order_id ); ?>" />
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="refund_amount"><?php esc_html_e( 'Refund Amount', 'leastudios-payments' ); ?></label>
							</th>
							<td>
								<input
									type="number"
									id="refund_amount"
									name="refund_amount"
									value="<?php echo esc_attr( (string) $max_refund ); ?>"
									min="1"
									max="<?php echo esc_attr( (string) $max_refund ); ?>"
									step="1"
									class="small-text"
								/>
								<p class="description">
									<?php
									printf(
										/* translators: %s: maximum refundable amount */
										esc_html__( 'Amount in smallest currency unit. Maximum: %s', 'leastudios-payments' ),
										esc_html( (string) $max_refund )
									);
									?>
								</p>
							</td>
						</tr>
					</table>
					<p>
						<button type="submit" class="button button-secondary" id="leastudios-payments-refund-btn">
							<?php esc_html_e( 'Issue Refund', 'leastudios-payments' ); ?>
						</button>
						<span id="leastudios-payments-refund-status" style="margin-left:10px;"></span>
					</p>
				</form>
			<?php endif; ?>

			<p style="margin-top:20px;">
				<a href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'Back to Orders', 'leastudios-payments' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Enqueue admin assets on the orders pages.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = sanitize_text_field( wp_unslash( $_GET['page'] ?? '' ) );

		if ( self::PAGE_SLUG !== $page ) {
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
				'refundUrl'     => rest_url( 'leastudios-payments/v1/refund' ),
				'refundNonce'   => wp_create_nonce( 'wp_rest' ),
				'confirmText'   => __( 'Are you sure you want to issue this refund? This cannot be undone.', 'leastudios-payments' ),
				'processingText' => __( 'Processing...', 'leastudios-payments' ),
				'successText'   => __( 'Refund issued successfully. Reloading...', 'leastudios-payments' ),
				'errorText'     => __( 'Refund failed: ', 'leastudios-payments' ),
			]
		);
	}

	/**
	 * Render admin notices.
	 *
	 * @return void
	 */
	private function render_notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['refunded'] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Refund issued successfully.', 'leastudios-payments' )
			);
		}
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
