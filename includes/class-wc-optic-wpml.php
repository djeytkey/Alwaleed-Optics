<?php
/**
 * WPML / WooCommerce Multilingual integration.
 *
 * @package WC_Optic_Product
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Optic_WPML
 */
class WC_Optic_WPML {

	const STRING_CONTEXT_CATALOG  = 'wc-optic-catalog';
	const STRING_CONTEXT_DIVISION = 'wc-optic-divisions';

	/**
	 * Whether hooks were already registered.
	 *
	 * @var bool
	 */
	protected static $booted = false;

	/**
	 * Bootstrap hooks when WPML is active.
	 */
	public static function init() {
		if ( self::$booted || ! self::is_active() ) {
			return;
		}
		self::$booted = true;

		add_filter( 'wc_optic_catalog_display_name', array( __CLASS__, 'filter_catalog_display_name' ), 10, 2 );
		add_filter( 'wc_optic_division_label', array( __CLASS__, 'filter_division_label' ), 10, 2 );

		add_action( 'wc_optic_catalog_term_saved', array( __CLASS__, 'on_catalog_term_saved' ), 10, 3 );
		add_action( 'wc_optic_catalog_term_deleted', array( __CLASS__, 'on_catalog_term_deleted' ), 10, 1 );

		add_action( 'admin_init', array( __CLASS__, 'register_all_catalog_strings' ), 20 );

		add_filter( 'body_class', array( __CLASS__, 'body_class_rtl' ) );
		add_filter( 'wcml_multi_currency_ajax_actions', array( __CLASS__, 'multicurrency_ajax_actions' ) );
		add_filter( 'wcml_product_content_label', array( __CLASS__, 'product_content_label' ), 10, 2 );

		add_filter( 'wcml_do_not_display_custom_fields_for_product', array( __CLASS__, 'hide_internal_index_meta_from_editor' ) );
	}

	/**
	 * Whether WPML core is loaded.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return defined( 'ICL_SITEPRESS_VERSION' ) || has_filter( 'wpml_current_language' ) || has_filter( 'wpml_translate_single_string' );
	}

	/**
	 * Register WPML hooks when the plugin boots (wpml_loaded may have already fired).
	 */
	public static function maybe_init() {
		if ( ! self::is_active() ) {
			return;
		}

		self::init();
		self::register_static_strings();
	}

	/**
	 * String name for one catalog row.
	 *
	 * @param int $term_id Catalog row id.
	 * @return string
	 */
	public static function catalog_string_name( $term_id ) {
		return 'catalog-term-' . (int) $term_id;
	}

	/**
	 * String name for a division slug.
	 *
	 * @param string $slug Division slug.
	 * @return string
	 */
	public static function division_string_name( $slug ) {
		return 'division-' . sanitize_key( $slug );
	}

	/**
	 * Register a catalog display name for String Translation.
	 *
	 * @param int    $term_id Catalog row id.
	 * @param string $name    Default (source) name.
	 */
	public static function register_catalog_string( $term_id, $name ) {
		$term_id = (int) $term_id;
		$name    = (string) $name;
		if ( $term_id < 1 || '' === trim( $name ) ) {
			return;
		}

		$key = self::catalog_string_name( $term_id );

		if ( has_action( 'wpml_register_single_string' ) ) {
			do_action( 'wpml_register_single_string', self::STRING_CONTEXT_CATALOG, $key, $name );
			return;
		}

		if ( function_exists( 'icl_register_string' ) ) {
			icl_register_string( self::STRING_CONTEXT_CATALOG, $key, $name );
		}
	}

	/**
	 * Remove a catalog string when the row is deleted.
	 *
	 * @param int $term_id Catalog row id.
	 */
	public static function unregister_catalog_string( $term_id ) {
		$term_id = (int) $term_id;
		if ( $term_id < 1 ) {
			return;
		}

		$key = self::catalog_string_name( $term_id );

		if ( has_action( 'wpml_unregister_string' ) ) {
			do_action( 'wpml_unregister_string', self::STRING_CONTEXT_CATALOG, $key );
			return;
		}

		if ( function_exists( 'icl_unregister_string' ) ) {
			icl_unregister_string( self::STRING_CONTEXT_CATALOG, $key );
		}
	}

	/**
	 * Translate a catalog display name for the current (or given) language.
	 *
	 * @param string   $name    Source name.
	 * @param int      $term_id Catalog row id.
	 * @param string|null $lang Language code or null for current.
	 * @return string
	 */
	public static function translate_catalog_name( $name, $term_id, $lang = null ) {
		$term_id = (int) $term_id;
		$name    = (string) $name;
		if ( $term_id < 1 || '' === $name || ! self::is_active() ) {
			return $name;
		}

		$key = self::catalog_string_name( $term_id );

		if ( has_filter( 'wpml_translate_single_string' ) ) {
			return (string) apply_filters( 'wpml_translate_single_string', $name, self::STRING_CONTEXT_CATALOG, $key, $lang );
		}

		if ( function_exists( 'icl_t' ) ) {
			$translated = icl_t( self::STRING_CONTEXT_CATALOG, $key, $name );
			return is_string( $translated ) && '' !== $translated ? $translated : $name;
		}

		return $name;
	}

	/**
	 * Register division labels (static strings).
	 */
	public static function register_static_strings() {
		if ( ! self::is_active() ) {
			return;
		}

		foreach ( WC_Optic_Plugin::get_divisions() as $slug => $def ) {
			$label = isset( $def['label'] ) ? (string) $def['label'] : '';
			if ( '' === $label ) {
				continue;
			}
			$key = self::division_string_name( $slug );
			if ( has_action( 'wpml_register_single_string' ) ) {
				do_action( 'wpml_register_single_string', self::STRING_CONTEXT_DIVISION, $key, $label );
			} elseif ( function_exists( 'icl_register_string' ) ) {
				icl_register_string( self::STRING_CONTEXT_DIVISION, $key, $label );
			}
		}

		self::register_all_catalog_strings();
	}

	/**
	 * Register every catalog row so WPML String Translation can pick them up.
	 */
	public static function register_all_catalog_strings() {
		if ( ! self::is_active() || ! is_admin() ) {
			return;
		}

		foreach ( WC_Optic_Catalog::TYPES as $type ) {
			foreach ( WC_Optic_Catalog::get_terms( $type ) as $row ) {
				if ( ! empty( $row->id ) && isset( $row->name ) ) {
					self::register_catalog_string( (int) $row->id, (string) $row->name );
				}
			}
		}
	}

	/**
	 * @param string $name Translated/default name.
	 * @param object $row  Catalog row.
	 * @return string
	 */
	public static function filter_catalog_display_name( $name, $row ) {
		if ( ! is_object( $row ) || empty( $row->id ) ) {
			return $name;
		}
		return self::translate_catalog_name( $name, (int) $row->id );
	}

	/**
	 * @param string $label Division label.
	 * @param string $slug  Division slug.
	 * @return string
	 */
	public static function filter_division_label( $label, $slug ) {
		$key = self::division_string_name( $slug );
		if ( has_filter( 'wpml_translate_single_string' ) ) {
			return (string) apply_filters( 'wpml_translate_single_string', $label, self::STRING_CONTEXT_DIVISION, $key, null );
		}
		if ( function_exists( 'icl_t' ) ) {
			$translated = icl_t( self::STRING_CONTEXT_DIVISION, $key, $label );
			return is_string( $translated ) && '' !== $translated ? $translated : $label;
		}
		return $label;
	}

	/**
	 * @param int    $term_id   Catalog id.
	 * @param string $name      Display name.
	 * @param string $term_type Catalog type (unused).
	 */
	public static function on_catalog_term_saved( $term_id, $name, $term_type ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		self::register_catalog_string( $term_id, $name );
	}

	/**
	 * @param int $term_id Catalog id.
	 */
	public static function on_catalog_term_deleted( $term_id ) {
		self::unregister_catalog_string( $term_id );
	}

	/**
	 * Ensure RTL layout when WPML serves an RTL language (Flatsome / theme may not add .rtl).
	 *
	 * @param string[] $classes Body classes.
	 * @return string[]
	 */
	public static function body_class_rtl( $classes ) {
		$lang = apply_filters( 'wpml_current_language', null );
		if ( ! $lang && defined( 'ICL_LANGUAGE_CODE' ) ) {
			$lang = ICL_LANGUAGE_CODE;
		}
		if ( ! $lang ) {
			return $classes;
		}

		$rtl_langs = apply_filters(
			'wc_optic_wpml_rtl_language_codes',
			array( 'ar', 'he', 'fa', 'ur' )
		);

		if ( in_array( $lang, $rtl_langs, true ) && ! in_array( 'rtl', $classes, true ) ) {
			$classes[] = 'rtl';
		}

		return $classes;
	}

	/**
	 * Admin-only AJAX used with product editing; no multicurrency switch required.
	 *
	 * @param string[] $actions Action names.
	 * @return string[]
	 */
	public static function multicurrency_ajax_actions( $actions ) {
		$actions[] = 'wc_optic_preview_sku';
		$actions[] = 'wc_optic_create_term';
		$actions[] = 'wc_optic_delete_term';
		return array_values( array_unique( $actions ) );
	}

	/**
	 * Friendly labels for optic meta in the WCML translation editor.
	 *
	 * @param string $label Field key.
	 * @param int    $product_id Product id.
	 * @return string
	 */
	public static function product_content_label( $label, $product_id ) {
		$map = array(
			'_optic_child_configs'       => __( 'Optic internal products (JSON)', 'wc-optic' ),
			'_optic_division'            => __( 'Optical division', 'wc-optic' ),
			'_optic_default_qty_per_eye' => __( 'Quantity per eye default', 'wc-optic' ),
		);

		return isset( $map[ $label ] ) ? $map[ $label ] : $label;
	}

	/**
	 * Index meta is derived; translators should edit child configs instead.
	 *
	 * @param string[] $fields Field keys hidden from WCML editor.
	 * @return string[]
	 */
	public static function hide_internal_index_meta_from_editor( $fields ) {
		return array_merge( $fields, array_values( WC_Optic_SKU::INDEX_META_KEYS ) );
	}
}
