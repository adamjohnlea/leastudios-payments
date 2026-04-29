<?php
/**
 * Subscriptions admin page.
 *
 * @package LEAStudios\Payments\Admin
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Admin;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Payments\Database\Subscription_Repository;
use LEAStudios\Payments\Security\Nonce;
use LEAStudios\Payments\Stripe\Stripe_Client;

/**
 * Manages the Subscriptions admin page — list view with cancel actions.
 */
class Subscriptions_Page {

	/**
	 * The page slug.
	 */
	private const PAGE_SLUG = 'leastudios-payments-subscriptions';

	/**
	 * The required capability.
	 */
	private const CAPABILITY = 'manage_options';

	/**
	 * Constructor.
	 *
	 * @param Subscription_Repository $subscription_repository The subscription repository.
	 * @param Stripe_Client           $stripe_client           The Stripe client.
	 */
	public function __construct(
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
		add_action( 'admin_init', [ $this, 'handle_actions' ] );
	}

	/**
	 * Add the Subscriptions submenu page.
	 *
	 * @return void
	 */
	public function add_submenu_page(): void {
		add_submenu_page(
			'leastudios-payments',
			__( 'Subscriptions', 'leastudios-payments' ),
			__( 'Subscriptions', 'leastudios-payments' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Render the subscriptions list.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$table = new Subscriptions_List_Table( $this->subscription_repository );
		$table->prepare_items();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Subscriptions', 'leastudios-payments' ); ?></h1>

			<?php $this->render_notices(); ?>
			<?php $table->views(); ?>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle cancel actions.
	 *
	 * @return void
	 */
	public function handle_actions(): void {
		if ( ! isset( $_GET['sub_action'] ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_GET['sub_action'] ) );
		$sub_id = absint( $_GET['sub_id'] ?? 0 );

		if ( ! in_array( $action, [ 'cancel_end', 'cancel_now' ], true ) || 0 === $sub_id ) {
			return;
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'leastudios_payments_' . $action ) ) {
			wp_die( esc_html__( 'Security check failed.', 'leastudios-payments' ) );
		}

		$subscription = $this->subscription_repository->get( $sub_id );

		if ( ! $subscription ) {
			wp_die( esc_html__( 'Subscription not found.', 'leastudios-payments' ) );
		}

		if ( ! $this->stripe_client->initialize() ) {
			$this->redirect_with_notice( 'error', __( 'Stripe is not configured.', 'leastudios-payments' ) );
			return;
		}

		try {
			if ( 'cancel_now' === $action ) {
				$stripe_sub = \Stripe\Subscription::retrieve( $subscription->stripe_subscription_id );
				$stripe_sub->cancel();

				$this->subscription_repository->update(
					$sub_id,
					[ 'status' => 'canceled' ]
				);

				/**
				 * Fires after a subscription is canceled immediately.
				 *
				 * @since 1.0.0
				 *
				 * @param int    $sub_id         The local subscription ID.
				 * @param string $stripe_sub_id  The Stripe subscription ID.
				 */
				do_action( 'leastudios_payments_subscription_canceled', $sub_id, $subscription->stripe_subscription_id );

				$this->redirect_with_notice( 'success', __( 'Subscription canceled immediately.', 'leastudios-payments' ) );
			} else {
				\Stripe\Subscription::update(
					$subscription->stripe_subscription_id,
					[ 'cancel_at_period_end' => true ]
				);

				$this->subscription_repository->update(
					$sub_id,
					[ 'cancel_at_period_end' => 1 ]
				);

				/**
				 * Fires after a subscription is set to cancel at period end.
				 *
				 * @since 1.0.0
				 *
				 * @param int    $sub_id         The local subscription ID.
				 * @param string $stripe_sub_id  The Stripe subscription ID.
				 */
				do_action( 'leastudios_payments_subscription_cancel_scheduled', $sub_id, $subscription->stripe_subscription_id );

				$this->redirect_with_notice( 'success', __( 'Subscription will be canceled at the end of the billing period.', 'leastudios-payments' ) );
			}
		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[leaStudios Payments] Subscription cancel error: ' . $e->getMessage() );
			}

			$this->redirect_with_notice( 'error', __( 'Failed to cancel subscription. Please try again or check the Stripe Dashboard.', 'leastudios-payments' ) );
		}
	}

	/**
	 * Render admin notices from transients.
	 *
	 * @return void
	 */
	private function render_notices(): void {
		$notice = get_transient( 'leastudios_payments_sub_notice' );

		if ( ! is_array( $notice ) ) {
			return;
		}

		delete_transient( 'leastudios_payments_sub_notice' );

		$type    = 'success' === ( $notice['type'] ?? '' ) ? 'success' : 'error';
		$message = $notice['message'] ?? '';

		if ( '' !== $message ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( $type ),
				esc_html( $message )
			);
		}
	}

	/**
	 * Redirect back to the subscriptions page with a notice.
	 *
	 * @param string $type    Notice type: 'success' or 'error'.
	 * @param string $message The notice message.
	 * @return void
	 */
	private function redirect_with_notice( string $type, string $message ): void {
		set_transient(
			'leastudios_payments_sub_notice',
			[
				'type'    => $type,
				'message' => $message,
			],
			30
		);

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}
}
