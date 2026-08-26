<?php
/**
 * Dynamic SKU generation for optic child configurations.
 *
 * @package WC_Optic_Product
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Optic_SKU
 */
class WC_Optic_SKU {

	const META_KEYS = array(
		'section'      => '_optic_cat_section',
		'company'      => '_optic_cat_company',
		'brand'        => '_optic_cat_brand',
		'timing'       => '_optic_cat_timing',
		'color'        => '_optic_cat_color',
		'sph'          => '_optic_cat_sph',
		'cyl'          => '_optic_cat_cyl',
		'axis'         => '_optic_cat_axis',
		'add'          => '_optic_cat_add',
		'pack'         => '_optic_cat_pack',
		'transparency' => '_optic_cat_transparency',
	);

	const CHILD_META_KEY = '_optic_child_configs';
	const IDENTITY_META_KEY = '_optic_identity_catalog';
	const RANGES_META_KEY   = '_optic_power_ranges';
	const GLOBAL_BACKORDER_ENABLED_OPTION  = 'wc_optic_backorder_enabled';
	const GLOBAL_BACKORDER_QTY_OPTION      = 'wc_optic_backorder_qty';
	const MAX_LEGACY_SYNTHETIC_CHILDREN    = 200;

	/**
	 * Product-level derived catalog index meta keys.
	 *
	 * @var array<string, string>
	 */
	const INDEX_META_KEYS = array(
		'section'      => '_optic_idx_section',
		'company'      => '_optic_idx_company',
		'brand'        => '_optic_idx_brand',
		'timing'       => '_optic_idx_timing',
		'color'        => '_optic_idx_color',
		'sph'          => '_optic_idx_sph',
		'cyl'          => '_optic_idx_cyl',
		'axis'         => '_optic_idx_axis',
		'add'          => '_optic_idx_add',
		'pack'         => '_optic_idx_pack',
		'transparency' => '_optic_idx_transparency',
	);

	/**
	 * Normalize one or more catalog ids into a unique int array.
	 *
	 * @param mixed $raw Scalar id, serialized meta array, or posted array.
	 * @return int[]
	 */
	public static function normalize_catalog_ids( $raw ) {
		if ( is_array( $raw ) ) {
			$values = $raw;
		} elseif ( null === $raw || '' === trim( (string) $raw ) ) {
			$values = array();
		} else {
			$values = array( $raw );
		}

		$ids = array();
		foreach ( $values as $value ) {
			$id = absint( $value );
			if ( $id ) {
				$ids[ $id ] = $id;
			}
		}

		return array_values( $ids );
	}

	/**
	 * Normalize a single catalog id.
	 *
	 * @param mixed $raw Raw value.
	 * @return int
	 */
	public static function normalize_catalog_id( $raw ) {
		$ids = self::normalize_catalog_ids( $raw );
		return empty( $ids ) ? 0 : (int) reset( $ids );
	}

	/**
	 * Whether storefront backorder is enabled globally.
	 *
	 * @return bool
	 */
	public static function is_backorder_enabled() {
		return 'yes' === (string) get_option( self::GLOBAL_BACKORDER_ENABLED_OPTION, 'no' );
	}

	/**
	 * Persist global backorder enabled flag.
	 *
	 * @param mixed $value Posted value.
	 * @return bool
	 */
	public static function set_backorder_enabled( $value ) {
		$enabled = ! empty( $value ) && 'no' !== (string) $value;
		update_option( self::GLOBAL_BACKORDER_ENABLED_OPTION, $enabled ? 'yes' : 'no', false );
		return $enabled;
	}

	/**
	 * Global extra sellable units beyond physical stock per internal product.
	 *
	 * @return int
	 */
	public static function get_global_backorder_qty() {
		return max( 0, absint( get_option( self::GLOBAL_BACKORDER_QTY_OPTION, 0 ) ) );
	}

	/**
	 * Persist global backorder quantity.
	 *
	 * @param mixed $value Posted value.
	 * @return int
	 */
	public static function set_global_backorder_qty( $value ) {
		$qty = max( 0, absint( $value ) );
		update_option( self::GLOBAL_BACKORDER_QTY_OPTION, $qty, false );
		return $qty;
	}

	/**
	 * Effective backorder allowance for one internal product.
	 *
	 * @param array $config Child config.
	 * @return int
	 */
	public static function get_child_backorder_qty( array $config ) {
		if ( ! self::is_backorder_enabled() ) {
			return 0;
		}

		if ( ! empty( $config['backorder_custom'] ) ) {
			return max( 0, absint( $config['backorder_qty'] ?? 0 ) );
		}

		return self::get_global_backorder_qty();
	}

	/**
	 * Units already sold against the backorder allowance.
	 *
	 * @param array $config Child config.
	 * @return int
	 */
	public static function get_child_backorder_consumed( array $config ) {
		return max( 0, absint( $config['backorder_consumed'] ?? 0 ) );
	}

	/**
	 * Total sellable units for one internal product (stock + remaining backorder).
	 *
	 * @param array $config Child config.
	 * @return int|null Null when stock is not managed.
	 */
	public static function get_child_sellable_qty( array $config ) {
		$stock = self::get_child_stock_qty( $config );
		if ( null === $stock ) {
			return null;
		}

		$backorder = self::get_child_backorder_qty( $config );
		if ( $backorder < 1 ) {
			return $stock;
		}

		return max( 0, $stock + $backorder - self::get_child_backorder_consumed( $config ) );
	}

	/**
	 * Get child configurations for a product.
	 *
	 * New child meta is authoritative. Legacy flat meta is converted on read.
	 *
	 * @param WC_Product $product Product.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_child_configs( WC_Product $product ) {
		$division = (string) $product->get_meta( '_optic_division', true );
		if ( '' === $division ) {
			return array();
		}

		$stored = $product->get_meta( self::CHILD_META_KEY, true );
		if ( is_array( $stored ) && ! empty( $stored ) ) {
			return self::normalize_child_configs( $stored, $division );
		}

		return self::get_legacy_child_configs( $product, $division );
	}

	/**
	 * Get only enabled and complete child configurations.
	 *
	 * @param WC_Product $product Product.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_enabled_child_configs( WC_Product $product ) {
		$division = (string) $product->get_meta( '_optic_division', true );
		$out      = array();
		foreach ( self::get_child_configs( $product ) as $config ) {
			if ( self::child_is_enabled( $config ) && self::child_is_complete( $config, $division ) ) {
				$out[] = $config;
			}
		}

		usort(
			$out,
			function ( $a, $b ) {
				$sort_a = isset( $a['sort'] ) ? (int) $a['sort'] : 0;
				$sort_b = isset( $b['sort'] ) ? (int) $b['sort'] : 0;
				if ( $sort_a === $sort_b ) {
					return strcmp( (string) ( $a['id'] ?? '' ), (string) ( $b['id'] ?? '' ) );
				}
				return $sort_a <=> $sort_b;
			}
		);

		return array_values( $out );
	}

	/**
	 * Find one child configuration by id.
	 *
	 * @param WC_Product $product Product.
	 * @param string     $child_id Child id.
	 * @param bool       $enabled_only Enabled only.
	 * @return array<string, mixed>|null
	 */
	public static function find_child_config( WC_Product $product, $child_id, $enabled_only = true ) {
		$child_id = (string) $child_id;
		if ( '' === $child_id ) {
			return null;
		}

		$configs = $enabled_only ? self::get_enabled_child_configs( $product ) : self::get_child_configs( $product );
		foreach ( $configs as $config ) {
			if ( $child_id === (string) ( $config['id'] ?? '' ) ) {
				return $config;
			}
		}

		return null;
	}

	/**
	 * Find one enabled child whose prescription powers match the given catalog ids.
	 *
	 * @param WC_Product $product      Product.
	 * @param array      $power_ids    Map power slug => catalog row id.
	 * @param bool       $enabled_only Only enabled children.
	 * @return array<string, mixed>|null
	 */
	public static function find_child_by_powers( WC_Product $product, array $power_ids, $enabled_only = true ) {
		$division = (string) $product->get_meta( '_optic_division', true );
		if ( ! $division ) {
			return null;
		}

		$required   = WC_Optic_Plugin::get_powers_for_division( $division );
		$normalized = array();
		foreach ( $required as $power ) {
			$id = isset( $power_ids[ $power ] ) ? absint( $power_ids[ $power ] ) : 0;
			if ( ! $id || ! WC_Optic_Catalog::get_valid_term( $id, $power ) ) {
				return null;
			}
			$normalized[ $power ] = $id;
		}

		$configs = $enabled_only ? self::get_enabled_child_configs( $product ) : self::get_child_configs( $product );
		foreach ( $configs as $config ) {
			$match = true;
			foreach ( $required as $power ) {
				$child_id = isset( $config['powers'][ $power ] ) ? (int) $config['powers'][ $power ] : 0;
				if ( $child_id !== $normalized[ $power ] ) {
					$match = false;
					break;
				}
			}
			if ( $match ) {
				return $config;
			}
		}

		return null;
	}

	/**
	 * Whether all division power selects are filled (price not required).
	 *
	 * @param array  $config   Child config.
	 * @param string $division Division slug.
	 * @return bool
	 */
	public static function child_powers_complete( array $config, $division ) {
		if ( ! $division ) {
			return false;
		}
		foreach ( WC_Optic_Plugin::get_powers_for_division( $division ) as $power ) {
			if ( empty( $config['powers'][ $power ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Stable key for a child's power combination, or empty when incomplete.
	 *
	 * @param array  $config   Child config.
	 * @param string $division Division slug.
	 * @return string
	 */
	public static function get_power_combination_key( array $config, $division ) {
		if ( ! self::child_powers_complete( $config, $division ) ) {
			return '';
		}
		$parts = array();
		foreach ( WC_Optic_Plugin::get_powers_for_division( $division ) as $power ) {
			$parts[] = (string) (int) ( $config['powers'][ $power ] ?? 0 );
		}
		return implode( '|', $parts );
	}

	/**
	 * Human-readable power summary (e.g. SPH +1.00 · CYL +1.25 · AXIS 125).
	 *
	 * @param array  $config   Child config.
	 * @param string $division Division slug.
	 * @return string
	 */
	public static function format_child_powers_label( array $config, $division ) {
		$parts = array();
		foreach ( WC_Optic_Plugin::get_powers_for_division( $division ) as $power ) {
			$id  = (int) ( $config['powers'][ $power ] ?? 0 );
			$row = $id ? WC_Optic_Catalog::get_term( $id ) : null;
			$val = $row ? WC_Optic_Catalog::get_display_name( $row ) : '—';
			$parts[] = strtoupper( $power ) . ' ' . $val;
		}
		return implode( ' | ', $parts );
	}

	/**
	 * Find another child that already uses the same power combination.
	 *
	 * @param array  $child_configs All children.
	 * @param array  $candidate     Candidate config (normalized).
	 * @param string $division      Division slug.
	 * @param string $exclude_id    Child id to ignore (the one being saved).
	 * @return array|null Conflicting child, or null.
	 */
	public static function find_duplicate_power_child( array $child_configs, array $candidate, $division, $exclude_id = '' ) {
		$key = self::get_power_combination_key( $candidate, $division );
		if ( '' === $key ) {
			return null;
		}

		$exclude_id = sanitize_key( (string) $exclude_id );
		foreach ( $child_configs as $config ) {
			if ( ! is_array( $config ) ) {
				continue;
			}
			$other_id = sanitize_key( (string) ( $config['id'] ?? '' ) );
			if ( $exclude_id && $other_id === $exclude_id ) {
				continue;
			}
			if ( self::get_power_combination_key( $config, $division ) === $key ) {
				return $config;
			}
		}

		return null;
	}

	/**
	 * Reject saving a child whose prescription already exists on another internal.
	 *
	 * @param array  $child_configs All children (before replacing candidate).
	 * @param array  $candidate     Candidate config.
	 * @param string $division      Division slug.
	 * @param string $exclude_id    Id of the child being updated.
	 * @return true|WP_Error
	 */
	public static function assert_unique_power_combination( array $child_configs, array $candidate, $division, $exclude_id = '' ) {
		$dup = self::find_duplicate_power_child( $child_configs, $candidate, $division, $exclude_id );
		if ( ! $dup ) {
			return true;
		}

		$label  = ! empty( $dup['label'] ) ? (string) $dup['label'] : __( 'Product', 'wc-optic' );
		$powers = self::format_child_powers_label( $candidate, $division );

		return new WP_Error(
			'wc_optic_duplicate_powers',
			sprintf(
				/* translators: 1: internal product label, 2: power combination */
				__( 'Duplicate internal product: « %1$s » already uses %2$s. Each prescription combination must be unique.', 'wc-optic' ),
				$label,
				$powers
			)
		);
	}

	/**
	 * Ensure no two children share the same power combination (enabled or not).
	 *
	 * @param array  $child_configs Normalized child configs.
	 * @param string $division      Division slug.
	 * @return true|WP_Error
	 */
	public static function validate_unique_power_combinations( array $child_configs, $division ) {
		if ( ! $division ) {
			return true;
		}

		$seen = array();

		foreach ( $child_configs as $config ) {
			if ( ! is_array( $config ) ) {
				continue;
			}
			$key = self::get_power_combination_key( $config, $division );
			if ( '' === $key ) {
				continue;
			}
			if ( isset( $seen[ $key ] ) ) {
				$first = $seen[ $key ];
				$label = ! empty( $config['label'] ) ? (string) $config['label'] : __( 'Product', 'wc-optic' );
				$other = ! empty( $first['label'] ) ? (string) $first['label'] : __( 'Product', 'wc-optic' );
				$powers = self::format_child_powers_label( $config, $division );
				return new WP_Error(
					'wc_optic_duplicate_powers',
					sprintf(
						/* translators: 1: first label, 2: second label, 3: powers */
						__( 'Duplicate internals « %1$s » and « %2$s » share %3$s. Each prescription combination must be unique.', 'wc-optic' ),
						$other,
						$label,
						$powers
					)
				);
			}
			$seen[ $key ] = $config;
		}

		return true;
	}

	/**
	 * Get one child config by id.
	 *
	 * @param WC_Product $product  Product.
	 * @param string     $child_id Child id.
	 * @return array|null
	 */
	public static function get_child_config_by_id( WC_Product $product, $child_id ) {
		$child_id = sanitize_key( (string) $child_id );
		if ( '' === $child_id ) {
			return null;
		}
		foreach ( self::get_child_configs( $product ) as $config ) {
			if ( sanitize_key( (string) ( $config['id'] ?? '' ) ) === $child_id ) {
				return $config;
			}
		}
		return null;
	}

	/**
	 * Compact list rows for the admin product screen.
	 *
	 * @param WC_Product $product Product.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_child_list_rows( WC_Product $product ) {
		$division = (string) $product->get_meta( '_optic_division', true );
		$rows     = array();
		foreach ( self::get_child_configs( $product ) as $index => $config ) {
			$rows[] = self::build_child_list_row( $config, $division, $index );
		}
		return $rows;
	}

	/**
	 * One list row payload.
	 *
	 * @param array  $config   Child config.
	 * @param string $division Division.
	 * @param int    $index    Sort index.
	 * @return array<string, mixed>
	 */
	public static function build_child_list_row( array $config, $division, $index = 0 ) {
		$stock = self::get_child_stock_qty( $config );
		return array(
			'id'       => (string) ( $config['id'] ?? '' ),
			'label'    => (string) ( $config['label'] ?? '' ),
			'powers'   => self::format_child_powers_label( $config, $division ),
			'price'    => (string) ( $config['unit_price'] ?? '' ),
			'stock'    => null === $stock ? '' : (string) $stock,
			'enabled'  => ! empty( $config['enabled'] ),
			'sku'      => (string) ( $config['sku'] ?? '' ),
			'sort'     => isset( $config['sort'] ) ? (int) $config['sort'] : (int) $index,
			'search'   => strtolower(
				implode(
					' ',
					array(
						(string) ( $config['label'] ?? '' ),
						self::format_child_powers_label( $config, $division ),
						(string) ( $config['sku'] ?? '' ),
					)
				)
			),
		);
	}

	/**
	 * Insert or update one child on a product (with duplicate-power guard).
	 *
	 * @param WC_Product $product   Product.
	 * @param array      $raw_child Raw child payload.
	 * @param array|null $identity  Optional identity override (applied to all children).
	 * @return array{config:array,rows:array}|WP_Error
	 */
	public static function upsert_child_on_product( WC_Product $product, array $raw_child, $identity = null ) {
		$division = (string) $product->get_meta( '_optic_division', true );
		if ( ! $division ) {
			return new WP_Error(
				'wc_optic_missing_division',
				__( 'Optical division is required. Save the product division first.', 'wc-optic' )
			);
		}

		if ( null === $identity ) {
			$identity = self::get_identity_catalog( $product );
		} else {
			$identity = self::normalize_identity_catalog( $identity );
			$product->update_meta_data( self::IDENTITY_META_KEY, $identity );
		}

		if ( $division && ! WC_Optic_Plugin::division_shows_color( $division ) ) {
			$identity['color'] = 0;
			$product->update_meta_data( self::IDENTITY_META_KEY, $identity );
		}

		$children = self::get_child_configs( $product );
		$raw_child['catalog'] = $identity;

		$existing_index = null;
		$child_id       = isset( $raw_child['id'] ) ? sanitize_key( (string) $raw_child['id'] ) : '';
		foreach ( $children as $i => $existing ) {
			if ( $child_id && sanitize_key( (string) ( $existing['id'] ?? '' ) ) === $child_id ) {
				$existing_index = $i;
				if ( isset( $existing['backorder_consumed'] ) && ! isset( $raw_child['backorder_consumed'] ) ) {
					$raw_child['backorder_consumed'] = $existing['backorder_consumed'];
				}
				break;
			}
		}

		$index  = null !== $existing_index ? (int) $existing_index : count( $children );
		$config = self::normalize_child_config( $raw_child, $division, $index );

		$complete = self::validate_child_configs_complete( array( $config ), $division, array( $raw_child ) );
		if ( is_wp_error( $complete ) ) {
			return $complete;
		}

		$unique = self::assert_unique_power_combination( $children, $config, $division, $config['id'] );
		if ( is_wp_error( $unique ) ) {
			return $unique;
		}

		if ( null !== $existing_index ) {
			$children[ $existing_index ] = $config;
		} else {
			$children[] = $config;
		}

		$children = self::apply_identity_to_children( $identity, $children, $division );
		$unique   = self::validate_unique_power_combinations( $children, $division );
		if ( is_wp_error( $unique ) ) {
			return $unique;
		}

		$children = self::preserve_child_backorder_consumed( $product, $children );
		self::persist_child_data( $product, $children );
		self::sync_product_sku( $product );
		$product->save();

		$saved = self::get_child_config_by_id( $product, $config['id'] );
		return array(
			'config' => $saved ? $saved : $config,
			'row'    => self::build_child_list_row( $saved ? $saved : $config, $division, $index ),
			'rows'   => self::get_child_list_rows( $product ),
			'count'  => count( self::get_child_configs( $product ) ),
		);
	}

	/**
	 * Remove one child by id.
	 *
	 * @param WC_Product $product  Product.
	 * @param string     $child_id Child id.
	 * @return array{rows:array,count:int}|WP_Error
	 */
	public static function remove_child_from_product( WC_Product $product, $child_id ) {
		$child_id = sanitize_key( (string) $child_id );
		if ( '' === $child_id ) {
			return new WP_Error( 'wc_optic_missing_child', __( 'Internal product not found.', 'wc-optic' ) );
		}

		$division = (string) $product->get_meta( '_optic_division', true );
		$children = self::get_child_configs( $product );
		$found    = false;
		$kept     = array();
		foreach ( $children as $config ) {
			if ( sanitize_key( (string) ( $config['id'] ?? '' ) ) === $child_id ) {
				$found = true;
				continue;
			}
			$kept[] = $config;
		}

		if ( ! $found ) {
			return new WP_Error( 'wc_optic_missing_child', __( 'Internal product not found.', 'wc-optic' ) );
		}

		$identity = self::get_identity_catalog( $product );
		$kept     = self::apply_identity_to_children( $identity, $kept, $division );
		self::persist_child_data( $product, $kept );
		self::sync_product_sku( $product );
		$product->save();

		return array(
			'rows'  => self::get_child_list_rows( $product ),
			'count' => count( $kept ),
		);
	}

	/**
	 * Ensure every child has all catalog and division power selects filled.
	 *
	 * @param array  $child_configs Normalized child configs.
	 * @param string $division      Division slug.
	 * @param array  $raw_configs   Raw POST child configs keyed by form suffix.
	 * @return true|WP_Error
	 */
	public static function validate_child_configs_complete( array $child_configs, $division, array $raw_configs = array() ) {
		if ( ! $division ) {
			return new WP_Error(
				'wc_optic_missing_division',
				__( 'Optical division is required.', 'wc-optic' )
			);
		}

		if ( empty( $child_configs ) ) {
			return new WP_Error(
				'wc_optic_missing_child',
				__( 'At least one internal product is required.', 'wc-optic' )
			);
		}

		$power_types    = WC_Optic_Catalog::get_power_types();
		$allowed_powers = WC_Optic_Plugin::get_powers_for_division( $division );
		$raw_list       = array_values( $raw_configs );

		foreach ( $child_configs as $index => $config ) {
			$position = $index + 1;
			$raw      = isset( $raw_list[ $index ] ) && is_array( $raw_list[ $index ] ) ? $raw_list[ $index ] : array();
			$label    = ! empty( $config['label'] ) ? (string) $config['label'] : sprintf(
				/* translators: %d: child config position */
				__( 'Product %d', 'wc-optic' ),
				$position
			);

			$raw_label = isset( $raw['label'] ) ? trim( sanitize_text_field( (string) $raw['label'] ) ) : '';
			if ( '' === $raw_label ) {
				return new WP_Error(
					'wc_optic_incomplete_child',
					sprintf(
						/* translators: %d: internal product position */
						__( 'Internal product #%1$d requires a label.', 'wc-optic' ),
						$position
					)
				);
			}

			if ( '' === trim( (string) ( $config['unit_price'] ?? '' ) ) ) {
				return new WP_Error(
					'wc_optic_incomplete_child',
					sprintf(
						/* translators: %s: internal product label */
						__( 'Internal product "%s" requires a unit price.', 'wc-optic' ),
						$label
					)
				);
			}

			if ( ! isset( $config['stock_qty'] ) || '' === trim( (string) $config['stock_qty'] ) ) {
				return new WP_Error(
					'wc_optic_incomplete_child',
					sprintf(
						/* translators: %s: internal product label */
						__( 'Internal product "%s" requires a stock quantity.', 'wc-optic' ),
						$label
					)
				);
			}

			foreach ( array_keys( self::META_KEYS ) as $type ) {
				if ( in_array( $type, $power_types, true ) ) {
					if ( ! in_array( $type, $allowed_powers, true ) ) {
						continue;
					}
					$value = isset( $config['powers'][ $type ] ) ? (int) $config['powers'][ $type ] : 0;
				} else {
					if ( ! in_array( $type, self::get_required_identity_types( $division ), true ) ) {
						continue;
					}
					$value = isset( $config['catalog'][ $type ] ) ? (int) $config['catalog'][ $type ] : 0;
				}

				if ( $value <= 0 ) {
					return new WP_Error(
						'wc_optic_incomplete_child',
						sprintf(
							/* translators: 1: internal product label, 2: catalog field label */
							__( 'Internal product "%1$s" is missing a required value for %2$s.', 'wc-optic' ),
							$label,
							WC_Optic_Catalog::get_type_label( $type )
						)
					);
				}
			}
		}

		return true;
	}

	/**
	 * Build storefront cascade data (children + term labels) for JS resolution.
	 *
	 * @param WC_Product $product Product.
	 * @return array<string, mixed>
	 */
	public static function get_storefront_matrix( WC_Product $product ) {
		$division = (string) $product->get_meta( '_optic_division', true );
		$powers   = $division ? WC_Optic_Plugin::get_powers_for_division( $division ) : array();
		$children = array();
		$term_ids = array();
		$no_power_child = null;

		foreach ( $powers as $power ) {
			$term_ids[ $power ] = array();
		}

		foreach ( self::get_enabled_child_configs( $product ) as $config ) {
			if ( ! self::child_is_complete( $config, $division ) ) {
				continue;
			}

			$remaining = WC_Optic_Cart::get_remaining_child_stock( $product, $config );
			$in_stock  = null === $remaining || $remaining > 0;
			$child_row = array(
				'id'      => (string) ( $config['id'] ?? '' ),
				'price'   => self::get_child_unit_price( $config ),
				'stock'   => $remaining,
				'inStock' => $in_stock,
			);

			if ( self::child_is_no_power( $config, $division ) ) {
				if ( null === $no_power_child ) {
					$no_power_child = $child_row;
				}
				continue;
			}

			$power_map = array();
			foreach ( $powers as $power ) {
				$tid                 = (int) ( $config['powers'][ $power ] ?? 0 );
				$power_map[ $power ] = $tid;
				if ( $tid ) {
					$term_ids[ $power ][ $tid ] = true;
				}
			}
			$child_row['powers'] = $power_map;
			$children[]          = $child_row;
		}

		$terms  = array();
		$labels = array();
		foreach ( $powers as $power ) {
			$labels[ $power ] = WC_Optic_Catalog::get_power_field_label( $power );
			$terms[ $power ]  = array();
			if ( empty( $term_ids[ $power ] ) ) {
				continue;
			}
			foreach ( array_keys( $term_ids[ $power ] ) as $tid ) {
				$row = WC_Optic_Catalog::get_valid_term( (int) $tid, $power );
				if ( $row ) {
					$terms[ $power ][ (string) (int) $tid ] = WC_Optic_Catalog::get_display_name( $row );
				}
			}
		}

		return array(
			'division'            => $division,
			'supportsNoPowerMode' => self::division_supports_no_power_mode( $division ),
			'noPowerChild'        => $no_power_child,
			'powers'              => $powers,
			'children'            => $children,
			'terms'               => $terms,
			'labels'              => $labels,
		);
	}

	/**
	 * Whether a division supports the no-power / power storefront toggle.
	 *
	 * @param string $division Division slug.
	 * @return bool
	 */
	public static function division_supports_no_power_mode( $division ) {
		return 'color_lenses' === sanitize_key( (string) $division );
	}

	/**
	 * Whether an internal product is a no-power variant (color lenses with SPH +0.00).
	 *
	 * @param array  $config   Child config.
	 * @param string $division Parent division.
	 * @return bool
	 */
	public static function child_is_no_power( array $config, $division ) {
		if ( ! self::division_supports_no_power_mode( $division ) ) {
			return false;
		}

		$sph_id = isset( $config['powers']['sph'] ) ? (int) $config['powers']['sph'] : 0;
		if ( $sph_id < 1 ) {
			return false;
		}

		return WC_Optic_Catalog::sph_term_is_zero_power( WC_Optic_Catalog::get_valid_term( $sph_id, 'sph' ) );
	}

	/**
	 * Find the enabled no-power child for a color lenses product.
	 *
	 * @param WC_Product $product Product.
	 * @return array<string, mixed>|null
	 */
	public static function find_no_power_child( WC_Product $product ) {
		$division = (string) $product->get_meta( '_optic_division', true );
		if ( ! self::division_supports_no_power_mode( $division ) ) {
			return null;
		}

		foreach ( self::get_enabled_child_configs( $product ) as $config ) {
			if ( self::child_is_no_power( $config, $division ) && self::child_is_complete( $config, $division ) ) {
				return $config;
			}
		}

		return null;
	}

	/**
	 * Internal child used for storefront default price display (option B).
	 *
	 * Color lenses: no-power (+0.00) child. Other divisions: lowest-priced enabled child.
	 *
	 * @param WC_Product $product Product.
	 * @return array<string, mixed>|null
	 */
	public static function get_default_display_child( WC_Product $product ) {
		$division = (string) $product->get_meta( '_optic_division', true );

		if ( self::division_supports_no_power_mode( $division ) ) {
			$no_power = self::find_no_power_child( $product );
			if ( $no_power ) {
				return $no_power;
			}
		}

		$best_config = null;
		$best_price  = 0.0;

		foreach ( self::get_enabled_child_configs( $product ) as $config ) {
			if ( ! self::child_is_complete( $config, $division ) ) {
				continue;
			}

			$price = self::get_child_unit_price( $config );
			if ( $price <= 0 ) {
				continue;
			}

			if ( null === $best_config || $price < $best_price ) {
				$best_config = $config;
				$best_price  = $price;
			}
		}

		return $best_config;
	}

	/**
	 * Default storefront unit price for an optic product.
	 *
	 * @param WC_Product $product Product.
	 * @return float
	 */
	public static function get_default_display_price( WC_Product $product ) {
		$config = self::get_default_display_child( $product );
		if ( ! $config ) {
			return 0.0;
		}

		return self::get_child_unit_price( $config );
	}

	/**
	 * Build one eye payload from a resolved child config.
	 *
	 * @param array  $config   Child config.
	 * @param string $division Product division.
	 * @return array|WP_Error
	 */
	public static function build_eye_payload_from_child( array $config, $division ) {
		$powers = array();
		foreach ( WC_Optic_Plugin::get_powers_for_division( $division ) as $power ) {
			if ( self::child_is_no_power( $config, $division ) ) {
				continue;
			}
			$id  = isset( $config['powers'][ $power ] ) ? (int) $config['powers'][ $power ] : 0;
			$row = $id ? WC_Optic_Catalog::get_valid_term( $id, $power ) : null;
			if ( ! $row ) {
				return new WP_Error( 'wc_optic', __( 'Selected internal product is incomplete.', 'wc-optic' ) );
			}
			$powers[ $power ] = array(
				'id'    => $id,
				'label' => WC_Optic_Catalog::get_display_name( $row ),
			);
		}

		return array(
			'child_id'   => (string) $config['id'],
			'label'      => (string) $config['label'],
			'display'    => self::child_display_label( $config, $division ),
			'sku'        => (string) $config['sku'],
			'unit_price' => self::get_child_unit_price( $config ),
			'stock_qty'  => self::get_child_stock_qty( $config ),
			'powers'     => $powers,
		);
	}

	/**
	 * Build SKU string for the first enabled child on a product.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function build_for_product( WC_Product $product ) {
		$children = self::get_enabled_child_configs( $product );
		if ( empty( $children ) ) {
			return '';
		}

		return isset( $children[0]['sku'] ) ? (string) $children[0]['sku'] : '';
	}

	/**
	 * Apply a parent/base SKU to the product.
	 *
	 * The parent SKU is kept intentionally neutral. Internal child SKUs are the
	 * operational identifiers and are stored in child config meta + cart/order payload.
	 *
	 * @param WC_Product $product Product.
	 */
	public static function sync_product_sku( WC_Product $product ) {
		if ( ! $product->get_sku( 'edit' ) ) {
			$product->set_sku( '' );
		}
	}

	/**
	 * Text segment used in dynamic SKU for one catalog row (SKU fragment field).
	 *
	 * @param object $row Catalog DB row.
	 * @return string
	 */
	public static function catalog_term_sku_part( $row ) {
		if ( ! $row ) {
			return '';
		}
		$frag = isset( $row->sku_fragment ) ? trim( (string) $row->sku_fragment ) : '';
		if ( '' !== $frag ) {
			return $frag;
		}
		$name = isset( $row->name ) ? trim( (string) $row->name ) : '';
		if ( '' !== $name ) {
			return $name;
		}
		return isset( $row->slug ) ? (string) $row->slug : '';
	}

	/**
	 * Build SKU from one child configuration.
	 *
	 * @param array  $child_config Child config.
	 * @param string $division     Division.
	 * @return string
	 */
	public static function build_for_child_config( array $child_config, $division ) {
		$allowed_powers = $division ? WC_Optic_Plugin::get_powers_for_division( $division ) : array();
		$power_types    = WC_Optic_Catalog::get_power_types();
		$catalog        = isset( $child_config['catalog'] ) && is_array( $child_config['catalog'] ) ? $child_config['catalog'] : array();
		$powers         = isset( $child_config['powers'] ) && is_array( $child_config['powers'] ) ? $child_config['powers'] : array();
		$parts          = array();

		foreach ( self::META_KEYS as $type => $meta_key ) {
			if ( in_array( $type, $power_types, true ) && ! in_array( $type, $allowed_powers, true ) ) {
				$parts[] = '';
				continue;
			}

			$id = in_array( $type, $power_types, true ) ? self::normalize_catalog_id( $powers[ $type ] ?? 0 ) : self::normalize_catalog_id( $catalog[ $type ] ?? 0 );
			if ( ! $id ) {
				$parts[] = '';
				continue;
			}

			$row = WC_Optic_Catalog::get_valid_term( $id, $type );
			if ( ! $row ) {
				$parts[] = '';
				continue;
			}

			$parts[] = self::catalog_term_sku_part( $row );
		}

		return implode( '', $parts );
	}

	/**
	 * Normalize one raw child config.
	 *
	 * @param array  $raw      Raw child config.
	 * @param string $division Parent division.
	 * @param int    $index    Visual index.
	 * @return array<string, mixed>
	 */
	public static function normalize_child_config( array $raw, $division, $index = 0 ) {
		$power_types    = WC_Optic_Catalog::get_power_types();
		$allowed_powers = $division ? WC_Optic_Plugin::get_powers_for_division( $division ) : array();
		$catalog        = isset( $raw['catalog'] ) && is_array( $raw['catalog'] ) ? $raw['catalog'] : array();
		$powers         = isset( $raw['powers'] ) && is_array( $raw['powers'] ) ? $raw['powers'] : array();

		$label = isset( $raw['label'] ) ? sanitize_text_field( wp_unslash( $raw['label'] ) ) : '';
		if ( '' === $label ) {
			/* translators: %d: child config position */
			$label = sprintf( __( 'Product %d', 'wc-optic' ), $index + 1 );
		}

		$id = isset( $raw['id'] ) ? sanitize_key( wp_unslash( $raw['id'] ) ) : '';
		if ( '' === $id ) {
			$id = 'child_' . wp_generate_password( 8, false, false );
		}

		$out = array(
			'id'                 => $id,
			'label'              => $label,
			'enabled'            => empty( $raw['enabled'] ) ? false : true,
			'sort'               => isset( $raw['sort'] ) ? (int) $raw['sort'] : $index,
			'unit_price'         => '',
			'stock_qty'          => '',
			'backorder_custom'   => ! empty( $raw['backorder_custom'] ),
			'backorder_qty'      => '',
			'backorder_consumed' => isset( $raw['backorder_consumed'] ) ? (string) max( 0, absint( $raw['backorder_consumed'] ) ) : '0',
			'alert_custom'       => ! empty( $raw['alert_custom'] ),
			'alert_qty'          => '',
			'catalog'            => array(),
			'powers'             => array(),
			'sku'                => '',
		);

		if ( isset( $raw['unit_price'] ) && '' !== trim( (string) $raw['unit_price'] ) ) {
			$out['unit_price'] = (string) wc_format_decimal( wp_unslash( $raw['unit_price'] ) );
		}
		if ( isset( $raw['stock_qty'] ) && '' !== trim( (string) $raw['stock_qty'] ) ) {
			$out['stock_qty'] = (string) absint( wp_unslash( $raw['stock_qty'] ) );
		}
		if ( $out['backorder_custom'] && isset( $raw['backorder_qty'] ) && '' !== trim( (string) $raw['backorder_qty'] ) ) {
			$out['backorder_qty'] = (string) absint( wp_unslash( $raw['backorder_qty'] ) );
		}
		if ( $out['alert_custom'] && isset( $raw['alert_qty'] ) && '' !== trim( (string) $raw['alert_qty'] ) ) {
			$out['alert_qty'] = (string) absint( wp_unslash( $raw['alert_qty'] ) );
		}

		foreach ( self::META_KEYS as $type => $meta_key ) {
			if ( in_array( $type, $power_types, true ) ) {
				$out['powers'][ $type ] = in_array( $type, $allowed_powers, true ) ? self::normalize_catalog_id( $powers[ $type ] ?? 0 ) : 0;
				continue;
			}

			$out['catalog'][ $type ] = self::normalize_catalog_id( $catalog[ $type ] ?? 0 );
		}

		foreach ( $out['catalog'] as $type => $id_value ) {
			if ( $id_value && ! WC_Optic_Catalog::get_valid_term( $id_value, $type ) ) {
				$out['catalog'][ $type ] = 0;
			}
		}

		foreach ( $out['powers'] as $type => $id_value ) {
			if ( $id_value && ! WC_Optic_Catalog::get_valid_term( $id_value, $type ) ) {
				$out['powers'][ $type ] = 0;
			}
		}

		$out['sku'] = self::build_for_child_config( $out, $division );

		return $out;
	}

	/**
	 * Normalize a list of child configs.
	 *
	 * @param array  $raw_configs Raw configs.
	 * @param string $division    Parent division.
	 * @return array<int, array<string, mixed>>
	 */
	public static function normalize_child_configs( array $raw_configs, $division ) {
		$out = array();
		foreach ( array_values( $raw_configs ) as $index => $raw_config ) {
			if ( ! is_array( $raw_config ) ) {
				continue;
			}

			$config = self::normalize_child_config( $raw_config, $division, $index );
			if ( ! self::child_has_any_values( $config ) ) {
				continue;
			}

			if ( isset( $out[ $config['id'] ] ) ) {
				$config['id'] = $config['id'] . '_' . ( $index + 1 );
			}

			$out[ $config['id'] ] = $config;
		}

		return array_values( $out );
	}

	/**
	 * Whether a child has enough data to be considered non-empty.
	 *
	 * @param array $config Child config.
	 * @return bool
	 */
	public static function child_has_any_values( array $config ) {
		if ( ! empty( $config['unit_price'] ) ) {
			return true;
		}

		foreach ( array( 'catalog', 'powers' ) as $key ) {
			$values = isset( $config[ $key ] ) && is_array( $config[ $key ] ) ? $config[ $key ] : array();
			foreach ( $values as $value ) {
				if ( (int) $value > 0 ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Whether a child is complete enough for storefront use.
	 *
	 * @param array  $config   Child config.
	 * @param string $division Parent division.
	 * @return bool
	 */
	public static function child_is_complete( array $config, $division ) {
		if ( empty( $config['unit_price'] ) ) {
			return false;
		}

		foreach ( WC_Optic_Plugin::get_powers_for_division( $division ) as $power ) {
			if ( empty( $config['powers'][ $power ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get child stock quantity, or null when stock is not managed.
	 *
	 * @param array $config Child config.
	 * @return int|null
	 */
	public static function get_child_stock_qty( array $config ) {
		if ( ! isset( $config['stock_qty'] ) || '' === trim( (string) $config['stock_qty'] ) ) {
			return null;
		}

		return max( 0, absint( $config['stock_qty'] ) );
	}

	/**
	 * Whether a child can satisfy the requested quantity.
	 *
	 * @param array $config             Child config.
	 * @param int   $requested_quantity Requested quantity.
	 * @return bool
	 */
	public static function child_is_in_stock( array $config, $requested_quantity = 1 ) {
		$sellable = self::get_child_sellable_qty( $config );
		if ( null === $sellable ) {
			return true;
		}

		return $sellable >= max( 1, (int) $requested_quantity );
	}

	/**
	 * Preserve backorder consumption counters when admin saves child configs.
	 *
	 * @param WC_Product $product       Product.
	 * @param array      $child_configs Normalized child configs.
	 * @return array<int, array<string, mixed>>
	 */
	public static function preserve_child_backorder_consumed( WC_Product $product, array $child_configs ) {
		$existing = array();
		foreach ( self::get_child_configs( $product ) as $config ) {
			$child_id = isset( $config['id'] ) ? (string) $config['id'] : '';
			if ( '' === $child_id ) {
				continue;
			}
			$existing[ $child_id ] = self::get_child_backorder_consumed( $config );
		}

		foreach ( $child_configs as &$config ) {
			$child_id = isset( $config['id'] ) ? (string) $config['id'] : '';
			if ( '' !== $child_id && isset( $existing[ $child_id ] ) ) {
				$config['backorder_consumed'] = (string) $existing[ $child_id ];
			}
		}
		unset( $config );

		return $child_configs;
	}

	/**
	 * Apply a stock delta to one child config, consuming backorder when needed.
	 *
	 * @param array $config Mutable child config.
	 * @param int   $delta  Negative to reduce, positive to restore.
	 */
	public static function apply_child_stock_delta( array &$config, $delta ) {
		$delta = (int) $delta;
		if ( 0 === $delta ) {
			return;
		}

		$current_stock = self::get_child_stock_qty( $config );
		if ( null === $current_stock ) {
			return;
		}

		$qty         = abs( $delta );
		$is_reduce   = $delta < 0;
		$consumed    = self::get_child_backorder_consumed( $config );
		$backorder   = self::get_child_backorder_qty( $config );

		if ( $is_reduce ) {
			$from_stock     = min( $qty, $current_stock );
			$from_backorder = min( $qty - $from_stock, max( 0, $backorder - $consumed ) );
			$config['stock_qty']          = (string) max( 0, $current_stock - $from_stock );
			$config['backorder_consumed'] = (string) ( $consumed + $from_backorder );
			return;
		}

		$from_backorder = min( $qty, $consumed );
		$from_stock     = $qty - $from_backorder;
		$config['backorder_consumed'] = (string) max( 0, $consumed - $from_backorder );
		$config['stock_qty']          = (string) max( 0, $current_stock + $from_stock );
	}

	/**
	 * Get enabled, complete, and currently purchasable child configs.
	 *
	 * @param WC_Product $product Product.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_purchasable_child_configs( WC_Product $product ) {
		$out = array();
		foreach ( self::get_enabled_child_configs( $product ) as $config ) {
			if ( self::child_is_in_stock( $config, 1 ) ) {
				$out[] = $config;
			}
		}

		return $out;
	}

	/**
	 * Whether a child is enabled.
	 *
	 * @param array $config Child config.
	 * @return bool
	 */
	public static function child_is_enabled( array $config ) {
		return ! empty( $config['enabled'] );
	}

	/**
	 * Build a user-facing child choice label.
	 *
	 * @param array  $config   Child config.
	 * @param string $division Parent division.
	 * @return string
	 */
	public static function child_display_label( array $config, $division ) {
		$bits = array();
		foreach ( WC_Optic_Plugin::get_powers_for_division( $division ) as $power ) {
			$id  = isset( $config['powers'][ $power ] ) ? (int) $config['powers'][ $power ] : 0;
			$row = $id ? WC_Optic_Catalog::get_valid_term( $id, $power ) : null;
			if ( $row ) {
				$bits[] = WC_Optic_Catalog::get_power_field_label( $power ) . ': ' . WC_Optic_Catalog::get_display_name( $row );
			}
		}

		if ( empty( $bits ) ) {
			return (string) ( $config['label'] ?? '' );
		}

		return implode( ' | ', $bits );
	}

	/**
	 * Get one child's unit price.
	 *
	 * @param array $config Child config.
	 * @return float
	 */
	public static function get_child_unit_price( array $config ) {
		if ( empty( $config['unit_price'] ) ) {
			return 0.0;
		}
		return (float) wc_format_decimal( $config['unit_price'] );
	}

	/**
	 * Collect unit prices from enabled, complete internal products.
	 *
	 * @param WC_Product $product Product.
	 * @return float[]
	 */
	public static function get_child_unit_prices( WC_Product $product ) {
		$division = (string) $product->get_meta( '_optic_division', true );
		$prices   = array();

		foreach ( self::get_enabled_child_configs( $product ) as $config ) {
			if ( ! self::child_is_complete( $config, $division ) ) {
				continue;
			}
			$price = self::get_child_unit_price( $config );
			if ( $price > 0 ) {
				$prices[] = $price;
			}
		}

		return $prices;
	}

	/**
	 * Get the minimum enabled child price for a product.
	 *
	 * @param WC_Product $product Product.
	 * @return float
	 */
	public static function get_min_child_price( WC_Product $product ) {
		$prices = self::get_child_unit_prices( $product );
		if ( empty( $prices ) ) {
			$configs = self::get_purchasable_child_configs( $product );
			foreach ( $configs as $config ) {
				$price = self::get_child_unit_price( $config );
				if ( $price > 0 ) {
					$prices[] = $price;
				}
			}
		}

		return empty( $prices ) ? 0.0 : (float) min( $prices );
	}

	/**
	 * Get the maximum enabled child price for a product.
	 *
	 * @param WC_Product $product Product.
	 * @return float
	 */
	public static function get_max_child_price( WC_Product $product ) {
		$prices = self::get_child_unit_prices( $product );
		return empty( $prices ) ? 0.0 : (float) max( $prices );
	}

	/**
	 * Min/max unit prices for storefront display.
	 *
	 * @param WC_Product $product Product.
	 * @return array{min: float, max: float}
	 */
	public static function get_child_price_range( WC_Product $product ) {
		$prices = self::get_child_unit_prices( $product );
		if ( empty( $prices ) ) {
			return array(
				'min' => 0.0,
				'max' => 0.0,
			);
		}

		return array(
			'min' => (float) min( $prices ),
			'max' => (float) max( $prices ),
		);
	}

	/**
	 * Persist child configs, derived indexes, and a minimal parent price.
	 *
	 * @param WC_Product $product       Product.
	 * @param array      $child_configs Normalized child configs.
	 */
	public static function persist_child_data( WC_Product $product, array $child_configs ) {
		$product->update_meta_data( self::CHILD_META_KEY, array_values( $child_configs ) );
		$product->delete_meta_data( '_optic_selector_ui' );

		$index = self::build_catalog_index_from_children( $child_configs );
		foreach ( self::INDEX_META_KEYS as $type => $meta_key ) {
			$product->update_meta_data( $meta_key, $index[ $type ] ?? array() );
		}

		$display_price = self::get_default_display_price( $product );

		if ( $display_price > 0 ) {
			$product->set_regular_price( (string) $display_price );
			$product->set_price( (string) $display_price );
		}
	}

	/**
	 * Build derived catalog indexes from children.
	 *
	 * @param array $child_configs Child configs.
	 * @return array<string, array<int, int>>
	 */
	public static function build_catalog_index_from_children( array $child_configs ) {
		$index       = array();
		$power_types = WC_Optic_Catalog::get_power_types();
		foreach ( array_keys( self::INDEX_META_KEYS ) as $type ) {
			$index[ $type ] = array();
		}

		foreach ( $child_configs as $config ) {
			$catalog = isset( $config['catalog'] ) && is_array( $config['catalog'] ) ? $config['catalog'] : array();
			$powers  = isset( $config['powers'] ) && is_array( $config['powers'] ) ? $config['powers'] : array();

			foreach ( $index as $type => $values ) {
				$id = in_array( $type, $power_types, true ) ? self::normalize_catalog_id( $powers[ $type ] ?? 0 ) : self::normalize_catalog_id( $catalog[ $type ] ?? 0 );
				if ( $id ) {
					$index[ $type ][ $id ] = $id;
				}
			}
		}

		foreach ( $index as $type => $values ) {
			$index[ $type ] = array_map( 'strval', array_values( $values ) );
		}

		return $index;
	}

	/**
	 * Get product-level derived catalog ids for one type.
	 *
	 * @param WC_Product $product Product.
	 * @param string     $type    Catalog type.
	 * @return int[]
	 */
	public static function get_product_catalog_ids( WC_Product $product, $type ) {
		if ( isset( self::INDEX_META_KEYS[ $type ] ) ) {
			$ids = self::normalize_catalog_ids( $product->get_meta( self::INDEX_META_KEYS[ $type ], true ) );
			if ( ! empty( $ids ) ) {
				return $ids;
			}
		}

		if ( isset( self::META_KEYS[ $type ] ) ) {
			$legacy = self::normalize_catalog_ids( $product->get_meta( self::META_KEYS[ $type ], true ) );
			if ( ! empty( $legacy ) ) {
				return $legacy;
			}
		}

		$index = self::build_catalog_index_from_children( self::get_child_configs( $product ) );
		return isset( $index[ $type ] ) ? $index[ $type ] : array();
	}

	/**
	 * Get the first saved catalog id for a product/type pair.
	 *
	 * @param WC_Product $product Product.
	 * @param string     $type    Catalog type.
	 * @return int
	 */
	public static function get_primary_product_catalog_id( WC_Product $product, $type ) {
		$ids = self::get_product_catalog_ids( $product, $type );
		return empty( $ids ) ? 0 : (int) reset( $ids );
	}

	/**
	 * Build SKU preview from a raw child config payload.
	 *
	 * @param array  $child_config Raw child config.
	 * @param string $division     Optical division slug.
	 * @return string
	 */
	public static function build_from_catalog_ids( array $child_config, $division = '' ) {
		$config = self::normalize_child_config( $child_config, $division, 0 );
		return self::build_for_child_config( $config, $division );
	}

	/**
	 * Convert legacy flat product meta into synthetic child configs.
	 *
	 * @param WC_Product $product  Product.
	 * @param string     $division Division.
	 * @return array<int, array<string, mixed>>
	 */
	protected static function get_legacy_child_configs( WC_Product $product, $division ) {
		$allowed_powers = WC_Optic_Plugin::get_powers_for_division( $division );
		if ( empty( $allowed_powers ) ) {
			return array();
		}

		$catalog = array();
		foreach ( self::META_KEYS as $type => $meta_key ) {
			if ( in_array( $type, WC_Optic_Catalog::get_power_types(), true ) ) {
				continue;
			}
			$catalog[ $type ] = self::normalize_catalog_id( $product->get_meta( $meta_key, true ) );
		}

		$power_values = array();
		foreach ( $allowed_powers as $power ) {
			$ids = self::normalize_catalog_ids( $product->get_meta( self::META_KEYS[ $power ], true ) );
			if ( empty( $ids ) ) {
				return array();
			}
			$power_values[ $power ] = $ids;
		}

		$combinations = self::expand_power_combinations( $power_values );
		if ( empty( $combinations ) ) {
			return array();
		}

		$price   = (string) wc_format_decimal( $product->get_regular_price( 'edit' ) ? $product->get_regular_price( 'edit' ) : $product->get_price( 'edit' ) );
		$configs = array();
		foreach ( $combinations as $index => $powers ) {
			$configs[] = self::normalize_child_config(
				array(
					'id'         => 'legacy_' . ( $index + 1 ),
					'enabled'    => true,
					'sort'       => $index,
					'label'      => sprintf(
						/* translators: %d: synthetic child position */
						__( 'Product %d', 'wc-optic' ),
						$index + 1
					),
					'unit_price' => $price,
					'catalog'    => $catalog,
					'powers'     => $powers,
				),
				$division,
				$index
			);
		}

		return $configs;
	}

	/**
	 * Catalog types that identify a product (shared by all internals).
	 *
	 * @return string[]
	 */
	public static function get_identity_catalog_types() {
		$out = array();
		foreach ( array_keys( self::META_KEYS ) as $type ) {
			if ( ! in_array( $type, WC_Optic_Catalog::get_power_types(), true ) ) {
				$out[] = $type;
			}
		}
		return $out;
	}

	/**
	 * Identity catalog types required for a division (color omitted when disabled).
	 *
	 * @param string $division Division slug.
	 * @return string[]
	 */
	public static function get_required_identity_types( $division ) {
		$types = self::get_identity_catalog_types();
		if ( ! WC_Optic_Plugin::division_shows_color( $division ) ) {
			$types = array_values( array_diff( $types, array( 'color' ) ) );
		}
		return $types;
	}

	/**
	 * Normalize parent-level catalog identity.
	 *
	 * @param mixed $raw Raw identity map.
	 * @return array<string, int>
	 */
	public static function normalize_identity_catalog( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();
		$out = array();
		foreach ( self::get_identity_catalog_types() as $type ) {
			$out[ $type ] = self::normalize_catalog_id( $raw[ $type ] ?? 0 );
		}
		return $out;
	}

	/**
	 * Get stored parent identity, falling back to the first child.
	 *
	 * @param WC_Product $product Product.
	 * @return array<string, int>
	 */
	public static function get_identity_catalog( WC_Product $product ) {
		$stored = self::normalize_identity_catalog( $product->get_meta( self::IDENTITY_META_KEY, true ) );
		foreach ( $stored as $id ) {
			if ( $id ) {
				return $stored;
			}
		}

		$children = self::get_child_configs( $product );
		if ( empty( $children ) ) {
			return $stored;
		}

		$first = $children[0];
		$catalog = isset( $first['catalog'] ) && is_array( $first['catalog'] ) ? $first['catalog'] : array();
		return self::normalize_identity_catalog( $catalog );
	}

	/**
	 * Copy identity catalog onto every child.
	 *
	 * @param array<string, int> $identity Identity map.
	 * @param array              $children Child configs.
	 * @param string             $division Division.
	 * @return array
	 */
	public static function apply_identity_to_children( array $identity, array $children, $division ) {
		$identity = self::normalize_identity_catalog( $identity );
		$out      = array();
		foreach ( array_values( $children ) as $index => $config ) {
			if ( ! is_array( $config ) ) {
				continue;
			}
			$config['catalog'] = $identity;
			$out[]             = self::normalize_child_config( $config, $division, $index );
		}
		return $out;
	}

	/**
	 * Persist division + identity on the product and rebuild every child catalog/SKU.
	 *
	 * Identity sync must not be blocked by duplicate-power warnings (e.g. after a
	 * division change). Uniqueness is returned for UI notices only.
	 *
	 * @param WC_Product         $product  Product.
	 * @param string             $division Division slug.
	 * @param array<string, int> $identity Identity catalog map.
	 * @return array{rows:array,count:int,unique:true|WP_Error}
	 */
	public static function sync_identity_on_product( WC_Product $product, $division, array $identity ) {
		$divs = WC_Optic_Plugin::get_divisions();
		$division = sanitize_key( (string) $division );
		if ( $division && ! isset( $divs[ $division ] ) ) {
			$division = '';
		}

		$identity = self::normalize_identity_catalog( $identity );
		// When the division hides color, never keep a stale color on internals/SKUs.
		if ( $division && ! WC_Optic_Plugin::division_shows_color( $division ) ) {
			$identity['color'] = 0;
		}

		$product->update_meta_data( '_optic_division', $division );
		$product->update_meta_data( self::IDENTITY_META_KEY, $identity );

		$children = self::get_child_configs( $product );
		$unique   = true;

		if ( ! empty( $children ) ) {
			$children = self::apply_identity_to_children( $identity, $children, $division );
			$unique   = self::validate_unique_power_combinations( $children, $division );
			$children = self::preserve_child_backorder_consumed( $product, $children );
			self::persist_child_data( $product, $children );
		}

		self::sync_product_sku( $product );
		$product->save();

		return array(
			'rows'     => self::get_child_list_rows( $product ),
			'count'    => count( self::get_child_configs( $product ) ),
			'unique'   => $unique,
			'identity' => $identity,
			'division' => $division,
		);
	}

	/**
	 * Normalize posted or stored power ranges.
	 *
	 * @param mixed  $raw      Raw ranges.
	 * @param string $division Division slug.
	 * @return array<string, array{from:string,to:string,step:string}>
	 */
	public static function normalize_power_ranges( $raw, $division ) {
		$raw     = is_array( $raw ) ? $raw : array();
		$allowed = $division ? WC_Optic_Plugin::get_powers_for_division( $division ) : WC_Optic_Catalog::get_power_types();
		$out     = array();
		foreach ( $allowed as $power ) {
			$row  = isset( $raw[ $power ] ) && is_array( $raw[ $power ] ) ? $raw[ $power ] : array();
			$step = isset( $row['step'] ) ? (string) $row['step'] : '';
			if ( '' === trim( $step ) ) {
				$step = (string) WC_Optic_Catalog::get_default_power_step( $power );
			}
			$out[ $power ] = array(
				'from' => isset( $row['from'] ) ? (string) $row['from'] : '',
				'to'   => isset( $row['to'] ) ? (string) $row['to'] : '',
				'step' => $step,
			);
		}
		return $out;
	}

	/**
	 * Count cartesian combinations for power ID lists.
	 *
	 * @param array<string, array<int, int>> $power_values Power value ids by type.
	 * @return int
	 */
	public static function count_power_combinations( array $power_values ) {
		$count = 1;
		foreach ( $power_values as $ids ) {
			$n = count( $ids );
			if ( $n < 1 ) {
				return 0;
			}
			$count *= $n;
		}
		return (int) $count;
	}

	/**
	 * Build internal products from catalog IDs (same shape as the product editor).
	 *
	 * @param string             $division    Division slug.
	 * @param array<string, int> $catalog     Shared identity catalog.
	 * @param array<string, int[]> $power_values Power ids by type.
	 * @param mixed              $unit_price  Unit price.
	 * @param mixed              $stock_qty   Stock qty.
	 * @return array|WP_Error
	 */
	public static function build_children_from_spec( $division, array $catalog, array $power_values, $unit_price, $stock_qty ) {
		$allowed = WC_Optic_Plugin::get_powers_for_division( $division );
		if ( empty( $allowed ) ) {
			return new WP_Error( 'wc_optic_missing_division', __( 'Optical division is required.', 'wc-optic' ) );
		}

		$filtered = array();
		foreach ( $allowed as $power ) {
			$ids = isset( $power_values[ $power ] ) ? array_values( array_filter( array_map( 'absint', (array) $power_values[ $power ] ) ) ) : array();
			if ( empty( $ids ) ) {
				return new WP_Error(
					'wc_optic_missing_power_range',
					sprintf(
						/* translators: %s: power type label */
						__( 'A range is required for %s.', 'wc-optic' ),
						WC_Optic_Catalog::get_type_label( $power )
					)
				);
			}
			$filtered[ $power ] = $ids;
		}

		$count = self::count_power_combinations( $filtered );
		if ( $count < 1 ) {
			return new WP_Error( 'wc_optic_empty_combinations', __( 'No internal products could be generated from this range.', 'wc-optic' ) );
		}
		if ( $count > self::MAX_LEGACY_SYNTHETIC_CHILDREN ) {
			return new WP_Error(
				'wc_optic_too_many_children',
				sprintf(
					/* translators: 1: generated count, 2: max allowed */
					__( 'This range would create %1$d internal products (maximum %2$d). Narrow a range or increase the step.', 'wc-optic' ),
					$count,
					self::MAX_LEGACY_SYNTHETIC_CHILDREN
				)
			);
		}

		$identity = self::normalize_identity_catalog( $catalog );
		foreach ( self::get_required_identity_types( $division ) as $type ) {
			if ( (int) ( $identity[ $type ] ?? 0 ) < 1 ) {
				return new WP_Error(
					'wc_optic_missing_identity',
					sprintf(
						/* translators: %s: catalog field label */
						__( 'Choose a value for %s once; it is copied to every internal product.', 'wc-optic' ),
						WC_Optic_Catalog::get_type_label( $type )
					)
				);
			}
		}

		$price = ( '' === trim( (string) $unit_price ) ) ? '' : (string) wc_format_decimal( $unit_price );
		if ( '' === $price ) {
			return new WP_Error( 'wc_optic_missing_price', __( 'A unit price is required to generate internal products.', 'wc-optic' ) );
		}

		$stock = ( '' === trim( (string) $stock_qty ) ) ? '0' : (string) absint( $stock_qty );
		$combos = self::expand_power_combinations( $filtered );
		$children = array();

		foreach ( $combos as $index => $powers ) {
			$config = self::normalize_child_config(
				array(
					'enabled'    => true,
					'sort'       => $index,
					'unit_price' => $price,
					'stock_qty'  => $stock,
					'catalog'    => $identity,
					'powers'     => $powers,
				),
				$division,
				$index
			);
			$config['label'] = self::child_display_label( $config, $division );
			$children[]      = $config;
		}

		$complete = self::validate_child_configs_complete( $children, $division, $children );
		if ( is_wp_error( $complete ) ) {
			return $complete;
		}

		$unique = self::validate_unique_power_combinations( $children, $division );
		if ( is_wp_error( $unique ) ) {
			return $unique;
		}

		return $children;
	}

	/**
	 * Count internals a range set would create, without writing catalog terms.
	 *
	 * @param string $division Division slug.
	 * @param array  $ranges   Ranges keyed by power.
	 * @return int|WP_Error
	 */
	public static function count_children_from_ranges( $division, array $ranges ) {
		$ranges = self::normalize_power_ranges( $ranges, $division );
		$count  = 1;
		foreach ( $ranges as $power => $range ) {
			$n = WC_Optic_Catalog::count_power_range( $power, $range['from'], $range['to'], $range['step'] );
			if ( is_wp_error( $n ) ) {
				return $n;
			}
			if ( $n < 1 ) {
				return 0;
			}
			$count *= (int) $n;
		}
		return (int) $count;
	}

	/**
	 * Build internals from numeric from/to/step ranges (creates missing catalog terms).
	 *
	 * @param string             $division   Division slug.
	 * @param array<string, int> $catalog    Shared identity.
	 * @param array              $ranges     Ranges keyed by power.
	 * @param mixed              $unit_price Unit price.
	 * @param mixed              $stock_qty  Stock qty.
	 * @return array|WP_Error
	 */
	public static function build_children_from_ranges( $division, array $catalog, array $ranges, $unit_price, $stock_qty ) {
		$ranges       = self::normalize_power_ranges( $ranges, $division );
		$power_values = array();

		foreach ( $ranges as $power => $range ) {
			$ids = WC_Optic_Catalog::resolve_power_range(
				$power,
				$range['from'],
				$range['to'],
				$range['step'],
				self::MAX_LEGACY_SYNTHETIC_CHILDREN
			);
			if ( is_wp_error( $ids ) ) {
				return $ids;
			}
			$power_values[ $power ] = $ids;
		}

		return self::build_children_from_spec( $division, $catalog, $power_values, $unit_price, $stock_qty );
	}

	/**
	 * Expand selected powers into concrete child combinations.
	 *
	 * @param array<string, array<int, int>> $power_values Power value ids by type.
	 * @return array<int, array<string, int>>
	 */
	public static function expand_power_combinations( array $power_values ) {
		$combinations = array( array() );

		foreach ( $power_values as $power => $ids ) {
			$next = array();
			foreach ( $combinations as $combination ) {
				foreach ( $ids as $id ) {
					$combination[ $power ] = (int) $id;
					$next[]                = $combination;
					if ( count( $next ) >= self::MAX_LEGACY_SYNTHETIC_CHILDREN ) {
						return $next;
					}
				}
			}
			$combinations = $next;
		}

		return $combinations;
	}
}
