<?php
/**
 * Subscriptions list table for the admin.
 *
 * @package LEAStudios\Payments\Admin
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Admin;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Payments\Database\Subscription_Repository;

// Load WP_List_Table if not available.
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Displays subscriptions in a WP_List_Table format.
 */
class Subscriptions_List_Table extends \WP_List_Table {

	/**
	 * Constructor.
	 *
	 * @param Subscription_Repository $subscription_repository The subscription repository.
	 */
	public function __construct(
		private readonly Subscription_Repository $subscription_repository,
	) {
		parent::__construct(
			[
				'singular' => 'subscription',
				'plural'   => 'subscriptions',
				'ajax'     => false,
			]
		);
	}

	/**
	 * Define table columns.
	 *
	 * @return array Column slugs and labels.
	 */
	public function get_columns(): array {
		return [
			'id'                   => __( 'ID', 'leastudios-payments' ),
			'customer_email'       => __( 'Customer', 'leastudios-payments' ),
			'stripe_price_id'      => __( 'Plan', 'leastudios-payments' ),
			'status'               => __( 'Status', 'leastudios-payments' ),
			'current_period_end'   => __( 'Renews', 'leastudios-payments' ),
			'actions'              => __( 'Actions', 'leastudios-payments' ),
		];
	}

	/**
	 * Prepare items for display.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$per_page = 20;
		$page     = $this->get_pagenum();
		$offset   = ( $page - 1 ) * $per_page;

		$this->_column_headers = [
			$this->get_columns(),
			[],
			[],
		];

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$status = sanitize_text_field( wp_unslash( $_GET['sub_status'] ?? '' ) );

		$this->items = $this->subscription_repository->get_all( $status, $per_page, $offset );
		$total       = $this->subscription_repository->count( $status );

		$this->set_pagination_args(
			[
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			]
		);
	}

	/**
	 * Get status filter views.
	 *
	 * @return array View links.
	 */
	protected function get_views(): array {
		$base_url = add_query_arg( 'page', 'leastudios-payments-subscriptions', admin_url( 'admin.php' ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = sanitize_text_field( wp_unslash( $_GET['sub_status'] ?? '' ) );

		$total          = $this->subscription_repository->count();
		$active_count   = $this->subscription_repository->count( 'active' );
		$canceled_count = $this->subscription_repository->count( 'canceled' );
		$past_due_count = $this->subscription_repository->count( 'past_due' );
		$trialing_count = $this->subscription_repository->count( 'trialing' );

		$views = [];

		$views['all'] = sprintf(
			'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
			esc_url( $base_url ),
			'' === $current ? 'current' : '',
			esc_html__( 'All', 'leastudios-payments' ),
			$total
		);

		$statuses = [
			'active'   => [ $active_count, __( 'Active', 'leastudios-payments' ) ],
			'trialing' => [ $trialing_count, __( 'Trialing', 'leastudios-payments' ) ],
			'past_due' => [ $past_due_count, __( 'Past Due', 'leastudios-payments' ) ],
			'canceled' => [ $canceled_count, __( 'Canceled', 'leastudios-payments' ) ],
		];

		foreach ( $statuses as $slug => $info ) {
			if ( $info[0] > 0 ) {
				$views[ $slug ] = sprintf(
					'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
					esc_url( add_query_arg( 'sub_status', $slug, $base_url ) ),
					$slug === $current ? 'current' : '',
					esc_html( $info[1] ),
					$info[0]
				);
			}
		}

		return $views;
	}

	/**
	 * Render the ID column.
	 *
	 * @param object $item The subscription row.
	 * @return string Column HTML.
	 */
	public function column_id( object $item ): string {
		return '#' . (int) $item->id;
	}

	/**
	 * Render the customer column.
	 *
	 * @param object $item The subscription row.
	 * @return string Column HTML.
	 */
	public function column_customer_email( object $item ): string {
		return esc_html( $item->customer_email );
	}

	/**
	 * Render the plan column.
	 *
	 * @param object $item The subscription row.
	 * @return string Column HTML.
	 */
	public function column_stripe_price_id( object $item ): string {
		return '<code>' . esc_html( $item->stripe_price_id ) . '</code>';
	}

	/**
	 * Render the status column.
	 *
	 * @param object $item The subscription row.
	 * @return string Column HTML.
	 */
	public function column_status( object $item ): string {
		$colors = [
			'active'   => '#00a32a',
			'trialing' => '#2271b1',
			'past_due' => '#dba617',
			'canceled' => '#d63638',
			'paused'   => '#787c82',
		];

		$color = $colors[ $item->status ] ?? '#787c82';
		$label = ucfirst( str_replace( '_', ' ', $item->status ) );

		$output = sprintf(
			'<span style="background:%s;color:#fff;padding:2px 8px;border-radius:3px;font-size:12px;">%s</span>',
			esc_attr( $color ),
			esc_html( $label )
		);

		if ( ! empty( $item->cancel_at_period_end ) && 'canceled' !== $item->status ) {
			$output .= '<br><small style="color:#d63638;">' . esc_html__( 'Cancels at period end', 'leastudios-payments' ) . '</small>';
		}

		return $output;
	}

	/**
	 * Render the renewal date column.
	 *
	 * @param object $item The subscription row.
	 * @return string Column HTML.
	 */
	public function column_current_period_end( object $item ): string {
		if ( 'canceled' === $item->status || empty( $item->current_period_end ) ) {
			return '&mdash;';
		}

		$timestamp = strtotime( $item->current_period_end );

		if ( false === $timestamp ) {
			return esc_html( $item->current_period_end );
		}

		return esc_html( wp_date( get_option( 'date_format' ), $timestamp ) ?? $item->current_period_end );
	}

	/**
	 * Render the actions column.
	 *
	 * @param object $item The subscription row.
	 * @return string Column HTML.
	 */
	public function column_actions( object $item ): string {
		$actions = [];

		if ( in_array( $item->status, [ 'active', 'trialing', 'past_due' ], true ) ) {
			if ( empty( $item->cancel_at_period_end ) ) {
				$cancel_end_url = wp_nonce_url(
					add_query_arg(
						[
							'page'       => 'leastudios-payments-subscriptions',
							'sub_action' => 'cancel_end',
							'sub_id'     => $item->id,
						],
						admin_url( 'admin.php' )
					),
					'leastudios_payments_cancel_end'
				);

				$actions[] = sprintf(
					'<a href="%s" onclick="return confirm(\'%s\');">%s</a>',
					esc_url( $cancel_end_url ),
					esc_js( __( 'Cancel this subscription at the end of the current billing period?', 'leastudios-payments' ) ),
					esc_html__( 'Cancel at Period End', 'leastudios-payments' )
				);
			}

			$cancel_now_url = wp_nonce_url(
				add_query_arg(
					[
						'page'       => 'leastudios-payments-subscriptions',
						'sub_action' => 'cancel_now',
						'sub_id'     => $item->id,
					],
					admin_url( 'admin.php' )
				),
				'leastudios_payments_cancel_now'
			);

			$actions[] = sprintf(
				'<a href="%s" style="color:#d63638;" onclick="return confirm(\'%s\');">%s</a>',
				esc_url( $cancel_now_url ),
				esc_js( __( 'Cancel this subscription immediately? This cannot be undone.', 'leastudios-payments' ) ),
				esc_html__( 'Cancel Immediately', 'leastudios-payments' )
			);
		}

		// Link to Stripe Dashboard.
		$stripe_url = 'https://dashboard.stripe.com/test/subscriptions/' . $item->stripe_subscription_id;
		$actions[]  = sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s &#8599;</a>',
			esc_url( $stripe_url ),
			esc_html__( 'Stripe', 'leastudios-payments' )
		);

		return implode( ' | ', $actions );
	}

	/**
	 * Default column renderer.
	 *
	 * @param object $item        The row.
	 * @param string $column_name The column slug.
	 * @return string Column value.
	 */
	public function column_default( $item, $column_name ): string {
		return esc_html( (string) ( $item->$column_name ?? '' ) );
	}

	/**
	 * Message for empty table.
	 *
	 * @return void
	 */
	public function no_items(): void {
		esc_html_e( 'No subscriptions found.', 'leastudios-payments' );
	}
}
