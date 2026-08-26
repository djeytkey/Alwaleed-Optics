<?php
/**
 * Plugin Name: Alwaleed Optics Products
 * Plugin URI: https://www.moroccoder.com/
 * Description: Custom WooCommerce product type for optical/lens products with prescription logic, dynamic SKU, and global catalog.
 * Version: 1.3.7
 * Author: Tarik BOUKJIJ
 * Text Domain: wc-optic
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 * WC tested up to: 9.8
 * Requires Plugins: woocommerce
 *
 * WPML: wpml-config.xml is loaded automatically when this plugin is active (no manual rescan).
 * Translate catalog names in WPML → String Translation (domain wc-optic-catalog).
 *
 * @package WC_Optic_Product
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WC_OPTIC_VERSION' ) ) {
	define( 'WC_OPTIC_VERSION', '1.3.7' );
	define( 'WC_OPTIC_PLUGIN_FILE', __FILE__ );
	define( 'WC_OPTIC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
	define( 'WC_OPTIC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

/**
 * Composer autoload (PhpSpreadsheet for XLSX import).
 */
$wc_optic_autoload = WC_OPTIC_PLUGIN_DIR . 'vendor/autoload.php';
if ( is_readable( $wc_optic_autoload ) ) {
	require_once $wc_optic_autoload;
}

require_once WC_OPTIC_PLUGIN_DIR . 'includes/class-wc-optic-autoload.php';
// Register immediately: activation runs before plugins_loaded and needs WC_Optic_Catalog.
WC_Optic_Autoload::register();

register_activation_hook( __FILE__, 'wc_optic_activate_plugin' );

/**
 * Plugin activation: schema + ask WPML to reload wpml-config.xml on next request.
 */
function wc_optic_activate_plugin() {
	WC_Optic_Database::activate();
	WC_Optic_Divisions::maybe_seed_defaults();
	update_option( 'wc_optic_pending_wpml_config', '1', false );
}

/**
 * Reload WPML custom-field rules after activation (WPML loads plugin wpml-config.xml files automatically).
 */
function wc_optic_maybe_reload_wpml_config() {
	if ( ! get_option( 'wc_optic_pending_wpml_config' ) ) {
		return;
	}
	delete_option( 'wc_optic_pending_wpml_config' );
	if ( class_exists( 'WPML_Config', false ) && is_callable( array( 'WPML_Config', 'load_config_run' ) ) ) {
		WPML_Config::load_config_run();
	}
}
add_action( 'plugins_loaded', 'wc_optic_maybe_reload_wpml_config', 20 );

/**
 * Bootstrap after plugins loaded.
 */
function wc_optic_product_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p>';
				echo esc_html__( 'WooCommerce Optic Product requires WooCommerce to be installed and active.', 'wc-optic' );
				echo '</p></div>';
			}
		);
		return;
	}

	load_plugin_textdomain( 'wc-optic', false, dirname( plugin_basename( WC_OPTIC_PLUGIN_FILE ) ) . '/languages' );

	WC_Optic_Autoload::register();
	WC_Optic_Plugin::instance();
}
add_action( 'plugins_loaded', 'wc_optic_product_init', 11 );
