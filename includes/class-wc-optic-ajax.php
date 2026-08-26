<?php
/**
 * AJAX handlers (admin catalog CRUD, SKU preview).
 *
 * @package WC_Optic_Product
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Optic_Ajax
 */
class WC_Optic_Ajax {

	/**
	 * Register hooks.
	 */
	public static function hooks() {
		add_action( 'wp_ajax_wc_optic_create_term', array( __CLASS__, 'create_term' ) );
		add_action( 'wp_ajax_wc_optic_delete_term', array( __CLASS__, 'delete_term' ) );
		add_action( 'wp_ajax_wc_optic_preview_sku', array( __CLASS__, 'preview_sku' ) );
		add_action( 'wp_ajax_wc_optic_restock_child', array( __CLASS__, 'restock_child' ) );
		add_action( 'wp_ajax_wc_optic_save_power_template', array( __CLASS__, 'save_power_template' ) );
		add_action( 'wp_ajax_wc_optic_delete_power_template', array( __CLASS__, 'delete_power_template' ) );
		add_action( 'wp_ajax_wc_optic_preview_convert', array( __CLASS__, 'preview_convert' ) );
		add_action( 'wp_ajax_wc_optic_run_convert_batch', array( __CLASS__, 'run_convert_batch' ) );
		add_action( 'wp_ajax_wc_optic_generate_product_children', array( __CLASS__, 'generate_product_children' ) );
		add_action( 'wp_ajax_wc_optic_count_power_ranges', array( __CLASS__, 'count_power_ranges' ) );
		add_action( 'wp_ajax_wc_optic_wizard_product', array( __CLASS__, 'wizard_product' ) );
		add_action( 'wp_ajax_wc_optic_load_child', array( __CLASS__, 'load_child' ) );
		add_action( 'wp_ajax_wc_optic_save_child', array( __CLASS__, 'save_child' ) );
		add_action( 'wp_ajax_wc_optic_remove_child', array( __CLASS__, 'remove_child' ) );
		add_action( 'wp_ajax_wc_optic_list_children', array( __CLASS__, 'list_children' ) );
	}

	/**
	 * Create catalog term (admin).
	 */
	public static function create_term() {
		check_ajax_referer( 'wc_optic_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-optic' ) ), 403 );
		}
		$type = isset( $_POST['term_type'] ) ? sanitize_key( wp_unslash( $_POST['term_type'] ) ) : '';
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$frag = isset( $_POST['sku_fragment'] ) ? WC_Optic_Catalog::sanitize_sku_fragment( wp_unslash( $_POST['sku_fragment'] ) ) : '';
		if ( ! WC_Optic_Catalog::is_valid_type( $type ) || '' === $name || '' === $frag ) {
			wp_send_json_error( array( 'message' => __( 'Name and SKU fragment are required.', 'wc-optic' ) ), 400 );
		}
		$slug_check = WC_Optic_Catalog::sanitize_slug( $name );
		$existing    = WC_Optic_Catalog::get_by_slug( $type, $slug_check );
		if ( $existing ) {
			wp_send_json_error( array( 'message' => __( 'An entry with the same label already exists in this list.', 'wc-optic' ) ), 409 );
		}
		$id = WC_Optic_Catalog::insert( $type, $name, $slug_check, $frag, 0 );
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Could not save.', 'wc-optic' ) ), 500 );
		}
		wp_send_json_success(
			array(
				'id'   => $id,
				'text' => $name,
			)
		);
	}

	/**
	 * Delete catalog term (admin settings).
	 */
	public static function delete_term() {
		check_ajax_referer( 'wc_optic_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-optic' ) ), 403 );
		}
		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$type = isset( $_POST['term_type'] ) ? sanitize_key( wp_unslash( $_POST['term_type'] ) ) : '';
		if ( ! $id || ! WC_Optic_Catalog::is_valid_type( $type ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid data.', 'wc-optic' ) ), 400 );
		}
		$row = WC_Optic_Catalog::get_term( $id );
		if ( ! $row || (string) $row->term_type !== $type ) {
			wp_send_json_error( array( 'message' => __( 'Entry not found.', 'wc-optic' ) ), 404 );
		}

		$affected = WC_Optic_Deletion_Log::find_products_using_term( $type, $id );

		if ( ! WC_Optic_Catalog::delete( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not delete.', 'wc-optic' ) ), 500 );
		}

		$log_id = WC_Optic_Deletion_Log::record( $row, get_current_user_id(), $affected );
		if ( ! $log_id ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'wc-optic: catalog term deleted but deletion log insert failed for catalog id ' . (string) $id );
		}

		wp_send_json_success(
			array(
				'log_id'              => $log_id,
				'affected_products'   => $affected,
				'deleted_term_name'   => (string) $row->name,
			)
		);
	}

	/**
	 * Preview SKU from posted catalog ids (admin product screen).
	 */
	public static function preview_sku() {
		check_ajax_referer( 'wc_optic_admin', 'nonce' );
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-optic' ) ), 403 );
		}
		$division = isset( $_POST['optic_division'] ) ? sanitize_key( wp_unslash( $_POST['optic_division'] ) ) : '';
		$divs     = WC_Optic_Plugin::get_divisions();
		if ( $division && ! isset( $divs[ $division ] ) ) {
			$division = '';
		}

		$child_config = isset( $_POST['child_config'] ) && is_array( $_POST['child_config'] ) ? wp_unslash( $_POST['child_config'] ) : array();

		$sku = WC_Optic_SKU::build_from_catalog_ids( $child_config, $division );

		wp_send_json_success(
			array(
				'sku'     => $sku,
				'qr_html' => WC_Optic_QR::render_admin_block( $sku ),
			)
		);
	}

	/**
	 * Add stock to one internal product (stock management page).
	 */
	public static function restock_child() {
		check_ajax_referer( 'wc_optic_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-optic' ) ), 403 );
		}

		$product_id      = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		$child_id        = isset( $_POST['child_id'] ) ? sanitize_key( wp_unslash( $_POST['child_id'] ) ) : '';
		$qty             = isset( $_POST['qty'] ) ? absint( wp_unslash( $_POST['qty'] ) ) : 0;
		$reset_backorder = ! empty( $_POST['reset_backorder'] );

		$result = WC_Optic_Stock::restock_child( $product_id, $child_id, $qty, $reset_backorder );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Posted conversion payload shared by preview/run.
	 *
	 * @return array
	 */
	protected static function posted_convert_args() {
		$catalog = isset( $_POST['catalog'] ) && is_array( $_POST['catalog'] ) ? wp_unslash( $_POST['catalog'] ) : array();
		$ranges  = isset( $_POST['ranges'] ) && is_array( $_POST['ranges'] ) ? wp_unslash( $_POST['ranges'] ) : array();

		return array(
			'division'    => isset( $_POST['division'] ) ? sanitize_key( wp_unslash( $_POST['division'] ) ) : '',
			'catalog'     => $catalog,
			'ranges'      => $ranges,
			'template_id' => isset( $_POST['template_id'] ) ? sanitize_key( wp_unslash( $_POST['template_id'] ) ) : '',
			'unit_price'  => isset( $_POST['unit_price'] ) ? wc_format_decimal( wp_unslash( $_POST['unit_price'] ) ) : '',
			'stock_qty'   => isset( $_POST['stock_qty'] ) ? absint( wp_unslash( $_POST['stock_qty'] ) ) : 0,
			'mode'        => ! empty( $_POST['replace'] ) ? 'replace' : 'skip_if_has_children',
		);
	}

	/**
	 * Posted product id list.
	 *
	 * @return int[]
	 */
	protected static function posted_product_ids() {
		$raw = isset( $_POST['product_ids'] ) ? wp_unslash( $_POST['product_ids'] ) : array();
		if ( ! is_array( $raw ) ) {
			$raw = array( $raw );
		}
		return array_values( array_filter( array_map( 'absint', $raw ) ) );
	}

	/**
	 * Save a range template.
	 */
	public static function save_power_template() {
		check_ajax_referer( 'wc_optic_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-optic' ) ), 403 );
		}

		$result = WC_Optic_Power_Template::save(
			array(
				'id'       => isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '',
				'name'     => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
				'division' => isset( $_POST['division'] ) ? sanitize_key( wp_unslash( $_POST['division'] ) ) : '',
				'ranges'   => isset( $_POST['ranges'] ) && is_array( $_POST['ranges'] ) ? wp_unslash( $_POST['ranges'] ) : array(),
			)
		);
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( array( 'template' => $result ) );
	}

	/**
	 * Delete a range template.
	 */
	public static function delete_power_template() {
		check_ajax_referer( 'wc_optic_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-optic' ) ), 403 );
		}

		$id = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		if ( ! WC_Optic_Power_Template::delete( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Template not found.', 'wc-optic' ) ), 404 );
		}

		wp_send_json_success();
	}

	/**
	 * Count internals for posted ranges.
	 */
	public static function count_power_ranges() {
		check_ajax_referer( 'wc_optic_admin', 'nonce' );
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-optic' ) ), 403 );
		}

		$division = isset( $_POST['division'] ) ? sanitize_key( wp_unslash( $_POST['division'] ) ) : '';
		$ranges   = isset( $_POST['ranges'] ) && is_array( $_POST['ranges'] ) ? wp_unslash( $_POST['ranges'] ) : array();
		$count    = WC_Optic_SKU::count_children_from_ranges( $division, $ranges );
		if ( is_wp_error( $count ) ) {
			wp_send_json_error( array( 'message' => $count->get_error_message(), 'count' => 0 ), 400 );
		}

		wp_send_json_success( array( 'count' => (int) $count ) );
	}

	/**
	 * Dry-run conversion.
	 */
	public static function preview_convert() {
		check_ajax_referer( 'wc_optic_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-optic' ) ), 403 );
		}

		wp_send_json_success( WC_Optic_Converter::preview( self::posted_product_ids(), self::posted_convert_args() ) );
	}

	/**
	 * Convert a small batch of products.
	 */
	public static function run_convert_batch() {
		check_ajax_referer( 'wc_optic_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-optic' ) ), 403 );
		}

		$args    = self::posted_convert_args();
		$results = array();
		foreach ( self::posted_product_ids() as $product_id ) {
			$result = WC_Optic_Converter::convert_product( $product_id, $args );
			if ( is_wp_error( $result ) ) {
				$results[] = array(
					'product_id' => $product_id,
					'status'     => 'error',
					'message'    => $result->get_error_message(),
				);
				continue;
			}
			$results[] = array_merge(
				$result,
				array(
					'status' => ! empty( $result['skipped'] ) ? 'skip' : 'ok',
				)
			);
		}

		wp_send_json_success( array( 'results' => $results ) );
	}

	/**
	 * One product payload for the conversion wizard.
	 */
	public static function wizard_product() {
		check_ajax_referer( 'wc_optic_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-optic' ) ), 403 );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		$result     = WC_Optic_Converter::get_wizard_product( $product_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Generate internals on one product edit screen.
	 */
	public static function generate_product_children() {
		check_ajax_referer( 'wc_optic_admin', 'nonce' );
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-optic' ) ), 403 );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		if ( $product_id < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Save the product first, then generate internals.', 'wc-optic' ) ), 400 );
		}

		$result = WC_Optic_Converter::convert_product( $product_id, self::posted_convert_args() );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Resolve optic product for child AJAX, or die with JSON error.
	 *
	 * @return WC_Product
	 */
	protected static function require_optic_product_for_child_ajax() {
		check_ajax_referer( 'wc_optic_admin', 'nonce' );
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-optic' ) ), 403 );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		if ( $product_id < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Save the product first, then manage internal products.', 'wc-optic' ) ), 400 );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || 'optic_product' !== $product->get_type() ) {
			wp_send_json_error( array( 'message' => __( 'Invalid optic product.', 'wc-optic' ) ), 400 );
		}

		return $product;
	}

	/**
	 * Load one internal product editor HTML.
	 */
	public static function load_child() {
		$product  = self::require_optic_product_for_child_ajax();
		$child_id = isset( $_POST['child_id'] ) ? sanitize_key( wp_unslash( $_POST['child_id'] ) ) : '';
		$config   = WC_Optic_SKU::get_child_config_by_id( $product, $child_id );
		if ( ! $config ) {
			wp_send_json_error( array( 'message' => __( 'Internal product not found.', 'wc-optic' ) ), 404 );
		}

		$division = (string) $product->get_meta( '_optic_division', true );
		ob_start();
		WC_Optic_Admin_Product::render_child_editor( $config, $division );
		$html = ob_get_clean();

		wp_send_json_success(
			array(
				'html'   => $html,
				'child'  => $config,
				'row'    => WC_Optic_SKU::build_child_list_row( $config, $division ),
			)
		);
	}

	/**
	 * Save (insert/update) one internal product — rejects duplicate powers.
	 */
	public static function save_child() {
		$product = self::require_optic_product_for_child_ajax();

		$raw_child = isset( $_POST['child_config'] ) && is_array( $_POST['child_config'] ) ? wp_unslash( $_POST['child_config'] ) : array();
		$identity  = isset( $_POST['identity'] ) && is_array( $_POST['identity'] ) ? wp_unslash( $_POST['identity'] ) : null;

		$division = isset( $_POST['optic_division'] ) ? sanitize_key( wp_unslash( $_POST['optic_division'] ) ) : '';
		$divs     = WC_Optic_Plugin::get_divisions();
		if ( $division && isset( $divs[ $division ] ) ) {
			$product->update_meta_data( '_optic_division', $division );
		}

		$result = WC_Optic_SKU::upsert_child_on_product( $product, $raw_child, $identity );
		if ( is_wp_error( $result ) ) {
			$status = ( 'wc_optic_duplicate_powers' === $result->get_error_code() ) ? 409 : 400;
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'code'    => $result->get_error_code(),
				),
				$status
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * Delete one internal product.
	 */
	public static function remove_child() {
		$product  = self::require_optic_product_for_child_ajax();
		$child_id = isset( $_POST['child_id'] ) ? sanitize_key( wp_unslash( $_POST['child_id'] ) ) : '';

		$result = WC_Optic_SKU::remove_child_from_product( $product, $child_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Refresh compact list rows.
	 */
	public static function list_children() {
		$product = self::require_optic_product_for_child_ajax();
		$rows    = WC_Optic_SKU::get_child_list_rows( $product );
		wp_send_json_success(
			array(
				'rows'  => $rows,
				'count' => count( $rows ),
			)
		);
	}
}
