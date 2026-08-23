<?php
/**
 * Admin: power range templates and simple-product conversion.
 *
 * @package WC_Optic_Product
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Optic_Admin_Convert
 */
class WC_Optic_Admin_Convert {

	/**
	 * Hooks.
	 */
	public static function hooks() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Whether this is the Convert screen.
	 *
	 * @param string $hook Hook suffix.
	 * @return bool
	 */
	public static function is_convert_page( $hook = '' ) {
		if ( isset( $_GET['page'] ) && 'wc-optic-convert' === sanitize_key( wp_unslash( $_GET['page'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}
		return WC_Optic_Admin_Menu::CONVERT_SCREEN === $hook;
	}

	/**
	 * Enqueue assets.
	 *
	 * @param string $hook Hook.
	 */
	public static function enqueue( $hook ) {
		if ( ! self::is_convert_page( $hook ) ) {
			return;
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'convert'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $tab, array( 'convert', 'templates' ), true ) ) {
			$tab = 'convert';
		}

		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_script( 'selectWoo' );
		wp_enqueue_script( 'wc-enhanced-select' );

		$style_deps = array();
		$script_deps = array( 'jquery', 'selectWoo', 'wc-enhanced-select', 'wc-optic-bootstrap' );

		if ( 'convert' === $tab ) {
			wp_enqueue_style(
				'wc-optic-datatables',
				WC_OPTIC_PLUGIN_URL . 'assets/vendor/datatables/dataTables.dataTables.min.css',
				array(),
				'2.1.8'
			);
			$style_deps[] = 'wc-optic-datatables';
			wp_enqueue_script(
				'wc-optic-datatables',
				WC_OPTIC_PLUGIN_URL . 'assets/vendor/datatables/dataTables.min.js',
				array( 'jquery' ),
				'2.1.8',
				true
			);
			$script_deps[] = 'wc-optic-datatables';
		}

		wp_enqueue_style(
			'wc-optic-admin',
			WC_OPTIC_PLUGIN_URL . 'assets/css/admin.css',
			$style_deps,
			WC_OPTIC_VERSION
		);
		wp_enqueue_style(
			'wc-optic-admin-wizard',
			WC_OPTIC_PLUGIN_URL . 'assets/css/admin-wizard.css',
			array( 'wc-optic-admin' ),
			WC_OPTIC_VERSION
		);
		wp_enqueue_script(
			'wc-optic-bootstrap',
			WC_OPTIC_PLUGIN_URL . 'assets/vendor/bootstrap/bootstrap.bundle.min.js',
			array(),
			'5.3.3',
			true
		);
		wp_enqueue_script(
			'wc-optic-admin-convert',
			WC_OPTIC_PLUGIN_URL . 'assets/js/admin-convert.js',
			$script_deps,
			WC_OPTIC_VERSION,
			true
		);

		$division_powers = array();
		$division_colors = array();
		foreach ( WC_Optic_Plugin::get_divisions() as $slug => $def ) {
			$division_powers[ $slug ] = $def['powers'];
			$division_colors[ $slug ] = ! empty( $def['show_color'] );
		}

		wp_localize_script(
			'wc-optic-admin-convert',
			'wcOpticConvert',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'wc_optic_admin' ),
				'divisionPowers'  => $division_powers,
				'divisionShowColor' => $division_colors,
				'powerTypes'      => WC_Optic_Catalog::get_power_types(),
				'defaultSteps'    => array(
					'sph'  => WC_Optic_Catalog::get_default_power_step( 'sph' ),
					'cyl'  => WC_Optic_Catalog::get_default_power_step( 'cyl' ),
					'axis' => WC_Optic_Catalog::get_default_power_step( 'axis' ),
					'add'  => WC_Optic_Catalog::get_default_power_step( 'add' ),
				),
				'maxChildren'     => WC_Optic_SKU::MAX_LEGACY_SYNTHETIC_CHILDREN,
				'templates'       => WC_Optic_Power_Template::get_all(),
				'convertTab'      => 'convert' === $tab,
				'dt'              => 'convert' === $tab ? self::get_datatables_i18n() : array(),
				'i18n'            => array(
					'saveFailed'      => __( 'Could not save the template.', 'wc-optic' ),
					'deleteConfirm'   => __( 'Delete this power range template?', 'wc-optic' ),
					'selectProducts'  => __( 'Select at least one product.', 'wc-optic' ),
					'needDivision'    => __( 'Choose an optical division.', 'wc-optic' ),
					'needIdentity'    => __( 'Fill every required identity field (section, company, brand, timing, pack, transparency — and color when the division uses it).', 'wc-optic' ),
					'needRanges'      => __( 'Set From, To and Step for each power of this division.', 'wc-optic' ),
					'needPrice'       => __( 'This product has no price. Enter a unit price.', 'wc-optic' ),
					'confirmReplace'  => __( 'This product already has internals. Replace them?', 'wc-optic' ),
					'confirmClose'    => __( 'Close the wizard? Unsaved steps for this product will be lost.', 'wc-optic' ),
					'convertFailed'   => __( 'Could not convert this product.', 'wc-optic' ),
					'loadFailed'      => __( 'Could not load the product.', 'wc-optic' ),
					'converted'       => __( 'Converted: %d internal products.', 'wc-optic' ),
					'skipped'         => __( 'Skipped: already has internal products.', 'wc-optic' ),
					'nextProduct'     => __( 'Next product', 'wc-optic' ),
					'nextStep'        => __( 'Next', 'wc-optic' ),
					'finish'          => __( 'Finish', 'wc-optic' ),
					'progress'        => __( 'Product %1$d of %2$d', 'wc-optic' ),
					'done'            => __( 'All selected products have been processed.', 'wc-optic' ),
					'selectAllFiltered' => __( 'Select all matching rows', 'wc-optic' ),
					'allProducts'       => __( 'All', 'wc-optic' ),
				),
			)
		);
	}

	/**
	 * DataTables strings for the Convert product list.
	 *
	 * @return array<string, mixed>
	 */
	protected static function get_datatables_i18n() {
		return array(
			'emptyTable'   => __( 'No simple products found.', 'wc-optic' ),
			'info'         => __( 'Showing _START_–_END_ of _TOTAL_ products', 'wc-optic' ),
			'infoEmpty'    => __( 'Showing 0 of 0 products', 'wc-optic' ),
			'infoFiltered' => __( '(_TOTAL_ of _MAX_ total)', 'wc-optic' ),
			'lengthMenu'   => __( 'Show _MENU_ products', 'wc-optic' ),
			'search'       => __( 'Search:', 'wc-optic' ),
			'zeroRecords'  => __( 'No matching products found.', 'wc-optic' ),
			'paginate'     => array(
				'first'    => __( 'First', 'wc-optic' ),
				'last'     => __( 'Last', 'wc-optic' ),
				'next'     => __( 'Next', 'wc-optic' ),
				'previous' => __( 'Previous', 'wc-optic' ),
			),
		);
	}

	/**
	 * Render the page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'convert'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $tab, array( 'convert', 'templates' ), true ) ) {
			$tab = 'convert';
		}

		echo '<div class="wrap wc-optic-convert-wrap" id="wc-optic-convert-root">';
		echo '<h1>' . esc_html__( 'Convert products', 'wc-optic' ) . '</h1>';
		echo '<h2 class="nav-tab-wrapper">';
		self::nav_tab( 'convert', $tab, __( 'Convert', 'wc-optic' ) );
		self::nav_tab( 'templates', $tab, __( 'Range templates', 'wc-optic' ) );
		echo '</h2>';

		if ( 'templates' === $tab ) {
			self::render_templates_tab();
		} else {
			self::render_convert_tab();
		}

		echo '</div>';
	}

	/**
	 * One nav tab.
	 *
	 * @param string $slug   Slug.
	 * @param string $active Active.
	 * @param string $label  Label.
	 */
	protected static function nav_tab( $slug, $active, $label ) {
		$url = add_query_arg(
			array(
				'page' => 'wc-optic-convert',
				'tab'  => $slug,
			),
			admin_url( 'admin.php' )
		);
		printf(
			'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
			esc_url( $url ),
			$slug === $active ? ' nav-tab-active' : '',
			esc_html( $label )
		);
	}

	/**
	 * Templates tab.
	 */
	protected static function render_templates_tab() {
		echo '<p class="description">' . esc_html__( 'A template stores from / to / step for each power of a division. Use it on the product screen or when converting many simple products.', 'wc-optic' ) . '</p>';

		echo '<table class="widefat striped wc-optic-template-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Name', 'wc-optic' ) . '</th>';
		echo '<th>' . esc_html__( 'Division', 'wc-optic' ) . '</th>';
		echo '<th>' . esc_html__( 'Ranges', 'wc-optic' ) . '</th>';
		echo '<th>' . esc_html__( 'Internals', 'wc-optic' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';

		$divs = WC_Optic_Plugin::get_divisions();
		foreach ( WC_Optic_Power_Template::get_all() as $tpl ) {
			$count = WC_Optic_Power_Template::count_children( $tpl );
			echo '<tr data-template-id="' . esc_attr( $tpl['id'] ) . '">';
			echo '<td>' . esc_html( $tpl['name'] ) . '</td>';
			echo '<td>' . esc_html( isset( $divs[ $tpl['division'] ] ) ? $divs[ $tpl['division'] ]['label'] : $tpl['division'] ) . '</td>';
			echo '<td>' . esc_html( self::format_ranges_summary( $tpl['ranges'] ) ) . '</td>';
			echo '<td>' . esc_html( is_wp_error( $count ) ? $count->get_error_message() : (string) $count ) . '</td>';
			echo '<td><button type="button" class="button-link-delete wc-optic-delete-template">' . esc_html__( 'Delete', 'wc-optic' ) . '</button></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Add template', 'wc-optic' ) . '</h2>';
		echo '<form id="wc-optic-template-form" class="wc-optic-template-form">';
		echo '<p><label for="wc_optic_tpl_name">' . esc_html__( 'Name', 'wc-optic' ) . '</label><br />';
		echo '<input type="text" id="wc_optic_tpl_name" name="name" class="regular-text" required /></p>';

		echo '<p><label for="wc_optic_tpl_division">' . esc_html__( 'Optical division', 'wc-optic' ) . '</label><br />';
		echo '<select id="wc_optic_tpl_division" name="division" class="wc-optic-select2">';
		echo '<option value="">' . esc_html__( '— Select —', 'wc-optic' ) . '</option>';
		foreach ( WC_Optic_Plugin::get_visible_divisions() as $slug => $def ) {
			echo '<option value="' . esc_attr( $slug ) . '">' . esc_html( $def['label'] ) . '</option>';
		}
		echo '</select></p>';

		self::render_range_fields( '', array(), 'ranges', 'wc-optic-tpl-ranges' );
		echo '<p><span class="wc-optic-range-count" data-count="0">0</span> ' . esc_html__( 'internal products', 'wc-optic' ) . '</p>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save template', 'wc-optic' ) . '</button></p>';
		echo '</form>';
	}

	/**
	 * Convert tab: product list + wizard launcher.
	 */
	protected static function render_convert_tab() {
		$stats    = WC_Optic_Converter::get_convert_stats();
		$products = WC_Optic_Converter::get_eligible_products(
			array(
				'limit' => WC_Optic_Converter::CONVERT_LIST_LIMIT,
				'page'  => 1,
			)
		);
		echo '<p class="description">' . esc_html__( 'Select one or more simple products, then start the wizard. Each product is converted one by one (Next). The modal cannot be closed by clicking outside.', 'wc-optic' ) . '</p>';
		if ( class_exists( 'WC_Optic_WPML' ) && WC_Optic_WPML::is_active() ) {
			echo '<p class="description">' . esc_html__( 'WPML: only default-language originals are listed. Internals are copied to Arabic (and other) translations after conversion.', 'wc-optic' ) . '</p>';
		}

		echo '<p class="wc-optic-convert-stats description" id="wc-optic-convert-stats"';
		echo ' data-eligible="' . esc_attr( (string) $stats['eligible'] ) . '"';
		echo ' data-total-simple="' . esc_attr( (string) $stats['total_simple'] ) . '">';
		echo esc_html(
			sprintf(
				/* translators: 1: eligible count, 2: total simple products in catalog */
				__( '%1$d products eligible for conversion (%2$d simple products in catalog).', 'wc-optic' ),
				$stats['eligible'],
				$stats['total_simple']
			)
		);
		if ( $stats['excluded_wpml'] > 0 ) {
			echo ' ';
			echo esc_html(
				sprintf(
					/* translators: %d: WPML translation count excluded */
					__( '%d WPML translations excluded.', 'wc-optic' ),
					$stats['excluded_wpml']
				)
			);
		}
		echo '</p>';

		echo '<p class="wc-optic-convert-toolbar">';
		echo '<label><input type="checkbox" id="wc-optic-convert-select-all" /> ' . esc_html__( 'Select all matching rows', 'wc-optic' ) . '</label> ';
		echo '<button type="button" class="button button-primary" id="wc-optic-start-wizard">' . esc_html__( 'Start wizard', 'wc-optic' ) . '</button>';
		echo '</p>';

		echo '<div class="wc-optic-datatable-wrap wc-optic-convert-datatable-wrap">';
		echo '<table class="widefat wc-optic-convert-datatable display" id="wc-optic-convert-table" width="100%">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Product', 'wc-optic' ) . '</th>';
		echo '<th>' . esc_html__( 'SKU (parent)', 'wc-optic' ) . '</th>';
		echo '<th>' . esc_html__( 'Price', 'wc-optic' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $products as $product ) {
			$product_id = $product->get_id();
			echo '<tr class="wc-optic-convert-row" data-product-id="' . esc_attr( (string) $product_id ) . '">';
			echo '<td class="wc-optic-convert-product-name">';
			echo '<label class="wc-optic-convert-product-label">';
			echo '<input type="checkbox" class="wc-optic-convert-product" value="' . esc_attr( (string) $product_id ) . '" /> ';
			echo esc_html( $product->get_name() );
			echo '</label></td>';
			echo '<td>' . esc_html( (string) $product->get_sku() ) . '</td>';
			echo '<td data-order="' . esc_attr( wc_format_decimal( $product->get_price( 'edit' ), wc_get_price_decimals() ) ) . '">' . wp_kses_post( $product->get_price_html() ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '</div>';

		self::render_wizard_modal();
	}

	/**
	 * Bootstrap modal (static backdrop) — one product at a time.
	 */
	protected static function render_wizard_modal() {
		echo '<div class="modal fade wc-optic-bs" id="wc-optic-wizard-modal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="wc-optic-wizard-title" aria-hidden="true">';
		echo '<div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">';
		echo '<div class="modal-content">';

		echo '<div class="modal-header">';
		echo '<h5 class="modal-title" id="wc-optic-wizard-title">' . esc_html__( 'Convert product', 'wc-optic' ) . '</h5>';
		echo '<span class="wc-optic-wizard-progress" id="wc-optic-wizard-progress"></span>';
		echo '<button type="button" class="btn-close" id="wc-optic-wizard-cancel" aria-label="' . esc_attr__( 'Close', 'wc-optic' ) . '"></button>';
		echo '</div>';

		echo '<div class="modal-body">';
		echo '<div class="progress wc-optic-wizard-bar" role="progressbar">';
		echo '<div class="progress-bar" id="wc-optic-wizard-bar" style="width: 33%"></div>';
		echo '</div>';
		echo '<ol class="wc-optic-wizard-steps">';
		echo '<li class="is-active" data-step-label="1">' . esc_html__( 'Product', 'wc-optic' ) . '</li>';
		echo '<li data-step-label="2">' . esc_html__( 'Identity', 'wc-optic' ) . '</li>';
		echo '<li data-step-label="3">' . esc_html__( 'Powers', 'wc-optic' ) . '</li>';
		echo '</ol>';

		echo '<div class="wc-optic-wizard-alert" id="wc-optic-wizard-alert" hidden></div>';

		echo '<div class="wc-optic-wizard-pane" data-step="1">';
		echo '<div class="wc-optic-wizard-product" id="wc-optic-wizard-product-card"></div>';
		echo '<p class="form-field"><label for="wc_optic_wizard_division">' . esc_html__( 'Optical division', 'wc-optic' ) . ' <abbr class="required">*</abbr></label>';
		echo '<select id="wc_optic_wizard_division" class="wc-optic-select2 wc-optic-wizard-select">';
		echo '<option value="">' . esc_html__( '— Select —', 'wc-optic' ) . '</option>';
		foreach ( WC_Optic_Plugin::get_visible_divisions() as $slug => $def ) {
			echo '<option value="' . esc_attr( $slug ) . '">' . esc_html( $def['label'] ) . '</option>';
		}
		echo '</select></p>';
		echo '</div>';

		echo '<div class="wc-optic-wizard-pane" data-step="2" hidden>';
		echo '<p class="description">' . esc_html__( 'Choose these values once for this product. They are copied to every generated internal.', 'wc-optic' ) . '</p>';
		self::render_identity_fields( array(), 'wizard_catalog', false );
		echo '</div>';

		echo '<div class="wc-optic-wizard-pane" data-step="3" hidden>';
		echo '<p><label for="wc_optic_wizard_template">' . esc_html__( 'Range template', 'wc-optic' ) . '</label><br />';
		echo '<select id="wc_optic_wizard_template" class="wc-optic-wizard-select">';
		echo '<option value="">' . esc_html__( 'Custom range', 'wc-optic' ) . '</option>';
		foreach ( WC_Optic_Power_Template::get_all() as $tpl ) {
			echo '<option value="' . esc_attr( $tpl['id'] ) . '" data-division="' . esc_attr( $tpl['division'] ) . '">' . esc_html( $tpl['name'] ) . '</option>';
		}
		echo '</select></p>';
		self::render_range_fields( '', array(), 'wizard_ranges', 'wc-optic-wizard-ranges' );
		echo '<p class="description">' . esc_html__( 'If 0.00 sits inside From / To, it is always generated as +0.00 — even when the step would skip it.', 'wc-optic' ) . '</p>';
		echo '<p><span class="wc-optic-range-count" data-count="0">0</span> ' . esc_html__( 'internal products', 'wc-optic' ) . '</p>';
		echo '<p><label for="wc_optic_wizard_price">' . esc_html__( 'Unit price', 'wc-optic' ) . '</label><br />';
		echo '<input type="text" id="wc_optic_wizard_price" class="wc_input_price regular-text" /></p>';
		echo '<p><label for="wc_optic_wizard_stock">' . esc_html__( 'Default stock', 'wc-optic' ) . '</label><br />';
		echo '<input type="number" id="wc_optic_wizard_stock" min="0" step="1" value="0" /></p>';
		echo '<p><label><input type="checkbox" id="wc_optic_wizard_replace" /> ' . esc_html__( 'Replace existing internals', 'wc-optic' ) . '</label></p>';
		echo '</div>';

		echo '</div>';

		echo '<div class="modal-footer">';
		echo '<button type="button" class="button" id="wc-optic-wizard-back">' . esc_html__( 'Back', 'wc-optic' ) . '</button>';
		echo '<button type="button" class="button button-primary" id="wc-optic-wizard-next">' . esc_html__( 'Next', 'wc-optic' ) . '</button>';
		echo '</div>';

		echo '</div></div></div>';
	}

	/**
	 * Shared identity selects.
	 *
	 * @param array  $selected Selected ids.
	 * @param string $name     Field name prefix.
	 * @param bool   $required Required.
	 * @param string $division Optional division slug (controls color field visibility).
	 */
	public static function render_identity_fields( array $selected, $name = '_optic_identity', $required = true, $division = '' ) {
		echo '<div class="wc-optic-identity-fields">';
		foreach ( WC_Optic_SKU::get_identity_catalog_types() as $type ) {
			$show_color = ( 'color' !== $type ) || WC_Optic_Plugin::division_shows_color( $division );
			$current = isset( $selected[ $type ] ) ? (int) $selected[ $type ] : 0;
			$field   = $name . '[' . $type . ']';
			$id      = 'wc_optic_identity_' . $type . '_' . sanitize_key( $name );
			echo '<p class="form-field form-field-wide wc-optic-identity-field wc-optic-identity-field--' . esc_attr( $type ) . '" data-optic-type="' . esc_attr( $type ) . '"' . ( $show_color ? '' : ' hidden' ) . '>';
			echo '<label for="' . esc_attr( $id ) . '">' . esc_html( WC_Optic_Catalog::get_type_label( $type ) );
			if ( $required && $show_color ) {
				echo ' <abbr class="required" title="' . esc_attr__( 'required', 'woocommerce' ) . '">*</abbr>';
			}
			echo '</label>';
			echo '<select name="' . esc_attr( $field ) . '" id="' . esc_attr( $id ) . '" class="wc-enhanced-select wc-optic-select2 wc-optic-identity-select" data-optic-type="' . esc_attr( $type ) . '" data-placeholder="' . esc_attr__( '— Select —', 'wc-optic' ) . '"' . ( $required && $show_color ? ' required="required" aria-required="true"' : '' ) . '>';
			echo '<option value=""></option>';
			foreach ( WC_Optic_Catalog::get_terms( $type ) as $row ) {
				echo '<option value="' . esc_attr( (string) $row->id ) . '" ' . selected( $current, (int) $row->id, false ) . '>' . esc_html( WC_Optic_Catalog::get_display_name( $row ) ) . '</option>';
			}
			echo '</select></p>';
		}
		echo '</div>';
	}

	/**
	 * From / to / step fields for all power types (JS shows those of the division).
	 *
	 * @param string $division Current division.
	 * @param array  $ranges   Saved ranges.
	 * @param string $name     Field name prefix.
	 * @param string $wrapper  Wrapper class.
	 */
	public static function render_range_fields( $division, array $ranges, $name = '_optic_power_ranges', $wrapper = 'wc-optic-power-ranges' ) {
		$ranges  = WC_Optic_SKU::normalize_power_ranges( $ranges, $division );
		$allowed = $division ? WC_Optic_Plugin::get_powers_for_division( $division ) : array();

		echo '<div class="' . esc_attr( $wrapper ) . ' wc-optic-power-ranges" data-name-prefix="' . esc_attr( $name ) . '">';
		foreach ( WC_Optic_Catalog::get_power_types() as $power ) {
			$row   = isset( $ranges[ $power ] ) ? $ranges[ $power ] : array(
				'from' => '',
				'to'   => '',
				'step' => (string) WC_Optic_Catalog::get_default_power_step( $power ),
			);
			$show  = empty( $allowed ) ? false : in_array( $power, $allowed, true );
			$base  = $name . '[' . $power . ']';
			echo '<div class="wc-optic-power-range" data-power="' . esc_attr( $power ) . '"' . ( $show ? '' : ' hidden' ) . '>';
			echo '<p class="wc-optic-power-range__label"><strong>' . esc_html( WC_Optic_Catalog::get_type_label( $power ) ) . '</strong></p>';
			echo '<div class="wc-optic-power-range__grid">';
			self::render_range_input( $base . '[from]', $row['from'], __( 'From', 'wc-optic' ), 'wc-optic-range-from' );
			self::render_range_input( $base . '[to]', $row['to'], __( 'To', 'wc-optic' ), 'wc-optic-range-to' );
			self::render_range_input( $base . '[step]', $row['step'], __( 'Step', 'wc-optic' ), 'wc-optic-range-step' );
			echo '</div>';
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * One numeric range input.
	 *
	 * @param string $name  Name.
	 * @param string $value Value.
	 * @param string $label Label.
	 * @param string $class Class.
	 */
	protected static function render_range_input( $name, $value, $label, $class ) {
		echo '<label class="wc-optic-power-range__field">';
		echo '<span>' . esc_html( $label ) . '</span>';
		echo '<input type="text" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" class="' . esc_attr( $class ) . '" />';
		echo '</label>';
	}

	/**
	 * Human summary of ranges.
	 *
	 * @param array $ranges Ranges.
	 * @return string
	 */
	protected static function format_ranges_summary( array $ranges ) {
		$bits = array();
		foreach ( $ranges as $power => $row ) {
			if ( ! is_array( $row ) || '' === trim( (string) ( $row['from'] ?? '' ) ) ) {
				continue;
			}
			$bits[] = sprintf(
				'%s %s→%s / %s',
				WC_Optic_Catalog::get_type_label( $power ),
				$row['from'],
				$row['to'],
				$row['step']
			);
		}
		return implode( ', ', $bits );
	}
}
