( function ( $ ) {
	'use strict';

	var $panel = null;
	var editorDirty = false;
	var editorBusy = false;

	function i18n( key, fallback ) {
		if ( wcOpticAdmin && wcOpticAdmin.i18n && wcOpticAdmin.i18n[ key ] ) {
			return wcOpticAdmin.i18n[ key ];
		}
		return fallback || key;
	}

	function getPanel() {
		if ( ! $panel || ! $panel.length ) {
			$panel = $( '#optic_product_data_panel' );
		}
		return $panel;
	}

	function getProductId() {
		var fromData = parseInt( getPanel().find( '.wc-optic-child-configs' ).data( 'product-id' ), 10 );
		if ( ! isNaN( fromData ) && fromData > 0 ) {
			return fromData;
		}
		return parseInt( ( wcOpticAdmin && wcOpticAdmin.productId ) || 0, 10 ) || 0;
	}

	function getSelectedDivision() {
		return $( '#_optic_division' ).val() || '';
	}

	function getAllowedPowers( division ) {
		if ( ! division || ! wcOpticAdmin.divisionPowers || ! wcOpticAdmin.divisionPowers[ division ] ) {
			return [];
		}
		return wcOpticAdmin.divisionPowers[ division ];
	}

	function divisionShowsColor( division ) {
		if ( ! division || ! wcOpticAdmin.divisionShowColor ) {
			return true;
		}
		if ( typeof wcOpticAdmin.divisionShowColor[ division ] === 'undefined' ) {
			return true;
		}
		return !! wcOpticAdmin.divisionShowColor[ division ];
	}

	function applyDivisionIdentityFields() {
		var division = getSelectedDivision();
		var showColor = divisionShowsColor( division );
		var $field = getPanel().find( '.wc-optic-identity-field--color' );
		var $select = $field.find( '.wc-optic-identity-select' );
		if ( ! showColor ) {
			$select.val( '' );
		}
		setSelectRequired( $select, showColor );
		$field.toggle( showColor );
		if ( showColor ) {
			initSelect2( $select );
		} else {
			destroySelect2( $select );
		}
	}

	function getSelect2Language() {
		if ( typeof wc_enhanced_select_params === 'undefined' ) {
			return {};
		}
		return {
			language: {
				noResults: function () {
					return wc_enhanced_select_params.i18n_no_matches;
				},
				searching: function () {
					return wc_enhanced_select_params.i18n_searching;
				},
			},
		};
	}

	function getSelect2Args( $el ) {
		return $.extend(
			{
				width: '100%',
				minimumResultsForSearch: 0,
				allowClear: ! $el.prop( 'required' ),
				closeOnSelect: ! $el.prop( 'multiple' ),
				placeholder: $el.data( 'placeholder' ) || '',
			},
			getSelect2Language()
		);
	}

	function setSelectRequired( $select, required ) {
		if ( ! $select || ! $select.length ) {
			return;
		}
		if ( required ) {
			$select.prop( 'required', true ).attr( 'aria-required', 'true' );
		} else {
			$select.prop( 'required', false ).removeAttr( 'aria-required' );
		}
		if ( $select.hasClass( 'enhanced' ) ) {
			destroySelect2( $select );
			initSelect2( $select );
		}
	}

	function destroySelect2( $el ) {
		if ( ! $el || ! $el.length ) {
			return;
		}
		if ( $el.hasClass( 'enhanced' ) && $el.data( 'select2' ) ) {
			$el.selectWoo( 'destroy' );
		}
		$el.removeClass( 'enhanced' );
	}

	function initSelect2( $el ) {
		if ( ! $el || ! $el.length || ! $el.is( ':visible' ) ) {
			return;
		}
		if ( $el.hasClass( 'enhanced' ) ) {
			$el.next( '.select2-container' ).css( 'width', '100%' );
			return;
		}
		$el.selectWoo( getSelect2Args( $el ) ).addClass( 'enhanced' );
		$el.next( '.select2-container' ).css( 'width', '100%' );
	}

	function initAllOpticSelect2() {
		getPanel().find( 'select.wc-optic-select2:visible' ).each( function () {
			initSelect2( $( this ) );
		} );
	}

	function getEditor() {
		return getPanel().find( '#wc-optic-child-editor' );
	}

	function getEditorBlock() {
		return getEditor().find( '.wc-optic-child-config' ).first();
	}

	function showNotice( message, isError ) {
		var $notice = getPanel().find( '#wc-optic-child-editor-notice' );
		if ( ! message ) {
			$notice.prop( 'hidden', true ).text( '' ).removeClass( 'notice-error notice-success' );
			return;
		}
		$notice
			.prop( 'hidden', false )
			.text( message )
			.toggleClass( 'notice-error', !! isError )
			.toggleClass( 'notice-success', ! isError );
	}

	function markDirty() {
		editorDirty = true;
	}

	function clearDirty() {
		editorDirty = false;
	}

	function collectParentIdentity() {
		var catalog = {};
		getPanel().find( '.wc-optic-identity-select' ).each( function () {
			var type = $( this ).data( 'optic-type' );
			if ( type ) {
				catalog[ type ] = $( this ).val() || '';
			}
		} );
		return catalog;
	}

	function syncIdentityToEditor() {
		var identity = collectParentIdentity();
		getEditorBlock().find( '.wc-optic-child-identity-value' ).each( function () {
			var type = $( this ).data( 'optic-type' );
			if ( type && typeof identity[ type ] !== 'undefined' ) {
				$( this ).val( identity[ type ] || '' );
			}
		} );
	}

	function applyDivisionPowerFields() {
		var division = getSelectedDivision();
		var allowed = getAllowedPowers( division );
		var $block = getEditorBlock();

		$block.find( '.wc-optic-child-power' ).each( function () {
			var $row = $( this );
			var $select = $row.find( 'select.wc-optic-child-select' );
			var type = $select.data( 'optic-type' );
			var show = type && allowed.indexOf( type ) !== -1;

			if ( ! show ) {
				setSelectRequired( $select, false );
				destroySelect2( $select );
				$select.val( '' );
				$row.hide();
				return;
			}

			setSelectRequired( $select, true );
			$row.show();
			initSelect2( $select );
		} );

		setSelectRequired( $( '#_optic_division' ), true );
		initSelect2( $( '#_optic_division' ) );
		applyDivisionIdentityFields();
	}

	function collectChildConfig( $block ) {
		var config = {
			id: $block.find( '.wc-optic-child-id' ).val() || '',
			label: $block.find( '.wc-optic-child-label' ).val() || '',
			enabled: $block.find( '.wc-optic-child-enabled-input' ).is( ':checked' ) ? '1' : '',
			sort: $block.find( '.wc-optic-child-sort' ).val() || '0',
			unit_price: $block.find( '.wc-optic-child-unit-price' ).val() || '',
			stock_qty: $block.find( '.wc-optic-child-stock-qty' ).val() || '',
			backorder_custom: $block.find( '.wc-optic-child-backorder-custom' ).is( ':checked' ) ? '1' : '',
			backorder_qty: $block.find( '.wc-optic-child-backorder-qty' ).val() || '',
			backorder_consumed: $block.find( '.wc-optic-child-backorder-consumed' ).val() || '0',
			alert_custom: $block.find( '.wc-optic-child-alert-custom' ).is( ':checked' ) ? '1' : '',
			alert_qty: $block.find( '.wc-optic-child-alert-qty' ).val() || '',
			catalog: {},
			powers: {},
		};

		$block.find( 'select.wc-optic-child-select' ).each( function () {
			var $el = $( this );
			var type = $el.data( 'optic-type' );
			if ( ! type ) {
				return;
			}
			if ( $el.data( 'is-power' ) ) {
				config.powers[ type ] = $el.val() || '';
			} else {
				config.catalog[ type ] = $el.val() || '';
			}
		} );

		$block.find( '.wc-optic-child-identity-value' ).each( function () {
			var type = $( this ).data( 'optic-type' );
			if ( type ) {
				config.catalog[ type ] = $( this ).val() || '';
			}
		} );

		var parentIdentity = collectParentIdentity();
		$.each( parentIdentity, function ( type, value ) {
			if ( value ) {
				config.catalog[ type ] = value;
			}
		} );

		return config;
	}

	function refreshBlockSkuPreview( $block ) {
		if ( ! $block || ! $block.length ) {
			return;
		}

		$.post(
			wcOpticAdmin.ajaxUrl,
			{
				action: 'wc_optic_preview_sku',
				nonce: wcOpticAdmin.nonce,
				optic_division: getSelectedDivision(),
				child_config: collectChildConfig( $block ),
			},
			function ( res ) {
				if ( ! res || ! res.success || ! res.data ) {
					return;
				}
				if ( typeof res.data.sku === 'string' ) {
					$block.find( '.wc-optic-child-sku-preview' ).text( res.data.sku );
				}
				if ( typeof res.data.qr_html === 'string' ) {
					$block.find( '.wc-optic-child-qr' ).html( res.data.qr_html );
				}
			}
		);
	}

	function getGlobalBackorderQty() {
		if ( ! wcOpticAdmin || ! wcOpticAdmin.backorderEnabled ) {
			return 0;
		}
		return parseInt( wcOpticAdmin.globalBackorderQty, 10 ) || 0;
	}

	function getGlobalAlertQty() {
		if ( ! wcOpticAdmin || ! wcOpticAdmin.alertEnabled ) {
			return 0;
		}
		return parseInt( wcOpticAdmin.globalAlertQty, 10 ) || 0;
	}

	function syncChildBackorderFields( $block ) {
		if ( ! $block || ! $block.length ) {
			return;
		}

		var enabled = !!( wcOpticAdmin && wcOpticAdmin.backorderEnabled );
		var $row = $block.find( '.wc-optic-child-backorder-row' );
		var $display = $block.find( '.wc-optic-child-backorder-display' );
		var $source = $block.find( '.wc-optic-child-backorder-card__source' ).not( '.wc-optic-child-alert-card__source' );
		var $custom = $block.find( '.wc-optic-child-backorder-custom' );
		var $qty = $block.find( '.wc-optic-child-backorder-qty' );
		var $customField = $block.find( '.wc-optic-child-backorder-custom-field' ).not( '.wc-optic-child-alert-custom-field' );
		var isCustom = $custom.is( ':checked' );
		var globalLabel = i18n( 'backorderGlobal', 'Global' );
		var customLabel = i18n( 'backorderCustom', 'Custom' );

		if ( ! $row.length ) {
			return;
		}

		$row.toggleClass( 'wc-optic-backorder-disabled', ! enabled );
		$row.toggleClass( 'wc-optic-child-backorder-card--custom', enabled && isCustom );

		if ( ! enabled ) {
			$display.text( '0' );
			$source.first().text( globalLabel );
			$qty.prop( 'disabled', true );
			$customField.addClass( 'wc-optic-is-hidden' );
			return;
		}

		if ( isCustom ) {
			$qty.prop( 'disabled', false );
			$display.text( $qty.val() || '0' );
			$source.first().text( customLabel );
			$customField.removeClass( 'wc-optic-is-hidden' );
			return;
		}

		$qty.prop( 'disabled', true );
		$display.text( String( getGlobalBackorderQty() ) );
		$source.first().text( globalLabel );
		$customField.addClass( 'wc-optic-is-hidden' );
	}

	function syncChildAlertFields( $block ) {
		if ( ! $block || ! $block.length ) {
			return;
		}

		var enabled = !!( wcOpticAdmin && wcOpticAdmin.alertEnabled );
		var $row = $block.find( '.wc-optic-child-alert-row' );
		var $display = $block.find( '.wc-optic-child-alert-display' );
		var $source = $block.find( '.wc-optic-child-alert-card__source' );
		var $custom = $block.find( '.wc-optic-child-alert-custom' );
		var $qty = $block.find( '.wc-optic-child-alert-qty' );
		var $customField = $block.find( '.wc-optic-child-alert-custom-field' );
		var isCustom = $custom.is( ':checked' );
		var globalLabel = i18n( 'alertGlobal', 'Global' );
		var customLabel = i18n( 'alertCustom', 'Custom' );

		if ( ! $row.length ) {
			return;
		}

		$row.toggleClass( 'wc-optic-backorder-disabled', ! enabled );
		$row.toggleClass( 'wc-optic-child-backorder-card--custom', enabled && isCustom );

		if ( ! enabled ) {
			$display.text( '0' );
			$source.text( globalLabel );
			$qty.prop( 'disabled', true );
			$customField.addClass( 'wc-optic-is-hidden' );
			return;
		}

		if ( isCustom ) {
			$qty.prop( 'disabled', false );
			$display.text( $qty.val() || '0' );
			$source.text( customLabel );
			$customField.removeClass( 'wc-optic-is-hidden' );
			return;
		}

		$qty.prop( 'disabled', true );
		$display.text( String( getGlobalAlertQty() ) );
		$source.text( globalLabel );
		$customField.addClass( 'wc-optic-is-hidden' );
	}

	function initChildBlock( $block ) {
		if ( ! $block || ! $block.length ) {
			return;
		}
		$block.find( 'select.wc-optic-select2' ).each( function () {
			destroySelect2( $( this ) );
			initSelect2( $( this ) );
		} );
		syncChildBackorderFields( $block );
		syncChildAlertFields( $block );
		refreshBlockSkuPreview( $block );
	}

	function escapeHtml( text ) {
		return $( '<div>' ).text( text == null ? '' : String( text ) ).html();
	}

	function buildRowHtml( row ) {
		var enabled = !! row.enabled;
		var status = enabled
			? '<span class="wc-optic-child-status wc-optic-child-status--on">' + escapeHtml( i18n( 'enabled', 'Enabled' ) ) + '</span>'
			: '<span class="wc-optic-child-status wc-optic-child-status--off">' + escapeHtml( i18n( 'disabled', 'Disabled' ) ) + '</span>';

		return (
			'<tr class="wc-optic-child-list-row" data-child-id="' +
			escapeHtml( row.id || '' ) +
			'" data-search="' +
			escapeHtml( row.search || '' ) +
			'">' +
			'<td class="wc-optic-child-list__label">' +
			escapeHtml( row.label || '' ) +
			'</td>' +
			'<td class="wc-optic-child-list__powers"><code dir="ltr">' +
			escapeHtml( row.powers || '' ) +
			'</code></td>' +
			'<td class="wc-optic-child-list__price">' +
			escapeHtml( row.price || '' ) +
			'</td>' +
			'<td class="wc-optic-child-list__stock">' +
			escapeHtml( row.stock || '' ) +
			'</td>' +
			'<td class="wc-optic-child-list__status">' +
			status +
			'</td>' +
			'<td class="wc-optic-child-list__actions">' +
			'<button type="button" class="button button-small wc-optic-edit-child">' +
			escapeHtml( i18n( 'edit', 'Edit' ) ) +
			'</button> ' +
			'<button type="button" class="button-link-delete wc-optic-remove-child-row">' +
			escapeHtml( i18n( 'remove', 'Remove' ) ) +
			'</button>' +
			'</td>' +
			'</tr>'
		);
	}

	function updateChildCount( count ) {
		getPanel().find( '#wc-optic-child-count' ).text( '(' + String( count ) + ')' );
	}

	function renderRows( rows ) {
		var $body = getPanel().find( '#wc-optic-child-list-body' );
		$body.empty();
		if ( ! rows || ! rows.length ) {
			$body.append(
				'<tr class="wc-optic-child-list-empty"><td colspan="6">' +
					escapeHtml( i18n( 'emptyList', 'No internal products yet.' ) ) +
					'</td></tr>'
			);
			updateChildCount( 0 );
			filterChildList();
			return;
		}
		$.each( rows, function ( _, row ) {
			$body.append( buildRowHtml( row ) );
		} );
		updateChildCount( rows.length );
		filterChildList();
	}

	function filterChildList() {
		var q = $.trim( getPanel().find( '#wc-optic-child-search' ).val() || '' ).toLowerCase();
		var $rows = getPanel().find( '#wc-optic-child-list-body tr.wc-optic-child-list-row' );
		var $empty = getPanel().find( '#wc-optic-child-list-body tr.wc-optic-child-list-empty' );
		var visible = 0;

		$rows.each( function () {
			var hay = String( $( this ).attr( 'data-search' ) || '' ).toLowerCase();
			var show = ! q || hay.indexOf( q ) !== -1;
			$( this ).toggle( show );
			if ( show ) {
				visible += 1;
			}
		} );

		$empty.remove();
		if ( ! $rows.length ) {
			getPanel()
				.find( '#wc-optic-child-list-body' )
				.append(
					'<tr class="wc-optic-child-list-empty"><td colspan="6">' +
						escapeHtml( i18n( 'emptyList', 'No internal products yet.' ) ) +
						'</td></tr>'
				);
			return;
		}
		if ( q && visible === 0 ) {
			getPanel()
				.find( '#wc-optic-child-list-body' )
				.append(
					'<tr class="wc-optic-child-list-empty"><td colspan="6">' +
						escapeHtml( i18n( 'noSearchResults', 'No internal products match your search.' ) ) +
						'</td></tr>'
				);
		}
	}

	function closeEditor() {
		var $editor = getEditor();
		$editor.find( 'select.wc-optic-select2' ).each( function () {
			destroySelect2( $( this ) );
		} );
		$editor.empty().prop( 'hidden', true );
		clearDirty();
		getPanel().find( '.wc-optic-child-list-row.is-editing' ).removeClass( 'is-editing' );
	}

	function openEditorHtml( html, childId ) {
		var $editor = getEditor();
		$editor.html( html ).prop( 'hidden', false );
		var $block = getEditorBlock();
		if ( childId ) {
			$block.attr( 'data-child-id', childId );
			$block.find( '.wc-optic-child-id' ).val( childId );
		}
		syncIdentityToEditor();
		applyDivisionPowerFields();
		initChildBlock( $block );
		clearDirty();
		showNotice( '' );
		$editor.get( 0 ).scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
	}

	function confirmDiscardIfNeeded() {
		if ( ! editorDirty ) {
			return true;
		}
		return window.confirm( i18n( 'confirmDiscard', 'Discard unsaved changes to this internal product?' ) );
	}

	function loadChild( childId ) {
		var productId = getProductId();
		if ( productId < 1 ) {
			showNotice( i18n( 'saveProductFirst', 'Save the product first, then manage internal products.' ), true );
			return;
		}
		if ( editorBusy ) {
			return;
		}
		if ( ! confirmDiscardIfNeeded() ) {
			return;
		}

		editorBusy = true;
		showNotice( i18n( 'loading', 'Loading…' ), false );
		getPanel().find( '.wc-optic-child-list-row' ).removeClass( 'is-editing' );
		getPanel().find( '.wc-optic-child-list-row[data-child-id="' + childId + '"]' ).addClass( 'is-editing' );

		$.post(
			wcOpticAdmin.ajaxUrl,
			{
				action: 'wc_optic_load_child',
				nonce: wcOpticAdmin.nonce,
				product_id: productId,
				child_id: childId,
			}
		)
			.done( function ( res ) {
				if ( ! res || ! res.success || ! res.data || ! res.data.html ) {
					showNotice( ( res && res.data && res.data.message ) || i18n( 'loading', 'Loading…' ), true );
					return;
				}
				openEditorHtml( res.data.html, childId );
			} )
			.fail( function ( xhr ) {
				var msg =
					xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
						? xhr.responseJSON.data.message
						: i18n( 'loading', 'Loading…' );
				showNotice( msg, true );
			} )
			.always( function () {
				editorBusy = false;
			} );
	}

	function openNewChildEditor() {
		var productId = getProductId();
		if ( productId < 1 ) {
			showNotice( i18n( 'saveProductFirst', 'Save the product first, then manage internal products.' ), true );
			return;
		}
		if ( ! confirmDiscardIfNeeded() ) {
			return;
		}

		var tpl = $( '#wc-optic-child-config-template' ).html();
		if ( ! tpl ) {
			return;
		}

		var html = tpl.replace( /__INDEX__/g, 'edit' );
		var newId = 'child_' + String( Date.now() ) + '_' + String( Math.floor( Math.random() * 1000 ) );
		getPanel().find( '.wc-optic-child-list-row' ).removeClass( 'is-editing' );
		openEditorHtml( html, newId );
		getEditorBlock().find( '.wc-optic-child-config__title' ).text( i18n( 'product', 'Product' ) );
		markDirty();
	}

	function saveChild() {
		var productId = getProductId();
		var $block = getEditorBlock();
		if ( productId < 1 || ! $block.length || editorBusy ) {
			return;
		}

		editorBusy = true;
		var $btns = $block.find( '.wc-optic-save-child' );
		$btns.prop( 'disabled', true ).text( i18n( 'saving', 'Saving…' ) );
		showNotice( '' );

		$.post(
			wcOpticAdmin.ajaxUrl,
			{
				action: 'wc_optic_save_child',
				nonce: wcOpticAdmin.nonce,
				product_id: productId,
				optic_division: getSelectedDivision(),
				identity: collectParentIdentity(),
				child_config: collectChildConfig( $block ),
			}
		)
			.done( function ( res ) {
				if ( ! res || ! res.success || ! res.data ) {
					showNotice(
						( res && res.data && res.data.message ) || i18n( 'duplicatePowers', 'Duplicate prescription.' ),
						true
					);
					return;
				}
				if ( res.data.rows ) {
					renderRows( res.data.rows );
				} else if ( typeof res.data.count !== 'undefined' ) {
					updateChildCount( res.data.count );
				}
				clearDirty();
				closeEditor();
				showNotice( i18n( 'saved', 'Internal product saved.' ), false );
			} )
			.fail( function ( xhr ) {
				var msg =
					xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
						? xhr.responseJSON.data.message
						: i18n( 'duplicatePowers', 'This prescription combination already exists.' );
				showNotice( msg, true );
			} )
			.always( function () {
				editorBusy = false;
				$btns.prop( 'disabled', false ).text( i18n( 'saveChild', 'Save internal product' ) );
			} );
	}

	function removeChild( childId ) {
		var productId = getProductId();
		if ( productId < 1 || ! childId || editorBusy ) {
			return;
		}
		if ( ! window.confirm( i18n( 'confirmRemove', 'Remove this internal product?' ) ) ) {
			return;
		}

		editorBusy = true;
		$.post(
			wcOpticAdmin.ajaxUrl,
			{
				action: 'wc_optic_remove_child',
				nonce: wcOpticAdmin.nonce,
				product_id: productId,
				child_id: childId,
			}
		)
			.done( function ( res ) {
				if ( ! res || ! res.success || ! res.data ) {
					showNotice( ( res && res.data && res.data.message ) || 'Error', true );
					return;
				}
				var openId = getEditorBlock().find( '.wc-optic-child-id' ).val();
				if ( openId === childId ) {
					closeEditor();
				}
				if ( res.data.rows ) {
					renderRows( res.data.rows );
				}
				showNotice( i18n( 'removed', 'Internal product removed.' ), false );
			} )
			.fail( function ( xhr ) {
				var msg =
					xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
						? xhr.responseJSON.data.message
						: 'Error';
				showNotice( msg, true );
			} )
			.always( function () {
				editorBusy = false;
			} );
	}

	function initOpticProductPanel() {
		getPanel().find( 'select.wc-optic-select2' ).each( function () {
			destroySelect2( $( this ) );
		} );
		applyDivisionIdentityFields();
		initAllOpticSelect2();
		if ( ! getEditor().prop( 'hidden' ) ) {
			applyDivisionPowerFields();
			initChildBlock( getEditorBlock() );
		}
	}

	function isOpticProductScreen() {
		return $( 'select#product-type' ).val() === 'optic_product';
	}

	function shouldDefaultToOpticProduct() {
		return !!( wcOpticAdmin && wcOpticAdmin.isNewProduct && $( 'select#product-type option[value="optic_product"]' ).length );
	}

	function ensureDefaultOpticProductType() {
		var $type = $( 'select#product-type' );
		if ( ! shouldDefaultToOpticProduct() || ! $type.length || isOpticProductScreen() ) {
			return;
		}
		$type.val( 'optic_product' ).trigger( 'change' );
	}

	function moveOpticTabFirst() {
		var $tabs = $( 'ul.product_data_tabs' );
		var $opticTab = $tabs.find( 'li.optic_config_tab' );
		if ( $tabs.length && $opticTab.length ) {
			$opticTab.prependTo( $tabs );
		}
	}

	function activateOpticConfigTab() {
		var $link = $( 'ul.product_data_tabs li.optic_config_tab:visible a' );
		if ( $link.length ) {
			$link.trigger( 'click' );
		}
	}

	function focusOpticProductAdminTab() {
		if ( ! isOpticProductScreen() ) {
			return;
		}
		moveOpticTabFirst();
		if ( wcOpticAdmin && wcOpticAdmin.isNewProduct ) {
			activateOpticConfigTab();
		}
	}

	$( document.body )
		.on( 'change', '#wc-optic-child-editor .wc-optic-child-select', function () {
			markDirty();
			refreshBlockSkuPreview( $( this ).closest( '.wc-optic-child-config' ) );
		} )
		.on( 'input change', '#wc-optic-child-editor input, #wc-optic-child-editor select', function () {
			markDirty();
		} )
		.on( 'input', '#wc-optic-child-editor .wc-optic-child-label', function () {
			var label = $.trim( $( this ).val() || '' );
			getEditorBlock()
				.find( '.wc-optic-child-config__title' )
				.text( label || i18n( 'product', 'Product' ) );
		} )
		.on( 'input', '#wc-optic-child-editor .wc-optic-child-unit-price', function () {
			refreshBlockSkuPreview( $( this ).closest( '.wc-optic-child-config' ) );
		} )
		.on( 'change', '#wc-optic-child-editor .wc-optic-child-backorder-custom', function () {
			syncChildBackorderFields( $( this ).closest( '.wc-optic-child-config' ) );
		} )
		.on( 'input', '#wc-optic-child-editor .wc-optic-child-backorder-qty', function () {
			syncChildBackorderFields( $( this ).closest( '.wc-optic-child-config' ) );
		} )
		.on( 'change', '#wc-optic-child-editor .wc-optic-child-alert-custom', function () {
			syncChildAlertFields( $( this ).closest( '.wc-optic-child-config' ) );
		} )
		.on( 'input', '#wc-optic-child-editor .wc-optic-child-alert-qty', function () {
			syncChildAlertFields( $( this ).closest( '.wc-optic-child-config' ) );
		} )
		.on( 'change', '#_optic_division', function () {
			applyDivisionIdentityFields();
			applyDivisionPowerFields();
			if ( ! getEditor().prop( 'hidden' ) ) {
				refreshBlockSkuPreview( getEditorBlock() );
			}
		} )
		.on( 'change', '.wc-optic-identity-select', function () {
			syncIdentityToEditor();
			if ( ! getEditor().prop( 'hidden' ) ) {
				refreshBlockSkuPreview( getEditorBlock() );
			}
		} )
		.on( 'input', '#wc-optic-child-search', function () {
			filterChildList();
		} )
		.on( 'click', '#wc-optic-add-child', function ( e ) {
			e.preventDefault();
			openNewChildEditor();
		} )
		.on( 'click', '.wc-optic-edit-child', function ( e ) {
			e.preventDefault();
			var childId = $( this ).closest( 'tr' ).data( 'child-id' );
			if ( childId ) {
				loadChild( String( childId ) );
			}
		} )
		.on( 'click', '.wc-optic-remove-child-row', function ( e ) {
			e.preventDefault();
			var childId = $( this ).closest( 'tr' ).data( 'child-id' );
			if ( childId ) {
				removeChild( String( childId ) );
			}
		} )
		.on( 'click', '.wc-optic-save-child', function ( e ) {
			e.preventDefault();
			saveChild();
		} )
		.on( 'click', '.wc-optic-cancel-child', function ( e ) {
			e.preventDefault();
			if ( ! confirmDiscardIfNeeded() ) {
				return;
			}
			closeEditor();
			showNotice( '' );
		} )
		.on( 'woocommerce-product-type-change', function () {
			if ( isOpticProductScreen() ) {
				focusOpticProductAdminTab();
				setTimeout( initOpticProductPanel, 100 );
			}
		} )
		.on( 'click', 'ul.product_data_tabs li a[href="#optic_product_data_panel"]', function () {
			setTimeout( initOpticProductPanel, 50 );
		} );

	$( function () {
		ensureDefaultOpticProductType();
		if ( isOpticProductScreen() ) {
			setTimeout( function () {
				focusOpticProductAdminTab();
				initOpticProductPanel();
			}, 120 );
		}
	} );
}( jQuery ) );
