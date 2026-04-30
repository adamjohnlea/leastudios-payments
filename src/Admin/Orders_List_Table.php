<?php
/**
 * Orders list table for the admin.
 *
 * @package LEAStudios\Payments\Admin
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Admin;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Payments\Database\Order_Repository;
use LEAStudios\Payments\Shared\Datetime_Util;
use LEAStudios\Payments\Support\Currency_Formatter;

// Load WP_List_Table if not available.
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Displays orders in a WP_List_Table format.
 */
class Orders_List_Table extends \WP_List_Table {

	/**
	 * Constructor.
	 *
	 * @param Order_Repository $order_repository The order repository.
	 */
	public function __construct(
		private readonly Order_Repository $order_repository,
	) {
		parent::__construct(
			[
				'singular' => 'order',
				'plural'   => 'orders',
				'ajax'     => false,
			]
		);
	}

	/**
	 * Define table columns.
	 *
	 * @return array<string, string> Column slugs and labels.
	 */
	public function get_columns(): array {
		return [
			'id'             => __( 'Order', 'leastudios-payments' ),
			'customer_email' => __( 'Customer', 'leastudios-payments' ),
			'amount_total'   => __( 'Amount', 'leastudios-payments' ),
			'order_type'     => __( 'Type', 'leastudios-payments' ),
			'payment_status' => __( 'Status', 'leastudios-payments' ),
			'created_at'     => __( 'Date', 'leastudios-payments' ),
		];
	}

	/**
	 * Define sortable columns.
	 *
	 * @return array<string, array{0: string, 1: bool}> Sortable column definitions.
	 */
	public function get_sortable_columns(): array {
		return [
			'created_at' => [ 'created_at', true ],
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
			$this->get_sortable_columns(),
		];

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter, no action taken.
		$status = sanitize_text_field( wp_unslash( $_GET['payment_status'] ?? '' ) );

		$this->items = $this->order_repository->get_all( $status, $per_page, $offset );
		$total       = $this->order_repository->count( $status );

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
	 * @return array<string, string> View links.
	 */
	protected function get_views(): array {
		$base_url = add_query_arg( 'page', 'leastudios-payments-orders', admin_url( 'admin.php' ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = sanitize_text_field( wp_unslash( $_GET['payment_status'] ?? '' ) );

		$total          = $this->order_repository->count();
		$paid_count     = $this->order_repository->count( 'paid' );
		$refunded_count = $this->order_repository->count( 'refunded' );
		$partial_count  = $this->order_repository->count( 'partial_refund' );

		$views = [];

		$views['all'] = sprintf(
			'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
			esc_url( $base_url ),
			'' === $current ? 'current' : '',
			esc_html__( 'All', 'leastudios-payments' ),
			$total
		);

		if ( $paid_count > 0 ) {
			$views['paid'] = sprintf(
				'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
				esc_url( add_query_arg( 'payment_status', 'paid', $base_url ) ),
				'paid' === $current ? 'current' : '',
				esc_html__( 'Paid', 'leastudios-payments' ),
				$paid_count
			);
		}

		if ( $refunded_count > 0 ) {
			$views['refunded'] = sprintf(
				'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
				esc_url( add_query_arg( 'payment_status', 'refunded', $base_url ) ),
				'refunded' === $current ? 'current' : '',
				esc_html__( 'Refunded', 'leastudios-payments' ),
				$refunded_count
			);
		}

		if ( $partial_count > 0 ) {
			$views['partial_refund'] = sprintf(
				'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
				esc_url( add_query_arg( 'payment_status', 'partial_refund', $base_url ) ),
				'partial_refund' === $current ? 'current' : '',
				esc_html__( 'Partially Refunded', 'leastudios-payments' ),
				$partial_count
			);
		}

		return $views;
	}

	/**
	 * Render the Order ID column.
	 *
	 * @param object $item The order row.
	 * @return string Column HTML.
	 */
	public function column_id( object $item ): string {
		$detail_url = add_query_arg(
			[
				'page'   => 'leastudios-payments-orders',
				'action' => 'view',
				'id'     => $item->id,
			],
			admin_url( 'admin.php' )
		);

		return sprintf(
			'<strong><a href="%s">#%d</a></strong>',
			esc_url( $detail_url ),
			(int) $item->id
		);
	}

	/**
	 * Render the customer column.
	 *
	 * @param object $item The order row.
	 * @return string Column HTML.
	 */
	public function column_customer_email( object $item ): string {
		$output = esc_html( $item->customer_email );

		if ( ! empty( $item->customer_name ) ) {
			$output = esc_html( $item->customer_name ) . '<br><small>' . esc_html( $item->customer_email ) . '</small>';
		}

		return $output;
	}

	/**
	 * Render the amount column.
	 *
	 * @param object $item The order row.
	 * @return string Column HTML.
	 */
	public function column_amount_total( object $item ): string {
		return esc_html( Currency_Formatter::format( (int) $item->amount_total, $item->currency ) );
	}

	/**
	 * Render the status column.
	 *
	 * @param object $item The order row.
	 * @return string Column HTML.
	 */
	public function column_payment_status( object $item ): string {
		$colors = [
			'paid'           => '#00a32a',
			'refunded'       => '#d63638',
			'partial_refund' => '#dba617',
		];

		$color = $colors[ $item->payment_status ] ?? '#787c82';
		$label = match ( $item->payment_status ) {
			'paid'           => __( 'Paid', 'leastudios-payments' ),
			'refunded'       => __( 'Refunded', 'leastudios-payments' ),
			'partial_refund' => __( 'Partial Refund', 'leastudios-payments' ),
			default          => ucfirst( $item->payment_status ),
		};

		return sprintf(
			'<span style="background:%s;color:#fff;padding:2px 8px;border-radius:3px;font-size:12px;">%s</span>',
			esc_attr( $color ),
			esc_html( $label )
		);
	}

	/**
	 * Render the type column.
	 *
	 * @param object $item The order row.
	 * @return string Column HTML.
	 */
	public function column_order_type( object $item ): string {
		$type = $item->order_type ?? 'one_time';

		if ( 'subscription' === $type ) {
			return sprintf(
				'<span style="background:#2271b1;color:#fff;padding:2px 8px;border-radius:3px;font-size:12px;">%s</span>',
				esc_html__( 'Subscription', 'leastudios-payments' )
			);
		}

		return sprintf(
			'<span style="background:#50575e;color:#fff;padding:2px 8px;border-radius:3px;font-size:12px;">%s</span>',
			esc_html__( 'One-time', 'leastudios-payments' )
		);
	}

	/**
	 * Render the date column.
	 *
	 * @param object $item The order row.
	 * @return string Column HTML.
	 */
	public function column_created_at( object $item ): string {
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		return esc_html( Datetime_Util::format_for_display( $item->created_at ?? null, $format ) );
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
		esc_html_e( 'No orders found.', 'leastudios-payments' );
	}
}
