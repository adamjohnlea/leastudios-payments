<?php
/**
 * Plugin settings page.
 *
 * @package LEAStudios\Payments\Admin
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Admin;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Payments\Encryption\Options_Encryptor;
use LEAStudios\Payments\Webhook\Webhook_Events;

/**
 * Registers and renders the leaStudios Payments settings page.
 */
class Settings_Page {

	/**
	 * The option group name.
	 */
	private const OPTION_GROUP = 'leastudios_payments_settings';

	/**
	 * The option name in the database.
	 */
	public const OPTION_NAME = 'leastudios_payments_options';

	/**
	 * The settings page slug.
	 */
	private const PAGE_SLUG = 'leastudios-payments-settings';

	/**
	 * The required capability.
	 */
	private const CAPABILITY = 'manage_options';

	/**
	 * The admin page hook suffix.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param Options_Encryptor $encryptor The options encryptor for sensitive values.
	 */
	public function __construct(
		private readonly Options_Encryptor $encryptor,
	) {}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'add_menu_pages' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Add the top-level menu and settings submenu.
	 *
	 * @return void
	 */
	public function add_menu_pages(): void {
		add_menu_page(
			__( 'leaStudios Payments', 'leastudios-payments' ),
			__( 'Payments', 'leastudios-payments' ),
			self::CAPABILITY,
			'leastudios-payments',
			[ $this, 'render_page' ],
			'dashicons-money-alt',
			58
		);

		$this->hook_suffix = (string) add_submenu_page(
			'leastudios-payments',
			__( 'Settings', 'leastudios-payments' ),
			__( 'Settings', 'leastudios-payments' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Register settings using the Settings API.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_options' ],
				'default'           => self::get_defaults(),
			]
		);

		// Stripe Credentials section.
		add_settings_section(
			'leastudios_payments_credentials',
			__( 'Stripe Credentials', 'leastudios-payments' ),
			[ $this, 'render_credentials_description' ],
			self::PAGE_SLUG
		);

		add_settings_field(
			'test_mode',
			__( 'Test Mode', 'leastudios-payments' ),
			[ $this, 'render_test_mode_field' ],
			self::PAGE_SLUG,
			'leastudios_payments_credentials'
		);

		add_settings_field(
			'publishable_key',
			__( 'Publishable Key', 'leastudios-payments' ),
			[ $this, 'render_publishable_key_field' ],
			self::PAGE_SLUG,
			'leastudios_payments_credentials'
		);

		add_settings_field(
			'secret_key',
			__( 'Secret Key', 'leastudios-payments' ),
			[ $this, 'render_secret_key_field' ],
			self::PAGE_SLUG,
			'leastudios_payments_credentials'
		);

		add_settings_field(
			'webhook_secret',
			__( 'Webhook Signing Secret', 'leastudios-payments' ),
			[ $this, 'render_webhook_secret_field' ],
			self::PAGE_SLUG,
			'leastudios_payments_credentials'
		);

		// General section.
		add_settings_section(
			'leastudios_payments_general',
			__( 'General', 'leastudios-payments' ),
			'__return_empty_string',
			self::PAGE_SLUG
		);

		add_settings_field(
			'default_currency',
			__( 'Default Currency', 'leastudios-payments' ),
			[ $this, 'render_currency_field' ],
			self::PAGE_SLUG,
			'leastudios_payments_general'
		);

		add_settings_field(
			'success_page',
			__( 'Success Page', 'leastudios-payments' ),
			[ $this, 'render_success_page_field' ],
			self::PAGE_SLUG,
			'leastudios_payments_general'
		);

		add_settings_field(
			'cancel_page',
			__( 'Cancel Page', 'leastudios-payments' ),
			[ $this, 'render_cancel_page_field' ],
			self::PAGE_SLUG,
			'leastudios_payments_general'
		);
	}

	/**
	 * Sanitize options before saving.
	 *
	 * @param array<string, mixed> $input Raw input values.
	 * @return array<string, mixed> Sanitized values.
	 */
	public function sanitize_options( array $input ): array {
		$defaults  = self::get_defaults();
		$existing  = get_option( self::OPTION_NAME, [] );
		$sanitized = [];

		$sanitized['test_mode'] = ! empty( $input['test_mode'] );

		$sanitized['publishable_key'] = isset( $input['publishable_key'] )
			? sanitize_text_field( $input['publishable_key'] )
			: $defaults['publishable_key'];

		// Secret key: only encrypt if a new non-empty value is provided.
		if ( ! empty( $input['secret_key'] ) ) {
			$sanitized['secret_key'] = $this->encryptor->encrypt( sanitize_text_field( $input['secret_key'] ) );
		} else {
			$sanitized['secret_key'] = is_array( $existing ) ? ( $existing['secret_key'] ?? '' ) : '';
		}

		// Webhook secret: only encrypt if a new non-empty value is provided.
		if ( ! empty( $input['webhook_secret'] ) ) {
			$sanitized['webhook_secret'] = $this->encryptor->encrypt( sanitize_text_field( $input['webhook_secret'] ) );
		} else {
			$sanitized['webhook_secret'] = is_array( $existing ) ? ( $existing['webhook_secret'] ?? '' ) : '';
		}

		$valid_currencies              = [ 'USD', 'GBP', 'EUR', 'CAD', 'AUD', 'NZD', 'CHF', 'JPY' ];
		$sanitized['default_currency'] = isset( $input['default_currency'] ) && in_array( strtoupper( $input['default_currency'] ), $valid_currencies, true )
			? strtoupper( $input['default_currency'] )
			: $defaults['default_currency'];

		$sanitized['success_page'] = isset( $input['success_page'] ) ? absint( $input['success_page'] ) : 0;
		$sanitized['cancel_page']  = isset( $input['cancel_page'] ) ? absint( $input['cancel_page'] ) : 0;

		return $sanitized;
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the credentials section description.
	 *
	 * @return void
	 */
	public function render_credentials_description(): void {
		?>
		<p class="description">
			<?php
			printf(
				/* translators: %s: Stripe dashboard URL */
				esc_html__( 'Enter your Stripe API keys from the %s.', 'leastudios-payments' ),
				'<a href="https://dashboard.stripe.com/apikeys" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Stripe Dashboard', 'leastudios-payments' ) . '</a>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Render the test mode field.
	 *
	 * @return void
	 */
	public function render_test_mode_field(): void {
		$options = get_option( self::OPTION_NAME, self::get_defaults() );
		$value   = $options['test_mode'] ?? self::get_defaults()['test_mode'];
		?>
		<label>
			<input
				type="checkbox"
				id="test_mode"
				name="<?php echo esc_attr( self::OPTION_NAME ); ?>[test_mode]"
				value="1"
				<?php checked( $value ); ?>
			/>
			<?php esc_html_e( 'Enable test mode (use test API keys).', 'leastudios-payments' ); ?>
		</label>
		<?php
	}

	/**
	 * Render the publishable key field.
	 *
	 * @return void
	 */
	public function render_publishable_key_field(): void {
		$options = get_option( self::OPTION_NAME, self::get_defaults() );
		$value   = $options['publishable_key'] ?? '';
		?>
		<input
			type="text"
			id="publishable_key"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[publishable_key]"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
			placeholder="pk_test_..."
		/>
		<?php
	}

	/**
	 * Render the secret key field.
	 *
	 * @return void
	 */
	public function render_secret_key_field(): void {
		$options = get_option( self::OPTION_NAME, self::get_defaults() );
		$has_key = ! empty( $options['secret_key'] );
		?>
		<input
			type="password"
			id="secret_key"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[secret_key]"
			value=""
			class="regular-text"
			placeholder="<?php echo $has_key ? esc_attr__( '(saved)', 'leastudios-payments' ) : 'sk_test_...'; ?>"
			autocomplete="new-password"
		/>
		<p class="description">
			<?php
			if ( $has_key ) {
				esc_html_e( 'A secret key is saved. Enter a new value to replace it, or leave blank to keep the existing key.', 'leastudios-payments' );
			} else {
				esc_html_e( 'Your Stripe secret key. This value will be encrypted before storage.', 'leastudios-payments' );
			}
			?>
		</p>
		<?php
	}

	/**
	 * Render the webhook secret field.
	 *
	 * @return void
	 */
	public function render_webhook_secret_field(): void {
		$options    = get_option( self::OPTION_NAME, self::get_defaults() );
		$has_secret = ! empty( $options['webhook_secret'] );
		?>
		<input
			type="password"
			id="webhook_secret"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[webhook_secret]"
			value=""
			class="regular-text"
			placeholder="<?php echo $has_secret ? esc_attr__( '(saved)', 'leastudios-payments' ) : 'whsec_...'; ?>"
			autocomplete="new-password"
		/>
		<p class="description">
			<?php
			if ( $has_secret ) {
				esc_html_e( 'A webhook secret is saved. Enter a new value to replace it, or leave blank to keep the existing secret.', 'leastudios-payments' );
			} else {
				esc_html_e( 'Your Stripe webhook signing secret. This value will be encrypted before storage.', 'leastudios-payments' );
			}
			?>
		</p>
		<p class="description">
			<?php
			printf(
				/* translators: %s: webhook URL */
				esc_html__( 'Webhook URL: %s', 'leastudios-payments' ),
				'<code>' . esc_html( rest_url( 'leastudios-payments/v1/webhook' ) ) . '</code>'
			);
			?>
		</p>
		<p class="description">
			<?php esc_html_e( 'Enable these events on the Stripe webhook endpoint:', 'leastudios-payments' ); ?>
		</p>
		<table class="widefat striped" style="max-width:640px;margin-top:4px;">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Stripe event', 'leastudios-payments' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Purpose', 'leastudios-payments' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( Webhook_Events::HANDLED as $event_type => $description ) : ?>
				<tr>
					<td><code><?php echo esc_html( $event_type ); ?></code></td>
					<td><?php echo esc_html( $description ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the default currency field.
	 *
	 * @return void
	 */
	public function render_currency_field(): void {
		$options = get_option( self::OPTION_NAME, self::get_defaults() );
		$value   = $options['default_currency'] ?? self::get_defaults()['default_currency'];

		$currencies = [
			'USD' => __( 'USD - US Dollar', 'leastudios-payments' ),
			'GBP' => __( 'GBP - British Pound', 'leastudios-payments' ),
			'EUR' => __( 'EUR - Euro', 'leastudios-payments' ),
			'CAD' => __( 'CAD - Canadian Dollar', 'leastudios-payments' ),
			'AUD' => __( 'AUD - Australian Dollar', 'leastudios-payments' ),
			'NZD' => __( 'NZD - New Zealand Dollar', 'leastudios-payments' ),
			'CHF' => __( 'CHF - Swiss Franc', 'leastudios-payments' ),
			'JPY' => __( 'JPY - Japanese Yen', 'leastudios-payments' ),
		];
		?>
		<select
			id="default_currency"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[default_currency]"
		>
			<?php foreach ( $currencies as $code => $label ) : ?>
				<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $value, $code ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Render the success page dropdown.
	 *
	 * @return void
	 */
	public function render_success_page_field(): void {
		$options = get_option( self::OPTION_NAME, self::get_defaults() );
		$value   = (int) ( $options['success_page'] ?? 0 );

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_dropdown_pages outputs pre-escaped HTML.
		wp_dropdown_pages(
			[
				'name'              => self::OPTION_NAME . '[success_page]',
				'id'                => 'success_page',
				'selected'          => $value,
				'show_option_none'  => __( '-- Default (return to page) --', 'leastudios-payments' ),
				'option_none_value' => '0',
			]
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
		<p class="description">
			<?php esc_html_e( 'Page to redirect to after a successful payment.', 'leastudios-payments' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the cancel page dropdown.
	 *
	 * @return void
	 */
	public function render_cancel_page_field(): void {
		$options = get_option( self::OPTION_NAME, self::get_defaults() );
		$value   = (int) ( $options['cancel_page'] ?? 0 );

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_dropdown_pages outputs pre-escaped HTML.
		wp_dropdown_pages(
			[
				'name'              => self::OPTION_NAME . '[cancel_page]',
				'id'                => 'cancel_page',
				'selected'          => $value,
				'show_option_none'  => __( '-- Default (return to page) --', 'leastudios-payments' ),
				'option_none_value' => '0',
			]
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
		<p class="description">
			<?php esc_html_e( 'Page to redirect to if the customer cancels checkout.', 'leastudios-payments' ); ?>
		</p>
		<?php
	}

	/**
	 * Enqueue admin assets on our settings page only.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'leastudios-payments-admin',
			LEASTUDIOS_PAYMENTS_URL . 'assets/css/admin.css',
			[],
			LEASTUDIOS_PAYMENTS_VERSION
		);
	}

	/**
	 * Get default option values.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_defaults(): array {
		return [
			'test_mode'        => true,
			'publishable_key'  => '',
			'secret_key'       => '',
			'webhook_secret'   => '',
			'default_currency' => 'USD',
			'success_page'     => 0,
			'cancel_page'      => 0,
		];
	}
}
