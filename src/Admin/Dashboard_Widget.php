<?php
/**
 * Dashboard widget for payment stats.
 *
 * @package LEAStudios\Payments\Admin
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Admin;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Payments\Database\Order_Repository;
use LEAStudios\Payments\Database\Subscription_Repository;
use LEAStudios\Payments\Stripe\Stripe_Client;
use LEAStudios\Payments\Support\Currency_Formatter;

/**
 * Adds a WordPress dashboard widget showing payment statistics.
 */
class Dashboard_Widget {

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
		add_action( 'wp_dashboard_setup', [ $this, 'register_widget' ] );
	}

	/**
	 * Register the dashboard widget.
	 *
	 * @return void
	 */
	public function register_widget(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'leastudios_payments_dashboard',
			__( 'leaStudios Payments', 'leastudios-payments' ),
			[ $this, 'render_widget' ]
		);
	}

	/**
	 * Render the dashboard widget content.
	 *
	 * @return void
	 */
	public function render_widget(): void {
		$revenue_30d      = $this->order_repository->get_revenue( 30 );
		$orders_30d       = $this->order_repository->count( 'paid' );
		$refunded_30d     = $this->order_repository->count( 'refunded' );
		$partial_30d      = $this->order_repository->count( 'partial_refund' );
		$active_subs      = $this->subscription_repository->count( 'active' );
		$default_currency = $this->stripe_client->get_default_currency();

		$orders_url = add_query_arg( 'page', 'leastudios-payments-orders', admin_url( 'admin.php' ) );
		$subs_url   = add_query_arg( 'page', 'leastudios-payments-subscriptions', admin_url( 'admin.php' ) );

		?>
		<div class="leastudios-payments-dashboard-widget">
			<style>
				.leastudios-payments-stats {
					display: grid;
					grid-template-columns: 1fr 1fr;
					gap: 12px;
					margin-bottom: 12px;
				}
				.leastudios-payments-stat {
					padding: 12px;
					background: #f6f7f7;
					border-radius: 4px;
					text-align: center;
				}
				.leastudios-payments-stat-value {
					font-size: 1.6em;
					font-weight: 600;
					color: #1d2327;
					line-height: 1.2;
				}
				.leastudios-payments-stat-label {
					font-size: 0.85em;
					color: #50575e;
					margin-top: 2px;
				}
				.leastudios-payments-stat--revenue .leastudios-payments-stat-value {
					color: #00a32a;
				}
			</style>

			<div class="leastudios-payments-stats">
				<div class="leastudios-payments-stat leastudios-payments-stat--revenue">
					<div class="leastudios-payments-stat-value">
						<?php echo esc_html( Currency_Formatter::format( $revenue_30d, $default_currency ) ); ?>
					</div>
					<div class="leastudios-payments-stat-label">
						<?php esc_html_e( 'Revenue (30 days)', 'leastudios-payments' ); ?>
					</div>
				</div>

				<div class="leastudios-payments-stat">
					<div class="leastudios-payments-stat-value">
						<?php echo esc_html( (string) $orders_30d ); ?>
					</div>
					<div class="leastudios-payments-stat-label">
						<?php esc_html_e( 'Paid Orders', 'leastudios-payments' ); ?>
					</div>
				</div>

				<div class="leastudios-payments-stat">
					<div class="leastudios-payments-stat-value">
						<?php echo esc_html( (string) $active_subs ); ?>
					</div>
					<div class="leastudios-payments-stat-label">
						<?php esc_html_e( 'Active Subscriptions', 'leastudios-payments' ); ?>
					</div>
				</div>

				<div class="leastudios-payments-stat">
					<div class="leastudios-payments-stat-value">
						<?php echo esc_html( (string) ( $refunded_30d + $partial_30d ) ); ?>
					</div>
					<div class="leastudios-payments-stat-label">
						<?php esc_html_e( 'Refunds', 'leastudios-payments' ); ?>
					</div>
				</div>
			</div>

			<p style="margin:0;">
				<a href="<?php echo esc_url( $orders_url ); ?>"><?php esc_html_e( 'View Orders', 'leastudios-payments' ); ?></a>
				&nbsp;|&nbsp;
				<a href="<?php echo esc_url( $subs_url ); ?>"><?php esc_html_e( 'View Subscriptions', 'leastudios-payments' ); ?></a>
			</p>
		</div>
		<?php
	}
}
