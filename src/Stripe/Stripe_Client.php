<?php
/**
 * Stripe API client wrapper.
 *
 * @package LEAStudios\Payments\Stripe
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Stripe;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Payments\Encryption\Options_Encryptor;

/**
 * Wraps the Stripe PHP SDK, configuring the API key from plugin settings.
 */
class Stripe_Client {

	/**
	 * Stripe API version this plugin is pinned to. Pinning is required so
	 * Stripe doesn't silently roll schema changes onto the account-default
	 * version under us. When bumping this constant, audit the webhook
	 * handlers in src/Checkout/ for any field-shape changes documented in
	 * the Stripe API changelog between the previous and new version.
	 */
	private const STRIPE_API_VERSION = '2026-03-25.dahlia';

	/**
	 * Whether the API key has been set on the SDK.
	 *
	 * @var bool
	 */
	private bool $initialized = false;

	/**
	 * Constructor.
	 *
	 * @param Options_Encryptor $encryptor The options encryptor for decrypting the secret key.
	 */
	public function __construct(
		private readonly Options_Encryptor $encryptor,
	) {}

	/**
	 * Initialize the Stripe SDK with the API key.
	 *
	 * @return bool True if successfully initialized.
	 */
	public function initialize(): bool {
		if ( $this->initialized ) {
			return true;
		}

		$secret_key = $this->get_secret_key();

		if ( '' === $secret_key ) {
			return false;
		}

		\Stripe\Stripe::setApiKey( $secret_key );
		\Stripe\Stripe::setApiVersion( self::STRIPE_API_VERSION );
		\Stripe\Stripe::setAppInfo( 'leaStudios Payments', LEASTUDIOS_PAYMENTS_VERSION, 'https://leastudios.com' );

		$this->initialized = true;

		return true;
	}

	/**
	 * Check if Stripe is configured with valid credentials.
	 *
	 * @return bool True if secret key is available.
	 */
	public function is_configured(): bool {
		return '' !== $this->get_secret_key();
	}

	/**
	 * Get the publishable key for frontend use.
	 *
	 * @return string The publishable key, or empty string if not configured.
	 */
	public function get_publishable_key(): string {
		$options = get_option( 'leastudios_payments_options', [] );

		return is_array( $options ) ? ( $options['publishable_key'] ?? '' ) : '';
	}

	/**
	 * Get whether test mode is enabled.
	 *
	 * @return bool True if in test mode.
	 */
	public function is_test_mode(): bool {
		$options = get_option( 'leastudios_payments_options', [] );

		return is_array( $options ) && ! empty( $options['test_mode'] );
	}

	/**
	 * Get the default currency.
	 *
	 * @return string Three-letter currency code (lowercase).
	 */
	public function get_default_currency(): string {
		$options  = get_option( 'leastudios_payments_options', [] );
		$currency = is_array( $options ) ? ( $options['default_currency'] ?? 'USD' ) : 'USD';

		return strtolower( $currency );
	}

	/**
	 * Get the webhook signing secret.
	 *
	 * @return string The decrypted webhook secret.
	 */
	public function get_webhook_secret(): string {
		$options   = get_option( 'leastudios_payments_options', [] );
		$encrypted = is_array( $options ) ? ( $options['webhook_secret'] ?? '' ) : '';

		if ( '' === $encrypted ) {
			return '';
		}

		return $this->encryptor->decrypt( $encrypted );
	}

	/**
	 * Get the decrypted secret key.
	 *
	 * @return string The decrypted secret key, or empty string.
	 */
	private function get_secret_key(): string {
		$options   = get_option( 'leastudios_payments_options', [] );
		$encrypted = is_array( $options ) ? ( $options['secret_key'] ?? '' ) : '';

		if ( '' === $encrypted ) {
			return '';
		}

		return $this->encryptor->decrypt( $encrypted );
	}
}
