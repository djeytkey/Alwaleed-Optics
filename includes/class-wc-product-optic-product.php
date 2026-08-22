<?php
/**
 * Optic WooCommerce product type.
 *
 * @package WC_Optic_Product
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Product_Optic_Product
 */
class WC_Product_Optic_Product extends WC_Product {

	/**
	 * Constructor.
	 *
	 * @param mixed $product Product.
	 */
	public function __construct( $product = 0 ) {
		$this->supports[] = 'ajax_add_to_cart';
		parent::__construct( $product );
	}

	/**
	 * Whether this optic product has exactly one purchasable internal product.
	 *
	 * @return bool
	 */
	protected function has_single_purchasable_child() {
		return false;
	}

	/**
	 * Whether this optic product has sellable prescription combinations.
	 *
	 * @return bool
	 */
	protected function has_multiple_purchasable_children() {
		return WC_Optic_Frontend::has_child_options( $this );
	}

	/**
	 * Supported product features.
	 *
	 * @param string $feature Feature name.
	 * @return bool
	 */
	public function supports( $feature ) {
		if ( 'ajax_add_to_cart' === $feature ) {
			return $this->has_single_purchasable_child() && $this->is_purchasable() && WC_Optic_Frontend::product_is_in_stock( $this );
		}

		return parent::supports( $feature );
	}

	/**
	 * Product type key.
	 *
	 * @return string
	 */
	public function get_type() {
		return 'optic_product';
	}

	/**
	 * Optic lines use per–internal-product quantities (cart payload), not WooCommerce "sold individually".
	 *
	 * @return bool
	 */
	public function is_sold_individually() {
		return false;
	}

	/**
	 * Stock is tracked on internal child configs, not the parent SKU row.
	 *
	 * @return bool
	 */
	public function managing_stock() {
		return false;
	}

	/**
	 * In-stock when at least one internal product has remaining quantity.
	 *
	 * @return bool
	 */
	public function is_in_stock() {
		return WC_Optic_Frontend::product_is_in_stock( $this );
	}

	/**
	 * Add to cart URL.
	 *
	 * @return string
	 */
	public function add_to_cart_url() {
		$can_add_directly = $this->has_single_purchasable_child() && $this->is_purchasable() && WC_Optic_Frontend::product_is_in_stock( $this );
		$url              = $can_add_directly ? remove_query_arg(
			'added-to-cart',
			add_query_arg(
				array(
					'add-to-cart' => $this->get_id(),
				),
				( function_exists( 'is_feed' ) && is_feed() ) || ( function_exists( 'is_404' ) && is_404() ) ? $this->get_permalink() : ''
			)
		) : $this->get_permalink();
		return apply_filters( 'woocommerce_product_add_to_cart_url', $url, $this );
	}

	/**
	 * Add to cart button text.
	 *
	 * @return string
	 */
	public function add_to_cart_text() {
		if ( $this->has_single_purchasable_child() && $this->is_purchasable() && WC_Optic_Frontend::product_is_in_stock( $this ) ) {
			$text = __( 'Add to cart', 'woocommerce' );
		} elseif ( $this->has_multiple_purchasable_children() ) {
			$text = __( 'Choisir une option', 'wc-optic' );
		} else {
			$text = __( 'Read more', 'woocommerce' );
		}

		return apply_filters( 'woocommerce_product_add_to_cart_text', $text, $this );
	}

	/**
	 * Add to cart description.
	 *
	 * @return string
	 */
	public function add_to_cart_description() {
		if ( $this->has_single_purchasable_child() && $this->is_purchasable() && WC_Optic_Frontend::product_is_in_stock( $this ) ) {
			/* translators: %s: product title */
			$text = __( 'Add to cart: &ldquo;%s&rdquo;', 'woocommerce' );
		} elseif ( $this->has_multiple_purchasable_children() ) {
			/* translators: %s: product title */
			$text = __( 'Choose options for &ldquo;%s&rdquo;', 'woocommerce' );
		} else {
			/* translators: %s: product title */
			$text = __( 'Read more about &ldquo;%s&rdquo;', 'woocommerce' );
		}

		return apply_filters( 'woocommerce_product_add_to_cart_description', sprintf( $text, $this->get_name() ), $this );
	}
}
