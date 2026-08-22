<?php
/**
 * Reusable power range presets (from / to / step per division power).
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
	 * All templates.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_all() {
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
	 * Templates for one division.
	 *
	 * @param string $division Division slug.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_for_division( $division ) {
		$division = sanitize_key( (string) $division );
		$out      = array();
		foreach ( self::get_all() as $row ) {
			if ( $row['division'] === $division ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * Sanitize one template.
	 *
	 * @param array $raw Raw data.
	 * @return array<string, mixed>|null
	 */
	public static function sanitize( array $raw ) {
		$name     = isset( $raw['name'] ) ? sanitize_text_field( wp_unslash( $raw['name'] ) ) : '';
		$division = isset( $raw['division'] ) ? sanitize_key( wp_unslash( $raw['division'] ) ) : '';
		$divs     = WC_Optic_Plugin::get_divisions();
		if ( '' === $name || ! isset( $divs[ $division ] ) ) {
			return null;
		}

		$id = isset( $raw['id'] ) ? sanitize_key( (string) $raw['id'] ) : '';
		if ( '' === $id ) {
			$id = 'tpl_' . wp_generate_password( 8, false, false );
		}

		return array(
			'id'       => $id,
			'name'     => $name,
			'division' => $division,
			'ranges'   => WC_Optic_SKU::normalize_power_ranges( isset( $raw['ranges'] ) ? $raw['ranges'] : array(), $division ),
		);
	}

	/**
	 * Count internals this template would create.
	 *
	 * @param array $template Template.
	 * @return int|WP_Error
	 */
	public static function count_children( array $template ) {
		$clean = self::sanitize( $template );
		if ( ! $clean ) {
			return new WP_Error( 'wc_optic_invalid_template', __( 'Invalid power range template.', 'wc-optic' ) );
		}
		return WC_Optic_SKU::count_children_from_ranges( $clean['division'], $clean['ranges'] );
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
			return new WP_Error( 'wc_optic_invalid_template', __( 'Name and division are required.', 'wc-optic' ) );
		}

		$count = self::count_children( $clean );
		if ( is_wp_error( $count ) ) {
			return $count;
		}

		$all     = self::get_all();
		$updated = false;
		foreach ( $all as $index => $row ) {
			if ( $row['id'] === $clean['id'] ) {
				$all[ $index ] = $clean;
				$updated       = true;
				break;
			}
		}
		if ( ! $updated ) {
			$all[] = $clean;
		}

		update_option( self::OPTION_KEY, $all, false );
		return $clean;
	}

	/**
	 * Delete a template.
	 *
	 * @param string $id Template id.
	 * @return bool
	 */
	public static function delete( $id ) {
		$id  = sanitize_key( (string) $id );
		$all = self::get_all();
		$out = array();
		$ok  = false;
		foreach ( $all as $row ) {
			if ( $row['id'] === $id ) {
				$ok = true;
				continue;
			}
			$out[] = $row;
		}
		if ( $ok ) {
			update_option( self::OPTION_KEY, $out, false );
		}
		return $ok;
	}
}
