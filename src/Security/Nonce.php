<?php
/**
 * Nonce helper utilities.
 *
 * @package LEAStudios\Payments\Security
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Security;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Static nonce helper with consistent plugin prefix.
 */
class Nonce {

	/**
	 * Nonce prefix for all plugin nonces.
	 */
	private const PREFIX = 'leastudios_payments_';

	/**
	 * Create a nonce.
	 *
	 * @param string $action The nonce action.
	 * @return string The nonce token.
	 */
	public static function create( string $action ): string {
		return wp_create_nonce( self::PREFIX . $action );
	}

	/**
	 * Verify a nonce.
	 *
	 * @param string $nonce  The nonce to verify.
	 * @param string $action The expected action.
	 * @return bool True if valid.
	 */
	public static function verify( string $nonce, string $action ): bool {
		return false !== wp_verify_nonce( $nonce, self::PREFIX . $action );
	}

	/**
	 * Check an AJAX request nonce or die.
	 *
	 * @param string $action    The expected action.
	 * @param string $param_key The $_REQUEST key holding the nonce.
	 * @return void
	 */
	public static function check_ajax( string $action, string $param_key = '_wpnonce' ): void {
		check_ajax_referer( self::PREFIX . $action, $param_key );
	}
}
