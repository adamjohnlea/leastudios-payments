<?php
/**
 * Tests for Subscription_Handler webhook field paths under Stripe API
 * 2026-03-25.dahlia. These lock in the field moves from 2025-04-30
 * (subscription.current_period_* → items[]) and 2025-09-30 (invoice
 * .subscription → invoice.parent.subscription_details.subscription).
 *
 * @package LEAStudios\Payments\Tests
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Tests;

use LEAStudios\Payments\Checkout\Subscription_Handler;
use LEAStudios\Payments\Database\Subscription_Repository;
use LEAStudios\Payments\Stripe\Customer_Manager;
use LEAStudios\Payments\Stripe\Stripe_Client;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\Payments\Checkout\Subscription_Handler
 */
class SubscriptionHandlerTest extends TestCase {

	private Subscription_Repository $subscription_repository;
	private Stripe_Client $stripe_client;
	private Customer_Manager $customer_manager;
	private Subscription_Handler $handler;

	public function set_up(): void {
		parent::set_up();

		$this->subscription_repository = $this->createMock( Subscription_Repository::class );
		$this->stripe_client           = $this->createMock( Stripe_Client::class );
		$this->customer_manager        = $this->createMock( Customer_Manager::class );
		$this->handler                 = new Subscription_Handler(
			$this->subscription_repository,
			$this->stripe_client,
			$this->customer_manager
		);
	}

	public function test_missing_subscription_id_returns_early(): void {
		$this->subscription_repository->expects( $this->never() )->method( 'upsert' );

		$this->handler->handle_subscription_change(
			[
				'data' => [
					'object' => [],
				],
			]
		);
	}

	public function test_subscription_change_reads_period_from_items_not_top_level(): void {
		$this->customer_manager->method( 'resolve_user_id' )->willReturn( 7 );

		$item_start = 1775347200; // 2026-04-01 00:00:00 UTC
		$item_end   = 1777939200; // 2026-05-01 00:00:00 UTC

		$this->subscription_repository->expects( $this->once() )
			->method( 'upsert' )
			->with(
				$this->callback(
					static function ( array $data ) use ( $item_start, $item_end ): bool {
						return gmdate( 'Y-m-d H:i:s', $item_start ) === $data['current_period_start']
							&& gmdate( 'Y-m-d H:i:s', $item_end ) === $data['current_period_end'];
					}
				)
			)
			->willReturn( 1 );

		$this->handler->handle_subscription_change(
			[
				'data' => [
					'object' => [
						'id'                   => 'sub_test',
						'customer'             => 'cus_test',
						'status'               => 'active',
						// Top-level period is intentionally a wrong (old) value;
						// the handler must ignore it under dahlia.
						'current_period_start' => 1577836800,
						'current_period_end'   => 1580515200,
						'items'                => [
							'data' => [
								[
									'price'                => [ 'id' => 'price_test' ],
									'current_period_start' => $item_start,
									'current_period_end'   => $item_end,
								],
							],
						],
					],
				],
			]
		);
	}

	public function test_subscription_change_handles_missing_items_gracefully(): void {
		$this->customer_manager->method( 'resolve_user_id' )->willReturn( null );

		$this->subscription_repository->expects( $this->once() )
			->method( 'upsert' )
			->with(
				$this->callback(
					function ( array $data ): bool {
						return null === $data['current_period_start']
							&& null === $data['current_period_end'];
					}
				)
			)
			->willReturn( 1 );

		$this->handler->handle_subscription_change(
			[
				'data' => [
					'object' => [
						'id'       => 'sub_no_items',
						'customer' => 'cus_test',
						'status'   => 'active',
					],
				],
			]
		);
	}

	public function test_invoice_paid_reads_subscription_from_parent_details(): void {
		$this->subscription_repository->expects( $this->once() )
			->method( 'get_by_stripe_id' )
			->with( 'sub_from_parent' )
			->willReturn( (object) [ 'id' => 9 ] );

		$this->subscription_repository->expects( $this->once() )
			->method( 'update' )
			->with( 9, [ 'status' => 'active' ] );

		$this->handler->handle_invoice_paid(
			[
				'data' => [
					'object' => [
						// Top-level `subscription` was removed in 2025-09-30.clover.
						'parent' => [
							'subscription_details' => [
								'subscription' => 'sub_from_parent',
							],
						],
					],
				],
			]
		);
	}

	public function test_invoice_paid_returns_early_when_nested_subscription_missing(): void {
		$this->subscription_repository->expects( $this->never() )->method( 'get_by_stripe_id' );
		$this->subscription_repository->expects( $this->never() )->method( 'update' );

		$this->handler->handle_invoice_paid(
			[
				'data' => [
					'object' => [
						'parent' => [
							'type' => 'manual',
						],
					],
				],
			]
		);
	}

	public function test_invoice_payment_failed_marks_subscription_past_due(): void {
		$this->subscription_repository->expects( $this->once() )
			->method( 'get_by_stripe_id' )
			->with( 'sub_failing' )
			->willReturn( (object) [ 'id' => 42 ] );

		$this->subscription_repository->expects( $this->once() )
			->method( 'update' )
			->with( 42, [ 'status' => 'past_due' ] );

		$this->handler->handle_invoice_payment_failed(
			[
				'data' => [
					'object' => [
						'parent' => [
							'subscription_details' => [
								'subscription' => 'sub_failing',
							],
						],
					],
				],
			]
		);
	}

	public function test_invoice_payment_failed_ignores_unknown_subscription(): void {
		$this->subscription_repository->expects( $this->once() )
			->method( 'get_by_stripe_id' )
			->with( 'sub_unknown' )
			->willReturn( null );

		$this->subscription_repository->expects( $this->never() )->method( 'update' );

		$this->handler->handle_invoice_payment_failed(
			[
				'data' => [
					'object' => [
						'parent' => [
							'subscription_details' => [
								'subscription' => 'sub_unknown',
							],
						],
					],
				],
			]
		);
	}
}
