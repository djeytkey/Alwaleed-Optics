<?php
/**
 * SQL storage for optic internal products (children).
 *
 * @package WC_Optic_Product
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Optic_Children
 */
class WC_Optic_Children {

	const MIGRATE_OPTION = 'wc_optic_children_migrated';
	const MIGRATE_CURSOR = 'wc_optic_children_migrate_cursor';

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		return WC_Optic_Database::table_children();
	}

	/**
	 * Whether SQL table exists and is ready.
	 *
	 * @return bool
	 */
	public static function table_ready() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $found === $table;
	}

	/**
	 * Count rows for one product.
	 *
	 * @param int   $product_id Product id.
	 * @param array $args       Optional filters: enabled_only, low_stock_only, search.
	 * @return int
	 */
	public static function count_by_product( $product_id, array $args = array() ) {
		global $wpdb;
		$product_id = absint( $product_id );
		if ( $product_id < 1 || ! self::table_ready() ) {
			return 0;
		}

		$table  = self::table();
		$where  = array( 'product_id = %d' );
		$params = array( $product_id );

		if ( ! empty( $args['enabled_only'] ) ) {
			$where[] = 'enabled = 1';
		}
		if ( ! empty( $args['low_stock_only'] ) ) {
			$where[] = 'is_low_stock = 1';
		}
		$search = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
		if ( '' !== $search ) {
			$where[]  = '(sku LIKE %s OR search_blob LIKE %s OR label LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$sql = 'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . implode( ' AND ', $where );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Global low-stock count (enabled rows).
	 *
	 * When WPML is active, only default-language originals are counted
	 * (translation copies are excluded from the badge / alerts total).
	 *
	 * @return int
	 */
	public static function count_low_stock_global() {
		global $wpdb;
		if ( ! self::table_ready() ) {
			return 0;
		}
		$table  = self::table();
		$filter = class_exists( 'WC_Optic_WPML' ) ? WC_Optic_WPML::sql_original_product_filter( 'c.product_id' ) : array( 'join' => '', 'where' => '' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT COUNT(*) FROM {$table} c {$filter['join']} WHERE c.enabled = 1 AND c.is_low_stock = 1 {$filter['where']}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Whether product has at least one enabled sellable child (SQL, no full hydrate).
	 *
	 * @param int $product_id Product id.
	 * @return bool
	 */
	public static function product_has_sellable_rows( $product_id ) {
		global $wpdb;
		$product_id = absint( $product_id );
		if ( $product_id < 1 || ! self::table_ready() ) {
			return false;
		}
		$table = self::table();
		// Sellable: unmanaged stock OR remaining stock/backorder > 0.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				WHERE product_id = %d AND enabled = 1
				AND (
					stock_qty IS NULL
					OR (stock_qty + IF(backorder_custom = 1, backorder_qty, 0) - backorder_consumed) > 0
				)
				LIMIT 1",
				$product_id
			)
		);
		return ! empty( $found );
	}

	/**
	 * Count enabled children for a product.
	 *
	 * @param int $product_id Product id.
	 * @return int
	 */
	public static function count_enabled( $product_id ) {
		return self::count_by_product( $product_id, array( 'enabled_only' => true ) );
	}

	/**
	 * Count enabled sellable children (ignores cart reservations).
	 *
	 * @param int $product_id Product id.
	 * @return int
	 */
	public static function count_sellable( $product_id ) {
		global $wpdb;
		$product_id = absint( $product_id );
		if ( $product_id < 1 || ! self::table_ready() ) {
			return 0;
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				WHERE product_id = %d AND enabled = 1
				AND (
					stock_qty IS NULL
					OR (stock_qty + IF(backorder_custom = 1, backorder_qty, 0) - backorder_consumed) > 0
				)",
				$product_id
			)
		);
	}

	/**
	 * Whether product has any SQL children.
	 *
	 * @param int $product_id Product id.
	 * @return bool
	 */
	public static function product_has_rows( $product_id ) {
		global $wpdb;
		$product_id = absint( $product_id );
		if ( $product_id < 1 || ! self::table_ready() ) {
			return false;
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE product_id = %d LIMIT 1",
				$product_id
			)
		);
		return ! empty( $found );
	}

	/**
	 * Fetch one config by child_key.
	 *
	 * @param int    $product_id Product id.
	 * @param string $child_key  Child key.
	 * @return array<string, mixed>|null
	 */
	public static function get_config_by_key( $product_id, $child_key ) {
		global $wpdb;
		$product_id = absint( $product_id );
		$child_key  = sanitize_key( (string) $child_key );
		if ( $product_id < 1 || '' === $child_key || ! self::table_ready() ) {
			return null;
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE product_id = %d AND child_key = %s LIMIT 1",
				$product_id,
				$child_key
			)
		);
		return $row ? self::row_to_config( $row ) : null;
	}

	/**
	 * Recompute denormalized is_low_stock from stock + alert settings.
	 */
	public static function recompute_low_stock_flags() {
		global $wpdb;
		if ( ! self::table_ready() ) {
			return;
		}
		$table = self::table();
		if ( ! WC_Optic_Stock::is_alert_enabled() ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "UPDATE {$table} SET is_low_stock = 0" );
			WC_Optic_SKU::bust_alert_count_cache();
			return;
		}

		$global = WC_Optic_Stock::get_alert_qty();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET is_low_stock = CASE
					WHEN stock_qty IS NULL THEN 0
					WHEN alert_custom = 1 AND stock_qty <= COALESCE(alert_qty, 0) THEN 1
					WHEN alert_custom = 0 AND stock_qty <= %d THEN 1
					ELSE 0
				END",
				$global
			)
		);
		WC_Optic_SKU::bust_alert_count_cache();
	}

	/**
	 * Fetch configs for a product (ordered).
	 *
	 * @param int   $product_id Product id.
	 * @param array $args       page, per_page, enabled_only, low_stock_only, search.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_configs( $product_id, array $args = array() ) {
		$rows = self::query_rows( $product_id, $args );
		$out  = array();
		foreach ( $rows as $row ) {
			$config = self::row_to_config( $row );
			if ( $config ) {
				$out[] = $config;
			}
		}
		return $out;
	}

	/**
	 * Query DB rows for one product.
	 *
	 * @param int   $product_id Product id.
	 * @param array $args       Filters + pagination.
	 * @return array<int, object>
	 */
	public static function query_rows( $product_id, array $args = array() ) {
		global $wpdb;
		$product_id = absint( $product_id );
		if ( $product_id < 1 || ! self::table_ready() ) {
			return array();
		}

		$defaults = array(
			'page'           => 1,
			'per_page'       => 0,
			'enabled_only'   => false,
			'low_stock_only' => false,
			'search'         => '',
		);
		$args     = wp_parse_args( $args, $defaults );

		$table  = self::table();
		$where  = array( 'product_id = %d' );
		$params = array( $product_id );

		if ( ! empty( $args['enabled_only'] ) ) {
			$where[] = 'enabled = 1';
		}
		if ( ! empty( $args['low_stock_only'] ) ) {
			$where[] = 'is_low_stock = 1';
		}
		$search = trim( (string) $args['search'] );
		if ( '' !== $search ) {
			$where[]  = '(sku LIKE %s OR search_blob LIKE %s OR label LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$sql = 'SELECT * FROM ' . $table . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY sort_order ASC, id ASC';

		$per_page = (int) $args['per_page'];
		if ( $per_page > 0 ) {
			$page     = max( 1, (int) $args['page'] );
			$offset   = ( $page - 1 ) * $per_page;
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = $per_page;
			$params[] = $offset;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
		return is_array( $results ) ? $results : array();
	}

	/**
	 * Global low-stock rows (paginated).
	 *
	 * @param array $args page, per_page, search.
	 * @return array{rows:array,total:int}
	 */
	public static function query_low_stock( array $args = array() ) {
		global $wpdb;
		if ( ! self::table_ready() ) {
			return array(
				'rows'  => array(),
				'total' => 0,
			);
		}

		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 25 ) ) );
		$search   = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
		$table    = self::table();
		$filter   = class_exists( 'WC_Optic_WPML' ) ? WC_Optic_WPML::sql_original_product_filter( 'c.product_id' ) : array( 'join' => '', 'where' => '' );

		$where  = array( 'c.enabled = 1', 'c.is_low_stock = 1' );
		$params = array();
		if ( '' !== $search ) {
			$where[]  = '(c.sku LIKE %s OR c.search_blob LIKE %s OR c.label LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where ) . $filter['where'];
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count_sql = "SELECT COUNT(*) FROM {$table} c {$filter['join']} WHERE {$where_sql}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$total = $params ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : (int) $wpdb->get_var( $count_sql );

		$offset = ( $page - 1 ) * $per_page;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$list_sql = "SELECT c.* FROM {$table} c {$filter['join']} WHERE {$where_sql} ORDER BY c.product_id ASC, c.sort_order ASC, c.id ASC LIMIT %d OFFSET %d";
		$params[] = $per_page;
		$params[] = $offset;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, $params ) );

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Parent product ids that have at least one enabled child.
	 *
	 * @return int[]
	 */
	public static function product_ids_with_children() {
		global $wpdb;
		if ( ! self::table_ready() ) {
			return array();
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( "SELECT DISTINCT product_id FROM {$table} WHERE enabled = 1 ORDER BY product_id ASC" );
		return array_map( 'absint', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Low-stock counts keyed by product_id.
	 *
	 * @return array<int, int>
	 */
	public static function low_stock_counts_by_product() {
		global $wpdb;
		if ( ! self::table_ready() ) {
			return array();
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT product_id, COUNT(*) AS c FROM {$table} WHERE enabled = 1 AND is_low_stock = 1 GROUP BY product_id" );
		$out  = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$out[ (int) $row->product_id ] = (int) $row->c;
			}
		}
		return $out;
	}

	/**
	 * Replace all children for a product (Convert / rebuild / persist).
	 *
	 * @param int   $product_id Product id.
	 * @param array $configs    Normalized configs.
	 * @return int Rows written.
	 */
	public static function replace_product_children( $product_id, array $configs ) {
		global $wpdb;
		$product_id = absint( $product_id );
		if ( $product_id < 1 || ! self::table_ready() ) {
			return 0;
		}

		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->delete( $table, array( 'product_id' => $product_id ), array( '%d' ) );

		$count = 0;
		$division = '';
		$product  = wc_get_product( $product_id );
		if ( $product instanceof WC_Product ) {
			$division = (string) $product->get_meta( '_optic_division', true );
		}
		foreach ( array_values( $configs ) as $index => $config ) {
			if ( ! is_array( $config ) ) {
				continue;
			}
			if ( self::insert_config_row( $product_id, $config, $index, $division ) ) {
				++$count;
			}
		}

		WC_Optic_SKU::bust_alert_count_cache();
		WC_Optic_SKU::clear_runtime_caches( $product_id );
		return $count;
	}

	/**
	 * Upsert one child row.
	 *
	 * @param int   $product_id Product id.
	 * @param array $config     Config.
	 * @return bool
	 */
	public static function upsert_child( $product_id, array $config ) {
		global $wpdb;
		$product_id = absint( $product_id );
		$child_key  = sanitize_key( (string) ( $config['id'] ?? '' ) );
		if ( $product_id < 1 || '' === $child_key || ! self::table_ready() ) {
			return false;
		}

		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE product_id = %d AND child_key = %s",
				$product_id,
				$child_key
			)
		);

		$row = self::config_to_row( $product_id, $config, (int) ( $config['sort'] ?? 0 ) );
		if ( $existing ) {
			unset( $row['product_id'], $row['child_key'] );
			$ok = false !== $wpdb->update(
				$table,
				$row,
				array(
					'product_id' => $product_id,
					'child_key'  => $child_key,
				)
			);
		} else {
			$ok = false !== $wpdb->insert( $table, $row );
		}

		if ( $ok ) {
			WC_Optic_SKU::bust_alert_count_cache();
			WC_Optic_SKU::clear_runtime_caches( $product_id );
		}
		return (bool) $ok;
	}

	/**
	 * Delete one child.
	 *
	 * @param int    $product_id Product id.
	 * @param string $child_key  Child key.
	 * @return bool
	 */
	public static function delete_child( $product_id, $child_key ) {
		global $wpdb;
		$product_id = absint( $product_id );
		$child_key  = sanitize_key( (string) $child_key );
		if ( $product_id < 1 || '' === $child_key || ! self::table_ready() ) {
			return false;
		}
		$table = self::table();
		$ok    = false !== $wpdb->delete(
			$table,
			array(
				'product_id' => $product_id,
				'child_key'  => $child_key,
			),
			array( '%d', '%s' )
		);
		if ( $ok ) {
			WC_Optic_SKU::bust_alert_count_cache();
			WC_Optic_SKU::clear_runtime_caches( $product_id );
		}
		return (bool) $ok;
	}

	/**
	 * Delete all children for a product.
	 *
	 * @param int $product_id Product id.
	 * @return int
	 */
	public static function delete_by_product( $product_id ) {
		global $wpdb;
		$product_id = absint( $product_id );
		if ( $product_id < 1 || ! self::table_ready() ) {
			return 0;
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->delete( $table, array( 'product_id' => $product_id ), array( '%d' ) );
		WC_Optic_SKU::bust_alert_count_cache();
		WC_Optic_SKU::clear_runtime_caches( $product_id );
		return (int) $deleted;
	}

	/**
	 * Copy all children from one product to another (WPML sync).
	 *
	 * @param int $source_id Source product.
	 * @param int $target_id Target product.
	 * @return int
	 */
	public static function copy_product_children( $source_id, $target_id ) {
		$configs = self::get_configs( absint( $source_id ) );
		return self::replace_product_children( absint( $target_id ), $configs );
	}

	/**
	 * Insert one config as a row.
	 *
	 * @param int    $product_id Product id.
	 * @param array  $config     Config.
	 * @param int    $index      Sort fallback.
	 * @param string $division   Optional division slug.
	 * @return bool
	 */
	protected static function insert_config_row( $product_id, array $config, $index, $division = '' ) {
		global $wpdb;
		$row = self::config_to_row( $product_id, $config, $index, $division );
		return false !== $wpdb->insert( self::table(), $row );
	}

	/**
	 * Map config array → DB row.
	 *
	 * @param int    $product_id Product id.
	 * @param array  $config     Config.
	 * @param int    $index      Sort fallback.
	 * @param string $division   Optional division slug.
	 * @return array<string, mixed>
	 */
	public static function config_to_row( $product_id, array $config, $index = 0, $division = '' ) {
		$child_key = sanitize_key( (string) ( $config['id'] ?? '' ) );
		if ( '' === $child_key ) {
			$child_key = 'child_' . wp_generate_password( 8, false, false );
			$config['id'] = $child_key;
		}

		$stock_raw = isset( $config['stock_qty'] ) ? trim( (string) $config['stock_qty'] ) : '';
		$stock_qty = ( '' === $stock_raw ) ? null : absint( $stock_raw );

		$unit_price = 0.0;
		if ( isset( $config['unit_price'] ) && '' !== trim( (string) $config['unit_price'] ) ) {
			$unit_price = (float) wc_format_decimal( $config['unit_price'] );
		}

		$powers  = isset( $config['powers'] ) && is_array( $config['powers'] ) ? $config['powers'] : array();
		$sph_id  = absint( $powers['sph'] ?? 0 );
		$cyl_id  = absint( $powers['cyl'] ?? 0 );
		$axis_id = absint( $powers['axis'] ?? 0 );
		$add_id  = absint( $powers['add'] ?? 0 );

		if ( '' === $division ) {
			$product = wc_get_product( $product_id );
			if ( $product instanceof WC_Product ) {
				$division = (string) $product->get_meta( '_optic_division', true );
			}
		}
		$powers_label = WC_Optic_SKU::child_display_label( $config, $division );
		$sku          = (string) ( $config['sku'] ?? '' );
		$label        = (string) ( $config['label'] ?? '' );
		$search_blob  = strtolower( trim( $sku . ' ' . $label . ' ' . $powers_label ) );

		$is_low = WC_Optic_Stock::child_is_low_stock( $config ) ? 1 : 0;

		return array(
			'product_id'         => absint( $product_id ),
			'child_key'          => $child_key,
			'sku'                => substr( $sku, 0, 191 ),
			'label'              => substr( $label, 0, 255 ),
			'enabled'            => empty( $config['enabled'] ) ? 0 : 1,
			'sort_order'         => isset( $config['sort'] ) ? (int) $config['sort'] : (int) $index,
			'unit_price'         => $unit_price,
			'stock_qty'          => $stock_qty,
			'backorder_custom'   => ! empty( $config['backorder_custom'] ) ? 1 : 0,
			'backorder_qty'      => absint( $config['backorder_qty'] ?? 0 ),
			'backorder_consumed' => absint( $config['backorder_consumed'] ?? 0 ),
			'alert_custom'       => ! empty( $config['alert_custom'] ) ? 1 : 0,
			'alert_qty'          => ( isset( $config['alert_qty'] ) && '' !== trim( (string) $config['alert_qty'] ) ) ? absint( $config['alert_qty'] ) : null,
			'sph_id'             => $sph_id,
			'cyl_id'             => $cyl_id,
			'axis_id'            => $axis_id,
			'add_id'             => $add_id,
			'search_blob'        => substr( $search_blob, 0, 512 ),
			'is_low_stock'       => $is_low,
			'config_json'        => wp_json_encode( $config ),
			'updated_at'         => current_time( 'mysql' ),
		);
	}

	/**
	 * Map DB row → config array.
	 *
	 * @param object $row DB row.
	 * @return array<string, mixed>|null
	 */
	public static function row_to_config( $row ) {
		if ( ! is_object( $row ) ) {
			return null;
		}
		$json = isset( $row->config_json ) ? (string) $row->config_json : '';
		if ( $json ) {
			$decoded = json_decode( $json, true );
			if ( is_array( $decoded ) && ! empty( $decoded['id'] ) ) {
				// Normalize sale so stale/invalid values never keep a strikethrough.
				if ( empty( $decoded['sale_price'] ) || '' === trim( (string) $decoded['sale_price'] ) ) {
					$decoded['sale_price'] = '';
				} else {
					$regular = isset( $decoded['unit_price'] ) ? (float) wc_format_decimal( $decoded['unit_price'] ) : 0.0;
					$sale    = (float) wc_format_decimal( $decoded['sale_price'] );
					if ( $regular <= 0 || $sale < 0 || $sale >= $regular ) {
						$decoded['sale_price'] = '';
					} else {
						$decoded['sale_price'] = (string) wc_format_decimal( $sale );
					}
				}
				return $decoded;
			}
		}

		// Fallback rebuild if JSON missing.
		return array(
			'id'                 => (string) $row->child_key,
			'label'              => (string) $row->label,
			'enabled'            => ! empty( $row->enabled ),
			'sort'               => (int) $row->sort_order,
			'unit_price'         => (string) $row->unit_price,
			'stock_qty'          => null === $row->stock_qty ? '' : (string) $row->stock_qty,
			'backorder_custom'   => ! empty( $row->backorder_custom ),
			'backorder_qty'      => (string) $row->backorder_qty,
			'backorder_consumed' => (string) $row->backorder_consumed,
			'alert_custom'       => ! empty( $row->alert_custom ),
			'alert_qty'          => null === $row->alert_qty ? '' : (string) $row->alert_qty,
			'sku'                => (string) $row->sku,
			'powers'             => array(
				'sph'  => (int) $row->sph_id,
				'cyl'  => (int) $row->cyl_id,
				'axis' => (int) $row->axis_id,
				'add'  => (int) $row->add_id,
			),
			'catalog'            => array(),
		);
	}

	/**
	 * Migrate `_optic_child_configs` meta into SQL (resumable batches).
	 */
	public static function maybe_migrate_from_meta() {
		if ( ! self::table_ready() ) {
			return;
		}
		if ( get_option( self::MIGRATE_OPTION ) ) {
			return;
		}

		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 20,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'offset'         => absint( get_option( self::MIGRATE_CURSOR, 0 ) ),
				'tax_query'      => array(
					array(
						'taxonomy' => 'product_type',
						'field'    => 'slug',
						'terms'    => array( 'optic_product' ),
					),
				),
			)
		);

		if ( ! is_array( $ids ) || ! $ids ) {
			update_option( self::MIGRATE_OPTION, '1', false );
			delete_option( self::MIGRATE_CURSOR );
			return;
		}

		$offset = absint( get_option( self::MIGRATE_CURSOR, 0 ) );
		foreach ( $ids as $product_id ) {
			self::migrate_product_from_meta( (int) $product_id );
			++$offset;
		}
		update_option( self::MIGRATE_CURSOR, $offset, false );

		// If we got a full batch, more remain; otherwise done.
		if ( count( $ids ) < 20 ) {
			update_option( self::MIGRATE_OPTION, '1', false );
			delete_option( self::MIGRATE_CURSOR );
		}
	}

	/**
	 * Migrate one product's meta blob to SQL and drop the blob.
	 *
	 * @param int $product_id Product id.
	 */
	public static function migrate_product_from_meta( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id < 1 ) {
			return;
		}

		// Already in SQL?
		if ( self::product_has_rows( $product_id ) ) {
			delete_post_meta( $product_id, WC_Optic_SKU::CHILD_META_KEY );
			$count = self::count_by_product( $product_id );
			update_post_meta( $product_id, WC_Optic_SKU::CHILD_COUNT_META_KEY, $count );
			return;
		}

		$stored = get_post_meta( $product_id, WC_Optic_SKU::CHILD_META_KEY, true );
		if ( ! is_array( $stored ) || ! $stored ) {
			update_post_meta( $product_id, WC_Optic_SKU::CHILD_COUNT_META_KEY, 0 );
			return;
		}

		$product = wc_get_product( $product_id );
		$division = $product instanceof WC_Product ? (string) $product->get_meta( '_optic_division', true ) : '';
		$configs  = WC_Optic_SKU::normalize_child_configs( $stored, $division );
		self::replace_product_children( $product_id, $configs );
		delete_post_meta( $product_id, WC_Optic_SKU::CHILD_META_KEY );
		update_post_meta( $product_id, WC_Optic_SKU::CHILD_COUNT_META_KEY, count( $configs ) );
	}
}
