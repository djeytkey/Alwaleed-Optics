<?php
/**
 * Reusable power range presets (name + From/To/Step; usable for any power).
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
	 * Power type used only to validate/normalize numeric From/To/Step (templates are not bound to a power).
	 */
	const VALIDATE_AS = 'sph';

	/**
	 * All templates (migrates legacy rows on read).
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
	 * All templates (usable for any power when filling wizard inputs).
	 *
	 * @param string $power Unused (kept for callers).
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_for_power( $power ) {
		unset( $power );
		return self::get_all();
	}

	/**
	 * Same template list for every power (JS / wizard dropdowns).
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function get_grouped_by_power() {
		$all     = self::get_all();
		$grouped = array();
		foreach ( WC_Optic_Catalog::get_power_types() as $power ) {
			$grouped[ $power ] = $all;
		}
		return $grouped;
	}

	/**
	 * @deprecated Templates are global; use get_all().
	 * @param string $division Division slug.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_for_division( $division ) {
		unset( $division );
		return self::get_all();
	}

	/**
	 * Sanitize one template (name + segments only).
	 *
	 * @param array $raw Raw data.
	 * @return array<string, mixed>|null
	 */
	public static function sanitize( array $raw ) {
		$name = isset( $raw['name'] ) ? sanitize_text_field( wp_unslash( $raw['name'] ) ) : '';
		if ( '' === $name ) {
			return null;
		}

		$id = isset( $raw['id'] ) ? sanitize_key( (string) $raw['id'] ) : '';
		if ( '' === $id ) {
			$id = 'tpl_' . wp_generate_password( 8, false, false );
		}

		$segment_raw = array();
		if ( isset( $raw['segments'] ) && is_array( $raw['segments'] ) ) {
			$segment_raw = $raw['segments'];
		} elseif ( isset( $raw['ranges'] ) && is_array( $raw['ranges'] ) ) {
			if ( isset( $raw['ranges']['shared'] ) && is_array( $raw['ranges']['shared'] ) ) {
				$segment_raw = $raw['ranges']['shared'];
			} elseif ( isset( $raw['power'] ) && isset( $raw['ranges'][ $raw['power'] ] ) ) {
				$segment_raw = $raw['ranges'][ $raw['power'] ];
			} else {
				foreach ( $raw['ranges'] as $maybe ) {
					if ( is_array( $maybe ) ) {
						$segment_raw = $maybe;
						break;
					}
				}
			}
		} elseif ( isset( $raw['from'] ) || isset( $raw['to'] ) ) {
			$segment_raw = array( $raw );
		}

		$segments = WC_Optic_SKU::normalize_power_range_segments( is_array( $segment_raw ) ? $segment_raw : array(), self::VALIDATE_AS );
		if ( ! $segments ) {
			return null;
		}

		foreach ( $segments as $segment ) {
			$bounds = WC_Optic_Catalog::normalize_power_range_bounds(
				self::VALIDATE_AS,
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
	 * Count unique values this range would produce (validated as SPH-scale numbers).
	 *
	 * @param array $template Template.
	 * @return int|WP_Error
	 */
	public static function count_values( array $template ) {
		$clean = self::sanitize( $template );
		if ( ! $clean ) {
			return new WP_Error( 'wc_optic_invalid_template', __( 'Invalid power range template.', 'wc-optic' ) );
		}
		return WC_Optic_Catalog::count_power_range_segments( self::VALIDATE_AS, $clean['segments'] );
	}

	/**
	 * @deprecated Use count_values().
	 * @param array $template Template.
	 * @return int|WP_Error
	 */
	public static function count_children( array $template ) {
		return self::count_values( $template );
	}

	/**
	 * Fingerprint for dedupe (name + segments).
	 *
	 * @param array $template Sanitized template.
	 * @return string
	 */
	protected static function fingerprint( array $template ) {
		return md5(
			wp_json_encode(
				array(
					'name'     => isset( $template['name'] ) ? $template['name'] : '',
					'segments' => isset( $template['segments'] ) ? $template['segments'] : array(),
				)
			)
		);
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
				__( 'Name and a valid from / to / step range are required (from must be ≤ to).', 'wc-optic' )
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
	 * Migrate legacy division / per-power templates to global name + segments.
	 */
	public static function maybe_migrate() {
		$flag = 'wc_optic_power_templates_v3';
		if ( get_option( $flag ) ) {
			return;
		}

		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) || ! $stored ) {
			update_option( $flag, '1', false );
			update_option( 'wc_optic_power_templates_v2', '1', false );
			return;
		}

		$candidates = array();
		foreach ( $stored as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			// Already global (name + segments, no power binding required).
			if ( isset( $row['segments'] ) && is_array( $row['segments'] ) && ! isset( $row['division'] ) ) {
				$name = isset( $row['name'] ) ? sanitize_text_field( $row['name'] ) : '';
				// Strip legacy " — SPH" style suffixes from multi-power duplicates when possible.
				if ( $name && preg_match( '/\s+[—–-]\s+(SPH|CYL|AXIS|ADD)$/u', $name ) ) {
					$name = trim( (string) preg_replace( '/\s+[—–-]\s+(SPH|CYL|AXIS|ADD)$/u', '', $name ) );
				}
				$clean = self::sanitize(
					array(
						'id'       => isset( $row['id'] ) ? $row['id'] : '',
						'name'     => $name ? $name : ( isset( $row['name'] ) ? $row['name'] : '' ),
						'segments' => $row['segments'],
					)
				);
				if ( $clean ) {
					$candidates[] = $clean;
				}
				continue;
			}

			// Legacy division + multi-power ranges → one template per distinct segment set.
			if ( isset( $row['division'] ) || ( isset( $row['ranges'] ) && ! isset( $row['segments'] ) ) ) {
				$name       = isset( $row['name'] ) ? sanitize_text_field( $row['name'] ) : '';
				$base_id    = isset( $row['id'] ) ? sanitize_key( (string) $row['id'] ) : ( 'tpl_' . wp_generate_password( 8, false, false ) );
				$division   = isset( $row['division'] ) ? sanitize_key( $row['division'] ) : '';
				$ranges     = isset( $row['ranges'] ) && is_array( $row['ranges'] ) ? $row['ranges'] : array();
				$normalized = WC_Optic_SKU::normalize_power_ranges( $ranges, $division );
				$seen       = array();
				$i          = 0;
				foreach ( $normalized as $power => $segments ) {
					$filled = WC_Optic_SKU::normalize_power_range_segments( $segments, $power );
					if ( ! $filled ) {
						continue;
					}
					$fp = md5( wp_json_encode( $filled ) );
					if ( isset( $seen[ $fp ] ) ) {
						continue;
					}
					$seen[ $fp ] = true;
					++$i;
					$tpl = self::sanitize(
						array(
							'id'       => $base_id . ( $i > 1 ? ( '_' . $i ) : '' ),
							'name'     => $name ? $name : __( 'Range template', 'wc-optic' ),
							'segments' => $filled,
						)
					);
					if ( $tpl ) {
						$candidates[] = $tpl;
					}
				}
				continue;
			}

			$clean = self::sanitize( $row );
			if ( $clean ) {
				$candidates[] = $clean;
			}
		}

		$migrated = array();
		foreach ( $candidates as $tpl ) {
			$fp = self::fingerprint( $tpl );
			if ( isset( $migrated[ $fp ] ) ) {
				continue;
			}
			$migrated[ $fp ] = $tpl;
		}

		update_option( self::OPTION_KEY, array_values( $migrated ), false );
		update_option( $flag, '1', false );
		update_option( 'wc_optic_power_templates_v2', '1', false );
	}
}
