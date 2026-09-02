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
	 * Prevent recursive translation sync on product save.
	 *
	 * @var bool
	 */
	protected static $syncing = false;

	/**
	 * Language codes to restore after a temporary switch.
	 *
	 * @var array<int, string|null>
	 */
	protected static $language_stack = array();

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
		add_action( 'woocommerce_after_product_object_save', array( __CLASS__, 'maybe_sync_after_save' ), 20, 1 );
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
			$translated = (string) apply_filters( 'wpml_translate_single_string', $name, self::STRING_CONTEXT_CATALOG, $key, $lang );
			return '' !== trim( $translated ) ? $translated : $name;
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
			$translated = (string) apply_filters( 'wpml_translate_single_string', $label, self::STRING_CONTEXT_DIVISION, $key, null );
			return '' !== trim( $translated ) ? $translated : $label;
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
	 * Switch WPML to the default language (stackable).
	 */
	public static function switch_to_default_language() {
		if ( ! self::is_active() ) {
			return;
		}

		$current = apply_filters( 'wpml_current_language', null );
		$default = apply_filters( 'wpml_default_language', null );
		self::$language_stack[] = $current;
		if ( $default && (string) $current !== (string) $default ) {
			do_action( 'wpml_switch_language', $default );
		}
	}

	/**
	 * Restore the language saved by switch_to_default_language().
	 */
	public static function restore_language() {
		if ( ! self::$language_stack ) {
			return;
		}

		$previous = array_pop( self::$language_stack );
		if ( null !== $previous && '' !== $previous ) {
			do_action( 'wpml_switch_language', $previous );
		}
	}

	/**
	 * Original / default-language product id for a WPML translation group.
	 *
	 * @param int $product_id Any product id in the group.
	 * @return int
	 */
	public static function get_original_product_id( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id < 1 || ! self::is_active() ) {
			return $product_id;
		}

		$default = apply_filters( 'wpml_default_language', null );
		$mapped  = apply_filters( 'wpml_object_id', $product_id, 'product', true, $default );
		return $mapped ? (int) $mapped : $product_id;
	}

	/**
	 * Whether the product is the WPML original (not a translation).
	 *
	 * @param int $product_id Product id.
	 * @return bool
	 */
	public static function is_original_product( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id < 1 || ! self::is_active() ) {
			return true;
		}

		$type = apply_filters( 'wpml_element_type', 'post_product' );
		$info = apply_filters(
			'wpml_element_language_details',
			null,
			array(
				'element_id'   => $product_id,
				'element_type' => $type,
			)
		);

		if ( is_object( $info ) && isset( $info->source_language_code ) ) {
			return empty( $info->source_language_code );
		}

		$lang    = apply_filters( 'wpml_element_language_code', null, array( 'element_id' => $product_id, 'element_type' => $type ) );
		$default = apply_filters( 'wpml_default_language', null );
		if ( $lang && $default ) {
			return (string) $lang === (string) $default;
		}

		return true;
	}

	/**
	 * After an original optic product is saved, copy internals to translations.
	 *
	 * @param WC_Product $product Product.
	 */
	public static function maybe_sync_after_save( $product ) {
		if ( self::$syncing || ! $product instanceof WC_Product || 'optic_product' !== $product->get_type() ) {
			return;
		}
		if ( ! self::is_original_product( $product->get_id() ) ) {
			return;
		}

		self::sync_product_translations( $product->get_id() );
	}

	/**
	 * Copy optic metas from the converted product to its WPML/WCML translations.
	 *
	 * @param int $product_id Source product id.
	 */
	public static function sync_product_translations( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id < 1 || ! self::is_active() || self::$syncing ) {
			return;
		}

		$source = wc_get_product( $product_id );
		if ( ! $source instanceof WC_Product ) {
			return;
		}

		$keys = array(
			'_optic_division',
			WC_Optic_SKU::CHILD_META_KEY,
			WC_Optic_SKU::IDENTITY_META_KEY,
			WC_Optic_SKU::RANGES_META_KEY,
		);
		$keys = array_merge( $keys, array_values( WC_Optic_SKU::INDEX_META_KEYS ) );

		$trid = apply_filters( 'wpml_element_trid', null, $product_id, 'post_product' );
		if ( ! $trid ) {
			return;
		}

		$translations = apply_filters( 'wpml_get_element_translations', null, $trid, 'post_product' );
		if ( ! is_array( $translations ) ) {
			return;
		}

		self::$syncing = true;
		$current_lang   = apply_filters( 'wpml_current_language', null );

		try {
			foreach ( $translations as $translation ) {
				$target_id = isset( $translation->element_id ) ? (int) $translation->element_id : 0;
				if ( $target_id < 1 || $target_id === $product_id ) {
					continue;
				}

				$lang = isset( $translation->language_code ) ? (string) $translation->language_code : '';
				if ( $lang ) {
					do_action( 'wpml_switch_language', $lang );
				}

				$target = wc_get_product( $target_id );
				if ( ! $target instanceof WC_Product || (int) $target->get_id() !== $target_id ) {
					continue;
				}

				if ( 'optic_product' !== $target->get_type() ) {
					wp_set_object_terms( $target_id, 'optic_product', 'product_type' );
					$target = wc_get_product( $target_id );
					if ( ! $target instanceof WC_Product || (int) $target->get_id() !== $target_id ) {
						continue;
					}
				}

				foreach ( $keys as $key ) {
					$target->update_meta_data( $key, $source->get_meta( $key, true ) );
				}

				WC_Optic_SKU::sync_product_sku( $target );
				$target->save();
			}
		} finally {
			if ( $current_lang ) {
				do_action( 'wpml_switch_language', $current_lang );
			}
			self::$syncing = false;
		}
	}

	/**
	 * Revert WPML/WCML product translations to simple (strip optic meta, same as source reset).
	 *
	 * @param int $product_id Source product id (already reverted).
	 */
	public static function revert_product_translations_to_simple( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id < 1 || ! self::is_active() || self::$syncing ) {
			return;
		}

		$trid = apply_filters( 'wpml_element_trid', null, $product_id, 'post_product' );
		if ( ! $trid ) {
			return;
		}

		$translations = apply_filters( 'wpml_get_element_translations', null, $trid, 'post_product' );
		if ( ! is_array( $translations ) ) {
			return;
		}

		self::$syncing = true;
		$current_lang = apply_filters( 'wpml_current_language', null );

		try {
			foreach ( $translations as $translation ) {
				$target_id = isset( $translation->element_id ) ? (int) $translation->element_id : 0;
				if ( $target_id < 1 || $target_id === $product_id ) {
					continue;
				}

				$lang = isset( $translation->language_code ) ? (string) $translation->language_code : '';
				if ( $lang ) {
					do_action( 'wpml_switch_language', $lang );
				}

				$target = wc_get_product( $target_id );
				if ( ! $target instanceof WC_Product || (int) $target->get_id() !== $target_id ) {
					continue;
				}

				WC_Optic_SKU::revert_to_simple_product( $target );
			}
		} finally {
			if ( $current_lang ) {
				do_action( 'wpml_switch_language', $current_lang );
			}
			self::$syncing = false;
		}
	}

	/**
	 * Admin AJAX used with product editing; keep WCML currency context stable.
	 *
	 * @param string[] $actions Action names.
	 * @return string[]
	 */
	public static function multicurrency_ajax_actions( $actions ) {
		$actions[] = 'wc_optic_preview_sku';
		$actions[] = 'wc_optic_create_term';
		$actions[] = 'wc_optic_delete_term';
		$actions[] = 'wc_optic_wizard_product';
		$actions[] = 'wc_optic_generate_product_children';
		$actions[] = 'wc_optic_count_power_ranges';
		$actions[] = 'wc_optic_save_power_template';
		$actions[] = 'wc_optic_delete_power_template';
		$actions[] = 'wc_optic_preview_convert';
		$actions[] = 'wc_optic_run_convert_batch';
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
			'_optic_identity_catalog'    => __( 'Optic identity catalog', 'wc-optic' ),
			'_optic_power_ranges'        => __( 'Optic power ranges', 'wc-optic' ),
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
