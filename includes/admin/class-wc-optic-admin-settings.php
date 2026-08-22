<?php
/**
 * WooCommerce → Optic Settings.
 *
 * @package WC_Optic_Product
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Optic_Admin_Settings
 */
class WC_Optic_Admin_Settings {

	/**
	 * Hooks.
	 */
	public static function hooks() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Enqueue SelectWoo on settings page.
	 *
	 * @param string $hook Hook.
	 */
	public static function enqueue( $hook ) {
		if ( WC_Optic_Admin_Menu::SETTINGS_SCREEN !== $hook ) {
			return;
		}
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_script( 'selectWoo' );
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_script(
			'wc-optic-admin-settings',
			WC_OPTIC_PLUGIN_URL . 'assets/js/admin-settings.js',
			array( 'jquery' ),
			WC_OPTIC_VERSION,
			true
		);
		wp_localize_script(
			'wc-optic-admin-settings',
			'wcOpticAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wc_optic_admin' ),
				'i18n'    => array(
					'confirmDelete'        => __( 'Are you sure you want to delete this catalog entry? This cannot be undone.', 'wc-optic' ),
					'deleteFailed'         => __( 'Could not delete the entry.', 'wc-optic' ),
					'affectedNoticeTitle'  => __( 'These products still reference the deleted catalog value. Update their optic configuration (SKU components) where needed:', 'wc-optic' ),
					'affectedNone'         => __( 'No products were using this value.', 'wc-optic' ),
					'confirmDivisionHide' => __( 'Hide this division? It will no longer appear when creating or editing products, but existing products keep working. Click "Save divisions" to apply.', 'wc-optic' ),
					'restoreDivision'     => __( 'Restore', 'wc-optic' ),
					'hideDivisionLabel'   => __( 'Hide division', 'wc-optic' ),
					'divisionPowerRequired' => __( 'Select at least one power for each division.', 'wc-optic' ),
				),
				'divisionPowers' => WC_Optic_Divisions::get_available_powers(),
				'divisionPowerLabels' => array_map(
					function ( $power ) {
						return WC_Optic_Divisions::get_power_label( $power );
					},
					array_combine( WC_Optic_Divisions::get_available_powers(), WC_Optic_Divisions::get_available_powers() )
				),
			)
		);
		wp_enqueue_style(
			'wc-optic-admin',
			WC_OPTIC_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			WC_OPTIC_VERSION
		);
	}

	/**
	 * Render settings tabs and tables.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		self::handle_global_settings_save();
		self::handle_divisions_save();

		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'section'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'deletion_log' === $requested ) {
			$active = 'deletion_log';
		} elseif ( WC_Optic_Catalog::is_valid_type( $requested ) ) {
			$active = $requested;
		} else {
			$active = 'section';
		}

		if ( 'deletion_log' !== $active ) {
			self::handle_post_save( $active );
		}

		echo '<div class="wrap woocommerce" id="wc-optic-settings-root" data-active-tab="' . esc_attr( $active ) . '">';
		echo '<h1>' . esc_html__( 'Optic Settings', 'wc-optic' ) . '</h1>';
		self::render_global_settings_panel();
		self::render_divisions_panel();
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( WC_Optic_Catalog::TYPES as $type ) {
			$url   = admin_url( 'admin.php?page=wc-optic-settings&tab=' . $type );
			$class = $active === $type ? 'nav-tab nav-tab-active' : 'nav-tab';
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . esc_html( self::tab_label( $type ) ) . '</a>';
		}
		$log_url = admin_url( 'admin.php?page=wc-optic-settings&tab=deletion_log' );
		$log_cls = 'deletion_log' === $active ? 'nav-tab nav-tab-active' : 'nav-tab';
		echo '<a class="' . esc_attr( $log_cls ) . '" href="' . esc_url( $log_url ) . '">' . esc_html__( 'Deletion log', 'wc-optic' ) . '</a>';
		$import_tab = ( 'deletion_log' === $active || ! WC_Optic_Catalog::is_valid_type( $active ) ) ? 'section' : $active;
		$import_url = admin_url( 'admin.php?page=wc-optic-import&tab=' . rawurlencode( $import_tab ) );
		echo '<a class="nav-tab" href="' . esc_url( $import_url ) . '">' . esc_html__( 'Import', 'wc-optic' ) . '</a>';
		echo '</h2>';

		echo '<div id="wc-optic-inline-messages" class="wc-optic-inline-messages" aria-live="polite"></div>';

		if ( 'deletion_log' === $active ) {
			self::render_deletion_log_panel();
			echo '</div>';
			return;
		}

		echo '<form method="post" action="" class="wc-optic-settings-form">';
		wp_nonce_field( 'wc_optic_settings_save', 'wc_optic_settings_nonce' );
		echo '<input type="hidden" name="wc_optic_active_tab" value="' . esc_attr( $active ) . '" />';

		$rows = WC_Optic_Catalog::get_terms( $active );
		echo '<p class="description">' . esc_html__( 'Enter a display name and the SKU fragment used when building product SKUs. Both fields are required for each row you save.', 'wc-optic' ) . '</p>';
		echo '<table class="widefat striped wc-optic-settings-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Name', 'wc-optic' ) . '</th>';
		echo '<th>' . esc_html__( 'SKU fragment', 'wc-optic' ) . '</th>';
		echo '<th>' . esc_html__( 'Sort', 'wc-optic' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'wc-optic' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			self::render_row( $active, $row, (string) $row->id );
		}
		self::render_row( $active, null, 'new_' . wp_unique_id() );

		echo '</tbody></table>';

		echo '<p class="wc-optic-settings-toolbar">';
		echo '<button type="button" class="button" id="wc-optic-add-row">' . esc_html__( 'Add new', 'wc-optic' ) . '</button> ';
		// echo '<span class="description">' . esc_html__( 'Add as many rows as you need, fill them in, then save once with the button below.', 'wc-optic' ) . '</span>';
		echo '</p>';

		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save changes', 'wc-optic' ) . '</button></p>';
		echo '</form>';

		echo '</div>';
	}

	/**
	 * Render global optic settings shared by all products.
	 */
	protected static function render_global_settings_panel() {
		echo '<form method="post" action="" class="wc-optic-global-settings">';
		wp_nonce_field( 'wc_optic_global_settings_save', 'wc_optic_global_settings_nonce' );
		echo '<div class="notice inline wc-optic-global-settings-box">';
		echo '<p><strong>' . esc_html__( 'Global storefront settings', 'wc-optic' ) . '</strong></p>';
		echo '<p class="description">' . esc_html__( 'These settings affect all optic products in the shop.', 'wc-optic' ) . '</p>';
		$backorder_enabled = WC_Optic_SKU::is_backorder_enabled();
		$backorder_qty     = WC_Optic_SKU::get_global_backorder_qty();
		$panel_class       = 'wc-optic-backorder-panel';
		if ( $backorder_enabled ) {
			$panel_class .= ' wc-optic-backorder-panel--enabled';
		}

		echo '<div class="wc-optic-inventory-panels-grid">';

		echo '<div class="' . esc_attr( $panel_class ) . '" id="wc-optic-global-backorder-panel">';
		echo '<div class="wc-optic-backorder-panel__header">';
		echo '<span class="dashicons dashicons-backup wc-optic-backorder-panel__icon" aria-hidden="true"></span>';
		echo '<div class="wc-optic-backorder-panel__titles">';
		echo '<h3 class="wc-optic-backorder-panel__title">' . esc_html__( 'Backorder', 'wc-optic' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Allow customers to purchase beyond physical stock up to a configurable allowance.', 'wc-optic' ) . '</p>';
		echo '</div>';
		echo '</div>';

		echo '<div class="wc-optic-backorder-panel__toggle-row">';
		echo '<label class="wc-optic-backorder-toggle" for="wc_optic_global_backorder_enabled">';
		echo '<input type="checkbox" name="wc_optic_global_backorder_enabled" id="wc_optic_global_backorder_enabled" class="wc-optic-backorder-toggle__input" value="1" ' . checked( $backorder_enabled, true, false ) . ' />';
		echo '<span class="wc-optic-backorder-toggle__switch" aria-hidden="true"></span>';
		echo '<span class="wc-optic-backorder-toggle__text">' . esc_html__( 'Enable backorder', 'wc-optic' ) . '</span>';
		echo '</label>';
		echo '</div>';

		echo '<div class="wc-optic-backorder-panel__details wc-optic-global-backorder-qty-wrap' . ( $backorder_enabled ? '' : ' wc-optic-is-hidden' ) . '">';
		echo '<label class="wc-optic-backorder-panel__qty-label" for="wc_optic_global_backorder_qty">' . esc_html__( 'Extra units per internal product', 'wc-optic' ) . '</label>';
		echo '<div class="wc-optic-backorder-panel__qty-row">';
		echo '<input type="number" name="wc_optic_global_backorder_qty" id="wc_optic_global_backorder_qty" class="wc-optic-backorder-input" min="0" step="1" value="' . esc_attr( (string) $backorder_qty ) . '" />';
		echo '<span class="wc-optic-backorder-panel__qty-suffix">' . esc_html__( 'units', 'wc-optic' ) . '</span>';
		echo '</div>';
		echo '<p class="wc-optic-backorder-panel__example description">';
		echo esc_html(
			sprintf(
				/* translators: 1: stock example, 2: backorder example, 3: total example */
				__( 'Example: stock %1$d + backorder %2$d = %3$d units sellable.', 'wc-optic' ),
				5,
				max( 1, $backorder_qty ),
				5 + max( 1, $backorder_qty )
			)
		);
		echo '</p>';
		echo '</div>';
		echo '</div>';

		$alert_enabled = WC_Optic_Stock::is_alert_enabled();
		$alert_qty     = WC_Optic_Stock::get_alert_qty();
		$alert_panel   = 'wc-optic-backorder-panel wc-optic-stock-alert-panel';
		if ( $alert_enabled ) {
			$alert_panel .= ' wc-optic-backorder-panel--enabled';
		}

		echo '<div class="' . esc_attr( $alert_panel ) . '" id="wc-optic-global-stock-alert-panel">';
		echo '<div class="wc-optic-backorder-panel__header">';
		echo '<span class="dashicons dashicons-warning wc-optic-backorder-panel__icon" aria-hidden="true"></span>';
		echo '<div class="wc-optic-backorder-panel__titles">';
		echo '<h3 class="wc-optic-backorder-panel__title">' . esc_html__( 'Stock alerts', 'wc-optic' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Internal products at or below this physical stock level appear in Stock → Stock alerts. Override per internal product with Custom on the product edit screen.', 'wc-optic' ) . '</p>';
		echo '</div>';
		echo '</div>';

		echo '<div class="wc-optic-backorder-panel__toggle-row">';
		echo '<label class="wc-optic-backorder-toggle" for="wc_optic_global_stock_alert_enabled">';
		echo '<input type="checkbox" name="wc_optic_global_stock_alert_enabled" id="wc_optic_global_stock_alert_enabled" class="wc-optic-backorder-toggle__input" value="1" ' . checked( $alert_enabled, true, false ) . ' />';
		echo '<span class="wc-optic-backorder-toggle__switch" aria-hidden="true"></span>';
		echo '<span class="wc-optic-backorder-toggle__text">' . esc_html__( 'Enable stock alerts', 'wc-optic' ) . '</span>';
		echo '</label>';
		echo '</div>';

		echo '<div class="wc-optic-backorder-panel__details wc-optic-global-stock-alert-qty-wrap' . ( $alert_enabled ? '' : ' wc-optic-is-hidden' ) . '">';
		echo '<label class="wc-optic-backorder-panel__qty-label" for="wc_optic_global_stock_alert_qty">' . esc_html__( 'Alert threshold per internal product', 'wc-optic' ) . '</label>';
		echo '<div class="wc-optic-backorder-panel__qty-row">';
		echo '<input type="number" name="wc_optic_global_stock_alert_qty" id="wc_optic_global_stock_alert_qty" class="wc-optic-backorder-input" min="0" step="1" value="' . esc_attr( (string) $alert_qty ) . '" />';
		echo '<span class="wc-optic-backorder-panel__qty-suffix">' . esc_html__( 'units or less', 'wc-optic' ) . '</span>';
		echo '</div>';
		echo '<p class="wc-optic-backorder-panel__example description">';
		echo esc_html(
			sprintf(
				/* translators: 1: stock example, 2: alert threshold example */
				__( 'Example: stock %1$d with threshold %2$d → alert shown.', 'wc-optic' ),
				3,
				max( 1, $alert_qty )
			)
		);
		echo '</p>';
		echo '</div>';
		echo '</div>';

		echo '</div>';

		echo '<p><button type="submit" class="button button-secondary">' . esc_html__( 'Save global settings', 'wc-optic' ) . '</button></p>';
		echo '</div>';
		echo '</form>';
	}

	/**
	 * Render configurable optical divisions panel.
	 */
	protected static function render_divisions_panel() {
		$divisions = WC_Optic_Divisions::get_stored_raw();
		$visible   = array();
		$hidden    = array();

		foreach ( $divisions as $slug => $def ) {
			if ( ! empty( $def['hidden'] ) ) {
				$hidden[ $slug ] = $def;
			} else {
				$visible[ $slug ] = $def;
			}
		}

		echo '<form method="post" action="" class="wc-optic-divisions-settings">';
		wp_nonce_field( 'wc_optic_divisions_save', 'wc_optic_divisions_nonce' );
		echo '<div class="notice inline wc-optic-divisions-settings-box">';
		echo '<p><strong>' . esc_html__( 'Optical divisions', 'wc-optic' ) . '</strong></p>';
		echo '<p class="description">' . esc_html__( 'Each division defines which prescription powers (SPH, CYL, AXIS, ADD) apply to optic products assigned to it. Hidden divisions stay available for existing products but are removed from product selectors.', 'wc-optic' ) . '</p>';

		echo '<table class="widefat striped wc-optic-divisions-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Division name', 'wc-optic' ) . '</th>';
		echo '<th>' . esc_html__( 'Associated powers', 'wc-optic' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'wc-optic' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody id="wc-optic-divisions-visible">';

		foreach ( $visible as $slug => $def ) {
			self::render_division_row( $slug, $def, (string) $slug, false );
		}
		self::render_division_row( '', array( 'label' => '', 'powers' => array(), 'hidden' => false ), 'new_' . wp_unique_id(), false );

		echo '</tbody>';

		if ( ! empty( $hidden ) ) {
			echo '<tbody id="wc-optic-divisions-hidden-separator"><tr class="wc-optic-divisions-hidden-heading"><td colspan="3"><strong>' . esc_html__( 'Hidden divisions', 'wc-optic' ) . '</strong></td></tr></tbody>';
			echo '<tbody id="wc-optic-divisions-hidden">';
			foreach ( $hidden as $slug => $def ) {
				self::render_division_row( $slug, $def, (string) $slug, true );
			}
			echo '</tbody>';
		} else {
			echo '<tbody id="wc-optic-divisions-hidden-separator" class="wc-optic-is-empty"><tr class="wc-optic-divisions-hidden-heading"><td colspan="3"><strong>' . esc_html__( 'Hidden divisions', 'wc-optic' ) . '</strong></td></tr></tbody>';
			echo '<tbody id="wc-optic-divisions-hidden"></tbody>';
		}

		echo '</table>';

		echo '<p class="wc-optic-divisions-toolbar">';
		echo '<button type="button" class="button" id="wc-optic-add-division">' . esc_html__( 'Add division', 'wc-optic' ) . '</button>';
		echo '</p>';

		echo '<p><button type="submit" class="button button-secondary">' . esc_html__( 'Save divisions', 'wc-optic' ) . '</button></p>';
		echo '</div>';
		echo '</form>';
	}

	/**
	 * One division settings row.
	 *
	 * @param string                                          $slug       Division slug (empty for new).
	 * @param array{label:string, powers:string[], hidden?:bool} $def        Division data.
	 * @param string                                          $suffix     Form array key suffix.
	 * @param bool                                            $is_hidden  Whether row is hidden.
	 */
	protected static function render_division_row( $slug, array $def, $suffix, $is_hidden = false ) {
		$pf     = 'wc_optic_division[' . $suffix . ']';
		$label  = isset( $def['label'] ) ? (string) $def['label'] : '';
		$powers = isset( $def['powers'] ) && is_array( $def['powers'] ) ? $def['powers'] : array();
		$slug   = sanitize_key( (string) $slug );
		$row_class = 'wc-optic-division-row';
		if ( $is_hidden ) {
			$row_class .= ' wc-optic-division-row--hidden';
		}

		echo '<tr class="' . esc_attr( $row_class ) . '">';
		echo '<td>';
		echo '<input type="text" name="' . esc_attr( $pf ) . '[label]" value="' . esc_attr( $label ) . '" class="regular-text wc-optic-division-label" autocomplete="off" />';
		if ( '' !== $slug ) {
			echo '<input type="hidden" name="' . esc_attr( $pf ) . '[slug]" value="' . esc_attr( $slug ) . '" />';
		}
		echo '<input type="hidden" class="wc-optic-division-hidden-flag" name="' . esc_attr( $pf ) . '[hidden]" value="' . esc_attr( $is_hidden ? '1' : '0' ) . '" />';
		echo '</td>';
		echo '<td class="wc-optic-division-powers">';
		foreach ( WC_Optic_Divisions::get_available_powers() as $power ) {
			$checked = in_array( $power, $powers, true );
			$id      = 'wc-optic-power-' . $suffix . '-' . $power;
			echo '<label for="' . esc_attr( $id ) . '" class="wc-optic-division-power-label">';
			echo '<input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $pf ) . '[powers][]" value="' . esc_attr( $power ) . '" ' . checked( $checked, true, false ) . ' /> ';
			echo esc_html( WC_Optic_Divisions::get_power_label( $power ) );
			echo '</label> ';
		}
		echo '</td>';
		echo '<td class="wc-optic-division-actions">';
		if ( '' !== $slug ) {
			if ( $is_hidden ) {
				echo '<button type="button" class="button wc-optic-restore-division">' . esc_html__( 'Restore', 'wc-optic' ) . '</button>';
			} else {
				$hide_label = sprintf(
					/* translators: %s: division name */
					__( 'Hide division %s', 'wc-optic' ),
					$label
				);
				echo '<button type="button" class="button-link-delete wc-optic-hide-division" aria-label="' . esc_attr( $hide_label ) . '">';
				echo '<span class="dashicons dashicons-hidden" aria-hidden="true"></span>';
				echo '</button>';
			}
		}
		echo '</td>';
		echo '</tr>';
	}

	/**
	 * Save optical divisions from settings form.
	 */
	protected static function handle_divisions_save() {
		if ( empty( $_POST['wc_optic_divisions_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wc_optic_divisions_nonce'] ) ), 'wc_optic_divisions_save' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( empty( $_POST['wc_optic_division'] ) || ! is_array( $_POST['wc_optic_division'] ) ) {
			return;
		}

		$parsed = WC_Optic_Divisions::parse_form_rows( wp_unslash( $_POST['wc_optic_division'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$parsed['divisions'] = WC_Optic_Divisions::preserve_missing_as_hidden( $parsed['divisions'] );

		if ( WC_Optic_Divisions::count_visible( $parsed['divisions'] ) < 1 ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'At least one visible division with a name and at least one power is required.', 'wc-optic' ) . '</p></div>';
				}
			);
			return;
		}

		$newly_hidden = WC_Optic_Divisions::find_newly_hidden( $parsed['divisions'] );

		WC_Optic_Divisions::save( $parsed['divisions'] );

		$user_id = get_current_user_id();
		foreach ( $newly_hidden as $slug => $data ) {
			WC_Optic_Deletion_Log::record_division(
				$slug,
				$data['label'],
				$user_id,
				$data['affected']
			);
		}

		add_action(
			'admin_notices',
			function () use ( $parsed, $newly_hidden ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Optical divisions saved.', 'wc-optic' ) . '</p></div>';
				if ( ! empty( $newly_hidden ) ) {
					$log_url       = admin_url( 'admin.php?page=wc-optic-settings&tab=deletion_log' );
					$with_products = 0;
					foreach ( $newly_hidden as $data ) {
						if ( ! empty( $data['affected'] ) ) {
							++$with_products;
						}
					}
					echo '<div class="notice notice-info is-dismissible"><p>';
					echo esc_html(
						sprintf(
							/* translators: %d: number of hidden divisions */
							_n(
								'%d division was hidden.',
								'%d divisions were hidden.',
								count( $newly_hidden ),
								'wc-optic'
							),
							count( $newly_hidden )
						)
					);
					if ( $with_products > 0 ) {
						echo ' ';
						echo esc_html(
							sprintf(
								/* translators: %d: number of divisions with affected products */
								_n(
									'%d still has products assigned — see the Deletion log for details.',
									'%d still have products assigned — see the Deletion log for details.',
									$with_products,
									'wc-optic'
								),
								$with_products
							)
						);
					}
					echo ' <a href="' . esc_url( $log_url ) . '">' . esc_html__( 'View deletion log', 'wc-optic' ) . '</a>';
					echo '</p></div>';
				}
				if ( $parsed['skipped_incomplete'] > 0 ) {
					echo '<div class="notice notice-warning is-dismissible"><p>';
					echo esc_html(
						sprintf(
							/* translators: %d: number of incomplete rows */
							_n(
								'%d division was not saved because a name and at least one power are required.',
								'%d divisions were not saved because a name and at least one power are required.',
								$parsed['skipped_incomplete'],
								'wc-optic'
							),
							$parsed['skipped_incomplete']
						)
					);
					echo '</p></div>';
				}
				if ( $parsed['skipped_duplicate'] > 0 ) {
					echo '<div class="notice notice-warning is-dismissible"><p>';
					echo esc_html(
						sprintf(
							/* translators: %d: number of rows skipped */
							_n(
								'%d division was not saved because another entry with the same name already exists.',
								'%d divisions were not saved because other entries with the same name already exist.',
								$parsed['skipped_duplicate'],
								'wc-optic'
							),
							$parsed['skipped_duplicate']
						)
					);
					echo '</p></div>';
				}
			}
		);
	}

	/**
	 * Deletion audit log (read-only).
	 */
	protected static function render_deletion_log_panel() {
		echo '<p class="description">' . esc_html__( 'Each entry stores the user, date, deleted catalog value or hidden division, and products that still referenced it.', 'wc-optic' ) . '</p>';

		$entries = WC_Optic_Deletion_Log::get_entries( 200 );
		if ( empty( $entries ) ) {
			echo '<p>' . esc_html__( 'No deletions have been recorded yet.', 'wc-optic' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped wc-optic-deletion-log-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Date', 'wc-optic' ) . '</th>';
		echo '<th>' . esc_html__( 'User', 'wc-optic' ) . '</th>';
		echo '<th>' . esc_html__( 'List', 'wc-optic' ) . '</th>';
		echo '<th>' . esc_html__( 'Deleted entry', 'wc-optic' ) . '</th>';
		echo '<th>' . esc_html__( 'Reference', 'wc-optic' ) . '</th>';
		echo '<th>' . esc_html__( 'Affected products', 'wc-optic' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $entries as $entry ) {
			$user         = $entry->deleted_by ? get_userdata( (int) $entry->deleted_by ) : false;
			$user_display = $user ? $user->display_name : '—';
			$date_display = mysql2date(
				get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
				$entry->deleted_at
			);

			$products = json_decode( $entry->affected_products, true );
			if ( ! is_array( $products ) ) {
				$products = array();
			}

			$is_division = in_array( (string) $entry->term_type, array( 'division', 'division_hidden' ), true );

			echo '<tr>';
			echo '<td>' . esc_html( $date_display ) . '</td>';
			echo '<td>' . esc_html( $user_display );
			if ( $user && $user->user_login ) {
				echo '<br /><span class="description">' . esc_html( $user->user_login ) . '</span>';
			}
			echo '</td>';
			echo '<td>' . esc_html( self::tab_label( $entry->term_type ) ) . '</td>';
			echo '<td><strong>' . esc_html( $entry->term_name ) . '</strong>';
			if ( $is_division && ! empty( $entry->term_slug ) ) {
				echo '<br /><span class="description">' . esc_html( $entry->term_slug ) . '</span>';
			}
			if ( 'division_hidden' === (string) $entry->term_type ) {
				echo '<br /><span class="description">' . esc_html__( 'Hidden division', 'wc-optic' ) . '</span>';
			}
			echo '</td>';
			echo '<td>';
			if ( $is_division ) {
				echo '<span class="description">' . esc_html__( 'Division slug', 'wc-optic' ) . '</span><br />';
				echo esc_html( (string) $entry->term_slug );
			} else {
				echo esc_html( (string) (int) $entry->catalog_term_id );
			}
			echo '</td>';
			echo '<td>';
			if ( empty( $products ) ) {
				echo '<em>' . esc_html__( 'None', 'wc-optic' ) . '</em>';
			} else {
				echo '<ul class="wc-optic-deletion-log-products">';
				foreach ( $products as $p ) {
					$pid  = isset( $p['id'] ) ? (int) $p['id'] : 0;
					$pname = isset( $p['name'] ) ? $p['name'] : '';
					$post  = $pid ? get_post( $pid ) : null;
					echo '<li>';
					if ( $post && get_edit_post_link( $pid ) ) {
						echo '<a href="' . esc_url( get_edit_post_link( $pid, 'raw' ) ) . '">' . esc_html( $pname ) . '</a>';
						echo ' <span class="description">#' . esc_html( (string) $pid ) . '</span>';
					} else {
						echo esc_html( $pname );
						if ( $pid ) {
							echo ' <span class="description">#' . esc_html( (string) $pid ) . ' (' . esc_html__( 'removed or no access', 'wc-optic' ) . ')</span>';
						}
					}
					echo '</li>';
				}
				echo '</ul>';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Tab label.
	 *
	 * @param string $type Type.
	 * @return string
	 */
	protected static function tab_label( $type ) {
		if ( 'division_hidden' === $type || 'division' === $type ) {
			return __( 'Divisions', 'wc-optic' );
		}
		return WC_Optic_Catalog::get_type_label( $type );
	}

	/**
	 * Table row.
	 *
	 * @param string     $type Term type.
	 * @param object|null $row DB row or null for empty row.
	 * @param string     $suffix Form array key suffix.
	 */
	protected static function render_row( $type, $row, $suffix ) {
		$id = $row ? (int) $row->id : 0;
		$pf = 'wc_optic_row[' . $suffix . ']';
		echo '<tr>';
		$frag_val = $row && isset( $row->sku_fragment ) ? (string) $row->sku_fragment : '';
		echo '<td><input type="text" name="' . esc_attr( $pf ) . '[name]" value="' . esc_attr( $row ? $row->name : '' ) . '" class="regular-text wc-optic-catalog-name" autocomplete="off" required /></td>';
		echo '<td><input type="text" name="' . esc_attr( $pf ) . '[sku_fragment]" value="' . esc_attr( $frag_val ) . '" class="regular-text wc-optic-catalog-fragment" autocomplete="off" required /></td>';
		echo '<td><input type="number" name="' . esc_attr( $pf ) . '[sort_order]" value="' . esc_attr( $row ? (int) $row->sort_order : 0 ) . '" class="small-text" /></td>';
		echo '<td>';
		if ( $id ) {
			$delete_label = sprintf(
				/* translators: %s: catalog entry name */
				__( 'Delete %s', 'wc-optic' ),
				$row->name
			);
			echo '<button type="button" class="button-link-delete wc-optic-delete-row" data-id="' . esc_attr( (string) $id ) . '" aria-label="' . esc_attr( $delete_label ) . '">';
			echo '<span class="dashicons dashicons-trash" aria-hidden="true"></span>';
			echo '</button> ';
			echo '<input type="hidden" name="' . esc_attr( $pf ) . '[id]" value="' . esc_attr( (string) $id ) . '" />';
		}
		echo '</td>';
		echo '</tr>';
	}

	/**
	 * Handle bulk save from form.
	 *
	 * @param string $active Active type tab.
	 */
	protected static function handle_post_save( $active ) {
		if ( empty( $_POST['wc_optic_settings_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wc_optic_settings_nonce'] ) ), 'wc_optic_settings_save' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( empty( $_POST['wc_optic_row'] ) || ! is_array( $_POST['wc_optic_row'] ) ) {
			return;
		}

		$skipped_duplicate  = 0;
		$skipped_incomplete = 0;

		foreach ( wp_unslash( $_POST['wc_optic_row'] ) as $key => $data ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( ! is_array( $data ) ) {
				continue;
			}
			$name = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
			$frag = isset( $data['sku_fragment'] ) ? WC_Optic_Catalog::sanitize_sku_fragment( $data['sku_fragment'] ) : '';
			$sort = isset( $data['sort_order'] ) ? (int) $data['sort_order'] : 0;
			$id   = isset( $data['id'] ) ? (int) $data['id'] : 0;

			if ( '' === trim( $name ) && '' === $frag ) {
				continue;
			}

			if ( '' === trim( $name ) || '' === $frag ) {
				++$skipped_incomplete;
				continue;
			}

			$slug = WC_Optic_Catalog::sanitize_slug( $name );

			if ( '' === $slug ) {
				$slug = 'item-' . strtolower( wp_unique_id() );
			}

			if ( $id ) {
				$other = WC_Optic_Catalog::get_by_slug( $active, $slug );
				if ( $other && (int) $other->id !== $id ) {
					$slug = WC_Optic_Catalog::sanitize_slug( $name ) . '-' . $id;
				}
				WC_Optic_Catalog::update(
					$id,
					array(
						'name'           => $name,
						'slug'           => $slug,
						'sku_fragment'   => $frag,
						'sort_order'     => $sort,
					)
				);
			} else {
				if ( WC_Optic_Catalog::get_by_slug( $active, $slug ) ) {
					++$skipped_duplicate;
					continue;
				}
				WC_Optic_Catalog::insert( $active, $name, $slug, $frag, $sort );
			}
		}

		add_action(
			'admin_notices',
			function () use ( $skipped_duplicate, $skipped_incomplete ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Optic catalog saved.', 'wc-optic' ) . '</p></div>';
				if ( $skipped_incomplete > 0 ) {
					echo '<div class="notice notice-warning is-dismissible"><p>';
					echo esc_html(
						sprintf(
							/* translators: %d: number of incomplete rows */
							_n(
								'%d row was not saved because both name and SKU fragment are required.',
								'%d rows were not saved because both name and SKU fragment are required.',
								$skipped_incomplete,
								'wc-optic'
							),
							$skipped_incomplete
						)
					);
					echo '</p></div>';
				}
				if ( $skipped_duplicate > 0 ) {
					echo '<div class="notice notice-warning is-dismissible"><p>';
					echo esc_html(
						sprintf(
							/* translators: %d: number of rows skipped */
							_n(
								'%d new row was not created because another entry with the same label already exists in this list.',
								'%d new rows were not created because other entries with the same label already exist in this list.',
								$skipped_duplicate,
								'wc-optic'
							),
							$skipped_duplicate
						)
					);
					echo '</p></div>';
				}
			}
		);
	}

	/**
	 * Save global settings shared by all optic products.
	 */
	protected static function handle_global_settings_save() {
		if ( empty( $_POST['wc_optic_global_settings_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wc_optic_global_settings_nonce'] ) ), 'wc_optic_global_settings_save' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$backorder_enabled = WC_Optic_SKU::set_backorder_enabled( isset( $_POST['wc_optic_global_backorder_enabled'] ) ? wp_unslash( $_POST['wc_optic_global_backorder_enabled'] ) : '' );
		$backorder_qty     = $backorder_enabled && isset( $_POST['wc_optic_global_backorder_qty'] )
			? WC_Optic_SKU::set_global_backorder_qty( wp_unslash( $_POST['wc_optic_global_backorder_qty'] ) )
			: WC_Optic_SKU::get_global_backorder_qty();
		$alert_enabled = WC_Optic_Stock::set_alert_enabled( isset( $_POST['wc_optic_global_stock_alert_enabled'] ) ? wp_unslash( $_POST['wc_optic_global_stock_alert_enabled'] ) : '' );
		$alert_qty     = $alert_enabled && isset( $_POST['wc_optic_global_stock_alert_qty'] )
			? WC_Optic_Stock::set_alert_qty( wp_unslash( $_POST['wc_optic_global_stock_alert_qty'] ) )
			: WC_Optic_Stock::get_alert_qty();

		add_action(
			'admin_notices',
			function () use ( $backorder_enabled, $backorder_qty, $alert_enabled, $alert_qty ) {
				echo '<div class="notice notice-success is-dismissible"><p>';
				echo esc_html__( 'Global optic settings saved.', 'wc-optic' );
				if ( $backorder_enabled ) {
					echo ' ';
					echo esc_html(
						sprintf(
							/* translators: %d: backorder quantity */
							__( 'Backorder enabled (%d extra unit(s) per internal product).', 'wc-optic' ),
							$backorder_qty
						)
					);
				} else {
					echo ' ';
					echo esc_html__( 'Backorder disabled.', 'wc-optic' );
				}
				echo ' ';
				if ( $alert_enabled ) {
					echo esc_html(
						sprintf(
							/* translators: %d: stock alert threshold */
							__( 'Stock alerts enabled (%d unit(s) or less).', 'wc-optic' ),
							$alert_qty
						)
					);
				} else {
					echo esc_html__( 'Stock alerts disabled.', 'wc-optic' );
				}
				echo '</p></div>';
			}
		);
	}
}
