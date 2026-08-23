<?php
/**
 * Convert simple products into optic products with generated internals.
 *
 * @package WC_Optic_Product
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Optic_Converter
 */
class WC_Optic_Converter {

	const BATCH_SIZE = 5;

	/**
	 * Max products loaded on the Convert screen (-1 = no cap).
	 */
	const CONVERT_LIST_LIMIT = -1;

	/**
	 * Counts for the Convert product list (simple totals vs eligible vs displayed).
	 *
	 * @return array{total_simple:int, eligible:int, excluded_wpml:int, excluded_ineligible:int}
	 */
	public static function get_convert_stats() {
		$wpml = class_exists( 'WC_Optic_WPML' ) && WC_Optic_WPML::is_active();
		if ( $wpml ) {
			WC_Optic_WPML::switch_to_default_language();
		}

		try {
			$ids = wc_get_products(
				array(
					'type'   => 'simple',
					'status' => array( 'publish', 'draft', 'private' ),
					'limit'  => -1,
					'return' => 'ids',
				)
			);
			if ( ! is_array( $ids ) ) {
				$ids = array();
			}

			$stats = array(
				'total_simple'        => count( $ids ),
				'eligible'            => 0,
				'excluded_wpml'       => 0,
				'excluded_ineligible' => 0,
			);

			foreach ( $ids as $product_id ) {
				$product_id = absint( $product_id );
				if ( ! $product_id ) {
					continue;
				}
				if ( $wpml && ! WC_Optic_WPML::is_original_product( $product_id ) ) {
					++$stats['excluded_wpml'];
					continue;
				}
				$product = wc_get_product( $product_id );
				if ( ! $product instanceof WC_Product || ! self::is_eligible( $product ) ) {
					++$stats['excluded_ineligible'];
					continue;
				}
				++$stats['eligible'];
			}

			return $stats;
		} finally {
			if ( $wpml ) {
				WC_Optic_WPML::restore_language();
			}
		}
	}

	/**
	 * Query simple products eligible for conversion.
	 *
	 * @param array $args Query args.
	 * @return WC_Product[]
	 */
	public static function get_eligible_products( array $args = array() ) {
		$defaults = array(
			'limit'  => 50,
			'page'   => 1,
			'search' => '',
		);
		$args     = wp_parse_args( $args, $defaults );

		$limit = (int) $args['limit'];
		if ( -1 !== $limit ) {
			$limit = max( 1, absint( $limit ) );
		}

		$query_args = array(
			'type'    => 'simple',
			'status'  => array( 'publish', 'draft', 'private' ),
			'limit'   => $limit,
			'page'    => max( 1, absint( $args['page'] ) ),
			'orderby' => 'title',
			'order'   => 'ASC',
			'return'  => 'objects',
		);

		$search = trim( (string) $args['search'] );
		if ( '' !== $search ) {
			$query_args['s'] = $search;
		}

		$wpml = class_exists( 'WC_Optic_WPML' ) && WC_Optic_WPML::is_active();
		if ( $wpml ) {
			WC_Optic_WPML::switch_to_default_language();
		}

		try {
			$products = wc_get_products( $query_args );
			if ( ! is_array( $products ) ) {
				return array();
			}

			$out = array();
			foreach ( $products as $product ) {
				if ( ! $product instanceof WC_Product ) {
					continue;
				}
				if ( ! self::is_eligible( $product ) ) {
					continue;
				}
				if ( $wpml && ! WC_Optic_WPML::is_original_product( $product->get_id() ) ) {
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
	 * Whether a product can receive generated internals.
	 *
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	public static function is_eligible( WC_Product $product ) {
		$type = $product->get_type();
		if ( 'simple' === $type ) {
			return true;
		}
		if ( 'optic_product' !== $type ) {
			return false;
		}
		return empty( WC_Optic_SKU::get_child_configs( $product ) );
	}

	/**
	 * Whether the product already has internals.
	 *
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	public static function has_children( WC_Product $product ) {
		return ! empty( WC_Optic_SKU::get_child_configs( $product ) );
	}

	/**
	 * Payload for the conversion wizard (one product).
	 *
	 * @param int $product_id Product id.
	 * @return array|WP_Error
	 */
	public static function get_wizard_product( $product_id ) {
		$requested_id = absint( $product_id );
		$display_name = '';
		if ( $requested_id ) {
			$requested = wc_get_product( $requested_id );
			if ( $requested instanceof WC_Product ) {
				$display_name = $requested->get_name();
			}
		}

		$wpml = class_exists( 'WC_Optic_WPML' ) && WC_Optic_WPML::is_active();
		if ( $wpml ) {
			$product_id = WC_Optic_WPML::get_original_product_id( $requested_id );
			WC_Optic_WPML::switch_to_default_language();
		} else {
			$product_id = $requested_id;
		}

		try {
			$product = wc_get_product( $product_id );
			if ( ! $product instanceof WC_Product ) {
				return new WP_Error( 'wc_optic_missing_product', __( 'Product not found.', 'wc-optic' ) );
			}

			$type = $product->get_type();
			if ( ! in_array( $type, array( 'simple', 'optic_product' ), true ) ) {
				return new WP_Error( 'wc_optic_unsupported_type', __( 'Only simple products can be converted in this version.', 'wc-optic' ) );
			}

			$image_id  = $product->get_image_id();
			$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';

			return array(
				'id'           => $product->get_id(),
				'name'         => $display_name ? $display_name : $product->get_name(),
				'sku'          => (string) $product->get_sku(),
				'price'        => self::get_source_price( $product ),
				'price_html'   => $product->get_price_html(),
				'edit_url'     => get_edit_post_link( $product->get_id(), 'raw' ),
				'image'        => $image_url ? $image_url : '',
				'type'         => $type,
				'division'     => (string) $product->get_meta( '_optic_division', true ),
				'identity'     => WC_Optic_SKU::get_identity_catalog( $product ),
				'has_children' => self::has_children( $product ),
			);
		} finally {
			if ( $wpml ) {
				WC_Optic_WPML::restore_language();
			}
		}
	}

	/**
	 * Default unit price from the current WooCommerce product.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function get_source_price( WC_Product $product ) {
		$regular = $product->get_regular_price( 'edit' );
		if ( '' !== trim( (string) $regular ) ) {
			return (string) wc_format_decimal( $regular );
		}
		$price = $product->get_price( 'edit' );
		if ( '' !== trim( (string) $price ) ) {
			return (string) wc_format_decimal( $price );
		}
		return '';
	}

	/**
	 * Preview conversion for many products without saving.
	 *
	 * @param int[] $product_ids Product ids.
	 * @param array $args        Conversion args.
	 * @return array
	 */
	public static function preview( array $product_ids, array $args ) {
		$per_product = 0;
		$ranges      = isset( $args['ranges'] ) && is_array( $args['ranges'] ) ? $args['ranges'] : array();
		$division    = isset( $args['division'] ) ? sanitize_key( $args['division'] ) : '';
		if ( $division && $ranges ) {
			$counted = WC_Optic_SKU::count_children_from_ranges( $division, $ranges );
			if ( is_wp_error( $counted ) ) {
				return array(
					'ok'       => false,
					'message'  => $counted->get_error_message(),
					'products' => array(),
					'total'    => 0,
				);
			}
			$per_product = (int) $counted;
		}

		$rows   = array();
		$errors = 0;
		foreach ( $product_ids as $product_id ) {
			$product_id = absint( $product_id );
			$product    = $product_id ? wc_get_product( $product_id ) : false;
			if ( ! $product instanceof WC_Product ) {
				$rows[] = array(
					'id'      => $product_id,
					'name'    => '',
					'status'  => 'error',
					'message' => __( 'Product not found.', 'wc-optic' ),
				);
				++$errors;
				continue;
			}

			$check = self::validate_product_args( $product, $args, true );
			if ( is_wp_error( $check ) ) {
				$rows[] = array(
					'id'      => $product->get_id(),
					'name'    => $product->get_name(),
					'status'  => 'error',
					'message' => $check->get_error_message(),
				);
				++$errors;
				continue;
			}

			if ( ! empty( $args['mode'] ) && 'replace' !== $args['mode'] && self::has_children( $product ) ) {
				$rows[] = array(
					'id'      => $product->get_id(),
					'name'    => $product->get_name(),
					'status'  => 'skip',
					'message' => __( 'Already has internal products.', 'wc-optic' ),
				);
				continue;
			}

			$rows[] = array(
				'id'      => $product->get_id(),
				'name'    => $product->get_name(),
				'status'  => 'ok',
				'message' => sprintf(
					/* translators: %d: internal product count */
					_n( '%d internal product', '%d internal products', $per_product, 'wc-optic' ),
					$per_product
				),
			);
		}

		return array(
			'ok'          => $errors < 1,
			'per_product' => $per_product,
			'total'       => $per_product * count( $product_ids ),
			'products'    => $rows,
		);
	}

	/**
	 * Convert one product.
	 *
	 * @param int   $product_id Product id.
	 * @param array $args       Args.
	 * @return array|WP_Error
	 */
	public static function convert_product( $product_id, array $args ) {
		$wpml = class_exists( 'WC_Optic_WPML' ) && WC_Optic_WPML::is_active();
		if ( $wpml ) {
			$product_id = WC_Optic_WPML::get_original_product_id( $product_id );
			WC_Optic_WPML::switch_to_default_language();
		}

		try {
			$product = wc_get_product( absint( $product_id ) );
			if ( ! $product instanceof WC_Product ) {
				return new WP_Error( 'wc_optic_missing_product', __( 'Product not found.', 'wc-optic' ) );
			}

			$type = $product->get_type();
			if ( ! in_array( $type, array( 'simple', 'optic_product' ), true ) ) {
				return new WP_Error( 'wc_optic_unsupported_type', __( 'Only simple products can be converted in this version.', 'wc-optic' ) );
			}

			$mode = isset( $args['mode'] ) ? sanitize_key( $args['mode'] ) : 'skip_if_has_children';
			if ( 'replace' !== $mode && self::has_children( $product ) ) {
				return array(
					'product_id'  => $product->get_id(),
					'skipped'     => true,
					'child_count' => count( WC_Optic_SKU::get_child_configs( $product ) ),
					'message'     => __( 'Already has internal products.', 'wc-optic' ),
				);
			}

			$prepared = self::prepare_args( $product, $args );
			if ( is_wp_error( $prepared ) ) {
				return $prepared;
			}

			$children = WC_Optic_SKU::build_children_from_ranges(
				$prepared['division'],
				$prepared['catalog'],
				$prepared['ranges'],
				$prepared['unit_price'],
				$prepared['stock_qty']
			);
			if ( is_wp_error( $children ) ) {
				return $children;
			}

			if ( 'optic_product' !== $product->get_type() ) {
				wp_set_object_terms( $product->get_id(), 'optic_product', 'product_type' );
				$product = wc_get_product( $product->get_id() );
				if ( ! $product instanceof WC_Product ) {
					return new WP_Error( 'wc_optic_type_failed', __( 'Could not change the product type.', 'wc-optic' ) );
				}
			}

			$product->update_meta_data( '_optic_division', $prepared['division'] );
			$product->update_meta_data( WC_Optic_SKU::IDENTITY_META_KEY, $prepared['catalog'] );
			$product->update_meta_data( WC_Optic_SKU::RANGES_META_KEY, $prepared['ranges'] );
			WC_Optic_SKU::persist_child_data( $product, $children );
			WC_Optic_SKU::sync_product_sku( $product );
			$product->save();

			if ( $wpml ) {
				WC_Optic_WPML::sync_product_translations( $product->get_id() );
			}

			return array(
				'product_id'  => $product->get_id(),
				'skipped'     => false,
				'child_count' => count( $children ),
				'sku_sample'  => isset( $children[0]['sku'] ) ? (string) $children[0]['sku'] : '',
			);
		} finally {
			if ( $wpml ) {
				WC_Optic_WPML::restore_language();
			}
		}
	}

	/**
	 * Validate and complete args for one product.
	 *
	 * @param WC_Product $product Product.
	 * @param array      $args    Posted args.
	 * @param bool       $preview Preview mode (do not require price if missing? we still require).
	 * @return array|WP_Error
	 */
	protected static function prepare_args( WC_Product $product, array $args, $preview = false ) {
		$division = isset( $args['division'] ) ? sanitize_key( $args['division'] ) : '';
		$divs     = WC_Optic_Plugin::get_divisions();
		if ( ! $division || ! isset( $divs[ $division ] ) ) {
			return new WP_Error( 'wc_optic_missing_division', __( 'Optical division is required.', 'wc-optic' ) );
		}

		$catalog = WC_Optic_SKU::normalize_identity_catalog( isset( $args['catalog'] ) ? $args['catalog'] : array() );
		$has_lot_identity = true;
		foreach ( $catalog as $id ) {
			if ( $id < 1 ) {
				$has_lot_identity = false;
				break;
			}
		}
		if ( ! $has_lot_identity ) {
			$catalog = WC_Optic_SKU::get_identity_catalog( $product );
		}

		$ranges = isset( $args['ranges'] ) && is_array( $args['ranges'] ) ? $args['ranges'] : array();
		if ( ! empty( $args['template_id'] ) ) {
			$template = WC_Optic_Power_Template::get( $args['template_id'] );
			if ( ! $template ) {
				return new WP_Error( 'wc_optic_missing_template', __( 'Power range template not found.', 'wc-optic' ) );
			}
			if ( $template['division'] !== $division ) {
				return new WP_Error( 'wc_optic_template_division', __( 'This template does not match the selected division.', 'wc-optic' ) );
			}
			$ranges = $template['ranges'];
		}

		$unit_price = isset( $args['unit_price'] ) ? (string) $args['unit_price'] : '';
		if ( '' === trim( $unit_price ) ) {
			$unit_price = self::get_source_price( $product );
		}

		$stock_qty = isset( $args['stock_qty'] ) && '' !== trim( (string) $args['stock_qty'] )
			? (string) absint( $args['stock_qty'] )
			: '0';

		if ( $preview ) {
			return array(
				'division'   => $division,
				'catalog'    => $catalog,
				'ranges'     => $ranges,
				'unit_price' => $unit_price,
				'stock_qty'  => $stock_qty,
			);
		}

		return array(
			'division'   => $division,
			'catalog'    => $catalog,
			'ranges'     => WC_Optic_SKU::normalize_power_ranges( $ranges, $division ),
			'unit_price' => $unit_price,
			'stock_qty'  => $stock_qty,
		);
	}

	/**
	 * Lightweight validation used by preview.
	 *
	 * @param WC_Product $product Product.
	 * @param array      $args    Args.
	 * @param bool       $preview Preview.
	 * @return true|WP_Error
	 */
	protected static function validate_product_args( WC_Product $product, array $args, $preview = false ) {
		$prepared = self::prepare_args( $product, $args, $preview );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		if ( '' === trim( (string) $prepared['unit_price'] ) ) {
			return new WP_Error( 'wc_optic_missing_price', __( 'This product has no price.', 'wc-optic' ) );
		}

		$identity = WC_Optic_SKU::normalize_identity_catalog( $prepared['catalog'] );
		foreach ( WC_Optic_SKU::get_required_identity_types( $prepared['division'] ) as $type ) {
			if ( (int) ( $identity[ $type ] ?? 0 ) < 1 ) {
				return new WP_Error(
					'wc_optic_missing_identity',
					sprintf(
						/* translators: %s: catalog field label */
						__( 'Missing optical identity: %s.', 'wc-optic' ),
						WC_Optic_Catalog::get_type_label( $type )
					)
				);
			}
		}

		return true;
	}
}
