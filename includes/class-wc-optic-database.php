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

	const TABLE_CATALOG     = 'wc_optic_catalog';
	const TABLE_DELETION_LOG = 'wc_optic_catalog_deletion_log';

	/** @var int Bump when adding DB tables or columns; see maybe_upgrade_schema(). */
	const SCHEMA_VERSION = 3;

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
	 * Run lightweight schema upgrades for existing installs.
	 */
	public static function maybe_upgrade_schema() {
		$v = (int) get_option( 'wc_optic_db_schema', 0 );
		if ( $v >= self::SCHEMA_VERSION ) {
			return;
		}
		if ( $v < 2 ) {
			self::create_deletion_log_table();
		}
		if ( $v < 3 ) {
			self::migrate_axe_to_axis();
		}
		update_option( 'wc_optic_db_schema', self::SCHEMA_VERSION );
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
}
