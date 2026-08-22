<?php
/**
 * Product data admin UI for Optic Product type.
 *
 * @package WC_Optic_Product
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Optic_Admin_Product
 */
class WC_Optic_Admin_Product {

	/**
	 * Hooks.
	 */
	public static function hooks() {
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'product_tabs' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'product_panels' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product' ), 10, 1 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Tab definition.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public static function product_tabs( $tabs ) {
		$tabs['optic_config'] = array(
			'label'    => __( 'Optic configuration', 'wc-optic' ),
			'target'   => 'optic_product_data_panel',
			'class'    => array( 'show_if_optic_product' ),
			'priority' => 1,
		);
		return $tabs;
	}

	/**
	 * Panel HTML.
	 */
	public static function product_panels() {
		global $post;

		echo '<div id="optic_product_data_panel" class="panel woocommerce_options_panel hidden">';

		$product = ( $post && ! empty( $post->ID ) ) ? wc_get_product( $post->ID ) : null;
		if ( ( ! $product || 'optic_product' !== $product->get_type() ) && self::is_new_product_screen() ) {
			$product = new WC_Product_Optic_Product();
		}

		if ( ! $product || 'optic_product' !== $product->get_type() ) {
			echo '<p>' . esc_html__( 'Save as Optic Product to configure optical data.', 'wc-optic' ) . '</p></div>';
			return;
		}

		woocommerce_wp_select(
			array(
				'id'                => '_optic_division',
				'label'             => __( 'Optical division', 'wc-optic' ) . ' <abbr class="required" title="' . esc_attr__( 'required', 'woocommerce' ) . '">*</abbr>',
				'options'           => self::division_options( (string) $product->get_meta( '_optic_division', true ) ),
				'value'             => $product->get_meta( '_optic_division', true ),
				'class'             => 'wc-enhanced-select wc-optic-select2',
				'wrapper_class'     => 'form-field-wide',
				'custom_attributes' => array(
					'data-placeholder' => __( '— Select —', 'wc-optic' ),
					'required'         => 'required',
					'aria-required'    => 'true',
				),
			)
		);

		$division      = (string) $product->get_meta( '_optic_division', true );
		$identity      = WC_Optic_SKU::get_identity_catalog( $product );
		$child_configs = WC_Optic_SKU::get_child_configs( $product );
		if ( empty( $child_configs ) ) {
			$child_configs[] = WC_Optic_SKU::normalize_child_config(
				array(
					'enabled' => true,
					'catalog' => $identity,
				),
				$division,
				0
			);
		}

		echo '<p class="form-field"><strong>' . esc_html__( 'Optical identity', 'wc-optic' ) . '</strong></p>';
		echo '<p class="form-field description">' . esc_html__( 'Choose these values once. They are copied to every internal product and used in the SKU.', 'wc-optic' ) . '</p>';
		WC_Optic_Admin_Convert::render_identity_fields( $identity, '_optic_identity', true );

		echo '<p class="form-field description">' . esc_html__( 'To generate power internals from a range, use Alwaleed Optics → Convert (wizard). You can still add one internal product at a time below.', 'wc-optic' ) . '</p>';

		echo '<div class="wc-optic-child-configs">';
		echo '<p class="form-field"><strong>' . esc_html__( 'Internal products', 'wc-optic' ) . '</strong></p>';
		echo '<p class="form-field wc-optic-sku-powers-hint description">';
		echo esc_html__( 'Each sellable power combination is one internal product (price, stock, SKU).', 'wc-optic' );
		echo '</p>';
		echo '<div id="wc-optic-child-config-list">';
		foreach ( array_values( $child_configs ) as $index => $config ) {
			self::render_child_config_block( $config, (string) $index, $division );
		}
		echo '</div>';
		echo '<p class="wc-optic-child-actions">';
		echo '<button type="button" class="button button-secondary" id="wc-optic-add-child">+</button> ';
		echo '<span class="description">' . esc_html__( 'Add another internal product.', 'wc-optic' ) . '</span>';
		echo '</p>';
		echo '</div>';

		echo '<script type="text/html" id="wc-optic-child-config-template">';
		self::render_child_config_block(
			WC_Optic_SKU::normalize_child_config(
				array(
					'enabled' => true,
				),
				$division,
				0
			),
			'__INDEX__',
			$division,
			true
		);
		echo '</script>';

		echo '</div>';
	}

	/**
	 * Type label for admin.
	 *
	 * @param string $type Type key.
	 * @return string
	 */
	protected static function type_label( $type ) {
		$labels = WC_Optic_Catalog::get_type_labels();
		if ( isset( $labels[ $type ] ) ) {
			return $labels[ $type ];
		}
		return $type;
	}

	/**
	 * Division <select> options.
	 *
	 * @param string $current_division Currently selected division slug.
	 * @return array
	 */
	protected static function division_options( $current_division = '' ) {
		$out = array( '' => __( '— Select —', 'wc-optic' ) );
		foreach ( WC_Optic_Plugin::get_visible_divisions() as $slug => $def ) {
			$out[ $slug ] = $def['label'];
		}

		$current_division = sanitize_key( (string) $current_division );
		if ( $current_division && ! isset( $out[ $current_division ] ) ) {
			$all = WC_Optic_Plugin::get_divisions();
			if ( isset( $all[ $current_division ] ) ) {
				$out[ $current_division ] = sprintf(
					/* translators: %s: division label */
					__( '%s (hidden)', 'wc-optic' ),
					$all[ $current_division ]['label']
				);
			}
		}

		return $out;
	}

	/**
	 * Save handler.
	 *
	 * @param WC_Product $product Product.
	 */
	public static function save_product( $product ) {
		if ( 'optic_product' !== $product->get_type() ) {
			return;
		}
		$division = isset( $_POST['_optic_division'] ) ? sanitize_key( wp_unslash( $_POST['_optic_division'] ) ) : '';
		$divs     = WC_Optic_Plugin::get_divisions();
		if ( $division && ! isset( $divs[ $division ] ) ) {
			$division = '';
		}

		$identity = WC_Optic_SKU::normalize_identity_catalog( isset( $_POST['_optic_identity'] ) ? wp_unslash( $_POST['_optic_identity'] ) : array() );

		$raw_children = isset( $_POST['_optic_child_configs'] ) && is_array( $_POST['_optic_child_configs'] ) ? wp_unslash( $_POST['_optic_child_configs'] ) : array();
		foreach ( $raw_children as $key => $raw_child ) {
			if ( is_array( $raw_child ) ) {
				$raw_children[ $key ]['catalog'] = $identity;
			}
		}
		$children = WC_Optic_SKU::normalize_child_configs( $raw_children, $division );

		$complete = WC_Optic_SKU::validate_child_configs_complete( $children, $division, $raw_children );
		if ( is_wp_error( $complete ) ) {
			WC_Admin_Notices::add_custom_notice(
				'wc_optic_incomplete_child',
				$complete->get_error_message()
			);
			return;
		}

		$product->update_meta_data( '_optic_division', $division );
		$product->update_meta_data( WC_Optic_SKU::IDENTITY_META_KEY, $identity );

		$unique = WC_Optic_SKU::validate_unique_power_combinations( $children, $division );
		if ( is_wp_error( $unique ) ) {
			WC_Admin_Notices::add_custom_notice(
				'wc_optic_duplicate_powers',
				$unique->get_error_message()
			);
			return;
		}

		$children = WC_Optic_SKU::preserve_child_backorder_consumed( $product, $children );
		WC_Optic_SKU::persist_child_data( $product, $children );
		WC_Optic_SKU::sync_product_sku( $product );
	}

	/**
	 * Scripts on product edit screen.
	 *
	 * @param string $hook Hook.
	 */
	public static function enqueue( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'product' !== $screen->id ) {
			return;
		}

		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_script( 'selectWoo' );
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style(
			'wc-optic-admin',
			WC_OPTIC_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			WC_OPTIC_VERSION
		);

		wp_enqueue_script(
			'wc-optic-admin-product',
			WC_OPTIC_PLUGIN_URL . 'assets/js/admin-product.js',
			array( 'jquery', 'selectWoo', 'wc-enhanced-select', 'wp-util' ),
			WC_OPTIC_VERSION,
			true
		);
		$division_powers = array();
		foreach ( WC_Optic_Plugin::get_divisions() as $slug => $def ) {
			$division_powers[ $slug ] = $def['powers'];
		}

		wp_localize_script(
			'wc-optic-admin-product',
			'wcOpticAdmin',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'wc_optic_admin' ),
				'isNewProduct'   => self::is_new_product_screen(),
				'divisionPowers' => $division_powers,
				'powerTypes'     => WC_Optic_Catalog::get_power_types(),
				'backorderEnabled'   => WC_Optic_SKU::is_backorder_enabled(),
				'globalBackorderQty' => WC_Optic_SKU::get_global_backorder_qty(),
				'alertEnabled'       => WC_Optic_Stock::is_alert_enabled(),
				'globalAlertQty'     => WC_Optic_Stock::get_alert_qty(),
				'i18n'               => array(
					'product'           => __( 'Product', 'wc-optic' ),
					'remove'            => __( 'Remove', 'wc-optic' ),
					'backorderAllowed'  => __( 'Backorder allowed', 'wc-optic' ),
					'backorderGlobal'   => __( 'Global', 'wc-optic' ),
					'backorderCustom'   => __( 'Custom', 'wc-optic' ),
					'backorderDisabled' => __( 'Backorder is disabled in global settings.', 'wc-optic' ),
					'alertGlobal'       => __( 'Global', 'wc-optic' ),
					'alertCustom'       => __( 'Custom', 'wc-optic' ),
					'alertDisabled'     => __( 'Stock alerts are disabled in global settings.', 'wc-optic' ),
				),
			)
		);
	}

	/**
	 * Render backorder allowance fields for one internal product.
	 *
	 * @param array  $config      Child config.
	 * @param string $index_token Field index token.
	 */
	protected static function render_child_backorder_fields( array $config, $index_token ) {
		$backorder_custom  = ! empty( $config['backorder_custom'] );
		$backorder_enabled = WC_Optic_SKU::is_backorder_enabled();
		$custom_qty        = isset( $config['backorder_qty'] ) && '' !== trim( (string) $config['backorder_qty'] )
			? (string) absint( $config['backorder_qty'] )
			: (string) WC_Optic_SKU::get_global_backorder_qty();
		$display_qty       = $backorder_custom
			? $custom_qty
			: ( $backorder_enabled ? (string) WC_Optic_SKU::get_global_backorder_qty() : '0' );
		$consumed          = WC_Optic_SKU::get_child_backorder_consumed( $config );
		$pf                = '_optic_child_configs[' . $index_token . ']';
		$row_class         = 'wc-optic-child-backorder-card wc-optic-child-backorder-row';
		if ( ! $backorder_enabled ) {
			$row_class .= ' wc-optic-backorder-disabled';
		}
		if ( $backorder_custom ) {
			$row_class .= ' wc-optic-child-backorder-card--custom';
		}

		echo '<div class="' . esc_attr( $row_class ) . '">';
		echo '<div class="wc-optic-child-backorder-card__header">';
		echo '<span class="wc-optic-child-backorder-card__title">' . esc_html__( 'Backorder allowance', 'wc-optic' ) . '</span>';
		echo '<span class="wc-optic-child-backorder-card__source">';
		echo esc_html( $backorder_custom ? __( 'Custom', 'wc-optic' ) : __( 'Global', 'wc-optic' ) );
		echo '</span>';
		echo '</div>';

		echo '<div class="wc-optic-child-backorder-card__body">';
		echo '<div class="wc-optic-child-backorder-effective">';
		echo '<span class="wc-optic-child-backorder-display wc-optic-child-backorder-effective__value">' . esc_html( $display_qty ) . '</span>';
		echo '<span class="wc-optic-child-backorder-effective__label">' . esc_html__( 'extra units', 'wc-optic' ) . '</span>';
		echo '</div>';

		echo '<div class="wc-optic-child-backorder-card__controls">';
		echo '<label class="wc-optic-backorder-toggle wc-optic-child-backorder-custom-label">';
		echo '<input type="checkbox" class="wc-optic-child-backorder-custom wc-optic-backorder-toggle__input" name="' . esc_attr( $pf ) . '[backorder_custom]" value="1" ' . checked( $backorder_custom, true, false ) . ' />';
		echo '<span class="wc-optic-backorder-toggle__switch" aria-hidden="true"></span>';
		echo '<span class="wc-optic-backorder-toggle__text">' . esc_html__( 'Custom allowance', 'wc-optic' ) . '</span>';
		echo '</label>';

		echo '<div class="wc-optic-child-backorder-custom-field">';
		echo '<label class="screen-reader-text" for="wc_optic_child_' . esc_attr( $index_token ) . '_backorder_qty">' . esc_html__( 'Custom backorder quantity', 'wc-optic' ) . '</label>';
		echo '<input type="number" class="wc-optic-child-backorder-qty wc-optic-backorder-input" id="wc_optic_child_' . esc_attr( $index_token ) . '_backorder_qty" name="' . esc_attr( $pf ) . '[backorder_qty]" value="' . esc_attr( $custom_qty ) . '" min="0" step="1" ' . disabled( ! $backorder_custom, true, false ) . ' />';
		echo '<span class="wc-optic-child-backorder-custom-field__suffix">' . esc_html__( 'units', 'wc-optic' ) . '</span>';
		echo '</div>';
		echo '</div>';
		echo '</div>';

		if ( ! $backorder_enabled ) {
			echo '<p class="wc-optic-child-backorder-card__notice description">' . esc_html__( 'Backorder is disabled in global settings.', 'wc-optic' ) . '</p>';
		} elseif ( $consumed > 0 ) {
			echo '<p class="wc-optic-child-backorder-card__notice wc-optic-child-backorder-consumed-note">';
			echo esc_html(
				sprintf(
					/* translators: %d: consumed backorder units */
					__( '%d backorder unit(s) already sold.', 'wc-optic' ),
					$consumed
				)
			);
			echo '</p>';
		}

		echo '<input type="hidden" name="' . esc_attr( $pf ) . '[backorder_consumed]" value="' . esc_attr( (string) $consumed ) . '" class="wc-optic-child-backorder-consumed" />';
		echo '</div>';
	}

	/**
	 * Render stock alert threshold fields for one internal product.
	 *
	 * @param array  $config      Child config.
	 * @param string $index_token Field index token.
	 */
	protected static function render_child_alert_fields( array $config, $index_token ) {
		$alert_custom  = ! empty( $config['alert_custom'] );
		$alert_enabled = WC_Optic_Stock::is_alert_enabled();
		$custom_qty    = isset( $config['alert_qty'] ) && '' !== trim( (string) $config['alert_qty'] )
			? (string) absint( $config['alert_qty'] )
			: (string) WC_Optic_Stock::get_alert_qty();
		$display_qty   = $alert_custom
			? $custom_qty
			: ( $alert_enabled ? (string) WC_Optic_Stock::get_alert_qty() : '0' );
		$pf            = '_optic_child_configs[' . $index_token . ']';
		$row_class     = 'wc-optic-child-backorder-card wc-optic-child-alert-card wc-optic-child-alert-row';
		if ( ! $alert_enabled ) {
			$row_class .= ' wc-optic-backorder-disabled';
		}
		if ( $alert_custom ) {
			$row_class .= ' wc-optic-child-backorder-card--custom';
		}

		echo '<div class="' . esc_attr( $row_class ) . '">';
		echo '<div class="wc-optic-child-backorder-card__header">';
		echo '<span class="wc-optic-child-backorder-card__title">' . esc_html__( 'Stock alert threshold', 'wc-optic' ) . '</span>';
		echo '<span class="wc-optic-child-backorder-card__source wc-optic-child-alert-card__source">';
		echo esc_html( $alert_custom ? __( 'Custom', 'wc-optic' ) : __( 'Global', 'wc-optic' ) );
		echo '</span>';
		echo '</div>';

		echo '<div class="wc-optic-child-backorder-card__body">';
		echo '<div class="wc-optic-child-backorder-effective">';
		echo '<span class="wc-optic-child-alert-display wc-optic-child-backorder-effective__value">' . esc_html( $display_qty ) . '</span>';
		echo '<span class="wc-optic-child-backorder-effective__label">' . esc_html__( 'units or less', 'wc-optic' ) . '</span>';
		echo '</div>';

		echo '<div class="wc-optic-child-backorder-card__controls">';
		echo '<label class="wc-optic-backorder-toggle wc-optic-child-alert-custom-label">';
		echo '<input type="checkbox" class="wc-optic-child-alert-custom wc-optic-backorder-toggle__input" name="' . esc_attr( $pf ) . '[alert_custom]" value="1" ' . checked( $alert_custom, true, false ) . ' />';
		echo '<span class="wc-optic-backorder-toggle__switch" aria-hidden="true"></span>';
		echo '<span class="wc-optic-backorder-toggle__text">' . esc_html__( 'Custom threshold', 'wc-optic' ) . '</span>';
		echo '</label>';

		echo '<div class="wc-optic-child-alert-custom-field wc-optic-child-backorder-custom-field">';
		echo '<label class="screen-reader-text" for="wc_optic_child_' . esc_attr( $index_token ) . '_alert_qty">' . esc_html__( 'Custom alert threshold', 'wc-optic' ) . '</label>';
		echo '<input type="number" class="wc-optic-child-alert-qty wc-optic-backorder-input" id="wc_optic_child_' . esc_attr( $index_token ) . '_alert_qty" name="' . esc_attr( $pf ) . '[alert_qty]" value="' . esc_attr( $custom_qty ) . '" min="0" step="1" ' . disabled( ! $alert_custom, true, false ) . ' />';
		echo '<span class="wc-optic-child-backorder-custom-field__suffix">' . esc_html__( 'units or less', 'wc-optic' ) . '</span>';
		echo '</div>';
		echo '</div>';
		echo '</div>';

		if ( ! $alert_enabled ) {
			echo '<p class="wc-optic-child-backorder-card__notice description">' . esc_html__( 'Stock alerts are disabled in global settings.', 'wc-optic' ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * Whether the current admin screen is the new product screen.
	 *
	 * @return bool
	 */
	protected static function is_new_product_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && 'product' === $screen->id && 'add' === $screen->action;
	}

	/**
	 * Render one repeatable child config block.
	 *
	 * @param array  $config      Child config.
	 * @param string $index_token Field index token.
	 * @param string $division    Parent division.
	 * @param bool   $is_template Template mode.
	 */
	protected static function render_child_config_block( array $config, $index_token, $division, $is_template = false ) {
		$power_types = WC_Optic_Catalog::get_power_types();
		$title       = ! empty( $config['label'] ) ? (string) $config['label'] : __( 'Product', 'wc-optic' );

		echo '<div class="wc-optic-child-config" data-child-index="' . esc_attr( $index_token ) . '">';
		echo '<div class="wc-optic-child-config__header">';
		echo '<h4 class="wc-optic-child-config__title">' . esc_html( $title ) . '</h4>';
		echo '<button type="button" class="button-link-delete wc-optic-remove-child">' . esc_html__( 'Remove', 'wc-optic' ) . '</button>';
		echo '</div>';

		echo '<input type="hidden" class="wc-optic-child-id" name="' . esc_attr( '_optic_child_configs[' . $index_token . '][id]' ) . '" value="' . esc_attr( (string) ( $config['id'] ?? '' ) ) . '" />';
		echo '<input type="hidden" class="wc-optic-child-sort" name="' . esc_attr( '_optic_child_configs[' . $index_token . '][sort]' ) . '" value="' . esc_attr( (string) ( $config['sort'] ?? 0 ) ) . '" />';

		echo '<p class="form-field form-field-wide wc-optic-child-enabled">';
		echo '<label>';
		echo '<input type="checkbox" name="' . esc_attr( '_optic_child_configs[' . $index_token . '][enabled]' ) . '" value="1" ' . checked( ! empty( $config['enabled'] ), true, false ) . ' />';
		echo ' ' . esc_html__( 'Enabled', 'wc-optic' );
		echo '</label>';
		echo '</p>';

		self::render_child_text_input(
			'_optic_child_configs[' . $index_token . '][label]',
			'wc_optic_child_' . $index_token . '_label',
			__( 'Label', 'wc-optic' ),
			(string) ( $config['label'] ?? '' ),
			'wc-optic-child-label',
			'text',
			array(),
			true
		);

		self::render_child_text_input(
			'_optic_child_configs[' . $index_token . '][unit_price]',
			'wc_optic_child_' . $index_token . '_unit_price',
			__( 'Unit price', 'wc-optic' ),
			(string) ( $config['unit_price'] ?? '' ),
			'wc-optic-child-unit-price wc_input_price',
			'text',
			array(),
			true
		);

		self::render_child_text_input(
			'_optic_child_configs[' . $index_token . '][stock_qty]',
			'wc_optic_child_' . $index_token . '_stock_qty',
			__( 'Stock quantity', 'wc-optic' ),
			(string) ( $config['stock_qty'] ?? '' ),
			'wc-optic-child-stock-qty',
			'number',
			array(
				'min'  => '0',
				'step' => '1',
			),
			true
		);

		echo '<div class="wc-optic-child-inventory-panels">';
		self::render_child_backorder_fields( $config, $index_token );
		self::render_child_alert_fields( $config, $index_token );
		echo '</div>';

		echo '<div class="wc-optic-child-fields-grid">';
		foreach ( WC_Optic_SKU::META_KEYS as $type => $meta_key ) {
			$is_power = in_array( $type, $power_types, true );
			$value    = $is_power ? (int) ( $config['powers'][ $type ] ?? 0 ) : (int) ( $config['catalog'][ $type ] ?? 0 );
			if ( ! $is_power ) {
				echo '<input type="hidden" class="wc-optic-child-identity-value" data-optic-type="' . esc_attr( $type ) . '" name="' . esc_attr( '_optic_child_configs[' . $index_token . '][catalog][' . $type . ']' ) . '" value="' . esc_attr( (string) $value ) . '" />';
				continue;
			}
			self::render_child_select_field(
				$index_token,
				$type,
				$value,
				true,
				$division
			);
		}
		echo '</div>';

		$child_sku = (string) ( $config['sku'] ?? '' );
		echo '<p class="form-field form-field-wide wc-optic-child-sku">';
		echo '<label>' . esc_html__( 'SKU preview', 'wc-optic' ) . '</label>';
		echo '<code class="wc-optic-child-sku-preview">' . esc_html( $child_sku ) . '</code>';
		echo '</p>';
		echo '<div class="form-field form-field-wide wc-optic-child-qr-wrap">';
		echo '<label>' . esc_html__( 'Preparation QR code', 'wc-optic' ) . '</label>';
		echo '<div class="wc-optic-child-qr">';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built via WC_Optic_QR::render_admin_block().
		echo WC_Optic_QR::render_admin_block( $child_sku );
		echo '</div>';
		echo '<p class="description">' . esc_html__( 'Admin only — scan during order preparation. Not shown to customers.', 'wc-optic' ) . '</p>';
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Render child text input.
	 *
	 * @param string $name       Field name.
	 * @param string $id         Field id.
	 * @param string $label      Label.
	 * @param string $value      Value.
	 * @param string $class      CSS classes.
	 * @param string $type       Input type.
	 * @param array  $attributes HTML attributes.
	 * @param bool   $required   Whether the field is required.
	 */
	protected static function render_child_text_input( $name, $id, $label, $value, $class = '', $type = 'text', array $attributes = array(), $required = false ) {
		if ( $required ) {
			$attributes['required']      = 'required';
			$attributes['aria-required'] = 'true';
		}

		$attrs = '';
		foreach ( $attributes as $attr_key => $attr_value ) {
			$attrs .= ' ' . sanitize_key( $attr_key ) . '="' . esc_attr( (string) $attr_value ) . '"';
		}

		echo '<p class="form-field form-field-wide">';
		echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $label );
		if ( $required ) {
			echo ' <abbr class="required" title="' . esc_attr__( 'required', 'woocommerce' ) . '">*</abbr>';
		}
		echo '</label>';
		echo '<input type="' . esc_attr( $type ) . '" class="' . esc_attr( $class ) . '" name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '" value="' . esc_attr( $value ) . '"' . $attrs . ' />';
		echo '</p>';
	}

	/**
	 * Render one catalog select inside a child block.
	 *
	 * @param string $index_token Field index token.
	 * @param string $type        Catalog type.
	 * @param int    $selected    Selected id.
	 * @param bool   $is_power    Whether field is a power.
	 * @param string $division    Parent division.
	 */
	protected static function render_child_select_field( $index_token, $type, $selected, $is_power, $division ) {
		$name = $is_power ? '_optic_child_configs[' . $index_token . '][powers][' . $type . ']' : '_optic_child_configs[' . $index_token . '][catalog][' . $type . ']';
		$id   = 'wc_optic_child_' . $index_token . '_' . $type;

		$wrapper = 'form-field form-field-wide wc-optic-child-field';
		if ( $is_power ) {
			$wrapper .= ' wc-optic-child-power';
		}

		echo '<p class="' . esc_attr( $wrapper ) . '" data-optic-type="' . esc_attr( $type ) . '">';
		echo '<label for="' . esc_attr( $id ) . '">' . esc_html( self::type_label( $type ) ) . ' <abbr class="required" title="' . esc_attr__( 'required', 'woocommerce' ) . '">*</abbr></label>';
		echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '" class="wc-enhanced-select wc-optic-select2 wc-optic-child-select" data-optic-type="' . esc_attr( $type ) . '" data-is-power="' . esc_attr( $is_power ? '1' : '0' ) . '" data-placeholder="' . esc_attr__( '- Select -', 'wc-optic' ) . '" required aria-required="true">';
		echo '<option value=""></option>';
		foreach ( WC_Optic_Catalog::get_terms( $type ) as $row ) {
			echo '<option value="' . esc_attr( (string) $row->id ) . '" ' . selected( (int) $selected, (int) $row->id, false ) . '>' . esc_html( WC_Optic_Catalog::get_display_name( $row ) ) . '</option>';
		}
		echo '</select>';
		echo '</p>';
	}
}
