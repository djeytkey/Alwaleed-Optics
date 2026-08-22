<?php
/**
 * Optic product add to cart form.
 *
 * @package WC_Optic_Product
 * @version 1.2.4
 */

defined( 'ABSPATH' ) || exit;

global $product;

$division = $product->get_meta( '_optic_division', true );
if ( ! $division ) {
	echo '<p class="wc-optic-notice">' . esc_html__( 'This product is not ready for sale yet.', 'wc-optic' ) . '</p>';
	return;
}

$storefront_matrix    = WC_Optic_SKU::get_storefront_matrix( $product );
$supports_no_power    = WC_Optic_SKU::division_supports_no_power_mode( $division );
$can_choose_different = count( $storefront_matrix['children'] ?? array() ) > 1;

if ( ! WC_Optic_Frontend::has_child_options( $product ) ) {
	echo '<p class="wc-optic-notice">' . esc_html__( 'This product is not ready for sale yet. Please configure its internal products in the product admin.', 'wc-optic' ) . '</p>';
	return;
}

echo WC_Optic_Frontend::get_stock_html( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

if ( ! WC_Optic_Frontend::product_is_in_stock( $product ) ) {
	return;
}

do_action( 'woocommerce_before_add_to_cart_form' );
?>

<form class="cart wc-optic-cart-form" action="<?php echo esc_url( apply_filters( 'woocommerce_add_to_cart_form_action', $product->get_permalink() ) ); ?>" method="post" enctype="multipart/form-data">
	<?php wp_nonce_field( 'wc_optic_add_to_cart', 'wc_optic_nonce' ); ?>

	<?php
	$default_price      = WC_Optic_SKU::get_default_display_price( $product );
	$display_price_html = WC_Optic_Pricing::format_display_price_html( $product );
	?>
	<div class="wc-optic-pricing" hidden aria-hidden="true" data-default-price="<?php echo esc_attr( (string) $default_price ); ?>">
		<span id="wc_optic_unit_price_display"><?php echo $display_price_html ? wp_kses_post( $display_price_html ) : ''; ?></span>
		<p class="wc-optic-line-total" hidden>
			<span id="wc_optic_line_total_display"></span>
		</p>
	</div>

	<div class="wc-optic-config-card">
		<?php if ( $supports_no_power ) : ?>
			<div class="wc-optic-config-table__row wc-optic-power-mode-row">
				<div class="wc-optic-config-table__values wc-optic-power-mode-row__values">
					<fieldset class="wc-optic-fieldset">
						<legend class="screen-reader-text"><?php esc_html_e( 'Power type', 'wc-optic' ); ?></legend>
						<div class="wc-optic-power-mode" role="radiogroup" aria-label="<?php esc_attr_e( 'Power type', 'wc-optic' ); ?>">
							<input type="radio" name="wc_optic_power_mode" id="wc_optic_tab_no_power" value="no_power" class="wc-optic-power-mode__input" checked="checked" />
							<label for="wc_optic_tab_no_power" class="wc-optic-power-mode__tab" data-testid="tab_no_power">
								<?php esc_html_e( 'No power', 'wc-optic' ); ?>
							</label>
							<input type="radio" name="wc_optic_power_mode" id="wc_optic_tab_power" value="power" class="wc-optic-power-mode__input" />
							<label for="wc_optic_tab_power" class="wc-optic-power-mode__tab" data-testid="tab_power">
								<?php esc_html_e( 'Power', 'wc-optic' ); ?>
							</label>
						</div>
					</fieldset>
				</div>
			</div>
		<?php endif; ?>

		<?php if ( $can_choose_different ) : ?>
			<p class="wc-optic-toggle wc-optic-toggle--question" <?php echo $supports_no_power ? 'hidden' : ''; ?>>
				<label for="wc_optic_different_power">
					<input type="checkbox" name="wc_optic_different_power" value="1" id="wc_optic_different_power" />
					<strong><?php esc_html_e( 'Need 2 Different Powers?', 'wc-optic' ); ?></strong>
				</label>
			</p>
		<?php endif; ?>

		<div class="wc-optic-config-table">
			<div class="wc-optic-config-table__row wc-optic-prescription-row" <?php echo $supports_no_power ? 'hidden' : ''; ?>>
				<div class="wc-optic-config-table__label">
					<strong><?php esc_html_e( 'Prescription', 'wc-optic' ); ?></strong>
				</div>
				<div class="wc-optic-config-table__values">
					<fieldset class="wc-optic-fieldset">
						<legend class="screen-reader-text"><?php esc_html_e( 'Prescription', 'wc-optic' ); ?></legend>
						<div class="wc-optic-eyes wc-optic-eyes--stack">
							<div class="wc-optic-eye wc-optic-eye--left" data-eye="left">
								<span class="wc-optic-eye-title wc-optic-title-both"><?php esc_html_e( 'Both eyes', 'wc-optic' ); ?></span>
								<span class="wc-optic-eye-title wc-optic-title-left" hidden><?php esc_html_e( 'Left eye (OS)', 'wc-optic' ); ?></span>
								<?php WC_Optic_Frontend::render_power_selectors( $product, 'left', true ); ?>
							</div>

							<div class="wc-optic-eye wc-optic-eye--right wc-optic-eye--secondary" data-eye="right" hidden>
								<span class="wc-optic-eye-title"><?php esc_html_e( 'Right eye (OD)', 'wc-optic' ); ?></span>
								<?php WC_Optic_Frontend::render_power_selectors( $product, 'right', false ); ?>
							</div>
						</div>
					</fieldset>
				</div>
			</div>

			<div class="wc-optic-config-table__row">
				<div class="wc-optic-config-table__label">
					<strong><?php esc_html_e( 'Quantity', 'wc-optic' ); ?></strong>
				</div>
				<div class="wc-optic-config-table__values">
					<div class="wc-optic-qty wc-optic-qty--single">
						<label for="wc_optic_qty" class="screen-reader-text"><?php esc_html_e( 'Quantity for both eyes', 'wc-optic' ); ?></label>
						<input type="number" name="wc_optic_qty" id="wc_optic_qty" min="1" step="1" value="1" class="input-text qty text" />
					</div>

					<div class="wc-optic-qty wc-optic-qty--dual" hidden>
						<p class="form-row">
							<label for="wc_optic_qty_left"><span class="wc-optic-ltr" dir="ltr"><?php esc_html_e( 'OS qty', 'wc-optic' ); ?></span></label>
							<input type="number" name="wc_optic_qty_left" id="wc_optic_qty_left" min="1" step="1" value="1" class="input-text qty text" />
						</p>
						<p class="form-row">
							<label for="wc_optic_qty_right"><span class="wc-optic-ltr" dir="ltr"><?php esc_html_e( 'OD qty', 'wc-optic' ); ?></span></label>
							<input type="number" name="wc_optic_qty_right" id="wc_optic_qty_right" min="1" step="1" value="1" class="input-text qty text" />
						</p>
					</div>
				</div>
			</div>
		</div>
	</div>

	<?php do_action( 'woocommerce_before_add_to_cart_button' ); ?>

	<input type="hidden" name="quantity" id="wc_optic_line_quantity" value="1" />

	<button type="submit" name="add-to-cart" value="<?php echo esc_attr( $product->get_id() ); ?>" class="single_add_to_cart_button button alt<?php echo esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ); ?>"><?php echo esc_html( $product->single_add_to_cart_text() ); ?></button>

	<?php do_action( 'woocommerce_after_add_to_cart_button' ); ?>
</form>

<?php do_action( 'woocommerce_after_add_to_cart_form' ); ?>
