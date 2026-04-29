<?php
/**
 * Customers admin page HTML rendering.
 *
 * @package LEAStudios\Payments\Admin
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Admin;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Payments\Stripe\Stripe_Client;
use LEAStudios\Payments\Support\Currency_Formatter;

/**
 * Renders the Customers list and detail views.
 */
class Customers_Renderer {

	/**
	 * Constructor.
	 *
	 * @param Customers_Query $query         The customer aggregation query service.
	 * @param Stripe_Client   $stripe_client The Stripe client (for dashboard URL).
	 * @param string          $page_slug     The owning page's slug.
	 */
	public function __construct(
		private readonly Customers_Query $query,
		private readonly Stripe_Client $stripe_client,
		private readonly string $page_slug,
	) {}

	/**
	 * Render the customers list view.
	 *
	 * @return void
	 */
	public function render_list(): void {
		$customers = $this->query->get_customers();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Customers', 'leastudios-payments' ); ?></h1>

			<?php if ( empty( $customers ) ) : ?>
				<p style="color:#50575e;"><?php esc_html_e( 'No customers yet. Customers will appear here after their first purchase.', 'leastudios-payments' ); ?></p>
			<?php else : ?>
				<table class="widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Customer', 'leastudios-payments' ); ?></th>
							<th><?php esc_html_e( 'Email', 'leastudios-payments' ); ?></th>
							<th><?php esc_html_e( 'Orders', 'leastudios-payments' ); ?></th>
							<th><?php esc_html_e( 'Total Spent', 'leastudios-payments' ); ?></th>
							<th><?php esc_html_e( 'Last Order', 'leastudios-payments' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $customers as $customer ) : ?>
							<?php
							$detail_url = add_query_arg(
								[
									'page'           => $this->page_slug,
									'action'         => 'view',
									'customer_email' => rawurlencode( $customer->customer_email ),
								],
								admin_url( 'admin.php' )
							);
							?>
							<tr>
								<td>
									<strong>
										<a href="<?php echo esc_url( $detail_url ); ?>">
											<?php echo esc_html( ! empty( $customer->customer_name ) ? $customer->customer_name : $customer->customer_email ); ?>
										</a>
									</strong>
								</td>
								<td><?php echo esc_html( $customer->customer_email ); ?></td>
								<td><?php echo esc_html( (string) $customer->order_count ); ?></td>
								<td><?php echo esc_html( Currency_Formatter::format( (int) $customer->total_spent, $customer->currency ?? 'usd' ) ); ?></td>
								<td>
									<?php
									$timestamp = strtotime( $customer->last_order_date );
									echo esc_html( false !== $timestamp ? wp_date( get_option( 'date_format' ), $timestamp ) : $customer->last_order_date );
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the customer detail view.
	 *
	 * @return void
	 */
	public function render_detail(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view.
		$customer_email = sanitize_email( wp_unslash( $_GET['customer_email'] ?? '' ) );

		if ( '' === $customer_email ) {
			wp_die( esc_html__( 'Invalid customer.', 'leastudios-payments' ) );
		}

		$orders        = $this->query->get_orders_by_email( $customer_email );
		$subscriptions = $this->query->get_subscriptions_by_email( $customer_email );
		$back_url      = add_query_arg( 'page', $this->page_slug, admin_url( 'admin.php' ) );

		$customer_name       = '';
		$stripe_customer_ids = [];

		foreach ( $orders as $order ) {
			if ( '' === $customer_name && ! empty( $order->customer_name ) ) {
				$customer_name = $order->customer_name;
			}

			if ( ! empty( $order->stripe_customer_id ) ) {
				$stripe_customer_ids[ $order->stripe_customer_id ] = true;
			}
		}

		$is_test     = $this->stripe_client->is_test_mode();
		$stripe_base = $is_test ? 'https://dashboard.stripe.com/test' : 'https://dashboard.stripe.com';

		?>
		<div class="wrap">
			<h1>
				<?php
				echo esc_html( '' !== $customer_name ? $customer_name : $customer_email );
				?>
			</h1>

			<table class="widefat fixed striped" style="max-width:500px;margin-bottom:20px;">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Email', 'leastudios-payments' ); ?></th>
						<td><?php echo esc_html( $customer_email ); ?></td>
					</tr>
					<?php foreach ( array_keys( $stripe_customer_ids ) as $cus_id ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Stripe Customer', 'leastudios-payments' ); ?></th>
						<td>
							<a href="<?php echo esc_url( $stripe_base . '/customers/' . $cus_id ); ?>" target="_blank" rel="noopener noreferrer">
								<code><?php echo esc_html( $cus_id ); ?></code> &#8599;
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Orders', 'leastudios-payments' ); ?></h2>

			<?php if ( empty( $orders ) ) : ?>
				<p style="color:#50575e;"><?php esc_html_e( 'No orders found for this customer.', 'leastudios-payments' ); ?></p>
			<?php else : ?>
				<table class="widefat fixed striped" style="max-width:700px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Order', 'leastudios-payments' ); ?></th>
							<th><?php esc_html_e( 'Amount', 'leastudios-payments' ); ?></th>
							<th><?php esc_html_e( 'Type', 'leastudios-payments' ); ?></th>
							<th><?php esc_html_e( 'Status', 'leastudios-payments' ); ?></th>
							<th><?php esc_html_e( 'Date', 'leastudios-payments' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $orders as $order ) : ?>
							<?php
							$order_url = add_query_arg(
								[
									'page'   => 'leastudios-payments-orders',
									'action' => 'view',
									'id'     => $order->id,
								],
								admin_url( 'admin.php' )
							);
							?>
							<tr>
								<td><a href="<?php echo esc_url( $order_url ); ?>">#<?php echo esc_html( (string) $order->id ); ?></a></td>
								<td><?php echo esc_html( Currency_Formatter::format( (int) $order->amount_total, $order->currency ) ); ?></td>
								<td><?php echo esc_html( 'subscription' === ( $order->order_type ?? '' ) ? __( 'Subscription', 'leastudios-payments' ) : __( 'One-time', 'leastudios-payments' ) ); ?></td>
								<td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $order->payment_status ) ) ); ?></td>
								<td>
									<?php
									$ts = strtotime( $order->created_at );
									echo esc_html( false !== $ts ? wp_date( get_option( 'date_format' ), $ts ) : $order->created_at );
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( ! empty( $subscriptions ) ) : ?>
				<h2><?php esc_html_e( 'Subscriptions', 'leastudios-payments' ); ?></h2>
				<table class="widefat fixed striped" style="max-width:700px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'ID', 'leastudios-payments' ); ?></th>
							<th><?php esc_html_e( 'Status', 'leastudios-payments' ); ?></th>
							<th><?php esc_html_e( 'Current Period End', 'leastudios-payments' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $subscriptions as $sub ) : ?>
							<tr>
								<td><code><?php echo esc_html( $sub->stripe_subscription_id ); ?></code></td>
								<td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $sub->status ) ) ); ?></td>
								<td>
									<?php
									if ( ! empty( $sub->current_period_end ) ) {
										$ts = strtotime( $sub->current_period_end );
										echo esc_html( false !== $ts ? wp_date( get_option( 'date_format' ), $ts ) : $sub->current_period_end );
									} else {
										echo '&mdash;';
									}
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<p style="margin-top:20px;">
				<a href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'Back to Customers', 'leastudios-payments' ); ?></a>
			</p>
		</div>
		<?php
	}
}
