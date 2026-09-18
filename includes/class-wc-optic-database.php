<?php
/**
 * Custom tables and activation.
 *
 * @package WC_Optic_Product
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Optic_Database
 */
class WC_Optic_Database {

	const TABLE_CATALOG      = 'wc_optic_catalog';
	const TABLE_DELETION_LOG = 'wc_optic_catalog_deletion_log';
	const TABLE_CHILDREN     = 'wc_optic_children';

	/** @var int Bump when adding DB tables or columns; see maybe_upgrade_schema(). */
	const SCHEMA_VERSION = 4;

	/**
	 * Create tables on activation.
	 */
	public static function activate() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = $wpdb->prefix . self::TABLE_CATALOG;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			term_type varchar(32) NOT NULL,
			slug varchar(191) NOT NULL,
			name varchar(255) NOT NULL,
			sku_fragment varchar(64) NOT NULL DEFAULT '',
			sort_order int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY type_slug (term_type, slug),
			KEY term_type (term_type),
			KEY sort_order (sort_order)
		) {$charset};";

		dbDelta( $sql );

		self::create_deletion_log_table();
		self::create_children_table();

		update_option( 'wc_optic_db_schema', self::SCHEMA_VERSION );

		self::ensure_product_type_term();
	}

	/**
	 * Create deletion audit log table (activation + upgrades).
	 */
	public static function create_deletion_log_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = $wpdb->prefix . self::TABLE_DELETION_LOG;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			catalog_term_id bigint(20) unsigned NOT NULL,
			term_type varchar(32) NOT NULL,
			term_name varchar(255) NOT NULL DEFAULT '',
			term_slug varchar(191) NOT NULL DEFAULT '',
			deleted_by bigint(20) unsigned NOT NULL DEFAULT 0,
			deleted_at datetime NOT NULL,
			affected_products longtext NOT NULL,
			PRIMARY KEY  (id),
			KEY deleted_at (deleted_at),
			KEY term_type (term_type),
			KEY catalog_term_id (catalog_term_id),
			KEY deleted_by (deleted_by)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Internal products table (replaces giant `_optic_child_configs` postmeta for queries).
	 */
	public static function create_children_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = $wpdb->prefix . self::TABLE_CHILDREN;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint(20) unsigned NOT NULL,
			child_key varchar(64) NOT NULL,
			sku varchar(191) NOT NULL DEFAULT '',
			label varchar(255) NOT NULL DEFAULT '',
			enabled tinyint(1) NOT NULL DEFAULT 1,
			sort_order int(11) NOT NULL DEFAULT 0,
			unit_price decimal(20,6) NOT NULL DEFAULT 0,
			stock_qty int(11) DEFAULT NULL,
			backorder_custom tinyint(1) NOT NULL DEFAULT 0,
			backorder_qty int(11) NOT NULL DEFAULT 0,
			backorder_consumed int(11) NOT NULL DEFAULT 0,
			alert_custom tinyint(1) NOT NULL DEFAULT 0,
			alert_qty int(11) DEFAULT NULL,
			sph_id bigint(20) unsigned NOT NULL DEFAULT 0,
			cyl_id bigint(20) unsigned NOT NULL DEFAULT 0,
			axis_id bigint(20) unsigned NOT NULL DEFAULT 0,
			add_id bigint(20) unsigned NOT NULL DEFAULT 0,
			search_blob varchar(512) NOT NULL DEFAULT '',
			is_low_stock tinyint(1) NOT NULL DEFAULT 0,
			config_json longtext NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY product_child (product_id, child_key),
			KEY product_enabled_sort (product_id, enabled, sort_order),
			KEY product_low (product_id, is_low_stock),
			KEY is_low_stock (is_low_stock),
			KEY sku (sku),
			KEY search_blob (search_blob(191))
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Run lightweight schema upgrades for existing installs.
	 */
	public static function maybe_upgrade_schema() {
		$v = (int) get_option( 'wc_optic_db_schema', 0 );
		if ( $v >= self::SCHEMA_VERSION ) {
			if ( class_exists( 'WC_Optic_Children' ) ) {
				WC_Optic_Children::maybe_migrate_from_meta();
			}
			return;
		}
		if ( $v < 2 ) {
			self::create_deletion_log_table();
		}
		if ( $v < 3 ) {
			self::migrate_axe_to_axis();
		}
		if ( $v < 4 ) {
			self::create_children_table();
		}
		update_option( 'wc_optic_db_schema', self::SCHEMA_VERSION );

		if ( class_exists( 'WC_Optic_Children' ) ) {
			WC_Optic_Children::maybe_migrate_from_meta();
		}
	}

	/**
	 * Rename legacy catalog type and product meta axe → axis.
	 */
	public static function migrate_axe_to_axis() {
		global $wpdb;

		$table = self::table_catalog();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$table} SET term_type = 'axis' WHERE term_type = 'axe'" );

		$wpdb->update(
			$wpdb->postmeta,
			array( 'meta_key' => '_optic_cat_axis' ),
			array( 'meta_key' => '_optic_cat_axe' ),
			array( '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Ensure product_type taxonomy has optic_product term.
	 */
	public static function ensure_product_type_term() {
		if ( ! taxonomy_exists( 'product_type' ) ) {
			return;
		}
		$slug = 'optic_product';
		if ( term_exists( $slug, 'product_type' ) ) {
			return;
		}
		wp_insert_term(
			__( 'Optic Product', 'wc-optic' ),
			'product_type',
			array(
				'slug' => $slug,
			)
		);
	}

	/**
	 * Full table name.
	 *
	 * @return string
	 */
	public static function table_catalog() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_CATALOG;
	}

	/**
	 * Deletion log table name.
	 *
	 * @return string
	 */
	public static function table_deletion_log() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_DELETION_LOG;
	}

	/**
	 * Internal children table name.
	 *
	 * @return string
	 */
	public static function table_children() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_CHILDREN;
	}
}
