<?php
/**
 * Main plugin singleton.
 *
 * @package WC_Optic_Product
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Optic_Plugin
 */
class WC_Optic_Plugin {

	/**
	 * Instance.
	 *
	 * @var WC_Optic_Plugin|null
	 */
	protected static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return WC_Optic_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * WC_Optic_Plugin constructor.
	 */
	private function __construct() {
		add_action(
			'before_woocommerce_init',
			function () {
				if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
					\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WC_OPTIC_PLUGIN_FILE, false );
				}
			}
		);

		WC_Optic_Database::maybe_upgrade_schema();
		WC_Optic_Database::ensure_product_type_term();
		WC_Optic_Divisions::maybe_seed_defaults();

		add_filter( 'woocommerce_product_class', array( $this, 'product_class' ), 10, 2 );
		add_filter( 'product_type_selector', array( $this, 'product_type_selector' ) );

		WC_Optic_Admin_Menu::hooks();
		WC_Optic_Admin_Product::hooks();
		WC_Optic_Admin_Settings::hooks();
		WC_Optic_Admin_Stock::hooks();
		WC_Optic_Admin_Convert::hooks();
		WC_Optic_Admin_Import::hooks();
		WC_Optic_Ajax::hooks();
		WC_Optic_Frontend::hooks();
		WC_Optic_Flatsome::hooks();
		WC_Optic_Cart::hooks();
		WC_Optic_Pricing::hooks();

		add_action( 'wpml_loaded', array( 'WC_Optic_WPML', 'maybe_init' ) );
		WC_Optic_WPML::maybe_init();
	}

	/**
	 * Map product type to class.
	 *
	 * @param string $classname Class name.
	 * @param string $product_type Type.
	 * @return string
	 */
	public function product_class( $classname, $product_type ) {
		if ( 'optic_product' === $product_type ) {
			return 'WC_Product_Optic_Product';
		}
		return $classname;
	}

	/**
	 * Admin product type dropdown label.
	 *
	 * @param array $types Types.
	 * @return array
	 */
	public function product_type_selector( $types ) {
		$types['optic_product'] = __( 'Optic Product', 'wc-optic' );
		return $types;
	}

	/**
	 * Division definitions: slug => powers.
	 *
	 * @return array<string, array{label:string, powers:string[]}>
	 */
	public static function get_divisions() {
		return WC_Optic_Divisions::get_all();
	}

	/**
	 * Visible divisions for product selectors (excludes hidden).
	 *
	 * @return array<string, array{label:string, powers:string[], hidden:bool}>
	 */
	public static function get_visible_divisions() {
		return WC_Optic_Divisions::get_visible();
	}

	/**
	 * Powers for division slug.
	 *
	 * @param string $division Division slug.
	 * @return string[]
	 */
	public static function get_powers_for_division( $division ) {
		$all = self::get_divisions();
		return isset( $all[ $division ] ) ? $all[ $division ]['powers'] : array();
	}
}
