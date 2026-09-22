<?php
/**
 * Inventory tracking and low-stock alerts for optic internal products.
 *
 * @package WC_Optic_Product
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Optic_Stock
 */
class WC_Optic_Stock {

	const GLOBAL_ALERT_ENABLED_OPTION = 'wc_optic_stock_alert_enabled';
	const GLOBAL_ALERT_QTY_OPTION     = 'wc_optic_stock_alert_qty';
	const ALERT_COUNT_TRANSIENT       = 'wc_optic_alert_count_v2';

	/**
	 * Whether low-stock alerts are enabled globally.
	 *
	 * @return bool
	 */
	public static function is_alert_enabled() {
		return 'yes' === (string) get_option( self::GLOBAL_ALERT_ENABLED_OPTION, 'yes' );
	}

	/**
	 * Persist global stock alert enabled flag.
	 *
	 * @param mixed $value Posted value.
	 * @return bool
	 */
	public static function set_alert_enabled( $value ) {
		$enabled = ! empty( $value ) && 'no' !== (string) $value;
		update_option( self::GLOBAL_ALERT_ENABLED_OPTION, $enabled ? 'yes' : 'no', false );
		if ( class_exists( 'WC_Optic_Children' ) ) {
			WC_Optic_Children::recompute_low_stock_flags();
		} else {
			self::bust_alert_count_cache();
		}
		return $enabled;
	}

	/**
	 * Global low-stock alert threshold (physical stock at or below this value triggers an alert).
	 *
	 * @return int
	 */
	public static function get_alert_qty() {
		return max( 0, absint( get_option( self::GLOBAL_ALERT_QTY_OPTION, 5 ) ) );
	}

	/**
	 * Persist global stock alert threshold.
	 *
	 * @param mixed $value Posted value.
	 * @return int
	 */
	public static function set_alert_qty( $value ) {
		$qty = max( 0, absint( $value ) );
		update_option( self::GLOBAL_ALERT_QTY_OPTION, $qty, false );
		if ( class_exists( 'WC_Optic_Children' ) ) {
			WC_Optic_Children::recompute_low_stock_flags();
		} else {
			self::bust_alert_count_cache();
		}
		return $qty;
	}

	/**
	 * Effective alert threshold for one internal product.
	 *
	 * @param array $config Child config.
	 * @return int
	 */
	public static function get_child_alert_qty( array $config ) {
		if ( ! empty( $config['alert_custom'] ) ) {
			return max( 0, absint( $config['alert_qty'] ?? 0 ) );
		}

		return self::get_alert_qty();
	}

	/**
	 * Whether one internal product is at or below the alert threshold.
	 *
	 * @param array $config Child config.
	 * @return bool
	 */
	public static function child_is_low_stock( array $config ) {
		if ( ! self::is_alert_enabled() ) {
			return false;
		}

		$stock = WC_Optic_SKU::get_child_stock_qty( $config );
		if ( null === $stock ) {
			return false;
		}

		return $stock <= self::get_child_alert_qty( $config );
	}

	/**
	 * All optic products for inventory views.
	 *
	 * WPML: default-language originals only (translations are stock mirrors).
	 *
	 * @return WC_Product[]
	 */
	public static function get_optic_products() {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}

		$wpml = class_exists( 'WC_Optic_WPML' ) && WC_Optic_WPML::is_active();
		if ( $wpml ) {
			WC_Optic_WPML::switch_to_default_language();
		}

		try {
			$products = wc_get_products(
				array(
					'type'    => 'optic_product',
					'status'  => array( 'publish', 'draft', 'private' ),
					'limit'   => -1,
					'orderby' => 'title',
					'order'   => 'ASC',
					'return'  => 'objects',
				)
			);
			if ( ! is_array( $products ) ) {
				return array();
			}

			if ( ! $wpml ) {
				return $products;
			}

			$out = array();
			foreach ( $products as $product ) {
				if ( ! $product instanceof WC_Product ) {
					continue;
				}
				if ( ! WC_Optic_WPML::is_original_product( $product->get_id() ) ) {
					continue;
				}
				$out[] = $product;
			}
			return $out;
		} finally {
			if ( $wpml ) {
				WC_Optic_WPML::restore_language();
			}
		}
	}

	/**
	 * Hierarchical inventory tree for the stock management tab (parents only).
	 *
	 * Children are loaded via AJAX when a parent is expanded.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_inventory_tree() {
		$tree           = array();
		$low_by_product = array();
		$use_sql        = class_exists( 'WC_Optic_Children' ) && WC_Optic_Children::table_ready();

		if ( $use_sql ) {
			$low_by_product = WC_Optic_Children::low_stock_counts_by_product();
		}

		foreach ( self::get_optic_products() as $product ) {
			$product_id = absint( $product->get_id() );
			if ( $use_sql && WC_Optic_Children::product_has_rows( $product_id ) ) {
				$child_count = WC_Optic_Children::count_by_product( $product_id, array( 'enabled_only' => true ) );
				$low_count   = isset( $low_by_product[ $product_id ] ) ? (int) $low_by_product[ $product_id ] : 0;
			} else {
				$children = WC_Optic_SKU::get_enabled_child_configs( $product );
				if ( empty( $children ) ) {
					continue;
				}
				$child_count = count( $children );
				$low_count   = 0;
				foreach ( $children as $config ) {
					if ( self::child_is_low_stock( $config ) ) {
						++$low_count;
					}
				}
			}

			if ( $child_count < 1 ) {
				continue;
			}

			$tree[] = array(
				'product_id'  => $product_id,
				'name'        => $product->get_name(),
				'sku'         => (string) $product->get_sku(),
				'edit_url'    => (string) get_edit_post_link( $product_id, 'raw' ),
				'child_count' => $child_count,
				'low_count'   => $low_count,
				'children'    => array(),
			);
		}

		return $tree;
	}

	/**
	 * Paginated enabled children for one parent (Stock management AJAX).
	 *
	 * @param int    $product_id Parent product id.
	 * @param int    $page       Page (1-based).
	 * @param int    $per_page   Page size.
	 * @param string $search     Optional search.
	 * @return array{rows:array,total:int,page:int,per_page:int}|WP_Error
	 */
	public static function get_inventory_children_page( $product_id, $page = 1, $per_page = 50, $search = '' ) {
		$product_id = absint( $product_id );
		$page       = max( 1, (int) $page );
		$per_page   = max( 1, min( 100, (int) $per_page ) );
		$search     = trim( (string) $search );

		$product = wc_get_product( $product_id );
		if ( ! $product || 'optic_product' !== $product->get_type() ) {
			return new WP_Error( 'wc_optic_stock', __( 'Product not found.', 'wc-optic' ) );
		}

		$division = (string) $product->get_meta( '_optic_division', true );
		$rows     = array();
		$total    = 0;

		if ( class_exists( 'WC_Optic_Children' ) && WC_Optic_Children::table_ready() && WC_Optic_Children::product_has_rows( $product_id ) ) {
			$args  = array(
				'page'         => $page,
				'per_page'     => $per_page,
				'enabled_only' => true,
				'search'       => $search,
			);
			$total = WC_Optic_Children::count_by_product( $product_id, $args );
			foreach ( WC_Optic_Children::get_configs( $product_id, $args ) as $config ) {
				$rows[] = self::format_child_row( $product, $config, $division );
			}
		} else {
			$all = WC_Optic_SKU::get_enabled_child_configs( $product );
			if ( '' !== $search ) {
				$needle = strtolower( $search );
				$all    = array_values(
					array_filter(
						$all,
						static function ( $config ) use ( $needle, $division ) {
							$blob = strtolower(
								(string) ( $config['sku'] ?? '' ) . ' ' .
								(string) ( $config['label'] ?? '' ) . ' ' .
								WC_Optic_SKU::child_display_label( $config, $division )
							);
							return false !== strpos( $blob, $needle );
						}
					)
				);
			}
			$total  = count( $all );
			$offset = ( $page - 1 ) * $per_page;
			$slice  = array_slice( $all, $offset, $per_page );
			foreach ( $slice as $config ) {
				$rows[] = self::format_child_row( $product, $config, $division );
			}
		}

		return array(
			'rows'     => $rows,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Format one child row for the management table.
	 *
	 * @param WC_Product $product  Parent product.
	 * @param array      $config   Child config.
	 * @param string     $division Parent division.
	 * @return array<string, mixed>
	 */
	public static function format_child_row( WC_Product $product, array $config, $division ) {
		$stock              = WC_Optic_SKU::get_child_stock_qty( $config );
		$backorder_qty      = WC_Optic_SKU::get_child_backorder_qty( $config );
		$backorder_consumed = WC_Optic_SKU::get_child_backorder_consumed( $config );
		$unit_price         = WC_Optic_SKU::get_child_unit_price( $config );

		return array(
			'child_id'           => (string) ( $config['id'] ?? '' ),
			'product_id'         => $product->get_id(),
			'powers'             => WC_Optic_SKU::child_display_label( $config, $division ),
			'sku'                => (string) ( $config['sku'] ?? '' ),
			'stock'              => null === $stock ? null : (int) $stock,
			'backorder_units'    => (int) $backorder_qty,
			'backorder_consumed' => (int) $backorder_consumed,
			'backorder_custom'   => ! empty( $config['backorder_custom'] ),
			'alert_custom'       => ! empty( $config['alert_custom'] ),
			'price'              => $unit_price,
			'price_html'         => WC_Optic_SKU::format_child_price_html( $config ),
			'is_low'             => self::child_is_low_stock( $config ),
			'alert_threshold'    => self::get_child_alert_qty( $config ),
		);
	}

	/**
	 * Low-stock alert rows for the alerts tab (legacy full scan — prefer get_alerts_page).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_alerts() {
		$page = self::get_alerts_page( 1, 500, '' );
		return is_wp_error( $page ) ? array() : ( $page['rows'] ?? array() );
	}

	/**
	 * Paginated low-stock alerts (QR generated for this page only).
	 *
	 * @param int    $page     Page.
	 * @param int    $per_page Per page.
	 * @param string $search   Search.
	 * @return array{rows:array,total:int,page:int,per_page:int}
	 */
	public static function get_alerts_page( $page = 1, $per_page = 25, $search = '' ) {
		$page     = max( 1, (int) $page );
		$per_page = max( 1, min( 100, (int) $per_page ) );
		$search   = trim( (string) $search );
		$alerts   = array();
		$total    = 0;

		if ( ! self::is_alert_enabled() ) {
			return array(
				'rows'     => array(),
				'total'    => 0,
				'page'     => $page,
				'per_page' => $per_page,
			);
		}

		if ( class_exists( 'WC_Optic_Children' ) && WC_Optic_Children::table_ready() ) {
			$result = WC_Optic_Children::query_low_stock(
				array(
					'page'     => $page,
					'per_page' => $per_page,
					'search'   => $search,
				)
			);
			$total = (int) ( $result['total'] ?? 0 );
			$cache = array();
			foreach ( $result['rows'] as $db_row ) {
				$pid = absint( $db_row->product_id ?? 0 );
				if ( ! isset( $cache[ $pid ] ) ) {
					$product = wc_get_product( $pid );
					$cache[ $pid ] = array(
						'product'  => $product,
						'division' => $product instanceof WC_Product ? (string) $product->get_meta( '_optic_division', true ) : '',
					);
				}
				$product = $cache[ $pid ]['product'];
				if ( ! $product instanceof WC_Product ) {
					continue;
				}
				$config = WC_Optic_Children::row_to_config( $db_row );
				if ( ! $config ) {
					continue;
				}
				$row = self::format_child_row( $product, $config, $cache[ $pid ]['division'] );
				$sku = (string) ( $config['sku'] ?? '' );
				$alerts[] = array_merge(
					$row,
					array(
						'product_name' => $product->get_name(),
						'qr_html'      => WC_Optic_QR::render_admin_block( $sku, '', 80 ),
					)
				);
			}
		} else {
			$all = array();
			foreach ( self::get_optic_products() as $product ) {
				$division = (string) $product->get_meta( '_optic_division', true );
				foreach ( WC_Optic_SKU::get_enabled_child_configs( $product ) as $config ) {
					if ( ! self::child_is_low_stock( $config ) ) {
						continue;
					}
					$row = self::format_child_row( $product, $config, $division );
					$sku = (string) ( $config['sku'] ?? '' );
					if ( '' !== $search ) {
						$blob = strtolower( $sku . ' ' . $product->get_name() . ' ' . ( $row['powers'] ?? '' ) );
						if ( false === strpos( $blob, strtolower( $search ) ) ) {
							continue;
						}
					}
					$all[] = array_merge(
						$row,
						array(
							'product_name' => $product->get_name(),
							'qr_html'      => '',
							'_sku'         => $sku,
						)
					);
				}
			}
			$total  = count( $all );
			$offset = ( $page - 1 ) * $per_page;
			$slice  = array_slice( $all, $offset, $per_page );
			foreach ( $slice as $row ) {
				$sku              = (string) ( $row['_sku'] ?? $row['sku'] ?? '' );
				unset( $row['_sku'] );
				$row['qr_html'] = WC_Optic_QR::render_admin_block( $sku, '', 80 );
				$alerts[]       = $row;
			}
		}

		return array(
			'rows'     => $alerts,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Count of internal products currently in alert state (cached for admin badges).
	 *
	 * @return int
	 */
	public static function get_alert_count() {
		$cached = get_transient( self::ALERT_COUNT_TRANSIENT );
		if ( false !== $cached && is_numeric( $cached ) ) {
			return max( 0, (int) $cached );
		}

		$count = self::count_low_stock_alerts();
		set_transient( self::ALERT_COUNT_TRANSIENT, $count, HOUR_IN_SECONDS );
		return $count;
	}

	/**
	 * Clear cached alert count (call after stock / child config changes).
	 */
	public static function bust_alert_count_cache() {
		delete_transient( self::ALERT_COUNT_TRANSIENT );
	}

	/**
	 * Count low-stock internals without building alert UI / QR markup.
	 *
	 * @return int
	 */
	public static function count_low_stock_alerts() {
		if ( ! self::is_alert_enabled() ) {
			return 0;
		}

		if ( class_exists( 'WC_Optic_Children' ) && WC_Optic_Children::table_ready() ) {
			return WC_Optic_Children::count_low_stock_global();
		}

		$count = 0;
		foreach ( self::get_optic_products() as $product ) {
			foreach ( WC_Optic_SKU::get_enabled_child_configs( $product ) as $config ) {
				if ( self::child_is_low_stock( $config ) ) {
					++$count;
				}
			}
		}

		return $count;
	}

	/**
	 * Add units to one internal product's physical stock.
	 *
	 * @param int    $product_id      Parent product ID.
	 * @param string $child_id        Child config ID.
	 * @param int    $qty             Units to add.
	 * @param bool   $reset_backorder Whether to clear consumed backorder units.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function restock_child( $product_id, $child_id, $qty, $reset_backorder = false ) {
		$product_id = absint( $product_id );
		$child_id   = sanitize_key( (string) $child_id );
		$qty        = absint( $qty );

		if ( $product_id < 1 || '' === $child_id || $qty < 1 ) {
			return new WP_Error( 'wc_optic_stock', __( 'Invalid restock request.', 'wc-optic' ) );
		}

		// Always restock the WPML original; translations receive the stock via sync.
		if ( class_exists( 'WC_Optic_WPML' ) && WC_Optic_WPML::is_active() ) {
			$original_id = WC_Optic_WPML::get_original_product_id( $product_id );
			if ( $original_id > 0 ) {
				$product_id = $original_id;
			}
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || 'optic_product' !== $product->get_type() ) {
			return new WP_Error( 'wc_optic_stock', __( 'Product not found.', 'wc-optic' ) );
		}

		$config = WC_Optic_SKU::get_child_config_by_id( $product, $child_id );
		if ( ! is_array( $config ) ) {
			return new WP_Error( 'wc_optic_stock', __( 'Internal product not found.', 'wc-optic' ) );
		}

		$current = WC_Optic_SKU::get_child_stock_qty( $config );
		if ( null === $current ) {
			$config['stock_qty'] = (string) $qty;
		} else {
			$config['stock_qty'] = (string) ( $current + $qty );
		}

		if ( $reset_backorder ) {
			$config['backorder_consumed'] = '0';
		}

		if ( class_exists( 'WC_Optic_Children' ) && WC_Optic_Children::table_ready() && WC_Optic_Children::product_has_rows( $product_id ) ) {
			WC_Optic_Children::upsert_child( $product_id, $config );
			self::bust_alert_count_cache();
		} else {
			$configs = WC_Optic_SKU::get_child_configs( $product );
			$found   = false;
			foreach ( $configs as $index => $existing ) {
				if ( (string) ( $existing['id'] ?? '' ) !== $child_id ) {
					continue;
				}
				$configs[ $index ] = $config;
				$found             = true;
				break;
			}
			if ( ! $found ) {
				return new WP_Error( 'wc_optic_stock', __( 'Internal product not found.', 'wc-optic' ) );
			}
			WC_Optic_SKU::persist_child_data( $product, $configs );
			$product->save();
		}

		if ( class_exists( 'WC_Optic_WPML' ) && WC_Optic_WPML::is_active() ) {
			WC_Optic_WPML::sync_child_stock_to_translations( $product_id, $config );
		}

		$new_stock = WC_Optic_SKU::get_child_stock_qty( $config );

		return array(
			'stock'              => null === $new_stock ? 0 : (int) $new_stock,
			'is_low'             => self::child_is_low_stock( $config ),
			'alert_count'        => self::get_alert_count(),
			'backorder_consumed' => WC_Optic_SKU::get_child_backorder_consumed( $config ),
			'backorder_reset'    => (bool) $reset_backorder,
		);
	}
}
