<?php
/**
 * UTC-anchored datetime helpers.
 *
 * @package LEAStudios\Payments\Shared
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Shared;

defined( 'ABSPATH' ) || exit;

/**
 * Centralises UTC↔WP-timezone conversion for stored timestamps.
 *
 * The orders, customers, products, prices, and subscriptions tables all
 * store timestamps in UTC. On display, route every conversion through
 * `format_for_display()` (which uses `get_date_from_gmt()`) instead of
 * `strtotime() + wp_date()` — the latter interprets the stored string in
 * PHP's `date.timezone` ini setting, which differs between Herd local dev
 * and production servers.
 */
final class Datetime_Util {

	/**
	 * Current time in UTC, formatted for a MySQL `datetime` column.
	 *
	 * @return string e.g. "2026-04-30 02:41:00".
	 */
	public static function utc_now_mysql(): string {
		return ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Convert a stored UTC datetime string to the WordPress display timezone.
	 *
	 * @param string|null $utc_mysql Stored UTC datetime, e.g. "2026-04-30 02:41:00".
	 * @param string      $format    PHP date format string.
	 *
	 * @return string Empty string for null/empty input.
	 */
	public static function format_for_display( ?string $utc_mysql, string $format ): string {
		if ( null === $utc_mysql || '' === $utc_mysql ) {
			return '';
		}

		return get_date_from_gmt( $utc_mysql, $format );
	}
}
