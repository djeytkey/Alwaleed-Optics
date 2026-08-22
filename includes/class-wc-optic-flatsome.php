<?php
/**
 * Modern cart & checkout styling for the Flatsome theme.
 *
 * @package WC_Optic_Product
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Optic_Flatsome
 */
class WC_Optic_Flatsome {

	/**
	 * Hooks.
	 */
	public static function hooks() {
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 30 );
	}

	/**
	 * Whether the active theme is Flatsome (parent or child).
	 *
	 * @return bool
	 */
	public static function is_active() {
		$theme = wp_get_theme();
		if ( ! $theme instanceof WP_Theme ) {
			return false;
		}

		$template  = (string) $theme->get_template();
		$stylesheet = (string) $theme->get_stylesheet();

		foreach ( array( $template, $stylesheet ) as $slug ) {
			if ( '' !== $slug && false !== strpos( $slug, 'flatsome' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Body classes for scoped cart/checkout CSS.
	 *
	 * @param string[] $classes Body classes.
	 * @return string[]
	 */
	public static function body_class( $classes ) {
		if ( ! self::is_active() ) {
			return $classes;
		}

		if ( is_cart() ) {
			$classes[] = 'wc-optic-flatsome-cart';
		}

		if ( is_checkout() && ! is_wc_endpoint_url( 'order-received' ) ) {
			$classes[] = 'wc-optic-flatsome-checkout';
		}

		return $classes;
	}

	/**
	 * Enqueue Flatsome cart/checkout assets.
	 */
	public static function enqueue_assets() {
		if ( ! self::is_active() || ( ! is_cart() && ! is_checkout() ) ) {
			return;
		}

		wp_enqueue_style(
			'wc-optic-frontend',
			WC_OPTIC_PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			WC_OPTIC_VERSION
		);

		wp_enqueue_style(
			'wc-optic-flatsome-checkout',
			WC_OPTIC_PLUGIN_URL . 'assets/css/flatsome-cart-checkout.css',
			array( 'wc-optic-frontend' ),
			WC_OPTIC_VERSION
		);

		if ( is_cart() ) {
			wp_enqueue_script(
				'wc-optic-cart',
				WC_OPTIC_PLUGIN_URL . 'assets/js/cart.js',
				array( 'jquery' ),
				WC_OPTIC_VERSION,
				true
			);
		}
	}
}
