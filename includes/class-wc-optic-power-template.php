<?php
/**
 * Reusable power range presets (one power type per template).
 *
 * @package WC_Optic_Product
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Optic_Power_Template
 */
class WC_Optic_Power_Template {

	const OPTION_KEY = 'wc_optic_power_templates';

	/**
	 * All templates (migrates legacy division-based rows on read).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_all() {
		self::maybe_migrate();

		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$out = array();
		foreach ( $stored as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$clean = self::sanitize( $row );
			if ( $clean ) {
				$out[ $clean['id'] ] = $clean;
			}
		}

		return array_values( $out );
	}

	/**
	 * One template by id.
	 *
	 * @param string $id Template id.
	 * @return array<string, mixed>|null
	 */
	public static function get( $id ) {
		$id = sanitize_key( (string) $id );
		if ( '' === $id ) {
			return null;
		}
		foreach ( self::get_all() as $row ) {
			if ( $row['id'] === $id ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Templates for one power type.
	 *
	 * @param string $power Power slug (sph|cyl|axis|add).
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_for_power( $power ) {
		$power = sanitize_key( (string) $power );
		$out   = array();
		foreach ( self::get_all() as $row ) {
			if ( $row['power'] === $power ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * Templates grouped by power for JS config.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function get_grouped_by_power() {
		$grouped = array();
		foreach ( WC_Optic_Catalog::get_power_types() as $power ) {
			$grouped[ $power ] = array();
		}
		foreach ( self::get_all() as $row ) {
			$power = $row['power'];
			if ( ! isset( $grouped[ $power ] ) ) {
				$grouped[ $power ] = array();
			}
			$grouped[ $power ][] = $row;
		}
		return $grouped;
	}

	/**
	 * @deprecated Use get_for_power().
	 * @param string $division Division slug.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_for_division( $division ) {
		unset( $division );
		return self::get_all();
	}

	/**
	 * Sanitize one template (power + segments).
	 *
	 * @param array $raw Raw data.
	 * @return array<string, mixed>|null
	 */
	public static function sanitize( array $raw ) {
		$name  = isset( $raw['name'] ) ? sanitize_text_field( wp_unslash( $raw['name'] ) ) : '';
		$power = isset( $raw['power'] ) ? sanitize_key( wp_unslash( $raw['power'] ) ) : '';
		if ( '' === $name || ! in_array( $power, WC_Optic_Catalog::get_power_types(), true ) ) {
			return null;
		}

		$id = isset( $raw['id'] ) ? sanitize_key( (string) $raw['id'] ) : '';
		if ( '' === $id ) {
			$id = 'tpl_' . wp_generate_password( 8, false, false );
		}

		$segment_raw = array();
		if ( isset( $raw['segments'] ) && is_array( $raw['segments'] ) ) {
			$segment_raw = $raw['segments'];
		} elseif ( isset( $raw['ranges'][ $power ] ) ) {
			$segment_raw = $raw['ranges'][ $power ];
		} elseif ( isset( $raw['from'] ) || isset( $raw['to'] ) ) {
			$segment_raw = array( $raw );
		}

		$segments = WC_Optic_SKU::normalize_power_range_segments( is_array( $segment_raw ) ? $segment_raw : array(), $power );
		if ( ! $segments ) {
			return null;
		}

		foreach ( $segments as $segment ) {
			$bounds = WC_Optic_Catalog::normalize_power_range_bounds(
				$power,
				$segment['from'],
				$segment['to'],
				$segment['step']
			);
			if ( is_wp_error( $bounds ) ) {
				return null;
			}
		}

		return array(
			'id'       => $id,
			'name'     => $name,
			'power'    => $power,
			'segments' => $segments,
		);
	}

	/**
	 * Human summary of template segments.
	 *
	 * @param array $template Template.
	 * @return string
	 */
	public static function format_segments_summary( array $template ) {
		$clean = isset( $template['segments'] ) ? $template : self::sanitize( $template );
		if ( ! $clean || empty( $clean['segments'] ) ) {
			return '';
		}
		$parts = array();
		foreach ( $clean['segments'] as $segment ) {
			$parts[] = sprintf(
				'%s→%s / %s',
				$segment['from'],
				$segment['to'],
				$segment['step']
			);
		}
		return implode( ' · ', $parts );
	}

	/**
	 * Count unique values this single-power template would produce.
	 *
	 * @param array $template Template.
	 * @return int|WP_Error
	 */
	public static function count_values( array $template ) {
		$clean = self::sanitize( $template );
		if ( ! $clean ) {
			return new WP_Error( 'wc_optic_invalid_template', __( 'Invalid power range template.', 'wc-optic' ) );
		}
		return WC_Optic_Catalog::count_power_range_segments( $clean['power'], $clean['segments'] );
	}

	/**
	 * @deprecated Single-power templates no longer imply a full product cartesian count.
	 * @param array $template Template.
	 * @return int|WP_Error
	 */
	public static function count_children( array $template ) {
		return self::count_values( $template );
	}

	/**
	 * Save one template (insert or update).
	 *
	 * @param array $raw Raw data.
	 * @return array|WP_Error
	 */
	public static function save( array $raw ) {
		$clean = self::sanitize( $raw );
		if ( ! $clean ) {
			return new WP_Error(
				'wc_optic_invalid_template',
				__( 'Name, power type, and a valid from / to / step range are required (from must be ≤ to).', 'wc-optic' )
			);
		}

		$count = self::count_values( $clean );
		if ( is_wp_error( $count ) ) {
			return $count;
		}

		self::maybe_migrate();

		$stored  = get_option( self::OPTION_KEY, array() );
		$stored  = is_array( $stored ) ? $stored : array();
		$all     = array();
		$updated = false;

		foreach ( $stored as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$existing = self::sanitize( $row );
			if ( ! $existing ) {
				continue;
			}
			if ( $existing['id'] === $clean['id'] ) {
				$all[]   = $clean;
				$updated = true;
			} else {
				$all[] = $existing;
			}
		}
		if ( ! $updated ) {
			$all[] = $clean;
		}

		update_option( self::OPTION_KEY, array_values( $all ), false );
		return $clean;
	}

	/**
	 * Delete a template.
	 *
	 * @param string $id Template id.
	 * @return bool
	 */
	public static function delete( $id ) {
		$id = sanitize_key( (string) $id );
		self::maybe_migrate();

		$stored = get_option( self::OPTION_KEY, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$out    = array();
		$ok     = false;
		foreach ( $stored as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$existing = self::sanitize( $row );
			if ( ! $existing ) {
				continue;
			}
			if ( $existing['id'] === $id ) {
				$ok = true;
				continue;
			}
			$out[] = $existing;
		}
		if ( $ok ) {
			update_option( self::OPTION_KEY, array_values( $out ), false );
		}
		return $ok;
	}

	/**
	 * Split legacy division-based templates into one template per power.
	 */
	public static function maybe_migrate() {
		$flag = 'wc_optic_power_templates_v2';
		if ( get_option( $flag ) ) {
			return;
		}

		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) || ! $stored ) {
			update_option( $flag, '1', false );
			return;
		}

		$needs = false;
		foreach ( $stored as $row ) {
			if ( is_array( $row ) && ( isset( $row['division'] ) || ( isset( $row['ranges'] ) && ! isset( $row['power'] ) ) ) ) {
				$needs = true;
				break;
			}
		}
		if ( ! $needs ) {
			update_option( $flag, '1', false );
			return;
		}

		$migrated = array();
		foreach ( $stored as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( isset( $row['power'] ) && isset( $row['segments'] ) ) {
				$clean = self::sanitize( $row );
				if ( $clean ) {
					$migrated[ $clean['id'] ] = $clean;
				}
				continue;
			}

			$name     = isset( $row['name'] ) ? sanitize_text_field( $row['name'] ) : '';
			$base_id  = isset( $row['id'] ) ? sanitize_key( (string) $row['id'] ) : ( 'tpl_' . wp_generate_password( 8, false, false ) );
			$division = isset( $row['division'] ) ? sanitize_key( $row['division'] ) : '';
			$ranges   = isset( $row['ranges'] ) && is_array( $row['ranges'] ) ? $row['ranges'] : array();
			$normalized = WC_Optic_SKU::normalize_power_ranges( $ranges, $division );

			foreach ( $normalized as $power => $segments ) {
				$filled = WC_Optic_SKU::normalize_power_range_segments( $segments, $power );
				if ( ! $filled ) {
					continue;
				}
				$label = WC_Optic_Catalog::get_type_label( $power );
				$tpl   = self::sanitize(
					array(
						'id'       => $base_id . '_' . $power,
						'name'     => $name ? ( $name . ' — ' . $label ) : $label,
						'power'    => $power,
						'segments' => $filled,
					)
				);
				if ( $tpl ) {
					$migrated[ $tpl['id'] ] = $tpl;
				}
			}
		}

		update_option( self::OPTION_KEY, array_values( $migrated ), false );
		update_option( $flag, '1', false );
	}
}
