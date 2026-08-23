<?php
/**
 * CRUD for optical catalog terms (global values).
 *
 * @package WC_Optic_Product
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Optic_Catalog
 */
class WC_Optic_Catalog {

	/**
	 * Request cache of catalog rows by power type.
	 *
	 * @var array<string, array<int, object>>
	 */
	protected static $power_term_cache = array();

	const TYPES = array(
		'section',
		'company',
		'brand',
		'timing',
		'color',
		'sph',
		'cyl',
		'axis',
		'add',
		'pack',
		'transparency',
	);

	/**
	 * Prescription power catalog types (replaces legacy single "rx" list).
	 *
	 * @return string[]
	 */
	public static function get_power_types() {
		return array( 'sph', 'cyl', 'axis', 'add' );
	}

	/**
	 * Human-readable label for a catalog type tab.
	 *
	 * @param string $type Type key.
	 * @return string
	 */
	public static function get_type_label( $type ) {
		$labels = self::get_type_labels();
		return isset( $labels[ $type ] ) ? $labels[ $type ] : $type;
	}

	/**
	 * All catalog type labels keyed by slug.
	 *
	 * @return array<string, string>
	 */
	public static function get_type_labels() {
		return array(
			'section'      => __( 'Sections', 'wc-optic' ),
			'company'      => __( 'Companies', 'wc-optic' ),
			'brand'        => __( 'Brands', 'wc-optic' ),
			'timing'       => __( 'Timings', 'wc-optic' ),
			'color'        => __( 'Colors', 'wc-optic' ),
			'sph'          => __( 'SPH', 'wc-optic' ),
			'cyl'          => __( 'CYL', 'wc-optic' ),
			'axis'         => __( 'AXIS', 'wc-optic' ),
			'add'          => __( 'ADD', 'wc-optic' ),
			'pack'         => __( 'Packs', 'wc-optic' ),
			'transparency' => __( 'Transparency', 'wc-optic' ),
		);
	}

	/**
	 * List terms by type.
	 *
	 * @param string $term_type Type key.
	 * @return array<int, object>
	 */
	public static function get_terms( $term_type ) {
		global $wpdb;
		$table = WC_Optic_Database::table_catalog();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE term_type = %s ORDER BY sort_order ASC, name ASC", $term_type ) );
	}

	/**
	 * Get single row by id.
	 *
	 * @param int $id Row id.
	 * @return object|null
	 */
	public static function get_term( $id ) {
		global $wpdb;
		$table = WC_Optic_Database::table_catalog();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	/**
	 * Find by type and slug.
	 *
	 * @param string $term_type Type.
	 * @param string $slug Slug.
	 * @return object|null
	 */
	public static function get_by_slug( $term_type, $slug ) {
		global $wpdb;
		$table = WC_Optic_Database::table_catalog();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE term_type = %s AND slug = %s", $term_type, self::sanitize_slug( $slug ) ) );
	}

	/**
	 * Insert term.
	 *
	 * @param string $term_type Type.
	 * @param string $name Display name.
	 * @param string $slug Slug.
	 * @param string $sku_fragment SKU fragment.
	 * @param int    $sort_order Order.
	 * @return int|false Insert id or false on duplicate/error.
	 */
	public static function insert( $term_type, $name, $slug, $sku_fragment = '', $sort_order = 0 ) {
		global $wpdb;
		$table = WC_Optic_Database::table_catalog();
		$slug  = self::sanitize_slug( $slug ? $slug : $name );
		$sku_fragment = self::sanitize_sku_fragment( $sku_fragment );
		$res   = $wpdb->insert(
			$table,
			array(
				'term_type'    => $term_type,
				'slug'         => $slug,
				'name'         => $name,
				'sku_fragment' => $sku_fragment,
				'sort_order'   => (int) $sort_order,
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s' )
		);
		if ( ! $res ) {
			return false;
		}
		$id = (int) $wpdb->insert_id;
		do_action( 'wc_optic_catalog_term_saved', $id, $name, $term_type );
		return $id;
	}

	/**
	 * Update term.
	 *
	 * @param int   $id ID.
	 * @param array $data Fields.
	 * @return bool
	 */
	public static function update( $id, array $data ) {
		global $wpdb;
		$table = WC_Optic_Database::table_catalog();
		$ok    = (bool) $wpdb->update( $table, $data, array( 'id' => $id ) );
		if ( $ok && isset( $data['name'] ) ) {
			$row = self::get_term( $id );
			$type = $row && isset( $row->term_type ) ? (string) $row->term_type : '';
			do_action( 'wc_optic_catalog_term_saved', (int) $id, (string) $data['name'], $type );
		}
		return $ok;
	}

	/**
	 * Delete term.
	 *
	 * @param int $id ID.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		$table = WC_Optic_Database::table_catalog();
		$id    = (int) $id;
		do_action( 'wc_optic_catalog_term_deleted', $id );
		return (bool) $wpdb->delete( $table, array( 'id' => $id ) );
	}

	/**
	 * Localized display name for a catalog row (WPML String Translation when active).
	 *
	 * @param object|null $row Catalog row from get_term() / get_valid_term().
	 * @return string
	 */
	public static function get_display_name( $row ) {
		if ( ! $row || ! isset( $row->name ) ) {
			return '';
		}
		return (string) apply_filters( 'wc_optic_catalog_display_name', (string) $row->name, $row );
	}

	/**
	 * Valid type check.
	 *
	 * @param string $type Type.
	 * @return bool
	 */
	public static function is_valid_type( $type ) {
		return in_array( $type, self::TYPES, true );
	}

	/**
	 * Validate a catalog row id belongs to the expected power/type.
	 *
	 * @param int    $id Catalog row id.
	 * @param string $term_type Expected term_type (e.g. sph, axis).
	 * @return object|null Row or null if invalid.
	 */
	public static function get_valid_term( $id, $term_type ) {
		$id = absint( $id );
		if ( ! $id || ! self::is_valid_type( $term_type ) ) {
			return null;
		}
		$row = self::get_term( $id );
		if ( ! $row || (string) $row->term_type !== (string) $term_type ) {
			return null;
		}
		return $row;
	}

	/**
	 * Display label for a power field (AXIS not AXIS from strtoupper axis).
	 *
	 * @param string $power Power key (sph, cyl, axis, add).
	 * @return string
	 */
	public static function get_power_field_label( $power ) {
		if ( in_array( $power, self::get_power_types(), true ) ) {
			return self::get_type_label( $power );
		}
		return strtoupper( $power );
	}

	/**
	 * Default increment for a power type.
	 *
	 * @param string $type Power type.
	 * @return float
	 */
	public static function get_default_power_step( $type ) {
		return 'axis' === $type ? 10.0 : 0.25;
	}

	/**
	 * Integer scale used to enumerate a power range without float drift.
	 *
	 * @param string $type Power type.
	 * @return int
	 */
	public static function get_power_scale( $type ) {
		return 'axis' === $type ? 1 : 100;
	}

	/**
	 * Parse a power number from admin/catalog input.
	 *
	 * @param mixed $raw Raw value.
	 * @return float|null
	 */
	public static function parse_power_number( $raw ) {
		$value = trim( (string) $raw );
		if ( '' === $value ) {
			return null;
		}

		$value = str_replace( array( '−', '–', '—' ), '-', $value );
		$value = str_replace( ',', '.', $value );
		$value = preg_replace( '/\s+/u', '', $value );
		$value = str_replace(
			array( '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩', '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' ),
			array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ),
			$value
		);
		if ( is_numeric( $value ) ) {
			return (float) $value;
		}

		return null;
	}

	/**
	 * Format a power number as catalog name / SKU fragment.
	 *
	 * @param string $type   Power type.
	 * @param float  $number Numeric value.
	 * @return string
	 */
	public static function format_power_value( $type, $number ) {
		$number = (float) $number;
		if ( 'axis' === $type ) {
			return (string) (int) round( $number );
		}

		$formatted = number_format( abs( $number ), 2, '.', '' );
		if ( $number < 0 ) {
			return '-' . $formatted;
		}

		return '+' . $formatted;
	}

	/**
	 * Extract a numeric power from a catalog row when possible.
	 *
	 * @param object|null $row Catalog row.
	 * @return float|null
	 */
	public static function parse_power_number_from_row( $row ) {
		if ( ! $row ) {
			return null;
		}

		foreach ( array( 'name', 'sku_fragment', 'slug' ) as $field ) {
			if ( ! isset( $row->{$field} ) ) {
				continue;
			}
			$parsed = self::parse_power_number( $row->{$field} );
			if ( null !== $parsed ) {
				return $parsed;
			}
		}

		return null;
	}

	/**
	 * Find an existing power term that matches a numeric value.
	 *
	 * @param string $type   Power type.
	 * @param float  $number Numeric value.
	 * @return object|null
	 */
	public static function find_power_term_by_value( $type, $number ) {
		if ( ! in_array( $type, self::get_power_types(), true ) ) {
			return null;
		}

		$formatted  = self::format_power_value( $type, $number );
		$candidates = array( $formatted, ltrim( $formatted, '+' ), (string) (int) round( (float) $number ) );
		if ( self::power_number_is_zero( $number ) ) {
			$candidates = array_merge( $candidates, array( '+0.00', '0.00', '0', '+0', '+000', '000' ) );
		}
		foreach ( array_unique( $candidates ) as $candidate ) {
			if ( '' === $candidate ) {
				continue;
			}
			$by_slug = self::get_by_slug( $type, $candidate );
			if ( $by_slug ) {
				return $by_slug;
			}
		}

		if ( ! isset( self::$power_term_cache[ $type ] ) ) {
			self::$power_term_cache[ $type ] = self::get_terms( $type );
		}

		$scale = self::get_power_scale( $type );
		$want  = (int) round( (float) $number * $scale );

		foreach ( self::$power_term_cache[ $type ] as $row ) {
			$parsed = self::parse_power_number_from_row( $row );
			if ( null === $parsed ) {
				continue;
			}
			if ( (int) round( $parsed * $scale ) === $want ) {
				return $row;
			}
			if ( self::power_number_is_zero( $number ) && self::sph_term_is_zero_power( $row ) ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Get or create a catalog term for one power value.
	 *
	 * @param string $type   Power type.
	 * @param float  $number Numeric value.
	 * @return object|WP_Error
	 */
	public static function get_or_create_power_term( $type, $number ) {
		if ( ! in_array( $type, self::get_power_types(), true ) ) {
			return new WP_Error( 'wc_optic_invalid_power_type', __( 'Invalid power type.', 'wc-optic' ) );
		}

		$existing = self::find_power_term_by_value( $type, $number );
		if ( $existing ) {
			return $existing;
		}

		$name = self::format_power_value( $type, $number );
		$sort = (int) round( (float) $number * self::get_power_scale( $type ) );
		$id   = self::insert( $type, $name, $name, $name, $sort );
		if ( ! $id ) {
			$existing = self::find_power_term_by_value( $type, $number );
			if ( $existing ) {
				return $existing;
			}

			return new WP_Error(
				'wc_optic_power_term_create_failed',
				sprintf(
					/* translators: 1: power type label, 2: formatted value */
					__( 'Could not create catalog value %1$s %2$s.', 'wc-optic' ),
					self::get_type_label( $type ),
					$name
				)
			);
		}

		$row = self::get_term( $id );
		if ( ! $row ) {
			return new WP_Error( 'wc_optic_power_term_create_failed', __( 'Could not create catalog value.', 'wc-optic' ) );
		}
		if ( ! isset( self::$power_term_cache[ $type ] ) ) {
			self::$power_term_cache[ $type ] = array();
		}
		self::$power_term_cache[ $type ][] = $row;
		return $row;
	}

	/**
	 * Count values in a from/to/step range without creating catalog terms.
	 *
	 * @param string $type Power type.
	 * @param mixed  $from Range start.
	 * @param mixed  $to   Range end.
	 * @param mixed  $step Increment.
	 * @return int|WP_Error
	 */
	public static function count_power_range( $type, $from, $to, $step ) {
		$values = self::enumerate_power_range_values( $type, $from, $to, $step );
		if ( is_wp_error( $values ) ) {
			return $values;
		}
		return count( $values );
	}

	/**
	 * Whether a numeric power is plano / zero.
	 *
	 * @param float $number Value.
	 * @return bool
	 */
	public static function power_number_is_zero( $number ) {
		return abs( (float) $number ) < 0.0001;
	}

	/**
	 * List numeric values for a range (always includes 0.00 when 0 is inside the bounds).
	 *
	 * @param string $type Power type.
	 * @param mixed  $from Range start.
	 * @param mixed  $to   Range end.
	 * @param mixed  $step Increment.
	 * @return float[]|WP_Error
	 */
	public static function enumerate_power_range_values( $type, $from, $to, $step ) {
		$bounds = self::normalize_power_range_bounds( $type, $from, $to, $step );
		if ( is_wp_error( $bounds ) ) {
			return $bounds;
		}

		$values = array();
		$seen   = array();
		for ( $value = (int) $bounds['from']; $value <= (int) $bounds['to']; $value += (int) $bounds['step'] ) {
			$number = $value / (int) $bounds['scale'];
			$key    = (string) (int) round( $number * (int) $bounds['scale'] );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$values[]     = $number;
		}

		if ( (int) $bounds['from'] <= 0 && (int) $bounds['to'] >= 0 ) {
			$zero_key = '0';
			if ( ! isset( $seen[ $zero_key ] ) ) {
				$values[] = 0.0;
			}
		}

		usort(
			$values,
			static function ( $a, $b ) {
				return $a <=> $b;
			}
		);

		return $values;
	}

	/**
	 * Normalize range bounds to integer scale.
	 *
	 * @param string $type Power type.
	 * @param mixed  $from Range start.
	 * @param mixed  $to   Range end.
	 * @param mixed  $step Increment.
	 * @return array{from:int,to:int,step:int,scale:int,count:int}|WP_Error
	 */
	public static function normalize_power_range_bounds( $type, $from, $to, $step ) {
		if ( ! in_array( $type, self::get_power_types(), true ) ) {
			return new WP_Error( 'wc_optic_invalid_power_type', __( 'Invalid power type.', 'wc-optic' ) );
		}

		$from_n = self::parse_power_number( $from );
		$to_n   = self::parse_power_number( $to );
		$step_n = self::parse_power_number( $step );
		if ( null === $step_n ) {
			$step_n = self::get_default_power_step( $type );
		}

		if ( null === $from_n || null === $to_n || $step_n <= 0 ) {
			return new WP_Error(
				'wc_optic_invalid_power_range',
				sprintf(
					/* translators: %s: power type label */
					__( 'The %s range needs a from value, a to value, and a positive step.', 'wc-optic' ),
					self::get_type_label( $type )
				)
			);
		}

		$scale  = self::get_power_scale( $type );
		$from_i = (int) round( $from_n * $scale );
		$to_i   = (int) round( $to_n * $scale );
		$step_i = (int) round( $step_n * $scale );
		if ( $step_i < 1 ) {
			return new WP_Error(
				'wc_optic_invalid_power_step',
				sprintf(
					/* translators: %s: power type label */
					__( 'The %s step is too small.', 'wc-optic' ),
					self::get_type_label( $type )
				)
			);
		}

		if ( $from_i > $to_i ) {
			$tmp    = $from_i;
			$from_i = $to_i;
			$to_i   = $tmp;
		}

		return array(
			'from'  => $from_i,
			'to'    => $to_i,
			'step'  => $step_i,
			'scale' => $scale,
			'count' => (int) floor( ( $to_i - $from_i ) / $step_i ) + 1,
		);
	}

	/**
	 * Resolve a from/to/step range into catalog term IDs, creating missing values.
	 *
	 * @param string $type  Power type.
	 * @param mixed  $from  Range start.
	 * @param mixed  $to    Range end.
	 * @param mixed  $step  Increment.
	 * @param int    $max   Max values allowed.
	 * @return int[]|WP_Error
	 */
	public static function resolve_power_range( $type, $from, $to, $step, $max = 200 ) {
		if ( ! in_array( $type, self::get_power_types(), true ) ) {
			return new WP_Error( 'wc_optic_invalid_power_type', __( 'Invalid power type.', 'wc-optic' ) );
		}

		$numbers = self::enumerate_power_range_values( $type, $from, $to, $step );
		if ( is_wp_error( $numbers ) ) {
			return $numbers;
		}

		$count = count( $numbers );
		$max   = max( 1, (int) $max );
		if ( $count > $max ) {
			return new WP_Error(
				'wc_optic_power_range_too_large',
				sprintf(
					/* translators: 1: power type label, 2: generated count, 3: max allowed */
					__( 'The %1$s range would create %2$d values (maximum %3$d). Narrow the range or increase the step.', 'wc-optic' ),
					self::get_type_label( $type ),
					$count,
					$max
				)
			);
		}

		$ids = array();
		foreach ( $numbers as $number ) {
			$row = self::get_or_create_power_term( $type, $number );
			if ( is_wp_error( $row ) ) {
				return $row;
			}
			$ids[] = (int) $row->id;
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Whether an SPH catalog row represents plano / zero power (+0.00).
	 *
	 * @param object|null $row SPH catalog row.
	 * @return bool
	 */
	public static function sph_term_is_zero_power( $row ) {
		if ( ! $row ) {
			return false;
		}

		$candidates = array(
			isset( $row->name ) ? (string) $row->name : '',
			isset( $row->slug ) ? (string) $row->slug : '',
			isset( $row->sku_fragment ) ? (string) $row->sku_fragment : '',
		);

		foreach ( $candidates as $value ) {
			if ( self::sph_value_is_zero_power( $value ) ) {
				return true;
			}
		}

		/**
		 * Filter whether an SPH catalog row is treated as zero power (+0.00).
		 *
		 * @param bool   $is_zero Whether the row is zero power.
		 * @param object $row     SPH catalog row.
		 */
		return (bool) apply_filters( 'wc_optic_sph_is_zero_power', false, $row );
	}

	/**
	 * Whether a display/slug/SKU fragment value represents plano (+0.00).
	 *
	 * @param string $value Raw value.
	 * @return bool
	 */
	public static function sph_value_is_zero_power( $value ) {
		$value = strtolower( trim( (string) $value ) );
		if ( '' === $value ) {
			return false;
		}

		if ( in_array( $value, array( 'plano', 'plan', 'zero', '0', '+0', '+0.00', '0.00', '+000', '000' ), true ) ) {
			return true;
		}

		$normalized = preg_replace( '/^\+/u', '', $value );
		$normalized = str_replace( ',', '.', $normalized );
		if ( is_numeric( $normalized ) && abs( (float) $normalized ) < 0.0001 ) {
			return true;
		}

		return false;
	}

	/**
	 * SKU fragment as entered (keeps +, -, etc.; used in product SKU).
	 *
	 * @param string $raw Raw fragment.
	 * @return string
	 */
	public static function sanitize_sku_fragment( $raw ) {
		return trim( wp_unslash( (string) $raw ) );
	}

	/**
	 * Slug for catalog rows: allows + and - in labels. Unlike sanitize_title(), does not strip these.
	 *
	 * @param string $raw Slug or name to derive from.
	 * @return string
	 */
	public static function sanitize_slug( $raw ) {
		$s = trim( wp_unslash( (string) $raw ) );
		$s = preg_replace( '/\s+/u', '-', $s );
		// Letters (incl. Arabic etc.), digits, underscore, plus, hyphen.
		$s = preg_replace( '/[^\p{L}\p{N}_+\-]/u', '', $s );
		$s = preg_replace( '/-{2,}/u', '-', $s );
		$s = trim( $s, '-_' );
		if ( '' === $s ) {
			return '';
		}
		if ( function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( $s, 'UTF-8' );
		}
		return strtolower( $s );
	}
}
