<?php
/**
 * Customer account page shortcode.
 *
 * @package LEAStudios\Payments\Render
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Render;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Payments\Database\Order_Repository;
use LEAStudios\Payments\Database\Subscription_Repository;
use LEAStudios\Payments\Stripe\Customer_Manager;
use LEAStudios\Payments\Stripe\Stripe_Client;

/**
 * Registers the [leastudios_payment_account] shortcode for displaying
 * a customer's order history, active subscriptions, and billing management.
 */
class Account {

	/**
	 * Constructor.
	 *
	 * @param Order_Repository        $order_repository        The order repository.
	 * @param Subscription_Repository $subscription_repository The subscription repository.
	 * @param Stripe_Client           $stripe_client           The Stripe client.
	 * @param Customer_Manager        $customer_manager        The customer manager.
	 */
	public function __construct(
		private readonly Order_Repository $order_repository,
		private readonly Subscription_Repository $subscription_repository,
		private readonly Stripe_Client $stripe_client,
		private readonly Customer_Manager $customer_manager,
	) {}

	/**
	 * Register the shortcode.
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( 'leastudios_payment_account', [ $this, 'handle' ] );
	}

	/**
	 * Handle the shortcode.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string The rendered HTML.
	 */
	public function handle( array|string $atts ): string {
		if ( ! is_user_logged_in() ) {
			return sprintf(
				'<div class="leastudios-payments-login-required"><p>%s</p><p><a href="%s" class="button">%s</a></p></div>',
				esc_html__( 'Please log in to view your account.', 'leastudios-payments' ),
				esc_url( wp_login_url( get_permalink() ) ),
				esc_html__( 'Log In', 'leastudios-payments' )
			);
		}

		$user_id = get_current_user_id();

		$this->enqueue_styles();

		ob_start();

		echo '<div class="leastudios-payments-account">';

		$this->render_subscriptions( $user_id );
		$this->render_orders( $user_id );
		$this->render_billing_link( $user_id );

		echo '</div>';

		return (string) ob_get_clean();
	}

	/**
	 * Render the active subscriptions section.
	 *
	 * @param int $user_id The WordPress user ID.
	 * @return void
	 */
	private function render_subscriptions( int $user_id ): void {
		$subscriptions = $this->get_user_subscriptions( $user_id );

		if ( empty( $subscriptions ) ) {
			return;
		}

		?>
		<h3><?php esc_html_e( 'My Subscriptions', 'leastudios-payments' ); ?></h3>
		<table class="leastudios-payments-account-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Plan', 'leastudios-payments' ); ?></th>
					<th><?php esc_html_e( 'Status', 'leastudios-payments' ); ?></th>
					<th><?php esc_html_e( 'Renews', 'leastudios-payments' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $subscriptions as $sub ) : ?>
					<tr>
						<td><?php echo esc_html( $this->get_price_label( $sub->stripe_price_id ) ); ?></td>
						<td>
							<?php
							$status_label = ucfirst( str_replace( '_', ' ', $sub->status ) );

							if ( ! empty( $sub->cancel_at_period_end ) && 'canceled' !== $sub->status ) {
								$status_label .= ' (' . __( 'cancels at period end', 'leastudios-payments' ) . ')';
							}

							echo esc_html( $status_label );
							?>
						</td>
						<td>
							<?php
							if ( 'canceled' === $sub->status || empty( $sub->current_period_end ) ) {
								echo '&mdash;';
							} else {
								$ts = strtotime( $sub->current_period_end );
								echo esc_html( false !== $ts ? wp_date( get_option( 'date_format' ), $ts ) ?? '' : '' );
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the order history section.
	 *
	 * @param int $user_id The WordPress user ID.
	 * @return void
	 */
	private function render_orders( int $user_id ): void {
		$orders = $this->get_user_orders( $user_id );

		?>
		<h3><?php esc_html_e( 'Order History', 'leastudios-payments' ); ?></h3>

		<?php if ( empty( $orders ) ) : ?>
			<p><?php esc_html_e( 'No orders yet.', 'leastudios-payments' ); ?></p>
		<?php else : ?>
			<table class="leastudios-payments-account-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'leastudios-payments' ); ?></th>
						<th><?php esc_html_e( 'Amount', 'leastudios-payments' ); ?></th>
						<th><?php esc_html_e( 'Type', 'leastudios-payments' ); ?></th>
						<th><?php esc_html_e( 'Status', 'leastudios-payments' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $orders as $order ) : ?>
						<tr>
							<td>
								<?php
								$ts = strtotime( $order->created_at );
								echo esc_html( false !== $ts ? wp_date( get_option( 'date_format' ), $ts ) ?? '' : '' );
								?>
							</td>
							<td><?php echo esc_html( $this->format_amount( (int) $order->amount_total, $order->currency ) ); ?></td>
							<td><?php echo esc_html( 'subscription' === ( $order->order_type ?? '' ) ? __( 'Subscription', 'leastudios-payments' ) : __( 'One-time', 'leastudios-payments' ) ); ?></td>
							<td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $order->payment_status ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render the Manage Billing button (Stripe Customer Portal link).
	 *
	 * @param int $user_id The WordPress user ID.
	 * @return void
	 */
	private function render_billing_link( int $user_id ): void {
		$customer_id = $this->customer_manager->get_customer_id( $user_id );

		if ( null === $customer_id ) {
			return;
		}

		$portal_url = rest_url( 'leastudios-payments/v1/portal-session' );
		$nonce      = wp_create_nonce( 'wp_rest' );
		$return_url = get_permalink();
		?>
		<div class="leastudios-payments-account-billing" style="margin-top:20px;">
			<form method="POST" action="<?php echo esc_url( $portal_url ); ?>" id="leastudios-payments-portal-form">
				<input type="hidden" name="return_url" value="<?php echo esc_url( is_string( $return_url ) ? $return_url : home_url() ); ?>" />
				<button type="button" class="button" id="leastudios-payments-portal-btn" data-url="<?php echo esc_url( $portal_url ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>" data-return="<?php echo esc_url( is_string( $return_url ) ? $return_url : home_url() ); ?>">
					<?php esc_html_e( 'Manage Billing & Payment Methods', 'leastudios-payments' ); ?>
				</button>
			</form>
			<p class="description" style="margin-top:6px;font-size:0.85em;color:#666;">
				<?php esc_html_e( 'Update your payment method, view invoices, or cancel subscriptions.', 'leastudios-payments' ); ?>
			</p>
		</div>
		<script>
		(function() {
			var btn = document.getElementById('leastudios-payments-portal-btn');
			if (!btn) return;
			btn.addEventListener('click', function() {
				btn.disabled = true;
				btn.textContent = '<?php echo esc_js( __( 'Redirecting...', 'leastudios-payments' ) ); ?>';
				fetch(btn.getAttribute('data-url'), {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': btn.getAttribute('data-nonce') },
					body: JSON.stringify({ return_url: btn.getAttribute('data-return') })
				})
				.then(function(r) { return r.json(); })
				.then(function(data) {
					if (data.success && data.url) {
						window.location.href = data.url;
					} else {
						btn.disabled = false;
						btn.textContent = '<?php echo esc_js( __( 'Manage Billing & Payment Methods', 'leastudios-payments' ) ); ?>';
						alert(data.message || '<?php echo esc_js( __( 'Unable to open billing portal.', 'leastudios-payments' ) ); ?>');
					}
				})
				.catch(function() {
					btn.disabled = false;
					btn.textContent = '<?php echo esc_js( __( 'Manage Billing & Payment Methods', 'leastudios-payments' ) ); ?>';
				});
			});
		})();
		</script>
		<?php
	}

	/**
	 * Get orders for a specific WP user.
	 *
	 * @param int $user_id The WordPress user ID.
	 * @return array Array of order objects.
	 */
	private function get_user_orders( int $user_id ): array {
		global $wpdb;

		$table = \LEAStudios\Payments\Database\Migration::table( 'orders' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE wp_user_id = %d ORDER BY created_at DESC LIMIT 50",
				$user_id
			)
		);
	}

	/**
	 * Get subscriptions for a specific WP user.
	 *
	 * @param int $user_id The WordPress user ID.
	 * @return array Array of subscription objects.
	 */
	private function get_user_subscriptions( int $user_id ): array {
		global $wpdb;

		$table = \LEAStudios\Payments\Database\Migration::table( 'subscriptions' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE wp_user_id = %d AND status != 'canceled' ORDER BY created_at DESC",
				$user_id
			)
		);
	}

	/**
	 * Get a human-readable label for a Stripe price ID.
	 *
	 * @param string $stripe_price_id The Stripe price ID.
	 * @return string The price label or the raw ID.
	 */
	private function get_price_label( string $stripe_price_id ): string {
		global $wpdb;

		$prices_table   = \LEAStudios\Payments\Database\Migration::table( 'prices' );
		$products_table = \LEAStudios\Payments\Database\Migration::table( 'products' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT p.name, pr.amount, pr.currency, pr.recurring_interval
				FROM {$prices_table} pr
				INNER JOIN {$products_table} p ON pr.product_id = p.id
				WHERE pr.stripe_price_id = %s",
				$stripe_price_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $row ) {
			return $stripe_price_id;
		}

		$label = $row->name . ' — ' . $this->format_amount( (int) $row->amount, $row->currency );

		if ( ! empty( $row->recurring_interval ) ) {
			$label .= '/' . $row->recurring_interval;
		}

		return $label;
	}

	/**
	 * Enqueue frontend styles.
	 *
	 * @return void
	 */
	private function enqueue_styles(): void {
		wp_enqueue_style(
			'leastudios-payments-frontend',
			LEASTUDIOS_PAYMENTS_URL . 'assets/css/frontend.css',
			[],
			LEASTUDIOS_PAYMENTS_VERSION
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
