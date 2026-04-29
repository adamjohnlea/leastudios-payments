<?php
/**
 * Orders admin page HTML rendering.
 *
 * @package LEAStudios\Payments\Admin
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Admin;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Payments\Database\Order_Repository;
use LEAStudios\Payments\Stripe\Stripe_Client;
use LEAStudios\Payments\Support\Currency_Formatter;

/**
 * Renders the Orders list and detail views.
 */
class Orders_Renderer {

	/**
	 * Constructor.
	 *
	 * @param Order_Repository $order_repository The order repository.
	 * @param Stripe_Client    $stripe_client    The Stripe client.
	 * @param string           $page_slug        The owning page's slug.
	 */
	public function __construct(
		private readonly Order_Repository $order_repository,
		private readonly Stripe_Client $stripe_client,
		private readonly string $page_slug,
	) {}

	/**
	 * Render the orders list view.
	 *
	 * @return void
	 */
	public function render_list(): void {
		$table = new Orders_List_Table( $this->order_repository );
		$table->prepare_items();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Orders', 'leastudios-payments' ); ?></h1>

			<?php $table->views(); ?>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( $this->page_slug ); ?>" />
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
	public function render_detail(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view.
		$order_id = absint( $_GET['id'] ?? 0 );
		$order    = $this->order_repository->get( $order_id );

		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'leastudios-payments' ) );
		}

		$back_url   = add_query_arg( 'page', $this->page_slug, admin_url( 'admin.php' ) );
		$line_items = json_decode( $order->line_items_json ?? '[]', true );

		if ( ! is_array( $line_items ) ) {
			$line_items = [];
		}

		$is_test     = $this->stripe_client->is_test_mode();
		$stripe_base = $is_test ? 'https://dashboard.stripe.com/test' : 'https://dashboard.stripe.com';

		$refundable = in_array( $order->payment_status, [ 'paid', 'partial_refund' ], true );
		$max_refund = (int) $order->amount_total - (int) $order->refunded_amount;

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
						<td><?php echo esc_html( Currency_Formatter::format( (int) $order->amount_total, $order->currency ) ); ?></td>
					</tr>
					<?php if ( (int) $order->refunded_amount > 0 ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Refunded', 'leastudios-payments' ); ?></th>
							<td style="color:#d63638;">
								-<?php echo esc_html( Currency_Formatter::format( (int) $order->refunded_amount, $order->currency ) ); ?>
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
							echo esc_html( false !== $timestamp ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) : $order->created_at );
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
								<td><?php echo esc_html( Currency_Formatter::format( (int) ( $item['amount'] ?? 0 ), $item['currency'] ?? $order->currency ) ); ?></td>
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
}
