<?php
/**
 * PSR-4-like autoloader for plugin classes.
 *
 * @package WC_Optic_Product
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Optic_Autoload
 */
class WC_Optic_Autoload {

	/**
	 * Register spl autoload (idempotent).
	 */
	public static function register() {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Autoload class files.
	 *
	 * @param string $class Class name.
	 */
	public static function autoload( $class ) {
		if ( 0 !== strpos( $class, 'WC_Optic_' ) && 'WC_Product_Optic_Product' !== $class ) {
			return;
		}

		$map = array(
			'WC_Product_Optic_Product'     => WC_OPTIC_PLUGIN_DIR . 'includes/class-wc-product-optic-product.php',
			'WC_Optic_Plugin'              => WC_OPTIC_PLUGIN_DIR . 'includes/class-wc-optic-plugin.php',
			'WC_Optic_Database'            => WC_OPTIC_PLUGIN_DIR . 'includes/class-wc-optic-database.php',
			'WC_Optic_Catalog'             => WC_OPTIC_PLUGIN_DIR . 'includes/class-wc-optic-catalog.php',
			'WC_Optic_Divisions'           => WC_OPTIC_PLUGIN_DIR . 'includes/class-wc-optic-divisions.php',
			'WC_Optic_Deletion_Log'        => WC_OPTIC_PLUGIN_DIR . 'includes/class-wc-optic-deletion-log.php',
			'WC_Optic_SKU'                 => WC_OPTIC_PLUGIN_DIR . 'includes/class-wc-optic-sku.php',
			'WC_Optic_Stock'               => WC_OPTIC_PLUGIN_DIR . 'includes/class-wc-optic-stock.php',
			'WC_Optic_QR'                  => WC_OPTIC_PLUGIN_DIR . 'includes/class-wc-optic-qr.php',
			'WC_Optic_Ajax'                => WC_OPTIC_PLUGIN_DIR . 'includes/class-wc-optic-ajax.php',
			'WC_Optic_Frontend'            => WC_OPTIC_PLUGIN_DIR . 'includes/class-wc-optic-frontend.php',
			'WC_Optic_Flatsome'           => WC_OPTIC_PLUGIN_DIR . 'includes/class-wc-optic-flatsome.php',
			'WC_Optic_Cart'                => WC_OPTIC_PLUGIN_DIR . 'includes/class-wc-optic-cart.php',
			'WC_Optic_Pricing'             => WC_OPTIC_PLUGIN_DIR . 'includes/class-wc-optic-pricing.php',
			'WC_Optic_WPML'                => WC_OPTIC_PLUGIN_DIR . 'includes/class-wc-optic-wpml.php',
			'WC_Optic_Admin_Menu'          => WC_OPTIC_PLUGIN_DIR . 'includes/admin/class-wc-optic-admin-menu.php',
			'WC_Optic_Admin_Settings'      => WC_OPTIC_PLUGIN_DIR . 'includes/admin/class-wc-optic-admin-settings.php',
			'WC_Optic_Admin_Product'       => WC_OPTIC_PLUGIN_DIR . 'includes/admin/class-wc-optic-admin-product.php',
			'WC_Optic_Admin_Import'        => WC_OPTIC_PLUGIN_DIR . 'includes/admin/class-wc-optic-admin-import.php',
			'WC_Optic_Power_Template'      => WC_OPTIC_PLUGIN_DIR . 'includes/class-wc-optic-power-template.php',
			'WC_Optic_Converter'           => WC_OPTIC_PLUGIN_DIR . 'includes/class-wc-optic-converter.php',
			'WC_Optic_Admin_Stock'         => WC_OPTIC_PLUGIN_DIR . 'includes/admin/class-wc-optic-admin-stock.php',
			'WC_Optic_Admin_Convert'       => WC_OPTIC_PLUGIN_DIR . 'includes/admin/class-wc-optic-admin-convert.php',
		);

		if ( isset( $map[ $class ] ) && is_readable( $map[ $class ] ) ) {
			require_once $map[ $class ];
		}
	}
}
