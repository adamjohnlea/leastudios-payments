<?php
/**
 * Products admin page.
 *
 * @package LEAStudios\Payments\Admin
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Admin;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Payments\Database\Price_Repository;
use LEAStudios\Payments\Database\Product_Repository;
use LEAStudios\Payments\Security\Nonce;
use LEAStudios\Payments\Stripe\Product_Sync;

/**
 * Manages the Products admin page — list, create, and edit views.
 */
class Products_Page {

	/**
	 * The page slug.
	 */
	private const PAGE_SLUG = 'leastudios-payments-products';

	/**
	 * The required capability.
	 */
	private const CAPABILITY = 'manage_options';

	/**
	 * Constructor.
	 *
	 * @param Product_Repository $product_repository The product repository.
	 * @param Price_Repository   $price_repository   The price repository.
	 * @param Product_Sync       $product_sync       The product sync service.
	 */
	public function __construct(
		private readonly Product_Repository $product_repository,
		private readonly Price_Repository $price_repository,
		private readonly Product_Sync $product_sync,
	) {}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'add_submenu_page' ] );
		add_action( 'admin_init', [ $this, 'handle_actions' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Add the Products submenu page.
	 *
	 * @return void
	 */
	public function add_submenu_page(): void {
		add_submenu_page(
			'leastudios-payments',
			__( 'Products', 'leastudios-payments' ),
			__( 'Products', 'leastudios-payments' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Route to the correct view based on the action parameter.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked in handle_actions for write operations.
		$action = sanitize_text_field( wp_unslash( $_GET['action'] ?? '' ) );

		match ( $action ) {
			'new'  => $this->render_create_form(),
			'edit' => $this->render_edit_form(),
			default => $this->render_list(),
		};
	}

	/**
	 * Handle form submissions and actions.
	 *
	 * @return void
	 */
	public function handle_actions(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_post_action().
		if ( ! isset( $_POST['leastudios_payments_product_action'] ) && ! isset( $_GET['product_action'] ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// Handle POST actions (create, update, add_price).
		if ( isset( $_POST['leastudios_payments_product_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handle_post_action().
			$this->handle_post_action();
			return;
		}

		// Handle GET actions (toggle_status, archive_price).
		$this->handle_get_action();
	}

	/**
	 * Handle POST form submissions.
	 *
	 * @return void
	 */
	private function handle_post_action(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified on next line.
		$action = sanitize_text_field( wp_unslash( $_POST['leastudios_payments_product_action'] ?? '' ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This IS the nonce verification.
		if ( ! isset( $_POST['_wpnonce'] ) || ! Nonce::verify( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'product_' . $action ) ) {
			wp_die( esc_html__( 'Security check failed.', 'leastudios-payments' ) );
		}

		match ( $action ) {
			'create'    => $this->handle_create(),
			'update'    => $this->handle_update(),
			'add_price' => $this->handle_add_price(),
			default     => null,
		};
	}

	/**
	 * Handle GET link actions (with nonce in URL).
	 *
	 * @return void
	 */
	private function handle_get_action(): void {
		$action = sanitize_text_field( wp_unslash( $_GET['product_action'] ?? '' ) );

		if ( ! isset( $_GET['_wpnonce'] ) || ! Nonce::verify( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), $action ) ) {
			wp_die( esc_html__( 'Security check failed.', 'leastudios-payments' ) );
		}

		$product_id = absint( $_GET['product_id'] ?? 0 );

		match ( $action ) {
			'activate'      => $this->product_sync->set_product_status( $product_id, 'active' ),
			'deactivate'    => $this->product_sync->set_product_status( $product_id, 'inactive' ),
			'archive_price' => $this->product_sync->archive_price( absint( $_GET['price_id'] ?? 0 ) ),
			default         => null,
		};

		$redirect_url = remove_query_arg( [ 'product_action', '_wpnonce', 'product_id', 'price_id' ] );
		wp_safe_redirect( add_query_arg( 'updated', '1', $redirect_url ) );
		exit;
	}

	/**
	 * Handle product creation.
	 *
	 * @return void
	 */
	private function handle_create(): void {
		// Nonce verified in handle_post_action().
		$name        = sanitize_text_field( wp_unslash( $_POST['product_name'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$description = sanitize_textarea_field( wp_unslash( $_POST['product_description'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$image_url   = esc_url_raw( wp_unslash( $_POST['product_image_url'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( '' === $name ) {
			$this->redirect_with_error( __( 'Product name is required.', 'leastudios-payments' ) );
			return;
		}

		// Parse prices from form.
		$prices = $this->parse_prices_from_post();

		if ( empty( $prices ) ) {
			$this->redirect_with_error( __( 'At least one price is required.', 'leastudios-payments' ) );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_post_action().
		$require_shipping = ! empty( $_POST['require_shipping'] );

		$result = $this->product_sync->create_product( $name, $description, $image_url, $prices, $require_shipping );

		if ( ! $result['success'] ) {
			$this->redirect_with_error( $result['error'] );
			return;
		}

		wp_safe_redirect(
			add_query_arg(
				[
					'page'    => self::PAGE_SLUG,
					'created' => '1',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle product update.
	 *
	 * @return void
	 */
	private function handle_update(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_post_action().
		$product_id  = absint( $_POST['product_id'] ?? 0 );
		$name        = sanitize_text_field( wp_unslash( $_POST['product_name'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$description = sanitize_textarea_field( wp_unslash( $_POST['product_description'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$image_url   = esc_url_raw( wp_unslash( $_POST['product_image_url'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( 0 === $product_id || '' === $name ) {
			$this->redirect_with_error( __( 'Product name is required.', 'leastudios-payments' ) );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_post_action().
		$require_shipping = ! empty( $_POST['require_shipping'] );

		$result = $this->product_sync->update_product( $product_id, $name, $description, $image_url );

		if ( ! $result['success'] ) {
			$this->redirect_with_error( $result['error'] );
			return;
		}

		// Update shipping flag locally (not synced to Stripe — it's a local checkout setting).
		$this->product_repository->update( $product_id, [ 'require_shipping' => $require_shipping ? 1 : 0 ] );

		wp_safe_redirect(
			add_query_arg(
				[
					'page'    => self::PAGE_SLUG,
					'action'  => 'edit',
					'id'      => $product_id,
					'updated' => '1',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle adding a price to an existing product.
	 *
	 * @return void
	 */
	private function handle_add_price(): void {
		$product_id = absint( $_POST['product_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_post_action().
		$prices     = $this->parse_prices_from_post();

		if ( 0 === $product_id || empty( $prices ) ) {
			$this->redirect_with_error( __( 'Invalid price data.', 'leastudios-payments' ) );
			return;
		}

		$result = $this->product_sync->add_price( $product_id, $prices[0] );

		if ( ! $result['success'] ) {
			$this->redirect_with_error( $result['error'] );
			return;
		}

		wp_safe_redirect(
			add_query_arg(
				[
					'page'    => self::PAGE_SLUG,
					'action'  => 'edit',
					'id'      => $product_id,
					'updated' => '1',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the product list view.
	 *
	 * @return void
	 */
	private function render_list(): void {
		$products = $this->product_repository->get_all();
		$new_url  = add_query_arg(
			[
				'page'   => self::PAGE_SLUG,
				'action' => 'new',
			],
			admin_url( 'admin.php' )
		);

		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Products', 'leastudios-payments' ); ?></h1>
			<a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action">
				<?php esc_html_e( 'Add New', 'leastudios-payments' ); ?>
			</a>
			<hr class="wp-header-end" />

			<?php $this->render_notices(); ?>

			<?php if ( empty( $products ) ) : ?>
				<div class="leastudios-payments-empty-state" style="text-align:center;padding:40px 20px;">
					<p style="font-size:1.1em;color:#50575e;">
						<?php esc_html_e( 'No products yet. Create your first product to start accepting payments.', 'leastudios-payments' ); ?>
					</p>
					<a href="<?php echo esc_url( $new_url ); ?>" class="button button-primary button-hero">
						<?php esc_html_e( 'Create Product', 'leastudios-payments' ); ?>
					</a>
				</div>
			<?php else : ?>
				<table class="widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Name', 'leastudios-payments' ); ?></th>
							<th><?php esc_html_e( 'Price(s)', 'leastudios-payments' ); ?></th>
							<th><?php esc_html_e( 'Type', 'leastudios-payments' ); ?></th>
							<th><?php esc_html_e( 'Shortcode', 'leastudios-payments' ); ?></th>
							<th><?php esc_html_e( 'Status', 'leastudios-payments' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'leastudios-payments' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $products as $product ) : ?>
							<?php
							$prices   = $this->price_repository->get_by_product( (int) $product->id, '' );
							$edit_url = add_query_arg(
								[
									'page'   => self::PAGE_SLUG,
									'action' => 'edit',
									'id'     => $product->id,
								],
								admin_url( 'admin.php' )
							);

							$toggle_action = 'active' === $product->status ? 'deactivate' : 'activate';
							$toggle_url    = wp_nonce_url(
								add_query_arg(
									[
										'page'           => self::PAGE_SLUG,
										'product_action' => $toggle_action,
										'product_id'     => $product->id,
									],
									admin_url( 'admin.php' )
								),
								'leastudios_payments_' . $toggle_action
							);
							?>
							<tr>
								<td>
									<strong>
										<a href="<?php echo esc_url( $edit_url ); ?>">
											<?php echo esc_html( $product->name ); ?>
										</a>
									</strong>
								</td>
								<td>
									<?php
									$active_prices = array_filter( $prices, static fn( $p ) => 'active' === $p->status );
									if ( empty( $active_prices ) ) {
										esc_html_e( 'No active prices', 'leastudios-payments' );
									} else {
										$formatted = array_map(
											fn( $p ) => $this->format_price( $p ),
											$active_prices
										);
										echo esc_html( implode( ', ', $formatted ) );
									}
									?>
								</td>
								<td>
									<?php
									$has_recurring = false;
									foreach ( $active_prices as $p ) {
										if ( 'recurring' === $p->type ) {
											$has_recurring = true;
											break;
										}
									}
									echo $has_recurring
										? esc_html__( 'Subscription', 'leastudios-payments' )
										: esc_html__( 'One-time', 'leastudios-payments' );
									?>
								</td>
								<td>
									<?php if ( ! empty( $active_prices ) ) : ?>
										<?php foreach ( $active_prices as $ap ) : ?>
											<code style="display:block;margin-bottom:4px;cursor:pointer;user-select:all;font-size:12px;" title="<?php esc_attr_e( 'Click to select', 'leastudios-payments' ); ?>">[leastudios_payment price_id="<?php echo esc_attr( (string) $ap->id ); ?>"]</code>
										<?php endforeach; ?>
									<?php else : ?>
										&mdash;
									<?php endif; ?>
								</td>
								<td>
									<?php
									$badge_color = 'active' === $product->status ? '#00a32a' : '#787c82';
									printf(
										'<span style="background:%s;color:#fff;padding:2px 8px;border-radius:3px;font-size:12px;">%s</span>',
										esc_attr( $badge_color ),
										esc_html( ucfirst( $product->status ) )
									);
									?>
								</td>
								<td>
									<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'leastudios-payments' ); ?></a>
									|
									<a href="<?php echo esc_url( $toggle_url ); ?>">
										<?php
										echo 'active' === $product->status
											? esc_html__( 'Deactivate', 'leastudios-payments' )
											: esc_html__( 'Activate', 'leastudios-payments' );
										?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the create product form.
	 *
	 * @return void
	 */
	private function render_create_form(): void {
		$back_url = add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Add New Product', 'leastudios-payments' ); ?></h1>

			<?php $this->render_notices(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<input type="hidden" name="leastudios_payments_product_action" value="create" />
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( Nonce::create( 'product_create' ) ); ?>" />

				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="product_name"><?php esc_html_e( 'Product Name', 'leastudios-payments' ); ?></label>
						</th>
						<td>
							<input type="text" id="product_name" name="product_name" class="regular-text" required />
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="product_description"><?php esc_html_e( 'Description', 'leastudios-payments' ); ?></label>
						</th>
						<td>
							<textarea id="product_description" name="product_description" class="large-text" rows="3"></textarea>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="product_image_url"><?php esc_html_e( 'Image', 'leastudios-payments' ); ?></label>
						</th>
						<td>
							<?php $this->render_image_picker( '' ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Shipping', 'leastudios-payments' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="require_shipping" value="1" />
								<?php esc_html_e( 'Collect shipping address at checkout', 'leastudios-payments' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Pricing', 'leastudios-payments' ); ?></h2>
				<?php $this->render_price_fields( 0 ); ?>

				<?php submit_button( __( 'Create Product', 'leastudios-payments' ) ); ?>

				<p><a href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'Back to Products', 'leastudios-payments' ); ?></a></p>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the edit product form.
	 *
	 * @return void
	 */
	private function render_edit_form(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$product_id = absint( $_GET['id'] ?? 0 );
		$product    = $this->product_repository->get( $product_id );

		if ( ! $product ) {
			wp_die( esc_html__( 'Product not found.', 'leastudios-payments' ) );
		}

		$prices   = $this->price_repository->get_by_product( $product_id, '' );
		$back_url = add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Edit Product', 'leastudios-payments' ); ?></h1>

			<?php $this->render_notices(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<input type="hidden" name="leastudios_payments_product_action" value="update" />
				<input type="hidden" name="product_id" value="<?php echo esc_attr( (string) $product_id ); ?>" />
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( Nonce::create( 'product_update' ) ); ?>" />

				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="product_name"><?php esc_html_e( 'Product Name', 'leastudios-payments' ); ?></label>
						</th>
						<td>
							<input type="text" id="product_name" name="product_name" class="regular-text" value="<?php echo esc_attr( $product->name ); ?>" required />
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="product_description"><?php esc_html_e( 'Description', 'leastudios-payments' ); ?></label>
						</th>
						<td>
							<textarea id="product_description" name="product_description" class="large-text" rows="3"><?php echo esc_textarea( $product->description ?? '' ); ?></textarea>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="product_image_url"><?php esc_html_e( 'Image', 'leastudios-payments' ); ?></label>
						</th>
						<td>
							<?php $this->render_image_picker( $product->image_url ?? '' ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Shipping', 'leastudios-payments' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="require_shipping" value="1" <?php checked( ! empty( $product->require_shipping ) ); ?> />
								<?php esc_html_e( 'Collect shipping address at checkout', 'leastudios-payments' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Stripe Product ID', 'leastudios-payments' ); ?></th>
						<td><code><?php echo esc_html( $product->stripe_product_id ); ?></code></td>
					</tr>
				</table>

				<?php submit_button( __( 'Update Product', 'leastudios-payments' ) ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Prices', 'leastudios-payments' ); ?></h2>

			<?php if ( ! empty( $prices ) ) : ?>
				<table class="widefat fixed striped" style="max-width:900px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Amount', 'leastudios-payments' ); ?></th>
							<th><?php esc_html_e( 'Type', 'leastudios-payments' ); ?></th>
							<th><?php esc_html_e( 'Shortcode', 'leastudios-payments' ); ?></th>
							<th><?php esc_html_e( 'Stripe ID', 'leastudios-payments' ); ?></th>
							<th><?php esc_html_e( 'Status', 'leastudios-payments' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'leastudios-payments' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $prices as $price ) : ?>
							<tr>
								<td><?php echo esc_html( $this->format_price( $price ) ); ?></td>
								<td>
									<?php
									if ( 'recurring' === $price->type ) {
										printf(
											/* translators: 1: interval count, 2: interval (month, year, etc.) */
											esc_html__( 'Every %1$s %2$s', 'leastudios-payments' ),
											esc_html( (string) $price->recurring_interval_count ),
											esc_html( $price->recurring_interval ?? '' )
										);
									} else {
										esc_html_e( 'One-time', 'leastudios-payments' );
									}
									?>
								</td>
								<td>
									<?php if ( 'active' === $price->status ) : ?>
										<code style="cursor:pointer;user-select:all;font-size:12px;" title="<?php esc_attr_e( 'Click to select', 'leastudios-payments' ); ?>">[leastudios_payment price_id="<?php echo esc_attr( (string) $price->id ); ?>"]</code>
									<?php else : ?>
										&mdash;
									<?php endif; ?>
								</td>
								<td><code><?php echo esc_html( $price->stripe_price_id ); ?></code></td>
								<td><?php echo esc_html( ucfirst( $price->status ) ); ?></td>
								<td>
									<?php if ( 'active' === $price->status ) : ?>
										<?php
										$archive_url = wp_nonce_url(
											add_query_arg(
												[
													'page'           => self::PAGE_SLUG,
													'action'         => 'edit',
													'id'             => $product_id,
													'product_action' => 'archive_price',
													'price_id'       => $price->id,
												],
												admin_url( 'admin.php' )
											),
											'leastudios_payments_archive_price'
										);
										?>
										<a href="<?php echo esc_url( $archive_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Are you sure? This will deactivate this price in Stripe.', 'leastudios-payments' ) ); ?>');">
											<?php esc_html_e( 'Archive', 'leastudios-payments' ); ?>
										</a>
									<?php else : ?>
										<span style="color:#787c82;"><?php esc_html_e( 'Archived', 'leastudios-payments' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Add Price', 'leastudios-payments' ); ?></h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<input type="hidden" name="leastudios_payments_product_action" value="add_price" />
				<input type="hidden" name="product_id" value="<?php echo esc_attr( (string) $product_id ); ?>" />
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( Nonce::create( 'product_add_price' ) ); ?>" />

				<?php $this->render_price_fields( 0 ); ?>

				<?php submit_button( __( 'Add Price', 'leastudios-payments' ), 'secondary' ); ?>
			</form>

			<p><a href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'Back to Products', 'leastudios-payments' ); ?></a></p>
		</div>
		<?php
	}

	/**
	 * Render price input fields for a single price row.
	 *
	 * @param int $index The row index (for array naming).
	 * @return void
	 */
	private function render_price_fields( int $index ): void {
		$currencies = [ 'USD', 'GBP', 'EUR', 'CAD', 'AUD', 'NZD', 'CHF', 'JPY' ];
		$options    = get_option( 'leastudios_payments_options', [] );
		$default    = is_array( $options ) ? strtoupper( $options['default_currency'] ?? 'USD' ) : 'USD';
		?>
		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="price_amount_<?php echo esc_attr( (string) $index ); ?>"><?php esc_html_e( 'Amount', 'leastudios-payments' ); ?></label>
				</th>
				<td>
					<input
						type="number"
						id="price_amount_<?php echo esc_attr( (string) $index ); ?>"
						name="prices[<?php echo esc_attr( (string) $index ); ?>][amount]"
						min="1"
						step="1"
						required
						class="small-text"
					/>
					<p class="description"><?php esc_html_e( 'Amount in smallest currency unit (e.g. 1000 = $10.00).', 'leastudios-payments' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="price_currency_<?php echo esc_attr( (string) $index ); ?>"><?php esc_html_e( 'Currency', 'leastudios-payments' ); ?></label>
				</th>
				<td>
					<select id="price_currency_<?php echo esc_attr( (string) $index ); ?>" name="prices[<?php echo esc_attr( (string) $index ); ?>][currency]">
						<?php foreach ( $currencies as $code ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $default, $code ); ?>>
								<?php echo esc_html( $code ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="price_type_<?php echo esc_attr( (string) $index ); ?>"><?php esc_html_e( 'Type', 'leastudios-payments' ); ?></label>
				</th>
				<td>
					<select id="price_type_<?php echo esc_attr( (string) $index ); ?>" name="prices[<?php echo esc_attr( (string) $index ); ?>][type]">
						<option value="one_time"><?php esc_html_e( 'One-time', 'leastudios-payments' ); ?></option>
						<option value="recurring"><?php esc_html_e( 'Recurring (subscription)', 'leastudios-payments' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="price_interval_<?php echo esc_attr( (string) $index ); ?>"><?php esc_html_e( 'Billing Interval', 'leastudios-payments' ); ?></label>
				</th>
				<td>
					<input
						type="number"
						name="prices[<?php echo esc_attr( (string) $index ); ?>][recurring_interval_count]"
						value="1"
						min="1"
						class="small-text"
						style="width:60px;"
					/>
					<select id="price_interval_<?php echo esc_attr( (string) $index ); ?>" name="prices[<?php echo esc_attr( (string) $index ); ?>][recurring_interval]">
						<option value="month"><?php esc_html_e( 'Month(s)', 'leastudios-payments' ); ?></option>
						<option value="year"><?php esc_html_e( 'Year(s)', 'leastudios-payments' ); ?></option>
						<option value="week"><?php esc_html_e( 'Week(s)', 'leastudios-payments' ); ?></option>
						<option value="day"><?php esc_html_e( 'Day(s)', 'leastudios-payments' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Only applies to recurring prices.', 'leastudios-payments' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render the image picker field with media library integration.
	 *
	 * @param string $current_url The current image URL.
	 * @return void
	 */
	private function render_image_picker( string $current_url ): void {
		?>
		<div class="leastudios-payments-image-picker">
			<input
				type="hidden"
				id="product_image_url"
				name="product_image_url"
				value="<?php echo esc_url( $current_url ); ?>"
			/>
			<div id="leastudios-payments-image-preview" style="margin-bottom:8px;">
				<?php if ( '' !== $current_url ) : ?>
					<img src="<?php echo esc_url( $current_url ); ?>" style="max-width:200px;max-height:200px;border:1px solid #dcdcde;border-radius:4px;" />
				<?php endif; ?>
			</div>
			<button type="button" class="button" id="leastudios-payments-select-image">
				<?php echo '' !== $current_url ? esc_html__( 'Change Image', 'leastudios-payments' ) : esc_html__( 'Select Image', 'leastudios-payments' ); ?>
			</button>
			<?php if ( '' !== $current_url ) : ?>
				<button type="button" class="button" id="leastudios-payments-remove-image" style="color:#d63638;">
					<?php esc_html_e( 'Remove', 'leastudios-payments' ); ?>
				</button>
			<?php else : ?>
				<button type="button" class="button" id="leastudios-payments-remove-image" style="color:#d63638;display:none;">
					<?php esc_html_e( 'Remove', 'leastudios-payments' ); ?>
				</button>
			<?php endif; ?>
			<p class="description"><?php esc_html_e( 'Choose an image from the media library. This image is shown on the Stripe checkout page.', 'leastudios-payments' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Enqueue admin assets on the products pages.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = sanitize_text_field( wp_unslash( $_GET['page'] ?? '' ) );

		if ( self::PAGE_SLUG !== $page ) {
			return;
		}

		// Enqueue the WordPress media library.
		wp_enqueue_media();

		wp_enqueue_style(
			'leastudios-payments-admin',
			LEASTUDIOS_PAYMENTS_URL . 'assets/css/admin.css',
			[],
			LEASTUDIOS_PAYMENTS_VERSION
		);

		wp_add_inline_script(
			'media-editor',
			"(function() {
				'use strict';
				document.addEventListener('DOMContentLoaded', function() {
					var selectBtn = document.getElementById('leastudios-payments-select-image');
					var removeBtn = document.getElementById('leastudios-payments-remove-image');
					var input = document.getElementById('product_image_url');
					var preview = document.getElementById('leastudios-payments-image-preview');

					if (!selectBtn || !input) return;

					var frame;

					selectBtn.addEventListener('click', function(e) {
						e.preventDefault();

						if (frame) {
							frame.open();
							return;
						}

						frame = wp.media({
							title: '" . esc_js( __( 'Select Product Image', 'leastudios-payments' ) ) . "',
							button: { text: '" . esc_js( __( 'Use This Image', 'leastudios-payments' ) ) . "' },
							multiple: false,
							library: { type: 'image' }
						});

						frame.on('select', function() {
							var attachment = frame.state().get('selection').first().toJSON();
							var url = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
							input.value = attachment.url;
							preview.innerHTML = '<img src=\"' + url + '\" style=\"max-width:200px;max-height:200px;border:1px solid #dcdcde;border-radius:4px;\" />';
							selectBtn.textContent = '" . esc_js( __( 'Change Image', 'leastudios-payments' ) ) . "';
							if (removeBtn) removeBtn.style.display = '';
						});

						frame.open();
					});

					if (removeBtn) {
						removeBtn.addEventListener('click', function(e) {
							e.preventDefault();
							input.value = '';
							preview.innerHTML = '';
							selectBtn.textContent = '" . esc_js( __( 'Select Image', 'leastudios-payments' ) ) . "';
							removeBtn.style.display = 'none';
						});
					}
				});
			})();"
		);
	}

	/**
	 * Parse price data from POST.
	 *
	 * @return array Array of parsed price definitions.
	 */
	private function parse_prices_from_post(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_post_action.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in handle_post_action(). Individual values sanitized below.
		$raw_prices = isset( $_POST['prices'] ) ? (array) wp_unslash( $_POST['prices'] ) : [];
		$prices     = [];

		foreach ( $raw_prices as $price_data ) {
			if ( ! is_array( $price_data ) ) {
				continue;
			}

			$amount = absint( $price_data['amount'] ?? 0 );

			if ( 0 === $amount ) {
				continue;
			}

			$type = sanitize_text_field( $price_data['type'] ?? 'one_time' );

			$prices[] = [
				'amount'                   => $amount,
				'currency'                 => sanitize_text_field( $price_data['currency'] ?? 'USD' ),
				'type'                     => in_array( $type, [ 'one_time', 'recurring' ], true ) ? $type : 'one_time',
				'recurring_interval'       => sanitize_text_field( $price_data['recurring_interval'] ?? 'month' ),
				'recurring_interval_count' => max( 1, absint( $price_data['recurring_interval_count'] ?? 1 ) ),
			];
		}

		return $prices;
	}

	/**
	 * Format a price object for display.
	 *
	 * @param object $price The price row.
	 * @return string Formatted price string.
	 */
	private function format_price( object $price ): string {
		$symbols = [
			'usd' => '$',
			'gbp' => "\xc2\xa3",
			'eur' => "\xe2\x82\xac",
			'cad' => 'CA$',
			'aud' => 'A$',
			'nzd' => 'NZ$',
			'chf' => 'CHF ',
			'jpy' => "\xc2\xa5",
		];

		$currency = strtolower( $price->currency );
		$symbol   = $symbols[ $currency ] ?? strtoupper( $currency ) . ' ';
		$amount   = (int) $price->amount;

		if ( 'jpy' === $currency ) {
			$formatted = $symbol . number_format( $amount );
		} else {
			$formatted = $symbol . number_format( $amount / 100, 2 );
		}

		if ( 'recurring' === $price->type && ! empty( $price->recurring_interval ) ) {
			$count    = (int) ( $price->recurring_interval_count ?? 1 );
			$interval = $price->recurring_interval;

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

	/**
	 * Render admin notices.
	 *
	 * @return void
	 */
	private function render_notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['created'] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Product created successfully.', 'leastudios-payments' )
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['updated'] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Product updated successfully.', 'leastudios-payments' )
			);
		}

		$error = get_transient( 'leastudios_payments_product_error' );

		if ( is_string( $error ) && '' !== $error ) {
			delete_transient( 'leastudios_payments_product_error' );
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html( $error )
			);
		}
	}

	/**
	 * Redirect back with an error stored in a transient.
	 *
	 * @param string $message The error message.
	 * @return void
	 */
	private function redirect_with_error( string $message ): void {
		set_transient( 'leastudios_payments_product_error', $message, 30 );
		$referer = wp_get_referer();
		wp_safe_redirect( $referer ? $referer : admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}
}
