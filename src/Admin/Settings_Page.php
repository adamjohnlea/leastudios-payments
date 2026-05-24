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
	 * Stripe-supported font_family values for branding_settings.
	 */
	private const ALLOWED_FONTS = [
		'default',
		'be_vietnam_pro',
		'bitter',
		'chakra_petch',
		'hahmlet',
		'inconsolata',
		'inter',
		'lato',
		'lora',
		'm_plus_1_code',
		'montserrat',
		'noto_sans',
		'noto_sans_jp',
		'noto_serif',
		'nunito',
		'open_sans',
		'pridi',
		'pt_sans',
		'pt_serif',
		'raleway',
		'roboto',
		'roboto_slab',
		'source_sans_pro',
		'titillium_web',
		'ubuntu_mono',
		'zen_maru_gothic',
	];

	/**
	 * Stripe-supported border_style values for branding_settings.
	 */
	private const ALLOWED_BORDER_STYLES = [ 'rectangular', 'rounded', 'pill' ];

	/**
	 * Admin page hook suffixes for both the top-level page and the Settings
	 * submenu — both render the settings UI, so both need our assets.
	 *
	 * @var array<int, string>
	 */
	private array $hook_suffixes = [];

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
		$this->hook_suffixes[] = (string) add_menu_page(
			__( 'leaStudios Payments', 'leastudios-payments' ),
			__( 'Payments', 'leastudios-payments' ),
			self::CAPABILITY,
			'leastudios-payments',
			[ $this, 'render_page' ],
			'dashicons-money-alt',
			58
		);

		$this->hook_suffixes[] = (string) add_submenu_page(
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

		// Checkout appearance section.
		add_settings_section(
			'leastudios_payments_appearance',
			__( 'Checkout appearance', 'leastudios-payments' ),
			[ $this, 'render_appearance_description' ],
			self::PAGE_SLUG
		);

		add_settings_field(
			'appearance_background_color',
			__( 'Background color', 'leastudios-payments' ),
			[ $this, 'render_background_color_field' ],
			self::PAGE_SLUG,
			'leastudios_payments_appearance'
		);

		add_settings_field(
			'appearance_button_color',
			__( 'Button color', 'leastudios-payments' ),
			[ $this, 'render_button_color_field' ],
			self::PAGE_SLUG,
			'leastudios_payments_appearance'
		);

		add_settings_field(
			'appearance_font_family',
			__( 'Font', 'leastudios-payments' ),
			[ $this, 'render_font_family_field' ],
			self::PAGE_SLUG,
			'leastudios_payments_appearance'
		);

		add_settings_field(
			'appearance_border_style',
			__( 'Border style', 'leastudios-payments' ),
			[ $this, 'render_border_style_field' ],
			self::PAGE_SLUG,
			'leastudios_payments_appearance'
		);

		add_settings_field(
			'appearance_display_name',
			__( 'Display name', 'leastudios-payments' ),
			[ $this, 'render_display_name_field' ],
			self::PAGE_SLUG,
			'leastudios_payments_appearance'
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

		// Branding settings. Empty values fall through to Stripe Dashboard branding.
		$branding_input                 = is_array( $input['branding_settings'] ?? null ) ? $input['branding_settings'] : [];
		$sanitized['branding_settings'] = [
			'background_color' => $this->sanitize_hex_color( $branding_input['background_color'] ?? '' ),
			'button_color'     => $this->sanitize_hex_color( $branding_input['button_color'] ?? '' ),
			'font_family'      => in_array( $branding_input['font_family'] ?? '', self::ALLOWED_FONTS, true )
				? (string) $branding_input['font_family']
				: '',
			'border_style'     => in_array( $branding_input['border_style'] ?? '', self::ALLOWED_BORDER_STYLES, true )
				? (string) $branding_input['border_style']
				: '',
			'display_name'     => isset( $branding_input['display_name'] )
				? sanitize_text_field( $branding_input['display_name'] )
				: '',
		];

		return $sanitized;
	}

	/**
	 * Sanitize a hex color string. Accepts #RGB or #RRGGBB; returns '' if invalid.
	 *
	 * @param mixed $value Raw input.
	 * @return string Normalized hex color or empty string.
	 */
	private function sanitize_hex_color( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		return preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $value ) ? $value : '';
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
	 * Render the appearance section description.
	 *
	 * @return void
	 */
	public function render_appearance_description(): void {
		?>
		<p class="description">
			<?php
			printf(
				/* translators: %s: Stripe Dashboard branding URL */
				esc_html__( 'Customize the embedded Stripe Checkout. Leave a field blank to fall back to your %s.', 'leastudios-payments' ),
				'<a href="https://dashboard.stripe.com/settings/branding/checkout" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Stripe Dashboard branding', 'leastudios-payments' ) . '</a>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Render the background color field.
	 *
	 * @return void
	 */
	public function render_background_color_field(): void {
		$value = $this->get_branding_value( 'background_color' );
		?>
		<input
			type="text"
			id="appearance_background_color"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[branding_settings][background_color]"
			value="<?php echo esc_attr( $value ); ?>"
			class="leastudios-payments-color-picker"
			data-default-color=""
			placeholder="#ffffff"
		/>
		<p class="description">
			<?php esc_html_e( 'Hex color for the area around the payment form (e.g. #ffffff).', 'leastudios-payments' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the button color field.
	 *
	 * @return void
	 */
	public function render_button_color_field(): void {
		$value = $this->get_branding_value( 'button_color' );
		?>
		<input
			type="text"
			id="appearance_button_color"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[branding_settings][button_color]"
			value="<?php echo esc_attr( $value ); ?>"
			class="leastudios-payments-color-picker"
			data-default-color=""
			placeholder="#0570de"
		/>
		<p class="description">
			<?php esc_html_e( 'Hex color for the primary Pay/Subscribe button.', 'leastudios-payments' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the font family field.
	 *
	 * @return void
	 */
	public function render_font_family_field(): void {
		$value = $this->get_branding_value( 'font_family' );
		?>
		<select
			id="appearance_font_family"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[branding_settings][font_family]"
		>
			<option value=""><?php esc_html_e( '-- Use Dashboard default --', 'leastudios-payments' ); ?></option>
			<?php foreach ( self::ALLOWED_FONTS as $font ) : ?>
				<option value="<?php echo esc_attr( $font ); ?>" <?php selected( $value, $font ); ?>>
					<?php echo esc_html( $font ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Render the border style field.
	 *
	 * @return void
	 */
	public function render_border_style_field(): void {
		$value = $this->get_branding_value( 'border_style' );
		?>
		<select
			id="appearance_border_style"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[branding_settings][border_style]"
		>
			<option value=""><?php esc_html_e( '-- Use Dashboard default --', 'leastudios-payments' ); ?></option>
			<?php foreach ( self::ALLOWED_BORDER_STYLES as $style ) : ?>
				<option value="<?php echo esc_attr( $style ); ?>" <?php selected( $value, $style ); ?>>
					<?php echo esc_html( $style ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Render the display name field.
	 *
	 * @return void
	 */
	public function render_display_name_field(): void {
		$value = $this->get_branding_value( 'display_name' );
		?>
		<input
			type="text"
			id="appearance_display_name"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[branding_settings][display_name]"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
		/>
		<p class="description">
			<?php esc_html_e( 'Optional override for the business name shown at the top of the checkout.', 'leastudios-payments' ); ?>
		</p>
		<?php
	}

	/**
	 * Read a single branding sub-key from saved options.
	 *
	 * @param string $key Branding key.
	 * @return string Saved value or empty string.
	 */
	private function get_branding_value( string $key ): string {
		$options  = get_option( self::OPTION_NAME, self::get_defaults() );
		$branding = is_array( $options['branding_settings'] ?? null ) ? $options['branding_settings'] : [];

		return isset( $branding[ $key ] ) && is_string( $branding[ $key ] ) ? $branding[ $key ] : '';
	}

	/**
	 * Enqueue admin assets on our settings page only.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, $this->hook_suffixes, true ) ) {
			return;
		}

		wp_enqueue_style(
			'leastudios-payments-admin',
			LEASTUDIOS_PAYMENTS_URL . 'assets/css/admin.css',
			[],
			LEASTUDIOS_PAYMENTS_VERSION
		);

		// WordPress core color picker for the appearance hex fields.
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_add_inline_script(
			'wp-color-picker',
			"jQuery(function($){ $('.leastudios-payments-color-picker').wpColorPicker(); });"
		);
	}

	/**
	 * Get default option values.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_defaults(): array {
		return [
			'test_mode'         => true,
			'publishable_key'   => '',
			'secret_key'        => '',
			'webhook_secret'    => '',
			'default_currency'  => 'USD',
			'success_page'      => 0,
			'cancel_page'       => 0,
			'branding_settings' => [
				'background_color' => '',
				'button_color'     => '',
				'font_family'      => '',
				'border_style'     => '',
				'display_name'     => '',
			],
		];
	}
}
