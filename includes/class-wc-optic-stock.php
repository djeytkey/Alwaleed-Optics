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
	 * @return WC_Product[]
	 */
	public static function get_optic_products() {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}

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

		return is_array( $products ) ? $products : array();
	}

	/**
	 * Hierarchical inventory tree for the stock management tab.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_inventory_tree() {
		$tree = array();

		foreach ( self::get_optic_products() as $product ) {
			$children = WC_Optic_SKU::get_enabled_child_configs( $product );
			if ( empty( $children ) ) {
				continue;
			}

			$division   = (string) $product->get_meta( '_optic_division', true );
			$child_rows = array();

			$low_count = 0;

			foreach ( $children as $config ) {
				$row = self::format_child_row( $product, $config, $division );
				if ( ! empty( $row['is_low'] ) ) {
					++$low_count;
				}
				$child_rows[] = $row;
			}

			$tree[] = array(
				'product_id'  => $product->get_id(),
				'name'        => $product->get_name(),
				'sku'         => (string) $product->get_sku(),
				'edit_url'    => (string) get_edit_post_link( $product->get_id(), 'raw' ),
				'child_count' => count( $child_rows ),
				'low_count'   => $low_count,
				'children'    => $child_rows,
			);
		}

		return $tree;
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
			'child_id'            => (string) ( $config['id'] ?? '' ),
			'product_id'          => $product->get_id(),
			'powers'              => WC_Optic_SKU::child_display_label( $config, $division ),
			'sku'                 => (string) ( $config['sku'] ?? '' ),
			'stock'               => null === $stock ? null : (int) $stock,
			'backorder_units'     => (int) $backorder_qty,
			'backorder_consumed'  => (int) $backorder_consumed,
			'backorder_custom'    => ! empty( $config['backorder_custom'] ),
			'alert_custom'        => ! empty( $config['alert_custom'] ),
			'price'               => $unit_price,
			'price_html'          => wc_price( $unit_price ),
			'is_low'              => self::child_is_low_stock( $config ),
			'alert_threshold'     => self::get_child_alert_qty( $config ),
		);
	}

	/**
	 * Low-stock alert rows for the alerts tab.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_alerts() {
		$alerts = array();

		foreach ( self::get_optic_products() as $product ) {
			$division = (string) $product->get_meta( '_optic_division', true );

			foreach ( WC_Optic_SKU::get_enabled_child_configs( $product ) as $config ) {
				if ( ! self::child_is_low_stock( $config ) ) {
					continue;
				}

				$sku = (string) ( $config['sku'] ?? '' );
				$row = self::format_child_row( $product, $config, $division );

				$alerts[] = array_merge(
					$row,
					array(
						'product_name' => $product->get_name(),
						'qr_html'      => WC_Optic_QR::render_admin_block( $sku, '', 80 ),
					)
				);
			}
		}

		return $alerts;
	}

	/**
	 * Count of internal products currently in alert state.
	 *
	 * @return int
	 */
	public static function get_alert_count() {
		return count( self::get_alerts() );
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

		$product = wc_get_product( $product_id );
		if ( ! $product || 'optic_product' !== $product->get_type() ) {
			return new WP_Error( 'wc_optic_stock', __( 'Product not found.', 'wc-optic' ) );
		}

		$configs        = WC_Optic_SKU::get_child_configs( $product );
		$found          = false;
		$updated_config = null;

		foreach ( $configs as $index => $config ) {
			if ( (string) ( $config['id'] ?? '' ) !== $child_id ) {
				continue;
			}

			$current = WC_Optic_SKU::get_child_stock_qty( $config );
			if ( null === $current ) {
				$configs[ $index ]['stock_qty'] = (string) $qty;
			} else {
				$configs[ $index ]['stock_qty'] = (string) ( $current + $qty );
			}

			if ( $reset_backorder ) {
				$configs[ $index ]['backorder_consumed'] = '0';
			}

			$updated_config = $configs[ $index ];
			$found          = true;
			break;
		}

		if ( ! $found || ! is_array( $updated_config ) ) {
			return new WP_Error( 'wc_optic_stock', __( 'Internal product not found.', 'wc-optic' ) );
		}

		WC_Optic_SKU::persist_child_data( $product, $configs );
		$product->save();

		$new_stock = WC_Optic_SKU::get_child_stock_qty( $updated_config );

		return array(
			'stock'              => null === $new_stock ? 0 : (int) $new_stock,
			'is_low'             => self::child_is_low_stock( $updated_config ),
			'alert_count'        => self::get_alert_count(),
			'backorder_consumed' => WC_Optic_SKU::get_child_backorder_consumed( $updated_config ),
			'backorder_reset'    => (bool) $reset_backorder,
		);
	}
}
