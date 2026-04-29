<?php
/**
 * Currency formatting helper.
 *
 * @package LEAStudios\Payments\Support
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Support;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Formats currency amounts for admin display.
 */
class Currency_Formatter {

	/**
	 * Currency code → display symbol map.
	 *
	 * @var array<string, string>
	 */
	private const SYMBOLS = [
		'usd' => '$',
		'gbp' => "\xc2\xa3",
		'eur' => "\xe2\x82\xac",
		'cad' => 'CA$',
		'aud' => 'A$',
		'nzd' => 'NZ$',
		'chf' => 'CHF ',
		'jpy' => "\xc2\xa5",
	];

	/**
	 * Format an amount in the smallest currency unit.
	 *
	 * @param int    $amount   Amount in smallest currency unit (e.g. cents).
	 * @param string $currency ISO currency code.
	 * @return string Formatted amount with symbol.
	 */
	public static function format( int $amount, string $currency ): string {
		$cur    = strtolower( $currency );
		$symbol = self::SYMBOLS[ $cur ] ?? strtoupper( $currency ) . ' ';

		// JPY is a zero-decimal currency.
		if ( 'jpy' === $cur ) {
			return $symbol . number_format( $amount );
		}

		return $symbol . number_format( $amount / 100, 2 );
	}

	/**
	 * Format a price row for display, including recurring interval suffix.
	 *
	 * @param object $price A price row with amount, currency, type, recurring_interval, recurring_interval_count.
	 * @return string Formatted price string.
	 */
	public static function format_price( object $price ): string {
		$formatted = self::format( (int) $price->amount, (string) $price->currency );

		if ( 'recurring' === $price->type && ! empty( $price->recurring_interval ) ) {
			$count    = (int) ( $price->recurring_interval_count ?? 1 );
			$interval = (string) $price->recurring_interval;

			if ( 1 === $count ) {
				/* translators: %s: billing interval (month, year, etc.) */
				$formatted .= sprintf( __( '/%s', 'leastudios-payments' ), $interval );
			} else {
				/* translators: 1: interval count, 2: interval */
				$formatted .= sprintf( __( ' every %1$s %2$ss', 'leastudios-payments' ), $count, $interval );
			}
		}

		return $formatted;
	}
}
