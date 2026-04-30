<?php
/**
 * Tests for Checkout_Handler.
 *
 * @package LEAStudios\Payments\Tests
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Tests;

use LEAStudios\Payments\Checkout\Checkout_Handler;
use LEAStudios\Payments\Database\Order_Repository;
use LEAStudios\Payments\Stripe\Customer_Manager;
use LEAStudios\Payments\Stripe\Stripe_Client;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\Payments\Checkout\Checkout_Handler
 */
class CheckoutHandlerTest extends TestCase {

	private Stripe_Client $stripe_client;
	private Order_Repository $order_repository;
	private Customer_Manager $customer_manager;
	private Checkout_Handler $handler;

	public function set_up(): void {
		parent::set_up();

		$this->stripe_client    = $this->createMock( Stripe_Client::class );
		$this->order_repository = $this->createMock( Order_Repository::class );
		$this->customer_manager = $this->createMock( Customer_Manager::class );
		$this->handler          = new Checkout_Handler( $this->stripe_client, $this->order_repository, $this->customer_manager );
	}

	public function test_init_registers_webhook_action(): void {
		$this->handler->init();

		$this->assertNotFalse(
			has_action( 'leastudios_payments_webhook_checkout_session_completed', [ $this->handler, 'handle_session_completed' ] )
		);
	}

	public function test_missing_session_id_returns_early(): void {
		$this->order_repository->expects( $this->never() )->method( 'get_by_session_id' );
		$this->order_repository->expects( $this->never() )->method( 'create' );

		$this->handler->handle_session_completed(
			[
				'data' => [
					'object' => [],
				],
			]
		);
	}

	public function test_duplicate_session_returns_early(): void {
		$this->order_repository->expects( $this->once() )
			->method( 'get_by_session_id' )
			->with( 'cs_test_123' )
			->willReturn( (object) [ 'id' => 1 ] );

		$this->stripe_client->expects( $this->never() )->method( 'initialize' );
		$this->order_repository->expects( $this->never() )->method( 'create' );

		$this->handler->handle_session_completed(
			[
				'data' => [
					'object' => [
						'id' => 'cs_test_123',
					],
				],
			]
		);
	}

	public function test_stripe_initialization_failure_returns_early(): void {
		$this->order_repository->method( 'get_by_session_id' )->willReturn( null );
		$this->stripe_client->method( 'initialize' )->willReturn( false );

		$this->order_repository->expects( $this->never() )->method( 'create' );

		$this->handler->handle_session_completed(
			[
				'data' => [
					'object' => [
						'id' => 'cs_test_456',
					],
				],
			]
		);
	}

	public function test_order_created_action_fires_on_success(): void {
		$this->order_repository->method( 'get_by_session_id' )->willReturn( null );
		$this->stripe_client->method( 'initialize' )->willReturn( true );
		$this->order_repository->method( 'create' )->willReturn( 42 );

		$fired     = false;
		$action_id = null;

		add_action(
			'leastudios_payments_order_created',
			function ( $order_id ) use ( &$fired, &$action_id ) {
				$fired     = true;
				$action_id = $order_id;
			},
			10,
			2
		);

		$this->handler->handle_session_completed(
			[
				'data' => [
					'object' => [
						'id'               => 'cs_test_789',
						'mode'             => 'payment',
						'customer'         => 'cus_abc',
						'customer_email'   => 'buyer@example.com',
						'customer_details' => [
							'email' => 'buyer@example.com',
							'name'  => 'Test Buyer',
						],
						'amount_total'     => 2999,
						'currency'         => 'usd',
						'payment_intent'   => 'pi_xyz',
						'metadata'         => [ 'wp_user_id' => '5' ],
					],
				],
			]
		);

		$this->assertTrue( $fired );
		$this->assertSame( 42, $action_id );
	}

	public function test_subscription_mode_sets_correct_order_type(): void {
		$this->order_repository->method( 'get_by_session_id' )->willReturn( null );
		$this->stripe_client->method( 'initialize' )->willReturn( true );

		$this->order_repository->expects( $this->once() )
			->method( 'create' )
			->with(
				$this->callback(
					function ( array $data ): bool {
						return 'subscription' === $data['order_type'];
					}
				)
			)
			->willReturn( 1 );

		$this->handler->handle_session_completed(
			[
				'data' => [
					'object' => [
						'id'               => 'cs_test_sub',
						'mode'             => 'subscription',
						'customer'         => 'cus_sub',
						'customer_details' => [
							'email' => 'sub@example.com',
							'name'  => 'Subscriber',
						],
						'amount_total'     => 999,
						'currency'         => 'usd',
					],
				],
			]
		);
	}
}
