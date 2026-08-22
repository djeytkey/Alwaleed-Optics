<?php
/**
 * Frontend template loading and assets.
 *
 * @package WC_Optic_Product
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Optic_Frontend
 */
class WC_Optic_Frontend {

	/**
	 * Hooks.
	 */
	public static function hooks() {
		add_filter( 'woocommerce_locate_template', array( __CLASS__, 'locate_template' ), 10, 3 );
		add_filter( 'wc_product_sku_enabled', array( __CLASS__, 'hide_parent_sku_for_optic_products' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'woocommerce_optic_product_add_to_cart', array( __CLASS__, 'render_add_to_cart' ), 30 );
	}

	/**
	 * Hide the parent product SKU on optic product pages.
	 *
	 * @param bool $enabled Whether WooCommerce should render the SKU block.
	 * @return bool
	 */
	public static function hide_parent_sku_for_optic_products( $enabled ) {
		if ( ! $enabled || ! is_product() ) {
			return $enabled;
		}

		global $product;
		if ( $product instanceof WC_Product && 'optic_product' === $product->get_type() ) {
			return false;
		}

		return $enabled;
	}

	/**
	 * Load add-to-cart template from plugin.
	 *
	 * @param string $template Path.
	 * @param string $template_name Name.
	 * @param string $template_path Path.
	 * @return string
	 */
	public static function locate_template( $template, $template_name, $template_path ) {
		$plugin_templates = array(
			'single-product/add-to-cart/optic_product.php',
			'cart/cart-item-data.php',
		);

		if ( in_array( $template_name, $plugin_templates, true ) ) {
			$plugin = WC_OPTIC_PLUGIN_DIR . 'templates/' . $template_name;
			if ( is_readable( $plugin ) ) {
				return $plugin;
			}
		}

		return $template;
	}

	/**
	 * Render the add-to-cart form for the custom optic product type.
	 */
	public static function render_add_to_cart() {
		wc_get_template( 'single-product/add-to-cart/optic_product.php' );
	}

	/**
	 * Scripts and styles for single optic product.
	 */
	public static function enqueue() {
		if ( ! is_product() ) {
			return;
		}
		$product = wc_get_product( get_queried_object_id() );
		if ( ! $product || 'optic_product' !== $product->get_type() ) {
			return;
		}

		wp_enqueue_style(
			'wc-optic-frontend',
			WC_OPTIC_PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			WC_OPTIC_VERSION
		);
		wp_enqueue_style( 'select2' );
		wp_enqueue_script( 'selectWoo' );

		wp_enqueue_script(
			'wc-optic-frontend',
			WC_OPTIC_PLUGIN_URL . 'assets/js/frontend.js',
			array( 'jquery', 'selectWoo', 'wc-add-to-cart' ),
			WC_OPTIC_VERSION,
			true
		);
		$default_price = WC_Optic_SKU::get_default_display_price( $product );

		wp_localize_script(
			'wc-optic-frontend',
			'wcOpticFront',
			array(
				'defaultPrice'     => $default_price,
				'defaultPriceHtml' => WC_Optic_Pricing::format_display_price_html( $product ),
				'summaryPriceSelector' => '.entry-summary > .price, .product-summary > .price, .summary > .price, .product-page-price',
				'currencySymbol' => get_woocommerce_currency_symbol(),
				'decimalSep'     => wc_get_price_decimal_separator(),
				'thousandSep'    => wc_get_price_thousand_separator(),
				'decimals'       => wc_get_price_decimals(),
				'priceFormat'    => get_woocommerce_price_format(),
				'matrix'         => WC_Optic_SKU::get_storefront_matrix( $product ),
				'i18n'           => array(
					'rightEye'       => __( 'Right eye (OD)', 'wc-optic' ),
					'leftEye'        => __( 'Left eye (OS)', 'wc-optic' ),
					'select'         => __( '— Select —', 'wc-optic' ),
					'price'          => __( 'Price', 'wc-optic' ),
					'selectedPrice'  => __( 'Selected price', 'wc-optic' ),
					'estimatedTotal' => __( 'Estimated total', 'wc-optic' ),
					'inStock'        => __( 'In stock', 'wc-optic' ),
					'outOfStock'     => __( 'Out of stock', 'wc-optic' ),
					'stockAvailable' => __( 'Available', 'wc-optic' ),
					'outOfStockOption' => __( 'Out of stock', 'wc-optic' ),
					'comboUnavailable' => __( 'This combination is not available.', 'wc-optic' ),
					'noPower'          => __( 'No power', 'wc-optic' ),
					'power'            => __( 'Power', 'wc-optic' ),
					'powerType'        => __( 'Power type', 'wc-optic' ),
					'noPowerUnavailable' => __( 'This product is not available without power.', 'wc-optic' ),
				),
			)
		);
	}

	/**
	 * Get enabled child configurations for storefront use.
	 *
	 * @param WC_Product $product Product.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_storefront_child_configs( WC_Product $product ) {
		return WC_Optic_SKU::get_enabled_child_configs( $product );
	}

	/**
	 * Whether a product has sellable child options.
	 *
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	public static function has_child_options( WC_Product $product ) {
		$matrix = WC_Optic_SKU::get_storefront_matrix( $product );
		return ! empty( $matrix['children'] ) || ! empty( $matrix['noPowerChild'] );
	}

	/**
	 * Whether a product still has at least one internal product available after cart reservations.
	 *
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	public static function has_remaining_child_options( WC_Product $product ) {
		foreach ( self::get_storefront_child_configs( $product ) as $config ) {
			$remaining = WC_Optic_Cart::get_remaining_child_stock( $product, $config );
			if ( null === $remaining || $remaining > 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether at least one internal product is currently in stock.
	 *
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	public static function product_is_in_stock( WC_Product $product ) {
		return self::has_remaining_child_options( $product );
	}

	/**
	 * Render a stock summary based on internal products.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function get_stock_html( WC_Product $product ) {
		$total      = count( self::get_storefront_child_configs( $product ) );
		$available  = 0;
		$class_name = $available > 0 ? 'in-stock' : 'out-of-stock';

		foreach ( self::get_storefront_child_configs( $product ) as $config ) {
			$remaining = WC_Optic_Cart::get_remaining_child_stock( $product, $config );
			if ( null === $remaining || $remaining > 0 ) {
				++$available;
			}
		}

		$class_name = $available > 0 ? 'in-stock' : 'out-of-stock';

		if ( $total < 1 ) {
			return '';
		}

		if ( $available < 1 ) {
			return '<p class="stock ' . esc_attr( $class_name ) . '">' . esc_html__( 'Out of stock', 'wc-optic' ) . '</p>';
		}

		if ( $available === $total ) {
			return '<p class="stock ' . esc_attr( $class_name ) . '">' . esc_html__( 'In stock', 'wc-optic' ) . '</p>';
		}

		return '<p class="stock ' . esc_attr( $class_name ) . '">' . esc_html(
			sprintf(
				/* translators: 1: available internal products, 2: total internal products */
				__( '%1$d of %2$d internal products available', 'wc-optic' ),
				$available,
				$total
			)
		) . '</p>';
	}

	/**
	 * Render cascading prescription power selectors for one eye.
	 *
	 * @param WC_Product $product  Product.
	 * @param string     $eye      left|right.
	 * @param bool       $required HTML required attribute.
	 */
	public static function render_power_selectors( WC_Product $product, $eye, $required = true ) {
		$eye      = 'right' === $eye ? 'right' : 'left';
		$division = (string) $product->get_meta( '_optic_division', true );
		$powers   = $division ? WC_Optic_Plugin::get_powers_for_division( $division ) : array();
		$req_attr = $required ? ' required' : '';

		echo '<div class="wc-optic-power-selectors" data-eye="' . esc_attr( $eye ) . '">';
		foreach ( $powers as $power ) {
			$field_name = 'wc_optic_' . $eye . '_' . $power;
			$field_id   = $field_name;
			echo '<p class="wc-optic-power wc-optic-power--' . esc_attr( $power ) . '">';
			echo '<label for="' . esc_attr( $field_id ) . '">' . esc_html( WC_Optic_Catalog::get_power_field_label( $power ) ) . '</label>';
			echo '<select name="' . esc_attr( $field_name ) . '" id="' . esc_attr( $field_id ) . '" class="wc-optic-power-dropdown" data-power="' . esc_attr( $power ) . '"' . $req_attr . ' data-placeholder="' . esc_attr__( '— Select —', 'wc-optic' ) . '">';
			echo '<option value=""></option>';
			echo '</select>';
			echo '</p>';
		}
		echo '<input type="hidden" name="wc_optic_' . esc_attr( $eye ) . '_child" class="wc-optic-resolved-child" value="" />';
		echo '<p class="wc-optic-resolution-notice" role="status" hidden></p>';
		echo '</div>';
	}

}
