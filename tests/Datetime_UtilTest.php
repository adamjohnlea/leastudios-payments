<?php
/**
 * Tests for Datetime_Util.
 *
 * @package LEAStudios\Payments\Tests
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Tests;

use LEAStudios\Payments\Shared\Datetime_Util;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\Payments\Shared\Datetime_Util
 */
class Datetime_UtilTest extends TestCase {

	public function test_utc_now_mysql_returns_utc_string(): void {
		$before = ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
		$mysql  = Datetime_Util::utc_now_mysql();
		$after  = ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();

		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $mysql );

		$parsed_ts = ( new \DateTimeImmutable( $mysql, new \DateTimeZone( 'UTC' ) ) )->getTimestamp();

		$this->assertGreaterThanOrEqual( $before, $parsed_ts );
		$this->assertLessThanOrEqual( $after, $parsed_ts );
	}

	public function test_format_for_display_converts_utc_to_wp_timezone(): void {
		update_option( 'timezone_string', 'America/Boise' );

		// 02:41 UTC on 2026-04-30 == 20:41 (8:41 PM) on 2026-04-29 in Boise (MDT, UTC-6).
		$formatted = Datetime_Util::format_for_display( '2026-04-30 02:41:00', 'Y-m-d H:i' );

		$this->assertSame( '2026-04-29 20:41', $formatted );
	}

	public function test_format_for_display_returns_empty_string_for_null_or_empty(): void {
		$this->assertSame( '', Datetime_Util::format_for_display( null, 'Y-m-d' ) );
		$this->assertSame( '', Datetime_Util::format_for_display( '', 'Y-m-d' ) );
	}
}
