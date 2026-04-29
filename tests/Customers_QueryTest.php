<?php
/**
 * Tests for Customers_Query.
 *
 * @package LEAStudios\Payments\Tests
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Tests;

use LEAStudios\Payments\Admin\Customers_Query;
use LEAStudios\Payments\Database\Migration;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\Payments\Admin\Customers_Query
 */
class Customers_QueryTest extends TestCase {

	private Customers_Query $query;

	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		// Plugin tables aren't covered by WP_UnitTestCase's transaction rollback.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'TRUNCATE TABLE ' . Migration::table( 'orders' ) );
		$wpdb->query( 'TRUNCATE TABLE ' . Migration::table( 'subscriptions' ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		$this->query = new Customers_Query();
	}

	private function insert_order( array $data ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			Migration::table( 'orders' ),
			array_merge(
				[
					'stripe_session_id'  => 'cs_' . wp_generate_password( 8, false ),
					'stripe_customer_id' => 'cus_default',
					'customer_email'     => 'default@example.com',
					'customer_name'      => 'Default',
					'amount_total'       => 1000,
					'currency'           => 'usd',
					'payment_status'     => 'paid',
					'order_type'         => 'one_time',
					'refunded_amount'    => 0,
				],
				$data
			)
		);
	}

	private function insert_subscription( array $data ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			Migration::table( 'subscriptions' ),
			array_merge(
				[
					'stripe_subscription_id' => 'sub_' . wp_generate_password( 8, false ),
					'stripe_customer_id'     => 'cus_default',
					'customer_email'         => 'default@example.com',
					'status'                 => 'active',
				],
				$data
			)
		);
	}

	public function test_get_customers_returns_empty_when_no_orders(): void {
		$this->assertSame( [], $this->query->get_customers() );
	}

	public function test_get_customers_groups_by_email_and_aggregates_totals(): void {
		$this->insert_order(
			[
				'customer_email' => 'alice@example.com',
				'customer_name'  => 'Alice',
				'amount_total'   => 2500,
				'created_at'     => '2026-01-01 00:00:00',
			]
		);
		$this->insert_order(
			[
				'customer_email'  => 'alice@example.com',
				'customer_name'   => 'Alice',
				'amount_total'    => 1500,
				'refunded_amount' => 500,
				'created_at'      => '2026-02-01 00:00:00',
			]
		);
		$this->insert_order(
			[
				'customer_email' => 'bob@example.com',
				'customer_name'  => 'Bob',
				'amount_total'   => 999,
				'created_at'     => '2026-01-15 00:00:00',
			]
		);

		$customers = $this->query->get_customers();

		$this->assertCount( 2, $customers );

		// Ordered by last_order_date DESC — Alice's most recent order is 2026-02-01.
		$this->assertSame( 'alice@example.com', $customers[0]->customer_email );
		$this->assertSame( 2, (int) $customers[0]->order_count );
		$this->assertSame( 3500, (int) $customers[0]->total_spent ); // 2500 + (1500 - 500).
		$this->assertSame( 'Alice', $customers[0]->customer_name );

		$this->assertSame( 'bob@example.com', $customers[1]->customer_email );
		$this->assertSame( 1, (int) $customers[1]->order_count );
	}

	public function test_get_customers_uses_most_recent_name_not_lexicographic_max(): void {
		// "Robert" sorts after "Bob" — MAX() would pick Robert even when Bob is the newer name.
		$this->insert_order(
			[
				'customer_email' => 'rename@example.com',
				'customer_name'  => 'Robert',
				'created_at'     => '2026-01-01 00:00:00',
			]
		);
		$this->insert_order(
			[
				'customer_email' => 'rename@example.com',
				'customer_name'  => 'Bob',
				'created_at'     => '2026-02-01 00:00:00',
			]
		);

		$customers = $this->query->get_customers();

		$this->assertCount( 1, $customers );
		$this->assertSame( 'Bob', $customers[0]->customer_name );
	}

	public function test_get_customers_excludes_orders_with_blank_email(): void {
		$this->insert_order( [ 'customer_email' => '' ] );
		$this->insert_order( [ 'customer_email' => 'real@example.com' ] );

		$customers = $this->query->get_customers();

		$this->assertCount( 1, $customers );
		$this->assertSame( 'real@example.com', $customers[0]->customer_email );
	}

	public function test_get_orders_by_email_returns_only_matching_orders(): void {
		$this->insert_order(
			[
				'customer_email' => 'a@example.com',
				'amount_total'   => 100,
			]
		);
		$this->insert_order(
			[
				'customer_email' => 'a@example.com',
				'amount_total'   => 200,
			]
		);
		$this->insert_order(
			[
				'customer_email' => 'b@example.com',
				'amount_total'   => 300,
			]
		);

		$orders = $this->query->get_orders_by_email( 'a@example.com' );

		$this->assertCount( 2, $orders );
		$this->assertEqualsCanonicalizing(
			[ 100, 200 ],
			array_map( static fn( $o ) => (int) $o->amount_total, $orders )
		);
	}

	public function test_get_subscriptions_by_email_returns_only_matching_subscriptions(): void {
		$this->insert_subscription(
			[
				'customer_email' => 'sub@example.com',
				'status'         => 'active',
			]
		);
		$this->insert_subscription(
			[
				'customer_email' => 'sub@example.com',
				'status'         => 'canceled',
			]
		);
		$this->insert_subscription( [ 'customer_email' => 'other@example.com' ] );

		$subscriptions = $this->query->get_subscriptions_by_email( 'sub@example.com' );

		$this->assertCount( 2, $subscriptions );
	}
}
