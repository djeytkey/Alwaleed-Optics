<?php
/**
 * Audit log when catalog terms are deleted + product lookup.
 *
 * @package WC_Optic_Product
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Optic_Deletion_Log
 */
class WC_Optic_Deletion_Log {

	/**
	 * Find products (and variations) that reference a catalog term id in post meta.
	 *
	 * @param string $term_type Catalog type (section, company, …).
	 * @param int    $catalog_term_id Catalog row id.
	 * @return array<int, array{id:int, name:string, edit_url:string}>
	 */
	public static function find_products_using_term( $term_type, $catalog_term_id ) {
		if ( ! isset( WC_Optic_SKU::INDEX_META_KEYS[ $term_type ] ) ) {
			return array();
		}

		global $wpdb;

		$new_meta_key = WC_Optic_SKU::INDEX_META_KEYS[ $term_type ];
		$old_meta_key = isset( WC_Optic_SKU::META_KEYS[ $term_type ] ) ? WC_Optic_SKU::META_KEYS[ $term_type ] : '';
		$value    = (string) absint( $catalog_term_id );
		$like     = '%"' . $wpdb->esc_like( $value ) . '"%';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE (
					( pm.meta_key = %s AND ( pm.meta_value = %s OR pm.meta_value LIKE %s ) )
					OR
					( pm.meta_key = %s AND ( pm.meta_value = %s OR pm.meta_value LIKE %s ) )
				)
				AND p.post_type IN ( 'product', 'product_variation' )
				AND p.post_status NOT IN ( 'trash', 'auto-draft' )",
				$new_meta_key,
				$value,
				$like,
				$old_meta_key,
				$value,
				$like
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = array();
		foreach ( $post_ids as $pid ) {
			$pid  = (int) $pid;
			$post = get_post( $pid );
			if ( ! $post ) {
				continue;
			}

			$name = $post->post_title;
			if ( 'product_variation' === $post->post_type && $post->post_parent ) {
				$parent = get_the_title( $post->post_parent );
				$name   = sprintf(
					/* translators: 1: variation ID, 2: parent product title */
					__( 'Variation #%1$s — %2$s', 'wc-optic' ),
					(string) $pid,
					$parent
				);
			}

			$edit_url = get_edit_post_link( $pid, 'raw' );
			if ( ! $edit_url ) {
				$edit_url = '';
			}

			$out[] = array(
				'id'       => $pid,
				'name'     => $name,
				'edit_url' => $edit_url,
			);
		}

		return $out;
	}

	/**
	 * Persist deletion audit row (call after the catalog row has been removed from wc_optic_catalog).
	 *
	 * @param object $catalog_row Row object (id, term_type, name, slug, …).
	 * @param int    $deleted_by_user_id User id.
	 * @param array  $affected_products Output of find_products_using_term() (snapshot).
	 * @return int Insert id or 0 on failure.
	 */
	public static function record( $catalog_row, $deleted_by_user_id, array $affected_products ) {
		global $wpdb;

		$snapshot = array();
		foreach ( $affected_products as $p ) {
			$snapshot[] = array(
				'id'   => isset( $p['id'] ) ? (int) $p['id'] : 0,
				'name' => isset( $p['name'] ) ? (string) $p['name'] : '',
			);
		}

		$table = WC_Optic_Database::table_deletion_log();
		$res   = $wpdb->insert(
			$table,
			array(
				'catalog_term_id'   => (int) $catalog_row->id,
				'term_type'         => (string) $catalog_row->term_type,
				'term_name'         => (string) $catalog_row->name,
				'term_slug'         => (string) $catalog_row->slug,
				'deleted_by'        => (int) $deleted_by_user_id,
				'deleted_at'        => current_time( 'mysql' ),
				'affected_products' => wp_json_encode( $snapshot, JSON_UNESCAPED_UNICODE ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( ! $res ) {
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Persist division deletion audit row.
	 *
	 * @param string $division_slug       Division slug.
	 * @param string $division_label      Division label at deletion time.
	 * @param int    $deleted_by_user_id  User id.
	 * @param array  $affected_products   Output of find_products_using_division().
	 * @return int Insert id or 0 on failure.
	 */
	public static function record_division( $division_slug, $division_label, $deleted_by_user_id, array $affected_products ) {
		global $wpdb;

		$snapshot = array();
		foreach ( $affected_products as $p ) {
			$snapshot[] = array(
				'id'   => isset( $p['id'] ) ? (int) $p['id'] : 0,
				'name' => isset( $p['name'] ) ? (string) $p['name'] : '',
			);
		}

		$table = WC_Optic_Database::table_deletion_log();
		$res   = $wpdb->insert(
			$table,
			array(
				'catalog_term_id'   => 0,
				'term_type'         => 'division_hidden',
				'term_name'         => (string) $division_label,
				'term_slug'         => sanitize_key( (string) $division_slug ),
				'deleted_by'        => (int) $deleted_by_user_id,
				'deleted_at'        => current_time( 'mysql' ),
				'affected_products' => wp_json_encode( $snapshot, JSON_UNESCAPED_UNICODE ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( ! $res ) {
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Recent deletion log rows (newest first).
	 *
	 * @param int $limit Max rows.
	 * @return array<int, object>
	 */
	public static function get_entries( $limit = 200 ) {
		global $wpdb;

		$table = WC_Optic_Database::table_deletion_log();
		$limit = max( 1, min( 500, absint( $limit ) ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY deleted_at DESC, id DESC LIMIT {$limit}" );
	}
}
