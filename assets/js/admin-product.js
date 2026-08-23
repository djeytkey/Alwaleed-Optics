( function ( $ ) {
	'use strict';

	var $panel = null;
	var childIndexCounter = 0;
	var copiedCatalogTypes = [ 'section', 'company', 'brand', 'timing', 'color', 'pack', 'transparency' ];

	function getPanel() {
		if ( ! $panel || ! $panel.length ) {
			$panel = $( '#optic_product_data_panel' );
		}
		return $panel;
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

	function isPowerType( type ) {
		return (
			wcOpticAdmin.powerTypes &&
			wcOpticAdmin.powerTypes.indexOf( type ) !== -1
		);
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

	function getChildBlocks() {
		return getPanel().find( '.wc-optic-child-config' );
	}

	function nextChildIndex() {
		childIndexCounter += 1;
		return childIndexCounter;
	}

	function syncChildCounter() {
		getChildBlocks().each( function () {
			var raw = parseInt( $( this ).attr( 'data-child-index' ), 10 );
			if ( ! isNaN( raw ) && raw >= childIndexCounter ) {
				childIndexCounter = raw;
			}
		} );
	}

	function getChildTitle( $block, index ) {
		var label = $.trim( $block.find( '.wc-optic-child-label' ).val() || '' );
		if ( label ) {
			return label;
		}
		return ( wcOpticAdmin.i18n && wcOpticAdmin.i18n.product ? wcOpticAdmin.i18n.product : 'Product' ) + ' ' + ( index + 1 );
	}

	function renumberBlocks() {
		getChildBlocks().each( function ( index ) {
			var $block = $( this );
			$block.find( '.wc-optic-child-sort' ).val( index );
			$block.find( '.wc-optic-child-config__title' ).text( getChildTitle( $block, index ) );
		} );
	}

	function applyDivisionPowerFields() {
		var division = getSelectedDivision();
		var allowed = getAllowedPowers( division );

		getChildBlocks().find( '.wc-optic-child-power' ).each( function () {
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
			enabled: $block.find( 'input[type="checkbox"][name*="[enabled]"]' ).is( ':checked' ) ? '1' : '',
			sort: $block.find( '.wc-optic-child-sort' ).val() || '0',
			unit_price: $block.find( '.wc-optic-child-unit-price' ).val() || '',
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

		var data = {
			action: 'wc_optic_preview_sku',
			nonce: wcOpticAdmin.nonce,
			optic_division: getSelectedDivision(),
			child_config: collectChildConfig( $block ),
		};

		$.post( wcOpticAdmin.ajaxUrl, data, function ( res ) {
			if ( ! res || ! res.success || ! res.data ) {
				return;
			}
			if ( typeof res.data.sku === 'string' ) {
				$block.find( '.wc-optic-child-sku-preview' ).text( res.data.sku );
			}
			if ( typeof res.data.qr_html === 'string' ) {
				$block.find( '.wc-optic-child-qr' ).html( res.data.qr_html );
			}
		} );
	}

	function refreshAllSkuPreviews() {
		getChildBlocks().each( function () {
			refreshBlockSkuPreview( $( this ) );
		} );
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
		var $source = $block.find( '.wc-optic-child-backorder-card__source' );
		var $custom = $block.find( '.wc-optic-child-backorder-custom' );
		var $qty = $block.find( '.wc-optic-child-backorder-qty' );
		var $customField = $block.find( '.wc-optic-child-backorder-custom-field' );
		var isCustom = $custom.is( ':checked' );
		var globalLabel = ( wcOpticAdmin.i18n && wcOpticAdmin.i18n.backorderGlobal ) || 'Global';
		var customLabel = ( wcOpticAdmin.i18n && wcOpticAdmin.i18n.backorderCustom ) || 'Custom';

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
		$display.text( String( getGlobalBackorderQty() ) );
		$source.text( globalLabel );
		$customField.addClass( 'wc-optic-is-hidden' );
	}

	function syncAllChildBackorderFields() {
		getChildBlocks().each( function () {
			syncChildBackorderFields( $( this ) );
		} );
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
		var globalLabel = ( wcOpticAdmin.i18n && wcOpticAdmin.i18n.alertGlobal ) || 'Global';
		var customLabel = ( wcOpticAdmin.i18n && wcOpticAdmin.i18n.alertCustom ) || 'Custom';

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

	function syncAllChildAlertFields() {
		getChildBlocks().each( function () {
			syncChildAlertFields( $( this ) );
		} );
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

	function syncIdentityToChildren() {
		var identity = collectParentIdentity();
		getChildBlocks().find( '.wc-optic-child-identity-value' ).each( function () {
			var type = $( this ).data( 'optic-type' );
			if ( type && typeof identity[ type ] !== 'undefined' ) {
				$( this ).val( identity[ type ] || '' );
			}
		} );
	}

	function copyCatalogValuesFromFirstChild( $targetBlock ) {
		var $blocks = getChildBlocks();
		var $sourceBlock = $blocks.first();

		if (
			! $targetBlock ||
			! $targetBlock.length ||
			! $sourceBlock.length ||
			$sourceBlock.is( $targetBlock )
		) {
			return;
		}

		$.each( copiedCatalogTypes, function ( _, type ) {
			var $source = $sourceBlock.find( 'select.wc-optic-child-select[data-optic-type="' + type + '"]' );
			var $target = $targetBlock.find( 'select.wc-optic-child-select[data-optic-type="' + type + '"]' );

			if ( ! $source.length || ! $target.length ) {
				return;
			}

			$target.val( $source.val() || '' );
		} );
	}

	function addChildBlock() {
		var tpl = $( '#wc-optic-child-config-template' ).html();
		if ( ! tpl ) {
			return;
		}

		var index = nextChildIndex();
		var html = tpl.replace( /__INDEX__/g, String( index ) );
		var $block = $( $.trim( html ) );
		$block.find( '.wc-optic-child-id' ).val( 'child_' + index );
		getPanel().find( '#wc-optic-child-config-list' ).append( $block );
		copyCatalogValuesFromFirstChild( $block );
		syncIdentityToChildren();
		initChildBlock( $block );
		applyDivisionPowerFields();
		renumberBlocks();
	}

	function initOpticProductPanel() {
		getPanel().find( 'select.wc-optic-select2' ).each( function () {
			destroySelect2( $( this ) );
		} );
		syncChildCounter();
		applyDivisionPowerFields();
		initAllOpticSelect2();
		renumberBlocks();
		syncAllChildBackorderFields();
		syncAllChildAlertFields();
		refreshAllSkuPreviews();
	}

	function isOpticProductScreen() {
		return $( 'select#product-type' ).val() === 'optic_product';
	}

	function shouldDefaultToOpticProduct() {
		return !! ( wcOpticAdmin && wcOpticAdmin.isNewProduct && $( 'select#product-type option[value="optic_product"]' ).length );
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
		.on( 'change', '.wc-optic-child-select', function () {
			refreshBlockSkuPreview( $( this ).closest( '.wc-optic-child-config' ) );
		} )
		.on( 'input', '.wc-optic-child-label', function () {
			renumberBlocks();
		} )
		.on( 'input', '.wc-optic-child-unit-price', function () {
			refreshBlockSkuPreview( $( this ).closest( '.wc-optic-child-config' ) );
		} )
		.on( 'change', '.wc-optic-child-backorder-custom', function () {
			syncChildBackorderFields( $( this ).closest( '.wc-optic-child-config' ) );
		} )
		.on( 'input', '.wc-optic-child-backorder-qty', function () {
			syncChildBackorderFields( $( this ).closest( '.wc-optic-child-config' ) );
		} )
		.on( 'change', '.wc-optic-child-alert-custom', function () {
			syncChildAlertFields( $( this ).closest( '.wc-optic-child-config' ) );
		} )
		.on( 'input', '.wc-optic-child-alert-qty', function () {
			syncChildAlertFields( $( this ).closest( '.wc-optic-child-config' ) );
		} )
		.on( 'input', '.wc-optic-child-stock-qty', function () {
			syncChildBackorderFields( $( this ).closest( '.wc-optic-child-config' ) );
		} )
		.on( 'change', '#_optic_division', function () {
			applyDivisionPowerFields();
			applyDivisionIdentityFields();
			refreshAllSkuPreviews();
			renumberBlocks();
		} )
		.on( 'change', '.wc-optic-identity-select', function () {
			syncIdentityToChildren();
			refreshAllSkuPreviews();
		} )
		.on( 'click', '#wc-optic-add-child', function ( e ) {
			e.preventDefault();
			addChildBlock();
		} )
		.on( 'click', '.wc-optic-remove-child', function ( e ) {
			e.preventDefault();
			$( this ).closest( '.wc-optic-child-config' ).remove();
			renumberBlocks();
			refreshAllSkuPreviews();
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
