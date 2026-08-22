<?php
/**
 * Cart item meta, validation, session, order persistence.
 *
 * @package WC_Optic_Product
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Optic_Cart
 */
class WC_Optic_Cart {

	const CART_KEY = '_wc_optic';

	/**
	 * Parsed add-to-cart payload cache for the current request.
	 *
	 * @var array<int, array|WP_Error>
	 */
	protected static $parse_cache = array();

	/**
	 * Hooks.
	 */
	public static function hooks() {
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'add_cart_item_data' ), 10, 4 );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add_to_cart' ), 10, 6 );
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'sync_cart_item_after_add' ), 20, 6 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( __CLASS__, 'sync_cart_item_from_session' ), 20, 3 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hide_admin_order_itemmeta' ) );
		add_filter( 'woocommerce_admin_html_order_item_class', array( __CLASS__, 'add_admin_order_item_class' ), 20, 3 );
		add_action( 'woocommerce_after_order_itemmeta', array( __CLASS__, 'render_admin_order_item_summary' ), 20, 3 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_order_assets' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'admin_order_body_class' ) );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'get_item_data' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_price', array( __CLASS__, 'cart_item_price' ), 10, 3 );
		add_filter( 'woocommerce_cart_item_subtotal', array( __CLASS__, 'cart_item_subtotal' ), 10, 3 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'order_line_item' ), 10, 4 );
		add_filter( 'woocommerce_cart_item_quantity', array( __CLASS__, 'cart_item_quantity' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_cart_scripts' ) );
		add_filter( 'woocommerce_update_cart_action_cart_updated', array( __CLASS__, 'sync_cart_optic_quantities' ), 5, 1 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'validate_cart_stock' ) );
		add_filter( 'woocommerce_cart_item_required_stock_is_not_enough', array( __CLASS__, 'bypass_parent_stock_check' ), 10, 3 );
		add_action( 'woocommerce_reduce_order_stock', array( __CLASS__, 'reduce_order_internal_stock' ) );
		add_action( 'woocommerce_restore_order_stock', array( __CLASS__, 'restore_order_internal_stock' ) );
	}

	/**
	 * Build optic payload from request (public for pricing/qty helpers).
	 *
	 * @param int $product_id Product id.
	 * @return array|WP_Error
	 */
	public static function parse_request_for_product( $product_id ) {
		return self::parse_request( $product_id );
	}

	/**
	 * Build optic payload from request.
	 *
	 * @param int $product_id Product id.
	 * @return array|WP_Error
	 */
	protected static function parse_request( $product_id ) {
		$product_id = absint( $product_id );
		if ( isset( self::$parse_cache[ $product_id ] ) ) {
			return self::$parse_cache[ $product_id ];
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || 'optic_product' !== $product->get_type() ) {
			self::$parse_cache[ $product_id ] = new WP_Error( 'wc_optic', __( 'Invalid optic product.', 'wc-optic' ) );
			return self::$parse_cache[ $product_id ];
		}

		if ( ! empty( $_POST['wc_optic_nonce'] ) && ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wc_optic_nonce'] ) ), 'wc_optic_add_to_cart' ) ) {
			self::$parse_cache[ $product_id ] = new WP_Error( 'wc_optic', __( 'Security check failed. Please reload the page.', 'wc-optic' ) );
			return self::$parse_cache[ $product_id ];
		}

		$division = $product->get_meta( '_optic_division', true );
		if ( ! $division ) {
			self::$parse_cache[ $product_id ] = new WP_Error( 'wc_optic', __( 'This product is not configured for prescriptions.', 'wc-optic' ) );
			return self::$parse_cache[ $product_id ];
		}

		if ( ! WC_Optic_Frontend::has_child_options( $product ) ) {
			self::$parse_cache[ $product_id ] = new WP_Error( 'wc_optic', __( 'This product has no internal products configured yet.', 'wc-optic' ) );
			return self::$parse_cache[ $product_id ];
		}

		$divisions  = WC_Optic_Plugin::get_divisions();
		$power_mode = 'power';
		if ( WC_Optic_SKU::division_supports_no_power_mode( $division ) ) {
			$posted_mode = isset( $_POST['wc_optic_power_mode'] ) ? sanitize_key( wp_unslash( $_POST['wc_optic_power_mode'] ) ) : 'no_power';
			$power_mode  = 'power' === $posted_mode ? 'power' : 'no_power';
		}

		if ( 'no_power' === $power_mode ) {
			$no_power_config = WC_Optic_SKU::find_no_power_child( $product );
			if ( ! $no_power_config ) {
				self::$parse_cache[ $product_id ] = new WP_Error( 'wc_optic', __( 'This product is not available without power.', 'wc-optic' ) );
				return self::$parse_cache[ $product_id ];
			}
			$left = WC_Optic_SKU::build_eye_payload_from_child( $no_power_config, $division );
			if ( is_wp_error( $left ) ) {
				self::$parse_cache[ $product_id ] = $left;
				return self::$parse_cache[ $product_id ];
			}
			$right    = $left;
			$different = false;
			$same      = true;
			$qty_mode  = 'single';
		} else {
			$different = empty( $_POST['wc_optic_different_power'] ) ? false : true;
			$same      = ! $different;
			$qty_mode  = $same ? 'single' : 'dual';
			$left      = self::parse_eye_child( $product, 'left', $division );
			$right     = $same ? $left : self::parse_eye_child( $product, 'right', $division );

			if ( is_wp_error( $left ) ) {
				self::$parse_cache[ $product_id ] = $left;
				return self::$parse_cache[ $product_id ];
			}
			if ( is_wp_error( $right ) ) {
				self::$parse_cache[ $product_id ] = $right;
				return self::$parse_cache[ $product_id ];
			}

			if ( ! $same && self::eyes_have_same_selection( $left, $right ) ) {
				$same  = true;
				$right = $left;
			}
		}

		$qty       = isset( $_POST['wc_optic_qty'] ) ? max( 1, (int) $_POST['wc_optic_qty'] ) : 1;
		$qty_left  = isset( $_POST['wc_optic_qty_left'] ) ? max( 0, (int) $_POST['wc_optic_qty_left'] ) : 0;
		$qty_right = isset( $_POST['wc_optic_qty_right'] ) ? max( 0, (int) $_POST['wc_optic_qty_right'] ) : 0;

		if ( 'dual' === $qty_mode ) {
			if ( $qty_left < 1 || $qty_right < 1 ) {
				self::$parse_cache[ $product_id ] = new WP_Error( 'wc_optic', __( 'Please enter a valid quantity for each eye.', 'wc-optic' ) );
				return self::$parse_cache[ $product_id ];
			}
			$line_qty = $qty_left + $qty_right;
		} else {
			$line_qty  = $qty;
			$qty_left  = 0;
			$qty_right = 0;
		}

		$payload = array(
			'division'     => $division,
			'division_lbl' => isset( $divisions[ $division ] ) ? $divisions[ $division ]['label'] : $division,
			'power_mode'   => $power_mode,
			'same_power'   => $same,
			'qty_mode'     => $qty_mode,
			'qty_single'   => $qty,
			'qty_left'     => $qty_left,
			'qty_right'    => $qty_right,
			'line_qty'     => $line_qty,
			'left'         => $left,
			'right'        => $right,
			'catalog'      => array(),
			'product_id'   => $product_id,
			'unit_price'   => 0,
			'line_total'   => 0,
		);

		$payload = self::normalize_payload_same_eyes( $payload );

		$payload['line_total'] = WC_Optic_Pricing::calculate_payload_total( $payload );
		$payload['unit_price'] = WC_Optic_Pricing::get_payload_effective_unit_price( $payload );

		$stock_validation = self::validate_payload_stock( $product, $payload );
		if ( is_wp_error( $stock_validation ) ) {
			self::$parse_cache[ $product_id ] = $stock_validation;
			return self::$parse_cache[ $product_id ];
		}

		self::$parse_cache[ $product_id ] = $payload;

		return self::$parse_cache[ $product_id ];
	}

	/**
	 * Parse one selected internal child for one eye from POST.
	 *
	 * @param WC_Product $product          Product.
	 * @param string     $eye              left|right.
	 * @param string     $division         Product division.
	 * @return array|WP_Error
	 */
	protected static function parse_eye_child( WC_Product $product, $eye, $division ) {
		$eye          = 'right' === $eye ? 'right' : 'left';
		$power_types  = WC_Optic_Plugin::get_powers_for_division( $division );
		$power_ids    = array();
		$powers_ready = true;

		foreach ( $power_types as $power ) {
			$key = 'wc_optic_' . $eye . '_' . $power;
			if ( ! isset( $_POST[ $key ] ) || '' === trim( (string) wp_unslash( $_POST[ $key ] ) ) ) {
				$powers_ready = false;
				break;
			}
			$power_ids[ $power ] = absint( wp_unslash( $_POST[ $key ] ) );
		}

		if ( ! $powers_ready ) {
			return new WP_Error( 'wc_optic', __( 'Please select all prescription values before adding to cart.', 'wc-optic' ) );
		}

		$config = WC_Optic_SKU::find_child_by_powers( $product, $power_ids, true );
		if ( ! $config ) {
			return new WP_Error( 'wc_optic', __( 'This prescription combination is not available.', 'wc-optic' ) );
		}

		$powers = array();
		foreach ( WC_Optic_Plugin::get_powers_for_division( $division ) as $power ) {
			$id  = isset( $config['powers'][ $power ] ) ? (int) $config['powers'][ $power ] : 0;
			$row = $id ? WC_Optic_Catalog::get_valid_term( $id, $power ) : null;
			if ( ! $row ) {
				return new WP_Error( 'wc_optic', __( 'Selected internal product is incomplete.', 'wc-optic' ) );
			}
			$powers[ $power ] = array(
				'id'    => $id,
				'label' => WC_Optic_Catalog::get_display_name( $row ),
			);
		}

		return array(
			'child_id'    => (string) $config['id'],
			'label'       => (string) $config['label'],
			'display'     => WC_Optic_SKU::child_display_label( $config, $division ),
			'sku'         => (string) $config['sku'],
			'unit_price'  => WC_Optic_SKU::get_child_unit_price( $config ),
			'stock_qty'   => WC_Optic_SKU::get_child_stock_qty( $config ),
			'powers'      => $powers,
		);
	}

	/**
	 * Merge optic data into cart line.
	 *
	 * @param array $cart_item_data Cart item data.
	 * @param int   $product_id Product id.
	 * @param int   $variation_id Variation.
	 * @param int   $quantity Qty.
	 * @return array
	 */
	public static function add_cart_item_data( $cart_item_data, $product_id, $variation_id, $quantity ) {
		$product = wc_get_product( $product_id );
		if ( ! $product || 'optic_product' !== $product->get_type() ) {
			return $cart_item_data;
		}
		$parsed = self::parse_request( $product_id );
		if ( is_wp_error( $parsed ) ) {
			return $cart_item_data;
		}
		$cart_item_data[ self::CART_KEY ] = $parsed;
		return $cart_item_data;
	}

	/**
	 * Sync optic payload after WooCommerce merges or adds a cart line.
	 *
	 * @param string $cart_item_key Cart item key.
	 * @param int    $product_id    Product id.
	 * @param int    $quantity      Added quantity.
	 * @param int    $variation_id  Variation id.
	 * @param array  $variation     Variation data.
	 * @param array  $cart_item_data Original cart item data.
	 */
	public static function sync_cart_item_after_add( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
		if ( ! WC()->cart || ! isset( WC()->cart->cart_contents[ $cart_item_key ] ) ) {
			return;
		}

		$item = WC()->cart->cart_contents[ $cart_item_key ];
		if ( empty( $item['data'] ) || ! $item['data'] instanceof WC_Product || 'optic_product' !== $item['data']->get_type() ) {
			return;
		}

		$item     = self::sync_cart_item_payload_quantities( $item );
		$line_qty = max( 1, (int) ( $item[ self::CART_KEY ]['line_qty'] ?? 1 ) );
		if ( (int) $item['quantity'] !== $line_qty ) {
			$item['quantity'] = $line_qty;
		}
		WC()->cart->cart_contents[ $cart_item_key ] = $item;
	}

	/**
	 * Sync optic payload when cart items are restored from session.
	 *
	 * @param array  $session_data Cart item data with product object.
	 * @param array  $values       Raw session values.
	 * @param string $cart_item_key Cart item key.
	 * @return array
	 */
	public static function sync_cart_item_from_session( $session_data, $values, $cart_item_key ) {
		if ( empty( $session_data['data'] ) || ! $session_data['data'] instanceof WC_Product || 'optic_product' !== $session_data['data']->get_type() ) {
			return $session_data;
		}

		return self::sync_cart_item_payload_quantities( $session_data );
	}

	/**
	 * Validate before add to cart.
	 *
	 * @param bool $passed Passed.
	 * @param int  $product_id Product id.
	 * @param int  $quantity Quantity.
	 * @return bool
	 */
	public static function validate_add_to_cart( $passed, $product_id, $quantity = 1, $variation_id = 0, $variations = array(), $cart_item_data = array() ) {
		$product = wc_get_product( $product_id );
		if ( ! $product || 'optic_product' !== $product->get_type() ) {
			return $passed;
		}
		$parsed = self::parse_request( $product_id );
		if ( is_wp_error( $parsed ) ) {
			wc_add_notice( $parsed->get_error_message(), 'error' );
			return false;
		}
		if ( (int) $parsed['line_qty'] < 1 ) {
			wc_add_notice( __( 'Invalid quantity.', 'wc-optic' ), 'error' );
			return false;
		}
		return $passed;
	}

	/**
	 * Hide optic meta rows from the default admin order-item meta table.
	 *
	 * @param array $hidden Hidden meta keys.
	 * @return array
	 */
	public static function hide_admin_order_itemmeta( $hidden ) {
		$hidden[] = '_wc_optic_payload';
		$hidden[] = __( 'Internal SKUs', 'wc-optic' );
		$hidden[] = __( 'Eye pricing', 'wc-optic' );
		$hidden[] = __( 'Eye quantities', 'wc-optic' );

		return array_values( array_unique( $hidden ) );
	}

	/**
	 * Add an optic-specific CSS class to admin order rows.
	 *
	 * @param string        $class Existing classes.
	 * @param WC_Order_Item $item  Order item.
	 * @param WC_Order      $order Order.
	 * @return string
	 */
	public static function add_admin_order_item_class( $class, $item, $order ) {
		if ( $item instanceof WC_Order_Item_Product && self::order_item_is_optic( $item ) ) {
			$class = trim( $class . ' wc-optic-order-item' );
		}

		return $class;
	}

	/**
	 * Render a styled optic summary block on the admin order page.
	 *
	 * @param int              $item_id Item id.
	 * @param WC_Order_Item    $item    Order item.
	 * @param WC_Product|false $product Product.
	 */
	public static function render_admin_order_item_summary( $item_id, $item, $product ) {
		$payload = self::get_order_item_optic_payload( $item );
		if ( ! $payload ) {
			return;
		}

		$payload  = self::normalize_payload_same_eyes( $payload );
		$order    = $item instanceof WC_Order_Item_Product ? $item->get_order() : null;
		$currency = $order instanceof WC_Order ? $order->get_currency() : '';
		$same     = self::payload_uses_single_eye_display( $payload );

		echo '<div class="wc-optic-order-summary' . ( $same ? ' wc-optic-order-summary--same-power' : '' ) . '">';
		echo '<div class="wc-optic-order-summary__eyes">';
		if ( $same ) {
			self::render_admin_order_eye_column( 'left', $payload, $currency, true );
		} else {
			self::render_admin_order_eye_column( 'left', $payload, $currency, false );
			self::render_admin_order_eye_column( 'right', $payload, $currency, false );
		}
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render one eye column in the admin order optic summary (OS left, OD right).
	 *
	 * @param string $eye_key  left|right.
	 * @param array  $payload  Optic payload.
	 * @param string $currency Order currency.
	 */
	protected static function render_admin_order_eye_column( $eye_key, array $payload, $currency = '', $combined = false ) {
		$data = self::get_eye_admin_summary( $eye_key, $payload, $combined );
		$col  = 'left' === $eye_key ? 'os' : 'od';
		if ( $combined ) {
			$title = __( 'Both eyes — same power', 'wc-optic' );
		} else {
			$title = 'left' === $eye_key ? __( 'OS — left eye', 'wc-optic' ) : __( 'OD — right eye', 'wc-optic' );
		}

		echo '<div class="wc-optic-order-summary__eye wc-optic-order-summary__eye--' . esc_attr( $col ) . '">';
		echo '<div class="wc-optic-order-summary__eye-title">' . esc_html( $title ) . '</div>';

		echo '<div class="wc-optic-order-summary__eye-meta">';
		if ( '' !== $data['display'] ) {
			self::render_admin_order_meta_row( __( 'Internal product', 'wc-optic' ), $data['display'] );
		}
		if ( '' !== $data['sku'] ) {
			self::render_admin_order_meta_row( __( 'Internal SKU', 'wc-optic' ), $data['sku'] );
		}
		if ( $data['unit_price'] > 0 ) {
			self::render_admin_order_meta_row(
				__( 'Unit price', 'wc-optic' ),
				self::format_plain_price( $data['unit_price'], $currency )
			);
		}
		self::render_admin_order_meta_row( __( 'Quantity', 'wc-optic' ), (string) $data['qty'] );
		echo '</div>';

		if ( '' !== $data['sku'] ) {
			echo '<div class="wc-optic-order-summary__eye-qr">';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built via WC_Optic_QR.
			echo WC_Optic_QR::render_admin_block( $data['sku'], '', 148 );
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * One label/value row inside an eye column.
	 *
	 * @param string $label Row label.
	 * @param string $value Row value.
	 */
	protected static function render_admin_order_meta_row( $label, $value ) {
		echo '<div class="wc-optic-order-summary__meta-row">';
		echo '<span class="wc-optic-order-summary__meta-label">' . esc_html( $label ) . '</span>';
		echo '<span class="wc-optic-order-summary__meta-value">' . esc_html( $value ) . '</span>';
		echo '</div>';
	}

	/**
	 * Per-eye data for admin order summary columns.
	 *
	 * @param string $eye_key left|right.
	 * @param array  $payload Optic payload.
	 * @return array{display:string, sku:string, unit_price:float, qty:int, line_total:float}
	 */
	protected static function get_eye_admin_summary( $eye_key, array $payload, $combined = false ) {
		$eye = isset( $payload[ $eye_key ] ) && is_array( $payload[ $eye_key ] ) ? $payload[ $eye_key ] : array();

		if ( $combined || self::payload_uses_single_eye_display( $payload ) ) {
			$qty = self::get_combined_line_quantity( $payload );
		} elseif ( 'dual' === self::get_effective_qty_mode( $payload ) ) {
			$qty = 'left' === $eye_key
				? max( 1, (int) ( $payload['qty_left'] ?? 1 ) )
				: max( 1, (int) ( $payload['qty_right'] ?? 1 ) );
		} else {
			$qty = self::get_combined_line_quantity( $payload );
		}

		$unit_price = isset( $eye['unit_price'] ) ? (float) wc_format_decimal( $eye['unit_price'] ) : 0.0;

		return array(
			'display'    => isset( $eye['display'] ) ? trim( (string) $eye['display'] ) : '',
			'sku'        => isset( $eye['sku'] ) ? trim( (string) $eye['sku'] ) : '',
			'unit_price' => $unit_price,
			'qty'        => $qty,
			'line_total' => $unit_price > 0 ? (float) wc_format_decimal( $unit_price * $qty ) : 0.0,
		);
	}

	/**
	 * Whether the order line should show one combined eye block (same power / same SKU).
	 *
	 * @param array $payload Optic payload.
	 * @return bool
	 */
	protected static function payload_uses_single_eye_display( array $payload ) {
		if ( ! empty( $payload['same_power'] ) ) {
			return true;
		}

		if ( 'single' === ( $payload['qty_mode'] ?? '' ) ) {
			return true;
		}

		$left  = isset( $payload['left'] ) && is_array( $payload['left'] ) ? $payload['left'] : array();
		$right = isset( $payload['right'] ) && is_array( $payload['right'] ) ? $payload['right'] : array();

		return self::eyes_have_same_selection( $left, $right );
	}

	/**
	 * Shared line quantity when both eyes use the same internal product.
	 *
	 * @param array $payload Optic payload.
	 * @return int
	 */
	protected static function get_combined_line_quantity( array $payload ) {
		if ( isset( $payload['qty_single'] ) && (int) $payload['qty_single'] > 0 ) {
			return max( 1, (int) $payload['qty_single'] );
		}

		if ( isset( $payload['line_qty'] ) && (int) $payload['line_qty'] > 0 ) {
			return max( 1, (int) $payload['line_qty'] );
		}

		$qty_left  = max( 0, (int) ( $payload['qty_left'] ?? 0 ) );
		$qty_right = max( 0, (int) ( $payload['qty_right'] ?? 0 ) );

		if ( $qty_left > 0 && $qty_right > 0 && $qty_left === $qty_right ) {
			return $qty_left;
		}

		return max( 1, $qty_left, $qty_right );
	}

	/**
	 * Whether an order line item is an optic product line.
	 *
	 * @param WC_Order_Item_Product $item Order item.
	 * @return bool
	 */
	protected static function order_item_is_optic( WC_Order_Item_Product $item ) {
		if ( self::get_order_item_optic_payload( $item ) ) {
			return true;
		}

		$product = $item->get_product();

		return $product && 'optic_product' === $product->get_type();
	}

	/**
	 * Whether every product line on the order is an optic line.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	protected static function order_has_only_optic_products( WC_Order $order ) {
		$items = $order->get_items( 'line_item' );
		if ( empty( $items ) ) {
			return false;
		}

		foreach ( $items as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product || ! self::order_item_is_optic( $item ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Resolve the order being edited on admin order screens.
	 *
	 * @return WC_Order|null
	 */
	protected static function get_admin_order_being_edited() {
		global $theorder;

		if ( $theorder instanceof WC_Order ) {
			return $theorder;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['post'] ) ) {
			$order_id = absint( wp_unslash( $_GET['post'] ) );
			if ( $order_id && 'shop_order' === get_post_type( $order_id ) ) {
				return wc_get_order( $order_id );
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['id'], $_GET['page'] ) && 'wc-orders' === sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return wc_get_order( absint( wp_unslash( $_GET['id'] ) ) );
		}

		return null;
	}

	/**
	 * Add body class on optic-only admin orders (column layout CSS).
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public static function admin_order_body_class( $classes ) {
		if ( ! self::is_admin_order_screen() ) {
			return $classes;
		}

		$order = self::get_admin_order_being_edited();
		if ( ! $order instanceof WC_Order || ! self::order_has_only_optic_products( $order ) ) {
			return $classes;
		}

		return $classes . ' wc-optic-admin-order-only';
	}

	/**
	 * Enqueue admin order styles/scripts for optic lines.
	 */
	public static function enqueue_admin_order_assets() {
		if ( ! self::is_admin_order_screen() ) {
			return;
		}

		wp_enqueue_style(
			'wc-optic-admin-order',
			WC_OPTIC_PLUGIN_URL . 'assets/css/admin-order.css',
			array( 'woocommerce_admin_styles' ),
			WC_OPTIC_VERSION
		);

		wp_add_inline_script(
			'jquery',
			'jQuery(function($){
				$("table.woocommerce_order_items").each(function(){
					var $table = $(this);
					var $rows = $table.find("tbody#order_line_items tr.item");
					if ($rows.length && $rows.length === $rows.filter(".wc-optic-order-item").length) {
						$table.addClass("wc-optic-order-only");
						$("body").addClass("wc-optic-admin-order-only");
					}
					$table.find(".wc-optic-order-item table.display_meta").each(function(){
						if (!$(this).find("tr").length) {
							$(this).hide();
						}
					});
				});
			});',
			'after'
		);
	}

	/**
	 * Cart / checkout line item meta display.
	 *
	 * @param array $item_data Item data.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public static function get_item_data( $item_data, $cart_item ) {
		if ( empty( $cart_item[ self::CART_KEY ] ) || ! is_array( $cart_item[ self::CART_KEY ] ) ) {
			return $item_data;
		}

		$html = self::render_line_summary_html( $cart_item[ self::CART_KEY ] );
		if ( '' === $html ) {
			return $item_data;
		}

		$item_data[] = array(
			'key'     => 'optic-line',
			'display' => $html,
		);

		return $item_data;
	}

	/**
	 * Cart/checkout HTML summary (two eye columns, no SKU / QR).
	 *
	 * @param array $payload Optic line payload.
	 * @return string Safe HTML.
	 */
	public static function render_line_summary_html( array $payload ) {
		$payload = self::normalize_payload_same_eyes( $payload );
		$same    = self::payload_uses_single_eye_display( $payload );

		ob_start();
		echo '<div class="wc-optic-line-summary' . ( $same ? ' wc-optic-line-summary--same-power' : '' ) . '">';
		echo '<div class="wc-optic-line-summary__eyes">';
		if ( $same ) {
			self::render_line_eye_column( 'left', $payload, true );
		} else {
			self::render_line_eye_column( 'left', $payload, false );
			self::render_line_eye_column( 'right', $payload, false );
		}
		echo '</div>';
		echo '</div>';

		return (string) ob_get_clean();
	}

	/**
	 * One eye column for cart/checkout summary.
	 *
	 * @param string $eye_key  left|right.
	 * @param array  $payload  Optic payload.
	 * @param bool   $combined Same power on both eyes.
	 */
	protected static function render_line_eye_column( $eye_key, array $payload, $combined = false ) {
		$eye  = isset( $payload[ $eye_key ] ) && is_array( $payload[ $eye_key ] ) ? $payload[ $eye_key ] : array();
		$col  = 'left' === $eye_key ? 'os' : 'od';
		$data = self::get_eye_admin_summary( $eye_key, $payload, $combined );

		if ( $combined ) {
			$title = __( 'Both eyes — same power', 'wc-optic' );
		} else {
			$title = 'left' === $eye_key ? __( 'OS — left eye', 'wc-optic' ) : __( 'OD — right eye', 'wc-optic' );
		}

		$powers_html = self::format_eye_powers_html( $eye );

		echo '<div class="wc-optic-line-summary__eye wc-optic-line-summary__eye--' . esc_attr( $col ) . '">';
		echo '<div class="wc-optic-line-summary__eye-title">' . esc_html( $title ) . '</div>';
		echo '<div class="wc-optic-line-summary__eye-meta">';

		if ( '' !== $powers_html ) {
			echo '<div class="wc-optic-line-summary__meta-row wc-optic-line-summary__meta-row--powers">';
			echo '<span class="wc-optic-line-summary__meta-label">' . esc_html__( 'Powers', 'wc-optic' ) . '</span>';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built in format_eye_powers_html().
			echo $powers_html;
			echo '</div>';
		} elseif ( 'no_power' === ( $payload['power_mode'] ?? '' ) ) {
			echo '<div class="wc-optic-line-summary__meta-row">';
			echo '<span class="wc-optic-line-summary__meta-label">' . esc_html__( 'Power type', 'wc-optic' ) . '</span>';
			echo '<span class="wc-optic-line-summary__meta-value">' . esc_html__( 'No power', 'wc-optic' ) . '</span>';
			echo '</div>';
		}

		if ( $data['unit_price'] > 0 ) {
			echo '<div class="wc-optic-line-summary__meta-row">';
			echo '<span class="wc-optic-line-summary__meta-label">' . esc_html__( 'Unit price', 'wc-optic' ) . '</span>';
			echo '<span class="wc-optic-line-summary__meta-value">' . wp_kses_post( wc_price( $data['unit_price'] ) ) . '</span>';
			echo '</div>';
		}

		echo '<div class="wc-optic-line-summary__meta-row">';
		echo '<span class="wc-optic-line-summary__meta-label">' . esc_html__( 'Quantity', 'wc-optic' ) . '</span>';
		echo '<span class="wc-optic-line-summary__meta-value">' . esc_html( (string) $data['qty'] ) . '</span>';
		echo '</div>';

		if ( ! empty( $data['line_total'] ) && $data['line_total'] > 0 ) {
			echo '<div class="wc-optic-line-summary__meta-row wc-optic-line-summary__meta-row--total">';
			echo '<span class="wc-optic-line-summary__meta-label">' . esc_html__( 'Total', 'wc-optic' ) . '</span>';
			echo '<span class="wc-optic-line-summary__meta-value">' . wp_kses_post( wc_price( $data['line_total'] ) ) . '</span>';
			echo '</div>';
		}

		echo '</div>';
		echo '</div>';
	}

	/**
	 * Format selected powers for one eye as HTML.
	 *
	 * @param array $eye Eye payload (left|right).
	 * @return string HTML or empty.
	 */
	protected static function format_eye_powers_html( array $eye ) {
		if ( empty( $eye['powers'] ) || ! is_array( $eye['powers'] ) ) {
			return '';
		}

		$items = array();
		foreach ( $eye['powers'] as $power => $data ) {
			$label = is_array( $data ) && isset( $data['label'] ) ? trim( (string) $data['label'] ) : '';
			if ( '' === $label ) {
				continue;
			}
			$type_label = WC_Optic_Catalog::get_type_label( (string) $power );
			$items[]    = '<li class="wc-optic-line-summary__power-item wc-optic-ltr" dir="ltr">'
				. '<span class="wc-optic-line-summary__power-key">' . esc_html( $type_label ) . '</span>'
				. '<span class="wc-optic-line-summary__power-val">' . esc_html( $label ) . '</span>'
				. '</li>';
		}

		if ( empty( $items ) ) {
			return '';
		}

		return '<ul class="wc-optic-line-summary__powers">' . implode( '', $items ) . '</ul>';
	}

	/**
	 * Replace misleading averaged unit price in cart for optic items.
	 *
	 * @param string $price_html Cart item price HTML.
	 * @param array  $cart_item  Cart item.
	 * @param string $cart_item_key Cart item key.
	 * @return string
	 */
	public static function cart_item_price( $price_html, $cart_item, $cart_item_key ) {
		if ( empty( $cart_item[ self::CART_KEY ] ) || ! is_array( $cart_item[ self::CART_KEY ] ) ) {
			return $price_html;
		}

		$o           = $cart_item[ self::CART_KEY ];
		$qty_mode    = self::get_effective_qty_mode( $o );
		$left_price  = isset( $o['left']['unit_price'] ) ? (float) wc_format_decimal( $o['left']['unit_price'] ) : 0.0;
		$right_price = isset( $o['right']['unit_price'] ) ? (float) wc_format_decimal( $o['right']['unit_price'] ) : 0.0;

		if ( 'dual' !== $qty_mode ) {
			return $left_price > 0 ? wc_price( $left_price ) : $price_html;
		}

		$out = '<div class="wc-optic-cart-eye-prices">';
		$out .= '<span class="wc-optic-cart-eye-price"><span class="wc-optic-ltr" dir="ltr">' . esc_html__( 'OD', 'wc-optic' ) . '</span>: ' . wp_kses_post( wc_price( $right_price ) ) . '</span>';
		$out .= '<span class="wc-optic-cart-eye-price"><span class="wc-optic-ltr" dir="ltr">' . esc_html__( 'OS', 'wc-optic' ) . '</span>: ' . wp_kses_post( wc_price( $left_price ) ) . '</span>';
		$out .= '</div>';

		return $out;
	}

	/**
	 * Show the true optic line total in cart/checkout (Flatsome subtotal column, mini-cart, etc.).
	 *
	 * WooCommerce otherwise uses (line_total / line_qty) × quantity, which looks like an average when qty is out of sync.
	 *
	 * @param string $subtotal_html Formatted subtotal HTML.
	 * @param array  $cart_item     Cart item.
	 * @param string $cart_item_key Cart item key.
	 * @return string
	 */
	public static function cart_item_subtotal( $subtotal_html, $cart_item, $cart_item_key ) {
		if ( empty( $cart_item[ self::CART_KEY ] ) || ! is_array( $cart_item[ self::CART_KEY ] ) ) {
			return $subtotal_html;
		}

		$cart_item = self::sync_cart_item_payload_quantities( $cart_item );
		return self::format_cart_line_subtotal_html( $cart_item );
	}

	/**
	 * Format optic line subtotal with the same tax display rules as WooCommerce cart rows.
	 *
	 * @param array $cart_item Cart item (must include CART_KEY payload).
	 * @return string
	 */
	public static function format_cart_line_subtotal_html( array $cart_item ) {
		$payload    = $cart_item[ self::CART_KEY ];
		$line_total = WC_Optic_Pricing::calculate_payload_total( $payload );
		$product    = ( ! empty( $cart_item['data'] ) && $cart_item['data'] instanceof WC_Product ) ? $cart_item['data'] : null;
		$cart       = WC()->cart;

		if ( ! $product || ! $cart ) {
			return wc_price( $line_total );
		}

		if ( $product->is_taxable() ) {
			if ( $cart->display_prices_including_tax() ) {
				$row_price = wc_get_price_including_tax( $product, array( 'qty' => 1, 'price' => $line_total ) );
				$html      = wc_price( $row_price );
				if ( ! wc_prices_include_tax() && $cart->get_subtotal_tax() > 0 ) {
					$html .= ' <small class="tax_label">' . WC()->countries->inc_tax_or_vat() . '</small>';
				}
				return $html;
			}

			$row_price = wc_get_price_excluding_tax( $product, array( 'qty' => 1, 'price' => $line_total ) );
			$html      = wc_price( $row_price );
			if ( wc_prices_include_tax() && $cart->get_subtotal_tax() > 0 ) {
				$html .= ' <small class="tax_label">' . WC()->countries->ex_tax_or_vat() . '</small>';
			}
			return $html;
		}

		return wc_price( $line_total );
	}

	/**
	 * Persist to order item meta.
	 *
	 * @param WC_Order_Item_Product $item Item.
	 * @param string                $cart_item_key Key.
	 * @param array                 $values Values.
	 * @param WC_Order              $order Order.
	 */
	public static function order_line_item( $item, $cart_item_key, $values, $order ) {
		if ( empty( $values[ self::CART_KEY ] ) || ! is_array( $values[ self::CART_KEY ] ) ) {
			return;
		}
		$o = self::normalize_payload_same_eyes( $values[ self::CART_KEY ] );
		$item->add_meta_data( '_wc_optic_payload', wp_json_encode( $o ), true );
		$item->add_meta_data( __( 'Internal SKUs', 'wc-optic' ), self::format_internal_skus_plain( $o ), true );
		$item->add_meta_data( __( 'Eye pricing', 'wc-optic' ), self::format_eye_pricing_plain( $o, $order->get_currency() ), true );
		$item->add_meta_data( __( 'Eye quantities', 'wc-optic' ), self::format_eye_quantities_plain( $o ), true );
	}

	/**
	 * Plain text prescription for order emails.
	 *
	 * @param array $o Data.
	 * @return string
	 */
	protected static function format_prescription_plain( array $o ) {
		$left  = isset( $o['left']['powers'] ) && is_array( $o['left']['powers'] ) ? $o['left']['powers'] : array();
		$right = isset( $o['right']['powers'] ) && is_array( $o['right']['powers'] ) ? $o['right']['powers'] : array();
		$fmt   = function ( $eye_key, $powers ) {
			$bits = array();
			foreach ( $powers as $k => $v ) {
				$label = is_array( $v ) && isset( $v['label'] ) ? $v['label'] : $v;
				$bits[] = strtoupper( $k ) . ': ' . $label;
			}
			$eye_label = 'left' === $eye_key ? __( 'OS (left)', 'wc-optic' ) : __( 'OD (right)', 'wc-optic' );
			return $eye_label . ' — ' . implode( ', ', $bits );
		};
		// Right (OD) first per medical / RTL-friendly ordering.
		return $fmt( 'right', $right ) . ' | ' . $fmt( 'left', $left );
	}

	/**
	 * Plain text child selection summary.
	 *
	 * @param array $o Data.
	 * @return string
	 */
	protected static function format_child_selection_plain( array $o ) {
		$fmt = function ( $eye_key, $data ) {
			$eye_label = 'left' === $eye_key ? __( 'OS (left)', 'wc-optic' ) : __( 'OD (right)', 'wc-optic' );
			$label     = isset( $data['display'] ) ? (string) $data['display'] : '';
			$sku       = isset( $data['sku'] ) ? (string) $data['sku'] : '';
			return trim( $eye_label . ' - ' . $label . ( $sku ? ' [' . $sku . ']' : '' ) );
		};

		$left  = isset( $o['left'] ) && is_array( $o['left'] ) ? $o['left'] : array();
		$right = isset( $o['right'] ) && is_array( $o['right'] ) ? $o['right'] : array();

		return $fmt( 'right', $right ) . ' | ' . $fmt( 'left', $left );
	}

	/**
	 * Plain text internal SKU summary only.
	 *
	 * @param array $o Data.
	 * @return string
	 */
	protected static function format_internal_skus_plain( array $o ) {
		$left_sku  = isset( $o['left']['sku'] ) ? trim( (string) $o['left']['sku'] ) : '';
		$right_sku = isset( $o['right']['sku'] ) ? trim( (string) $o['right']['sku'] ) : '';
		$same      = ! empty( $o['same_power'] ) || ( $left_sku && $left_sku === $right_sku );

		if ( $same ) {
			return $left_sku ? $left_sku : $right_sku;
		}

		return trim( 'OD: ' . $right_sku . ' | OS: ' . $left_sku );
	}

	/**
	 * Plain text eye quantity summary.
	 *
	 * @param array $o Data.
	 * @return string
	 */
	protected static function format_eye_quantities_plain( array $o ) {
		$o = self::normalize_payload_same_eyes( $o );
		if ( self::payload_uses_single_eye_display( $o ) ) {
			return __( 'Both eyes', 'wc-optic' ) . ': ' . self::get_combined_line_quantity( $o );
		}

		$qty_mode = self::get_effective_qty_mode( $o );
		if ( 'dual' !== $qty_mode ) {
			return __( 'Both eyes', 'wc-optic' ) . ': ' . self::get_combined_line_quantity( $o );
		}

		$qty_right = isset( $o['qty_right'] ) ? max( 1, (int) $o['qty_right'] ) : 1;
		$qty_left  = isset( $o['qty_left'] ) ? max( 1, (int) $o['qty_left'] ) : 1;

		return 'OD: ' . $qty_right . ' | OS: ' . $qty_left;
	}

	/**
	 * Plain text eye pricing summary for order admin.
	 *
	 * @param array  $o        Data.
	 * @param string $currency Order currency.
	 * @return string
	 */
	protected static function format_eye_pricing_plain( array $o, $currency = '' ) {
		$o           = self::normalize_payload_same_eyes( $o );
		$left_price  = isset( $o['left']['unit_price'] ) ? (float) wc_format_decimal( $o['left']['unit_price'] ) : 0.0;
		$right_price = isset( $o['right']['unit_price'] ) ? (float) wc_format_decimal( $o['right']['unit_price'] ) : 0.0;

		if ( self::payload_uses_single_eye_display( $o ) ) {
			$qty = self::get_combined_line_quantity( $o );
			return __( 'Both eyes', 'wc-optic' ) . ': ' . self::format_plain_price( $left_price, $currency ) . ' x ' . $qty;
		}

		$qty_mode = self::get_effective_qty_mode( $o );
		if ( 'dual' !== $qty_mode ) {
			$qty = self::get_combined_line_quantity( $o );
			return __( 'Both eyes', 'wc-optic' ) . ': ' . self::format_plain_price( $left_price, $currency ) . ' x ' . $qty;
		}

		$qty_right = isset( $o['qty_right'] ) ? max( 1, (int) $o['qty_right'] ) : 1;
		$qty_left  = isset( $o['qty_left'] ) ? max( 1, (int) $o['qty_left'] ) : 1;

		return 'OD: ' . self::format_plain_price( $right_price, $currency ) . ' x ' . $qty_right . ' | OS: ' . self::format_plain_price( $left_price, $currency ) . ' x ' . $qty_left;
	}

	/**
	 * Build a plain text price string.
	 *
	 * @param float  $amount   Price amount.
	 * @param string $currency Currency code.
	 * @return string
	 */
	protected static function format_plain_price( $amount, $currency = '' ) {
		$args  = $currency ? array( 'currency' => $currency ) : array();
		$price = wp_strip_all_tags( html_entity_decode( wc_price( $amount, $args ) ) );
		return trim( preg_replace( '/\s+/u', ' ', $price ) );
	}

	/**
	 * Read optic payload from an order item.
	 *
	 * @param WC_Order_Item $item Order item.
	 * @return array<string, mixed>|null
	 */
	protected static function get_order_item_optic_payload( $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return null;
		}

		$payload_json = $item->get_meta( '_wc_optic_payload', true );
		if ( ! $payload_json ) {
			return null;
		}

		$payload = json_decode( (string) $payload_json, true );
		return is_array( $payload ) ? $payload : null;
	}

	/**
	 * Whether the current screen is an admin order editor.
	 *
	 * @return bool
	 */
	protected static function is_admin_order_screen() {
		if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();
		if ( ! $screen ) {
			return false;
		}

		return in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true );
	}

	/**
	 * Replace quantity input on cart for optic lines.
	 *
	 * @param string $html HTML.
	 * @param string $cart_item_key Key.
	 * @param array  $cart_item Item.
	 * @return string
	 */
	public static function cart_item_quantity( $html, $cart_item_key, $cart_item ) {
		if ( empty( $cart_item[ self::CART_KEY ] ) ) {
			return $html;
		}

		$cart_item = self::sync_cart_item_payload_quantities( $cart_item );
		$o         = $cart_item[ self::CART_KEY ];
		$qty_mode = self::get_effective_qty_mode( $o );
		$ql       = isset( $o['qty_left'] ) ? max( 1, (int) $o['qty_left'] ) : 1;
		$qr       = isset( $o['qty_right'] ) ? max( 1, (int) $o['qty_right'] ) : 1;
		$qs       = isset( $o['qty_single'] ) ? max( 1, (int) $o['qty_single'] ) : 1;
		$total    = 'dual' === $qty_mode ? $ql + $qr : $qs;
		$product  = ! empty( $cart_item['data'] ) && $cart_item['data'] instanceof WC_Product ? $cart_item['data'] : null;
		$left_cfg = $product && ! empty( $o['left']['child_id'] ) ? WC_Optic_SKU::find_child_config( $product, (string) $o['left']['child_id'], false ) : null;
		$right_cfg = $product && ! empty( $o['right']['child_id'] ) ? WC_Optic_SKU::find_child_config( $product, (string) $o['right']['child_id'], false ) : null;
		$single_max = is_array( $left_cfg ) ? self::get_remaining_child_stock( $product, $left_cfg, $cart_item_key ) : null;
		$left_max   = is_array( $left_cfg ) ? self::get_remaining_child_stock( $product, $left_cfg, $cart_item_key ) : null;
		$right_max  = is_array( $right_cfg ) ? self::get_remaining_child_stock( $product, $right_cfg, $cart_item_key ) : null;
		$single_max_attr = null === $single_max ? '' : ' max="' . esc_attr( (string) $single_max ) . '"';
		$left_max_attr   = null === $left_max ? '' : ' max="' . esc_attr( (string) $left_max ) . '"';
		$right_max_attr  = null === $right_max ? '' : ' max="' . esc_attr( (string) $right_max ) . '"';

		ob_start();
		echo '<div class="wc-optic-cart-qty wc-optic-cart-qty--' . esc_attr( $qty_mode ) . '" data-cart-key="' . esc_attr( $cart_item_key ) . '" data-qty-mode="' . esc_attr( $qty_mode ) . '">';
		echo '<input type="hidden" name="cart[' . esc_attr( $cart_item_key ) . '][qty]" value="' . esc_attr( (string) $total ) . '" class="wc-optic-cart-line-total" />';
		if ( 'dual' === $qty_mode ) {
			echo '<div class="wc-optic-cart-qty__field wc-optic-ltr" dir="ltr">';
			echo '<span class="wc-optic-cart-qty__label">' . esc_html__( 'OS', 'wc-optic' ) . '</span>';
			echo '<input type="number" min="1"' . $left_max_attr . ' name="wc_optic_cart[' . esc_attr( $cart_item_key ) . '][left]" class="wc-optic-cart-q-left input-text qty text" value="' . esc_attr( (string) $ql ) . '" />';
			echo '</div>';
			echo '<div class="wc-optic-cart-qty__field wc-optic-ltr" dir="ltr">';
			echo '<span class="wc-optic-cart-qty__label">' . esc_html__( 'OD', 'wc-optic' ) . '</span>';
			echo '<input type="number" min="1"' . $right_max_attr . ' name="wc_optic_cart[' . esc_attr( $cart_item_key ) . '][right]" class="wc-optic-cart-q-right input-text qty text" value="' . esc_attr( (string) $qr ) . '" />';
			echo '</div>';
		} else {
			echo '<div class="wc-optic-cart-qty__field wc-optic-ltr" dir="ltr">';
			echo '<span class="wc-optic-cart-qty__label">' . esc_html__( 'Qty', 'wc-optic' ) . '</span>';
			echo '<input type="number" min="1"' . $single_max_attr . ' name="wc_optic_cart[' . esc_attr( $cart_item_key ) . '][single]" class="wc-optic-cart-q-single input-text qty text" value="' . esc_attr( (string) $qs ) . '" />';
			echo '</div>';
		}
		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * After cart form processing, sync per-eye quantities from POST into cart item payload.
	 *
	 * @param bool $cart_updated Whether cart was updated.
	 * @return bool
	 */
	public static function sync_cart_optic_quantities( $cart_updated ) {
		if ( empty( $_POST['wc_optic_cart'] ) || ! is_array( $_POST['wc_optic_cart'] ) ) {
			return $cart_updated;
		}
		$optic_post = wp_unslash( $_POST['wc_optic_cart'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		foreach ( $optic_post as $cart_item_key => $eyes ) {
			$cart_item_key = sanitize_text_field( $cart_item_key );
			$item          = WC()->cart->get_cart_item( $cart_item_key );
			if ( ! $item || empty( $item[ self::CART_KEY ] ) || ! is_array( $item[ self::CART_KEY ] ) ) {
				continue;
			}
			if ( ! isset( WC()->cart->cart_contents[ $cart_item_key ] ) ) {
				continue;
			}

			if ( 'dual' !== self::get_effective_qty_mode( $item[ self::CART_KEY ] ) ) {
				$s = isset( $eyes['single'] ) ? max( 1, absint( $eyes['single'] ) ) : 1;
				WC()->cart->cart_contents[ $cart_item_key ][ self::CART_KEY ]['qty_mode']   = 'single';
				WC()->cart->cart_contents[ $cart_item_key ][ self::CART_KEY ]['same_power'] = true;
				WC()->cart->cart_contents[ $cart_item_key ][ self::CART_KEY ]['qty_single'] = $s;
				WC()->cart->cart_contents[ $cart_item_key ][ self::CART_KEY ]['line_qty']   = $s;
				$new_total = $s;
			} else {
				$l = isset( $eyes['left'] ) ? max( 1, absint( $eyes['left'] ) ) : 1;
				$r = isset( $eyes['right'] ) ? max( 1, absint( $eyes['right'] ) ) : 1;
				WC()->cart->cart_contents[ $cart_item_key ][ self::CART_KEY ]['qty_mode']   = 'dual';
				WC()->cart->cart_contents[ $cart_item_key ][ self::CART_KEY ]['same_power'] = false;
				WC()->cart->cart_contents[ $cart_item_key ][ self::CART_KEY ]['qty_left']   = $l;
				WC()->cart->cart_contents[ $cart_item_key ][ self::CART_KEY ]['qty_right']  = $r;
				WC()->cart->cart_contents[ $cart_item_key ][ self::CART_KEY ]['line_qty']   = $l + $r;
				$new_total = $l + $r;
			}

			if ( (int) WC()->cart->cart_contents[ $cart_item_key ]['quantity'] !== (int) $new_total ) {
				WC()->cart->set_quantity( $cart_item_key, $new_total, false );
			}
			$cart_updated = true;
		}
		return $cart_updated;
	}

	/**
	 * Skip WooCommerce parent-product stock math for optic lines (stock lives on child configs).
	 *
	 * @param bool       $not_enough Whether parent stock is insufficient.
	 * @param WC_Product $product    Product in cart.
	 * @param array      $values     Cart item.
	 * @return bool
	 */
	public static function bypass_parent_stock_check( $not_enough, $product, $values ) {
		if ( ! empty( $values[ self::CART_KEY ] ) && $product instanceof WC_Product && 'optic_product' === $product->get_type() ) {
			return false;
		}
		return $not_enough;
	}

	/**
	 * Validate optic stock across the whole cart.
	 */
	public static function validate_cart_stock() {
		if ( ! WC()->cart ) {
			return;
		}

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item[ self::CART_KEY ] ) || ! is_array( $cart_item[ self::CART_KEY ] ) ) {
				continue;
			}

			$product = ! empty( $cart_item['data'] ) && $cart_item['data'] instanceof WC_Product ? $cart_item['data'] : wc_get_product( $cart_item['product_id'] ?? 0 );
			if ( ! $product || 'optic_product' !== $product->get_type() ) {
				continue;
			}

			$validation = self::validate_payload_stock( $product, $cart_item[ self::CART_KEY ], $cart_item['key'] ?? '' );
			if ( is_wp_error( $validation ) ) {
				wc_add_notice( $validation->get_error_message(), 'error' );
			}
		}
	}

	/**
	 * Reduce internal child stock quantities when Woo reduces order stock.
	 *
	 * @param int|WC_Order $order Order object or id.
	 */
	public static function reduce_order_internal_stock( $order ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! $order || 'yes' === $order->get_meta( '_wc_optic_internal_stock_reduced', true ) ) {
			return;
		}

		self::apply_order_internal_stock_delta( $order, -1 );
		$order->update_meta_data( '_wc_optic_internal_stock_reduced', 'yes' );
		$order->save();
	}

	/**
	 * Restore internal child stock quantities when Woo restores order stock.
	 *
	 * @param int|WC_Order $order Order object or id.
	 */
	public static function restore_order_internal_stock( $order ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! $order || 'yes' !== $order->get_meta( '_wc_optic_internal_stock_reduced', true ) ) {
			return;
		}

		self::apply_order_internal_stock_delta( $order, 1 );
		$order->delete_meta_data( '_wc_optic_internal_stock_reduced' );
		$order->save();
	}

	/**
	 * Apply stock deltas from an order payload onto internal child configs.
	 *
	 * @param WC_Order $order     Order.
	 * @param int      $direction -1 to reduce, +1 to restore.
	 */
	protected static function apply_order_internal_stock_delta( WC_Order $order, $direction ) {
		$updates = array();

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$payload_json = $item->get_meta( '_wc_optic_payload', true );
			if ( ! $payload_json ) {
				continue;
			}

			$payload = json_decode( (string) $payload_json, true );
			if ( ! is_array( $payload ) ) {
				continue;
			}

			$product_id = $item->get_product_id();
			if ( $product_id < 1 ) {
				continue;
			}

			foreach ( self::build_child_quantity_map( $payload ) as $child_id => $entry ) {
				$qty = isset( $entry['qty'] ) ? absint( $entry['qty'] ) : 0;
				if ( $qty < 1 ) {
					continue;
				}

				if ( ! isset( $updates[ $product_id ] ) ) {
					$updates[ $product_id ] = array();
				}
				if ( ! isset( $updates[ $product_id ][ $child_id ] ) ) {
					$updates[ $product_id ][ $child_id ] = 0;
				}

				$updates[ $product_id ][ $child_id ] += $qty * (int) $direction;
			}
		}

		foreach ( $updates as $product_id => $child_deltas ) {
			$product = wc_get_product( $product_id );
			if ( ! $product || 'optic_product' !== $product->get_type() ) {
				continue;
			}

			$configs = WC_Optic_SKU::get_child_configs( $product );
			foreach ( $configs as &$config ) {
				$child_id = isset( $config['id'] ) ? (string) $config['id'] : '';
				if ( '' === $child_id || ! isset( $child_deltas[ $child_id ] ) ) {
					continue;
				}

				if ( null === WC_Optic_SKU::get_child_stock_qty( $config ) ) {
					continue;
				}

				WC_Optic_SKU::apply_child_stock_delta( $config, (int) $child_deltas[ $child_id ] );
			}
			unset( $config );

			WC_Optic_SKU::persist_child_data( $product, $configs );
			$product->save();
		}
	}

	/**
	 * Build requested quantities grouped by internal child id.
	 *
	 * @param array $payload Optic payload.
	 * @return array<string, array<string, mixed>>
	 */
	protected static function build_child_quantity_map( array $payload ) {
		$map      = array();
		$qty_mode = self::get_effective_qty_mode( $payload );

		if ( 'dual' === $qty_mode ) {
			self::add_child_quantity_to_map( $map, $payload['left'] ?? array(), isset( $payload['qty_left'] ) ? (int) $payload['qty_left'] : 0 );
			self::add_child_quantity_to_map( $map, $payload['right'] ?? array(), isset( $payload['qty_right'] ) ? (int) $payload['qty_right'] : 0 );
			return $map;
		}

		self::add_child_quantity_to_map( $map, $payload['left'] ?? array(), isset( $payload['qty_single'] ) ? (int) $payload['qty_single'] : 0 );
		return $map;
	}

	/**
	 * Add one eye quantity into the grouped stock request map.
	 *
	 * @param array $map   Mutable quantity map.
	 * @param array $eye   Eye payload.
	 * @param int   $qty   Requested quantity.
	 */
	protected static function add_child_quantity_to_map( array &$map, array $eye, $qty ) {
		$child_id = isset( $eye['child_id'] ) ? (string) $eye['child_id'] : '';
		$qty      = max( 0, (int) $qty );
		if ( '' === $child_id || $qty < 1 ) {
			return;
		}

		if ( ! isset( $map[ $child_id ] ) ) {
			$map[ $child_id ] = array(
				'qty'   => 0,
				'label' => isset( $eye['display'] ) ? (string) $eye['display'] : '',
				'sku'   => isset( $eye['sku'] ) ? (string) $eye['sku'] : '',
			);
		}

		$map[ $child_id ]['qty'] += $qty;
	}

	/**
	 * Validate a parsed optic payload against current child stock.
	 *
	 * @param WC_Product $product Product.
	 * @param array      $payload Parsed payload.
	 * @return true|WP_Error
	 */
	protected static function validate_payload_stock( WC_Product $product, array $payload, $exclude_cart_item_key = '' ) {
		foreach ( self::build_child_quantity_map( $payload ) as $child_id => $entry ) {
			$config = WC_Optic_SKU::find_child_config( $product, $child_id, false );
			if ( ! $config ) {
				return new WP_Error( 'wc_optic', __( 'Selected internal product is no longer available.', 'wc-optic' ) );
			}

			$requested = isset( $entry['qty'] ) ? max( 1, (int) $entry['qty'] ) : 1;
			$remaining = self::get_remaining_child_stock( $product, $config, $exclude_cart_item_key );
			if ( null === $remaining || $requested <= $remaining ) {
				continue;
			}

			$label     = self::get_stock_error_label( $config, $entry );
			return new WP_Error(
				'wc_optic_stock',
				sprintf(
					/* translators: 1: internal product label, 2: remaining stock quantity */
					__( '%1$s only has %2$d unit(s) left in stock.', 'wc-optic' ),
					$label,
					max( 0, (int) $remaining )
				)
			);
		}

		return true;
	}

	/**
	 * Get the quantity of one internal child already reserved in the current cart.
	 *
	 * @param WC_Product $product               Product.
	 * @param string     $child_id              Child id.
	 * @param string     $exclude_cart_item_key Optional cart item key to exclude.
	 * @return int
	 */
	public static function get_reserved_child_quantity( WC_Product $product, $child_id, $exclude_cart_item_key = '' ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return 0;
		}

		$child_id = (string) $child_id;
		if ( '' === $child_id ) {
			return 0;
		}

		$reserved = 0;
		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( $exclude_cart_item_key && $cart_item_key === $exclude_cart_item_key ) {
				continue;
			}
			if ( empty( $cart_item['product_id'] ) || (int) $cart_item['product_id'] !== (int) $product->get_id() ) {
				continue;
			}
			if ( empty( $cart_item[ self::CART_KEY ] ) || ! is_array( $cart_item[ self::CART_KEY ] ) ) {
				continue;
			}

			$cart_item = self::sync_cart_item_payload_quantities( $cart_item );
			foreach ( self::build_child_quantity_map( $cart_item[ self::CART_KEY ] ) as $reserved_child_id => $entry ) {
				if ( $reserved_child_id !== $child_id ) {
					continue;
				}
				$reserved += isset( $entry['qty'] ) ? max( 0, (int) $entry['qty'] ) : 0;
			}
		}

		return $reserved;
	}

	/**
	 * Get the remaining stock for one internal child after current cart reservations.
	 *
	 * @param WC_Product $product               Product.
	 * @param array      $config                Child config.
	 * @param string     $exclude_cart_item_key Optional cart item key to exclude.
	 * @return int|null
	 */
	public static function get_remaining_child_stock( WC_Product $product, array $config, $exclude_cart_item_key = '' ) {
		$sellable_qty = WC_Optic_SKU::get_child_sellable_qty( $config );
		if ( null === $sellable_qty ) {
			return null;
		}

		$child_id = isset( $config['id'] ) ? (string) $config['id'] : '';
		if ( '' === $child_id ) {
			return $sellable_qty;
		}

		return max( 0, $sellable_qty - self::get_reserved_child_quantity( $product, $child_id, $exclude_cart_item_key ) );
	}

	/**
	 * Get a human-readable label for stock errors.
	 *
	 * @param array $config Child config.
	 * @param array $entry  Requested quantity entry.
	 * @return string
	 */
	protected static function get_stock_error_label( array $config, array $entry ) {
		$label = '';
		if ( ! empty( $entry['label'] ) ) {
			$label = (string) $entry['label'];
		} elseif ( ! empty( $config['label'] ) ) {
			$label = (string) $config['label'];
		}

		$sku = ! empty( $entry['sku'] ) ? (string) $entry['sku'] : (string) ( $config['sku'] ?? '' );
		if ( $sku ) {
			return trim( $label ? $label . ' [' . $sku . ']' : $sku );
		}

		return $label ? $label : __( 'Selected internal product', 'wc-optic' );
	}

	/**
	 * Derive the effective quantity mode from the payload.
	 *
	 * This keeps cart behavior correct even for legacy/stale payloads already in session.
	 *
	 * @param array $payload Optic payload.
	 * @return string
	 */
	protected static function get_effective_qty_mode( array $payload ) {
		$left  = isset( $payload['left'] ) && is_array( $payload['left'] ) ? $payload['left'] : array();
		$right = isset( $payload['right'] ) && is_array( $payload['right'] ) ? $payload['right'] : array();

		if ( self::eyes_have_same_selection( $left, $right ) ) {
			return 'single';
		}

		$left_child  = isset( $left['child_id'] ) ? (string) $left['child_id'] : '';
		$right_child = isset( $right['child_id'] ) ? (string) $right['child_id'] : '';
		if ( $left_child && $right_child && $left_child !== $right_child ) {
			return 'dual';
		}

		return ( isset( $payload['qty_mode'] ) && 'dual' === $payload['qty_mode'] ) ? 'dual' : 'single';
	}

	/**
	 * Whether both eyes resolved to the same internal product / prescription.
	 *
	 * @param array $left  Left eye payload.
	 * @param array $right Right eye payload.
	 * @return bool
	 */
	protected static function eyes_have_same_selection( array $left, array $right ) {
		$left_id  = isset( $left['child_id'] ) ? (string) $left['child_id'] : '';
		$right_id = isset( $right['child_id'] ) ? (string) $right['child_id'] : '';
		if ( $left_id && $right_id ) {
			return $left_id === $right_id;
		}

		$left_powers  = isset( $left['powers'] ) && is_array( $left['powers'] ) ? $left['powers'] : array();
		$right_powers = isset( $right['powers'] ) && is_array( $right['powers'] ) ? $right['powers'] : array();
		if ( empty( $left_powers ) || empty( $right_powers ) ) {
			return false;
		}

		$keys = array_unique( array_merge( array_keys( $left_powers ), array_keys( $right_powers ) ) );
		foreach ( $keys as $power ) {
			$lid = isset( $left_powers[ $power ]['id'] ) ? (int) $left_powers[ $power ]['id'] : 0;
			$rid = isset( $right_powers[ $power ]['id'] ) ? (int) $right_powers[ $power ]['id'] : 0;
			if ( $lid !== $rid ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Collapse dual-eye payload when both eyes share the same prescription.
	 *
	 * @param array $payload Optic payload.
	 * @return array
	 */
	protected static function normalize_payload_same_eyes( array $payload ) {
		$left  = isset( $payload['left'] ) && is_array( $payload['left'] ) ? $payload['left'] : array();
		$right = isset( $payload['right'] ) && is_array( $payload['right'] ) ? $payload['right'] : array();

		if ( ! self::eyes_have_same_selection( $left, $right ) ) {
			return $payload;
		}

		$was_dual  = ( isset( $payload['qty_mode'] ) && 'dual' === $payload['qty_mode'] );
		$qty_left  = max( 0, (int) ( $payload['qty_left'] ?? 0 ) );
		$qty_right = max( 0, (int) ( $payload['qty_right'] ?? 0 ) );

		$payload['same_power'] = true;
		$payload['qty_mode']   = 'single';
		$payload['right']      = $left;

		if ( $qty_left > 0 && $qty_right > 0 ) {
			// Same power: mirrored per-eye qty fields mean one shared line qty, not a sum.
			$payload['qty_single'] = ( $qty_left === $qty_right ) ? $qty_left : ( $qty_left + $qty_right );
			$payload['line_qty']   = $payload['qty_single'];
		} elseif ( $was_dual && (int) ( $payload['line_qty'] ?? 0 ) > 0 ) {
			$payload['qty_single'] = max( 1, (int) $payload['line_qty'] );
		} elseif ( empty( $payload['qty_single'] ) && ! empty( $payload['line_qty'] ) ) {
			$payload['qty_single'] = max( 1, (int) $payload['line_qty'] );
		}

		if ( empty( $payload['line_qty'] ) && ! empty( $payload['qty_single'] ) ) {
			$payload['line_qty'] = max( 1, (int) $payload['qty_single'] );
		}

		return $payload;
	}

	/**
	 * Keep optic payload quantities aligned with the actual WooCommerce cart line quantity.
	 *
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public static function sync_cart_item_payload_quantities( array $cart_item ) {
		if ( empty( $cart_item[ self::CART_KEY ] ) || ! is_array( $cart_item[ self::CART_KEY ] ) ) {
			return $cart_item;
		}

		$payload  = self::normalize_payload_same_eyes( $cart_item[ self::CART_KEY ] );
		$line_qty = isset( $cart_item['quantity'] ) ? max( 1, (int) $cart_item['quantity'] ) : ( isset( $payload['line_qty'] ) ? max( 1, (int) $payload['line_qty'] ) : 1 );
		$qty_mode = self::get_effective_qty_mode( $payload );

		if ( 'dual' === $qty_mode ) {
			$payload['line_qty'] = ( isset( $payload['qty_left'] ) ? max( 1, (int) $payload['qty_left'] ) : 1 ) + ( isset( $payload['qty_right'] ) ? max( 1, (int) $payload['qty_right'] ) : 1 );
		} else {
			$payload['same_power'] = true;
			$payload['qty_mode']   = 'single';
			$payload['qty_single'] = $line_qty;
			$payload['line_qty']   = $line_qty;
			if ( empty( $payload['right'] ) && ! empty( $payload['left'] ) ) {
				$payload['right'] = $payload['left'];
			}
		}

		$payload['line_total'] = WC_Optic_Pricing::calculate_payload_total( $payload );
		$payload['unit_price'] = WC_Optic_Pricing::get_payload_effective_unit_price( $payload );

		$cart_item[ self::CART_KEY ] = $payload;
		return $cart_item;
	}

	/**
	 * Enqueue cart JS.
	 */
	public static function enqueue_cart_scripts() {
		if ( ! is_cart() && ! is_checkout() ) {
			return;
		}

		if ( class_exists( 'WC_Optic_Flatsome' ) && WC_Optic_Flatsome::is_active() ) {
			return;
		}

		wp_enqueue_style(
			'wc-optic-frontend',
			WC_OPTIC_PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			WC_OPTIC_VERSION
		);
		wp_enqueue_script(
			'wc-optic-cart',
			WC_OPTIC_PLUGIN_URL . 'assets/js/cart.js',
			array( 'jquery' ),
			WC_OPTIC_VERSION,
			true
		);
	}
}
