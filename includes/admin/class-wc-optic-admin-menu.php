<?php
/**
 * Top-level WordPress admin menu for Alwaleed Optics.
 *
 * @package WC_Optic_Product
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Optic_Admin_Menu
 */
class WC_Optic_Admin_Menu {

	const PARENT_SLUG     = 'wc-optic-settings';
	const MENU_POSITION   = 3;
	const SETTINGS_SCREEN = 'toplevel_page_wc-optic-settings';
	const IMPORT_SCREEN   = 'wc-optic-settings_page_wc-optic-import';
	const STOCK_SCREEN    = 'wc-optic-settings_page_wc-optic-stock';
	const CONVERT_SCREEN  = 'wc-optic-settings_page_wc-optic-convert';

	/**
	 * Hooks.
	 */
	public static function hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'register' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_alert_badges' ), 999 );
	}

	/**
	 * Register main menu and submenus (Settings, Import).
	 */
	public static function register() {
		add_menu_page(
			__( 'Alwaleed Optics', 'wc-optic' ),
			__( 'Alwaleed Optics', 'wc-optic' ),
			'manage_woocommerce',
			self::PARENT_SLUG,
			array( 'WC_Optic_Admin_Settings', 'render_page' ),
			'dashicons-visibility',
			self::MENU_POSITION
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Settings', 'wc-optic' ),
			__( 'Settings', 'wc-optic' ),
			'manage_woocommerce',
			self::PARENT_SLUG,
			array( 'WC_Optic_Admin_Settings', 'render_page' )
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Stock', 'wc-optic' ),
			__( 'Stock', 'wc-optic' ),
			'manage_woocommerce',
			'wc-optic-stock',
			array( 'WC_Optic_Admin_Stock', 'render_page' )
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Convert', 'wc-optic' ),
			__( 'Convert', 'wc-optic' ),
			'manage_woocommerce',
			'wc-optic-convert',
			array( 'WC_Optic_Admin_Convert', 'render_page' )
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Import', 'wc-optic' ),
			__( 'Import', 'wc-optic' ),
			'manage_woocommerce',
			'wc-optic-import',
			array( 'WC_Optic_Admin_Import', 'render_page' )
		);
	}

	/**
	 * Append alert count bubble to the main menu and Stock submenu.
	 */
	public static function add_alert_badges() {
		$count = WC_Optic_Stock::get_alert_count();
		if ( $count < 1 ) {
			return;
		}

		global $menu, $submenu;

		$badge = ' <span class="awaiting-mod update-plugins count-' . esc_attr( (string) $count ) . '"><span class="plugin-count">' . esc_html( number_format_i18n( $count ) ) . '</span></span>';

		foreach ( $menu as $key => $item ) {
			if ( isset( $item[2] ) && self::PARENT_SLUG === $item[2] ) {
				$menu[ $key ][0] .= $badge; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				break;
			}
		}

		if ( ! isset( $submenu[ self::PARENT_SLUG ] ) || ! is_array( $submenu[ self::PARENT_SLUG ] ) ) {
			return;
		}

		foreach ( $submenu[ self::PARENT_SLUG ] as $key => $item ) {
			if ( isset( $item[2] ) && 'wc-optic-stock' === $item[2] ) {
				$submenu[ self::PARENT_SLUG ][ $key ][0] .= $badge; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				break;
			}
		}
	}
}
