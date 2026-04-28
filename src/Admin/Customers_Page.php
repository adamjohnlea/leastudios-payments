<?php
/**
 * Customers admin page.
 *
 * @package LEAStudios\Payments\Admin
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Admin;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Payments\Database\Migration;
use LEAStudios\Payments\Database\Order_Repository;
use LEAStudios\Payments\Database\Subscription_Repository;
use LEAStudios\Payments\Stripe\Stripe_Client;

/**
 * Displays customers aggregated from orders and subscriptions.
 */
class Customers_Page {

	/**
	 * The page slug.
	 */
	private const PAGE_SLUG = 'leastudios-payments-customers';

	/**
	 * The required capability.
	 */
	private const CAPABILITY = 'manage_options';

	/**
	 * Constructor.
	 *
	 * @param Order_Repository        $order_repository        The order repository.
	 * @param Subscription_Repository $subscription_repository The subscription repository.
	 * @param Stripe_Client           $stripe_client           The Stripe client.
	 */
	public function __construct(
		private readonly Order_Repository $order_repository,
		private readonly Subscription_Repository $subscription_repository,
		private readonly Stripe_Client $stripe_client,
	) {}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'add_submenu_page' ] );
	}

	/**
	 * Add the Customers submenu page.
	 *
	 * @return void
	 */
	public function add_submenu_page(): void {
		add_submenu_page(
			'leastudios-payments',
			__( 'Customers', 'leastudios-payments' ),
			__( 'Customers', 'leastudios-payments' ),
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
	 * Render the customers list.
	 *
	 * @return void
	 */
	private function render_list(): void {
		$customers = $this->get_customers();

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
									'page'           => self::PAGE_SLUG,
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
								<td><?php echo esc_html( $this->format_amount( (int) $customer->total_spent, $customer->currency ?? 'usd' ) ); ?></td>
								<td>
									<?php
									$timestamp = strtotime( $customer->last_order_date );
									echo esc_html( false !== $timestamp ? wp_date( get_option( 'date_format' ), $timestamp ) ?? $customer->last_order_date : $customer->last_order_date );
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
	private function render_detail(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view.
		$customer_email = sanitize_email( wp_unslash( $_GET['customer_email'] ?? '' ) );

		if ( '' === $customer_email ) {
			wp_die( esc_html__( 'Invalid customer.', 'leastudios-payments' ) );
		}

		$orders        = $this->get_customer_orders_by_email( $customer_email );
		$subscriptions = $this->get_customer_subscriptions_by_email( $customer_email );
		$back_url      = add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) );

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
								<td><?php echo esc_html( $this->format_amount( (int) $order->amount_total, $order->currency ) ); ?></td>
								<td><?php echo esc_html( 'subscription' === ( $order->order_type ?? '' ) ? __( 'Subscription', 'leastudios-payments' ) : __( 'One-time', 'leastudios-payments' ) ); ?></td>
								<td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $order->payment_status ) ) ); ?></td>
								<td>
									<?php
									$ts = strtotime( $order->created_at );
									echo esc_html( false !== $ts ? wp_date( get_option( 'date_format' ), $ts ) ?? $order->created_at : $order->created_at );
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
										echo esc_html( false !== $ts ? wp_date( get_option( 'date_format' ), $ts ) ?? $sub->current_period_end : $sub->current_period_end );
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

	/**
	 * Get aggregated customer list from orders.
	 *
	 * @return array Array of customer objects with order_count, total_spent, last_order_date.
	 */
	private function get_customers(): array {
		global $wpdb;

		$table = Migration::table( 'orders' );

		// Group by email so customers with multiple Stripe Customer IDs appear as one row.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results(
			"SELECT
				MAX( stripe_customer_id ) AS stripe_customer_id,
				customer_email,
				MAX( customer_name ) AS customer_name,
				COUNT(*) AS order_count,
				SUM( amount_total - refunded_amount ) AS total_spent,
				MIN( currency ) AS currency,
				MAX( created_at ) AS last_order_date
			FROM {$table}
			WHERE customer_email != ''
			GROUP BY customer_email
			ORDER BY last_order_date DESC
			LIMIT 100"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Get all orders for a customer by email.
	 *
	 * @param string $email The customer email.
	 * @return array Array of order objects.
	 */
	private function get_customer_orders_by_email( string $email ): array {
		global $wpdb;

		$table = Migration::table( 'orders' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE customer_email = %s ORDER BY created_at DESC",
				$email
			)
		);
	}

	/**
	 * Get all subscriptions for a customer by email.
	 *
	 * @param string $email The customer email.
	 * @return array Array of subscription objects.
	 */
	private function get_customer_subscriptions_by_email( string $email ): array {
		global $wpdb;

		$table = Migration::table( 'subscriptions' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE customer_email = %s ORDER BY created_at DESC",
				$email
			)
		);
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
