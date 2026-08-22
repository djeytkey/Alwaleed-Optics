<?php
/**
 * Optic product pricing derived from selected internal child products.
 *
 * @package WC_Optic_Product
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Optic_Pricing
 */
class WC_Optic_Pricing {

	/**
	 * Hooks.
	 */
	public static function hooks() {
		add_filter( 'woocommerce_add_to_cart_quantity', array( __CLASS__, 'add_to_cart_quantity' ), 10, 2 );
		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'is_purchasable' ), 20, 2 );
		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'filter_price_html' ), 20, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'apply_cart_item_prices' ), 20 );
	}

	/**
	 * Use optic line quantity (single qty or left + right) when adding to cart.
	 *
	 * @param int $quantity   Requested quantity.
	 * @param int $product_id Product id.
	 * @return int
	 */
	public static function add_to_cart_quantity( $quantity, $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product || 'optic_product' !== $product->get_type() ) {
			return $quantity;
		}

		$parsed = WC_Optic_Cart::parse_request_for_product( $product_id );
		if ( is_wp_error( $parsed ) ) {
			return $quantity;
		}

		return max( 1, (int) $parsed['line_qty'] );
	}

	/**
	 * Allow optic parent products to be purchasable when they have valid internal children.
	 *
	 * @param bool       $purchasable Current purchasable status.
	 * @param WC_Product $product     Product object.
	 * @return bool
	 */
	public static function is_purchasable( $purchasable, $product ) {
		if ( $purchasable || ! $product instanceof WC_Product || 'optic_product' !== $product->get_type() ) {
			return $purchasable;
		}

		return WC_Optic_Frontend::has_child_options( $product );
	}

	/**
	 * Unit price for an optic product (active price, incl. sale if set).
	 *
	 * @param WC_Product $product Product.
	 * @return float
	 */
	public static function get_unit_price( WC_Product $product ) {
		$price = WC_Optic_SKU::get_default_display_price( $product );
		if ( $price > 0 ) {
			return $price;
		}

		$fallback = $product->get_price();
		if ( '' === $fallback || null === $fallback ) {
			return 0.0;
		}

		return (float) wc_format_decimal( $fallback );
	}

	/**
	 * Formatted single default price HTML for an optic parent product.
	 *
	 * Uses the default internal child (no-power for color lenses, otherwise lowest price).
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function format_display_price_html( WC_Product $product ) {
		$price = WC_Optic_SKU::get_default_display_price( $product );
		if ( $price <= 0 ) {
			return '';
		}

		return wc_price( $price );
	}

	/**
	 * @deprecated Use format_display_price_html().
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function format_price_range_html( WC_Product $product ) {
		return self::format_display_price_html( $product );
	}

	/**
	 * Replace parent product price with the default internal child price (loops + single).
	 *
	 * @param string     $price_html Default HTML.
	 * @param WC_Product $product    Product.
	 * @return string
	 */
	public static function filter_price_html( $price_html, $product ) {
		if ( ! $product instanceof WC_Product || 'optic_product' !== $product->get_type() ) {
			return $price_html;
		}

		$display_html = self::format_display_price_html( $product );
		return $display_html ? $display_html : $price_html;
	}

	/**
	 * Line total for unit price × quantity.
	 *
	 * @param float $unit_price Unit price.
	 * @param int   $quantity   Line quantity.
	 * @return float
	 */
	public static function calculate_line_total( $unit_price, $quantity ) {
		return (float) wc_format_decimal( (float) $unit_price * max( 1, (int) $quantity ) );
	}

	/**
	 * Calculate the cart line total from a parsed optic payload.
	 *
	 * @param array $payload Optic payload.
	 * @return float
	 */
	public static function calculate_payload_total( array $payload ) {
		$left_price  = isset( $payload['left']['unit_price'] ) ? (float) wc_format_decimal( $payload['left']['unit_price'] ) : 0.0;
		$right_price = isset( $payload['right']['unit_price'] ) ? (float) wc_format_decimal( $payload['right']['unit_price'] ) : 0.0;
		$left_child  = isset( $payload['left']['child_id'] ) ? (string) $payload['left']['child_id'] : '';
		$right_child = isset( $payload['right']['child_id'] ) ? (string) $payload['right']['child_id'] : '';
		$same_power  = ! empty( $payload['same_power'] ) || ( $left_child && $left_child === $right_child );
		$qty_mode    = ( $left_child && $right_child && $left_child !== $right_child ) ? 'dual' : ( isset( $payload['qty_mode'] ) ? (string) $payload['qty_mode'] : 'single' );

		if ( 'dual' === $qty_mode ) {
			$qty_left  = isset( $payload['qty_left'] ) ? max( 1, (int) $payload['qty_left'] ) : 1;
			$qty_right = isset( $payload['qty_right'] ) ? max( 1, (int) $payload['qty_right'] ) : 1;
			return (float) wc_format_decimal( ( $left_price * $qty_left ) + ( $right_price * $qty_right ) );
		}

		$qty = isset( $payload['qty_single'] ) ? max( 1, (int) $payload['qty_single'] ) : 1;
		if ( $same_power || ( isset( $payload['left']['child_id'], $payload['right']['child_id'] ) && $payload['left']['child_id'] === $payload['right']['child_id'] ) ) {
			return (float) wc_format_decimal( $left_price * $qty );
		}

		return (float) wc_format_decimal( ( $left_price + $right_price ) * $qty );
	}

	/**
	 * Calculate an effective per-line unit price for WooCommerce cart math.
	 *
	 * @param array $payload Optic payload.
	 * @return float
	 */
	public static function get_payload_effective_unit_price( array $payload ) {
		$qty = isset( $payload['line_qty'] ) ? max( 1, (int) $payload['line_qty'] ) : 1;
		return (float) wc_format_decimal( self::calculate_payload_total( $payload ) / $qty );
	}

	/**
	 * Apply computed optic prices onto cart item product objects.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function apply_cart_item_prices( $cart ) {
		if ( ! $cart || is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( empty( $cart_item[ WC_Optic_Cart::CART_KEY ] ) || empty( $cart_item['data'] ) || ! $cart_item['data'] instanceof WC_Product ) {
				continue;
			}

			$payload = $cart_item[ WC_Optic_Cart::CART_KEY ];
			if ( ! is_array( $payload ) ) {
				continue;
			}

			$cart_item = WC_Optic_Cart::sync_cart_item_payload_quantities( $cart_item );
			$payload   = $cart_item[ WC_Optic_Cart::CART_KEY ];
			$line_qty  = max( 1, (int) ( $payload['line_qty'] ?? 1 ) );
			$line_total = self::calculate_payload_total( $payload );

			if ( (int) $cart_item['quantity'] !== $line_qty ) {
				$cart->cart_contents[ $cart_item_key ]['quantity'] = $line_qty;
			}

			$effective_unit = $line_qty > 0 ? (float) wc_format_decimal( $line_total / $line_qty ) : 0.0;
			$cart->cart_contents[ $cart_item_key ]['data']->set_price( $effective_unit );
			$cart->cart_contents[ $cart_item_key ][ WC_Optic_Cart::CART_KEY ] = $payload;
		}
	}
}
