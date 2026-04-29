<?php
/**
 * Tests for Currency_Formatter.
 *
 * @package LEAStudios\Payments\Tests
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Tests;

use LEAStudios\Payments\Support\Currency_Formatter;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\Payments\Support\Currency_Formatter
 */
class Currency_FormatterTest extends TestCase {

	public function test_format_usd_uses_dollar_symbol_and_two_decimals(): void {
		$this->assertSame( '$10.00', Currency_Formatter::format( 1000, 'usd' ) );
		$this->assertSame( '$0.99', Currency_Formatter::format( 99, 'USD' ) );
	}

	public function test_format_jpy_skips_decimals(): void {
		$this->assertSame( "\xc2\xa5" . '1,234', Currency_Formatter::format( 1234, 'jpy' ) );
		$this->assertSame( "\xc2\xa5" . '0', Currency_Formatter::format( 0, 'JPY' ) );
	}

	public function test_format_gbp_uses_pound_symbol(): void {
		$this->assertSame( "\xc2\xa3" . '5.50', Currency_Formatter::format( 550, 'gbp' ) );
	}

	public function test_format_unknown_currency_falls_back_to_uppercase_code(): void {
		$this->assertSame( 'XYZ 12.34', Currency_Formatter::format( 1234, 'xyz' ) );
	}

	public function test_format_price_one_time_has_no_suffix(): void {
		$price = (object) [
			'amount'   => 2500,
			'currency' => 'usd',
			'type'     => 'one_time',
		];

		$this->assertSame( '$25.00', Currency_Formatter::format_price( $price ) );
	}

	public function test_format_price_recurring_singular_interval_appends_slash_suffix(): void {
		$price = (object) [
			'amount'                   => 999,
			'currency'                 => 'usd',
			'type'                     => 'recurring',
			'recurring_interval'       => 'month',
			'recurring_interval_count' => 1,
		];

		$this->assertSame( '$9.99/month', Currency_Formatter::format_price( $price ) );
	}

	public function test_format_price_recurring_plural_interval_appends_every_suffix(): void {
		$price = (object) [
			'amount'                   => 4999,
			'currency'                 => 'usd',
			'type'                     => 'recurring',
			'recurring_interval'       => 'month',
			'recurring_interval_count' => 3,
		];

		$this->assertSame( '$49.99 every 3 months', Currency_Formatter::format_price( $price ) );
	}

	public function test_format_price_recurring_without_interval_omits_suffix(): void {
		$price = (object) [
			'amount'             => 1000,
			'currency'           => 'usd',
			'type'               => 'recurring',
			'recurring_interval' => '',
		];

		$this->assertSame( '$10.00', Currency_Formatter::format_price( $price ) );
	}
}
