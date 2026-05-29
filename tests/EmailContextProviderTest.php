<?php
/**
 * Tests for the Email_Context_Provider public read seam.
 *
 * @package LEAStudios\Payments\Tests
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Tests;

use LEAStudios\Payments\Database\Order_Repository;
use LEAStudios\Payments\Database\Subscription_Repository;
use LEAStudios\Payments\Support\Email_Context_Provider;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\Payments\Support\Email_Context_Provider
 */
class EmailContextProviderTest extends TestCase {

	private Order_Repository $orders;
	private Subscription_Repository $subscriptions;
	private Email_Context_Provider $provider;

	public function set_up(): void {
		parent::set_up();

		$this->orders        = $this->createMock( Order_Repository::class );
		$this->subscriptions = $this->createMock( Subscription_Repository::class );
		$this->provider      = new Email_Context_Provider( $this->orders, $this->subscriptions );
	}

	public function test_init_registers_filters(): void {
		$this->provider->init();

		$this->assertNotFalse( has_filter( 'leastudios_payments_order_email_context', [ $this->provider, 'order_email_context' ] ) );
		$this->assertNotFalse( has_filter( 'leastudios_payments_subscription_email_context', [ $this->provider, 'subscription_email_context' ] ) );
		$this->assertNotFalse( has_filter( 'leastudios_payments_local_subscription_id', [ $this->provider, 'local_subscription_id' ] ) );
	}

	public function test_order_email_context_returns_documented_shape(): void {
		$row                           = new \stdClass();
		$row->customer_name            = 'Jane Buyer';
		$row->customer_email           = 'jane@example.com';
		$row->amount_total             = 2599;
		$row->currency                 = 'usd';
		$row->line_items_json          = '[{"description":"Pro Plan","price_id":"price_pro"}]';
		$row->order_type               = 'one_time';
		$row->stripe_payment_intent_id = 'pi_123';
		$row->payment_status           = 'paid';
		$row->refunded_amount          = 500;

		$this->orders->method( 'get' )->with( 42 )->willReturn( $row );

		$context = $this->provider->order_email_context( null, 42 );

		$this->assertIsArray( $context );
		$this->assertSame(
			[
				'customer_name',
				'customer_email',
				'amount_total',
				'currency',
				'line_items_json',
				'order_type',
				'stripe_payment_intent_id',
				'payment_status',
				'refunded_amount',
			],
			array_keys( $context )
		);
		$this->assertSame( 'Jane Buyer', $context['customer_name'] );
		$this->assertSame( 2599, $context['amount_total'] );
		$this->assertSame( 500, $context['refunded_amount'] );
		$this->assertIsInt( $context['amount_total'] );
		$this->assertIsInt( $context['refunded_amount'] );
	}

	public function test_order_email_context_returns_default_when_missing(): void {
		$this->orders->method( 'get' )->willReturn( null );

		$this->assertNull( $this->provider->order_email_context( null, 999 ) );
	}

	public function test_subscription_email_context_returns_documented_shape(): void {
		$row                       = new \stdClass();
		$row->customer_email       = 'sub@example.com';
		$row->wp_user_id           = 7;
		$row->status               = 'active';
		$row->current_period_start = '2026-01-01 00:00:00';
		$row->current_period_end   = '2026-02-01 00:00:00';
		$row->stripe_customer_id   = '';
		$row->stripe_price_id      = '';

		$this->subscriptions->method( 'get' )->with( 5 )->willReturn( $row );

		$context = $this->provider->subscription_email_context( null, 5 );

		$this->assertIsArray( $context );
		$this->assertSame(
			[
				'customer_email',
				'wp_user_id',
				'status',
				'current_period_start',
				'current_period_end',
				'product_name',
			],
			array_keys( $context )
		);
		$this->assertSame( 'sub@example.com', $context['customer_email'] );
		$this->assertSame( 7, $context['wp_user_id'] );
		$this->assertIsInt( $context['wp_user_id'] );
		$this->assertSame( '', $context['product_name'] );
	}

	public function test_subscription_email_context_returns_default_when_missing(): void {
		$this->subscriptions->method( 'get' )->willReturn( null );

		$this->assertNull( $this->provider->subscription_email_context( null, 999 ) );
	}

	public function test_local_subscription_id_resolves_stripe_id(): void {
		$row     = new \stdClass();
		$row->id = 99;

		$this->subscriptions->method( 'get_by_stripe_id' )->with( 'sub_known' )->willReturn( $row );

		$this->assertSame( 99, $this->provider->local_subscription_id( null, 'sub_known' ) );
	}

	public function test_local_subscription_id_returns_default_when_unknown(): void {
		$this->subscriptions->method( 'get_by_stripe_id' )->willReturn( null );

		$this->assertNull( $this->provider->local_subscription_id( null, 'sub_unknown' ) );
	}
}
