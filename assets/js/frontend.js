( function ( $ ) {
	'use strict';

	function getMatrix() {
		if ( typeof wcOpticFront === 'undefined' || ! wcOpticFront.matrix ) {
			return { powers: [], children: [], terms: {}, labels: {} };
		}
		return wcOpticFront.matrix;
	}

	function getI18n( key, fallback ) {
		if ( typeof wcOpticFront !== 'undefined' && wcOpticFront.i18n && wcOpticFront.i18n[ key ] ) {
			return wcOpticFront.i18n[ key ];
		}
		return fallback || '';
	}

	function isDifferentPowerMode() {
		if ( isNoPowerMode() ) {
			return false;
		}
		var $cb = $( '#wc_optic_different_power' );
		return $cb.length > 0 && $cb.is( ':checked' );
	}

	function supportsNoPowerMode() {
		var matrix = getMatrix();
		return matrix.supportsNoPowerMode === true;
	}

	function isNoPowerMode() {
		if ( ! supportsNoPowerMode() ) {
			return false;
		}
		var $checked = $( 'input[name="wc_optic_power_mode"]:checked' );
		return $checked.length > 0 && $checked.val() === 'no_power';
	}

	function getNoPowerChild() {
		var matrix = getMatrix();
		return matrix.noPowerChild || null;
	}

	function formatPrice( amount ) {
		if ( typeof wcOpticFront === 'undefined' ) {
			return String( amount );
		}
		var n = parseFloat( amount );
		if ( isNaN( n ) ) {
			n = 0;
		}
		var decimals = parseInt( wcOpticFront.decimals, 10 );
		if ( isNaN( decimals ) ) {
			decimals = 2;
		}
		var parts = n.toFixed( decimals ).split( '.' );
		var intPart = parts[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, wcOpticFront.thousandSep || ',' );
		var formatted = parts.length > 1 ? intPart + ( wcOpticFront.decimalSep || '.' ) + parts[ 1 ] : intPart;
		var format = wcOpticFront.priceFormat || '%1$s%2$s';
		return format.replace( '%1$s', wcOpticFront.currencySymbol || '' ).replace( '%2$s', formatted );
	}

	function getEyeContainer( eye ) {
		return $( '.wc-optic-eye[data-eye="' + eye + '"]' );
	}

	function getPowerSelectors( eye ) {
		return getEyeContainer( eye ).find( 'select.wc-optic-power-dropdown' );
	}

	function getPartialSelection( eye, upToIndex ) {
		var matrix = getMatrix();
		var partial = {};
		var $selects = getPowerSelectors( eye );
		$selects.each( function ( idx ) {
			if ( typeof upToIndex !== 'undefined' && idx >= upToIndex ) {
				return false;
			}
			var power = $( this ).data( 'power' );
			var val = $( this ).val();
			if ( power && val ) {
				partial[ power ] = String( val );
			}
		} );
		return partial;
	}

	function childrenMatching( partial ) {
		return getMatrix().children.filter( function ( child ) {
			for ( var key in partial ) {
				if ( ! Object.prototype.hasOwnProperty.call( partial, key ) ) {
					continue;
				}
				if ( String( child.powers[ key ] ) !== String( partial[ key ] ) ) {
					return false;
				}
			}
			return true;
		} );
	}

	function childHasStock( child ) {
		return child.inStock === true || child.inStock === 1 || child.inStock === '1';
	}

	function optionIsPurchasable( partial, powerKey, termId ) {
		var probe = $.extend( {}, partial );
		probe[ powerKey ] = String( termId );
		var matches = childrenMatching( probe );
		if ( ! matches.length ) {
			return false;
		}
		return matches.some( childHasStock );
	}

	function sortTermIds( powerKey, ids ) {
		var terms = getMatrix().terms[ powerKey ] || {};
		return ids.slice().sort( function ( a, b ) {
			var la = terms[ String( a ) ] || '';
			var lb = terms[ String( b ) ] || '';
			return la.localeCompare( lb, undefined, { numeric: true, sensitivity: 'base' } );
		} );
	}

	function populatePowerSelect( eye, powerIndex ) {
		var matrix = getMatrix();
		var powers = matrix.powers || [];
		var powerKey = powers[ powerIndex ];
		if ( ! powerKey ) {
			return;
		}

		var $select = getEyeContainer( eye ).find( 'select.wc-optic-power-dropdown[data-power="' + powerKey + '"]' );
		if ( ! $select.length ) {
			return;
		}

		var partial = getPartialSelection( eye, powerIndex );
		var matches = childrenMatching( partial );
		var termIds = {};
		matches.forEach( function ( child ) {
			var tid = child.powers[ powerKey ];
			if ( tid ) {
				termIds[ String( tid ) ] = true;
			}
		} );

		var current = $select.val();
		var placeholder = $select.data( 'placeholder' ) || getI18n( 'select', '— Select —' );
		var outOfStockLabel = getI18n( 'outOfStockOption', 'Out of stock' );
		var html = '<option value=""></option>';

		sortTermIds( powerKey, Object.keys( termIds ) ).forEach( function ( tid ) {
			var label = ( matrix.terms[ powerKey ] && matrix.terms[ powerKey ][ tid ] ) || tid;
			var purchasable = optionIsPurchasable( partial, powerKey, tid );
			var suffix = purchasable ? '' : ' (' + outOfStockLabel + ')';
			html += '<option value="' + tid + '"' + ( purchasable ? '' : ' disabled class="wc-optic-option--rupture"' ) + '>' + label + suffix + '</option>';
		} );

		$select.html( html );
		if ( current && $select.find( 'option[value="' + current + '"]' ).length && ! $select.find( 'option[value="' + current + '"]' ).is( ':disabled' ) ) {
			$select.val( current );
		} else {
			$select.val( '' );
		}

		if ( $select.data( 'select2' ) ) {
			$select.trigger( 'change.select2' );
		}
	}

	function clearDownstreamPowers( eye, fromIndex ) {
		var matrix = getMatrix();
		var powers = matrix.powers || [];
		for ( var i = fromIndex; i < powers.length; i++ ) {
			var $sel = getEyeContainer( eye ).find( 'select.wc-optic-power-dropdown[data-power="' + powers[ i ] + '"]' );
			$sel.html( '<option value=""></option>' );
			if ( $sel.data( 'select2' ) ) {
				$sel.trigger( 'change.select2' );
			}
		}
	}

	function isSelectionComplete( eye ) {
		if ( isNoPowerMode() ) {
			return !! getNoPowerChild();
		}
		var matrix = getMatrix();
		var powers = matrix.powers || [];
		return Object.keys( getPartialSelection( eye ) ).length === powers.length;
	}

	function resolveChildForEye( eye ) {
		if ( isNoPowerMode() ) {
			return getNoPowerChild();
		}
		if ( ! isSelectionComplete( eye ) ) {
			return null;
		}
		var partial = getPartialSelection( eye );
		var matches = childrenMatching( partial );
		return matches.length ? matches[ 0 ] : null;
	}

	/**
	 * @param {string} eye left|right
	 * @param {{ showInvalidCombo?: boolean }} options Show "combination not available" only when true (submit).
	 */
	function syncResolvedChildField( eye, options ) {
		options = options || {};
		var showInvalidCombo = options.showInvalidCombo === true;
		var child = resolveChildForEye( eye );
		var $hidden = getEyeContainer( eye ).find( '.wc-optic-resolved-child' );
		var $notice = getEyeContainer( eye ).find( '.wc-optic-resolution-notice' );

		if ( ! child ) {
			$hidden.val( '' );
			if ( showInvalidCombo && isSelectionComplete( eye ) ) {
				$notice.prop( 'hidden', false );
				$notice.text( getI18n( 'comboUnavailable', 'This combination is not available.' ) );
			} else {
				$notice.prop( 'hidden', true );
				$notice.text( '' );
			}
			return null;
		}

		$hidden.val( child.id );
		if ( ! childHasStock( child ) ) {
			$notice.prop( 'hidden', false );
			$notice.text( getI18n( 'outOfStockOption', 'Out of stock' ) );
		} else {
			$notice.prop( 'hidden', true );
			$notice.text( '' );
		}

		return child;
	}

	function getEyeResolvedData( eye ) {
		var child = syncResolvedChildField( eye );
		if ( ! child ) {
			return { price: 0, stock: null, inStock: false };
		}
		var stock = child.stock;
		if ( stock === '' || typeof stock === 'undefined' ) {
			stock = null;
		} else {
			stock = parseInt( stock, 10 );
			if ( isNaN( stock ) ) {
				stock = null;
			}
		}
		return {
			price: parseFloat( child.price ) || 0,
			stock: stock,
			inStock: childHasStock( child ),
		};
	}

	function getEyeFieldPrice( eye ) {
		return getEyeResolvedData( eye ).price;
	}

	function getEyeFieldStock( eye ) {
		var data = getEyeResolvedData( eye );
		if ( ! data.inStock ) {
			return data.stock === null ? 0 : data.stock;
		}
		return data.stock;
	}

	function initPowerDropdowns( $scope ) {
		if ( typeof $.fn.selectWoo !== 'function' ) {
			return;
		}

		( $scope && $scope.length ? $scope : $( document ) )
			.find( 'select.wc-optic-power-dropdown:visible' )
			.each( function () {
				var $el = $( this );
				if ( $el.data( 'select2' ) ) {
					$el.next( '.select2-container' ).css( 'width', '100%' );
					return;
				}
				$el.selectWoo( {
					width: '100%',
					minimumResultsForSearch: 0,
					allowClear: false,
					placeholder: $el.data( 'placeholder' ) || getI18n( 'select', '' ),
				} );
			} );
	}

	function refreshEyeCascade( eye, changedIndex ) {
		var matrix = getMatrix();
		var powers = matrix.powers || [];
		clearDownstreamPowers( eye, changedIndex + 1 );
		for ( var i = changedIndex + 1; i < powers.length; i++ ) {
			populatePowerSelect( eye, i );
		}
		syncResolvedChildField( eye );
	}

	function initEyeCascade( eye ) {
		var matrix = getMatrix();
		if ( ! matrix.powers || ! matrix.powers.length ) {
			return;
		}
		populatePowerSelect( eye, 0 );
		syncResolvedChildField( eye );
	}

	function syncRightPowersFromLeft() {
		var matrix = getMatrix();
		var powers = matrix.powers || [];
		if ( ! powers.length ) {
			return;
		}

		populatePowerSelect( 'right', 0 );
		powers.forEach( function ( power, index ) {
			var $left  = getEyeContainer( 'left' ).find( 'select.wc-optic-power-dropdown[data-power="' + power + '"]' );
			var $right = getEyeContainer( 'right' ).find( 'select.wc-optic-power-dropdown[data-power="' + power + '"]' );
			if ( index > 0 ) {
				populatePowerSelect( 'right', index );
			}
			$right.val( $left.val() || '' );
			if ( $right.data( 'select2' ) ) {
				$right.trigger( 'change.select2' );
			}
		} );
		syncResolvedChildField( 'right' );
	}

	function eyesHaveSameSelection() {
		var left = resolveChildForEye( 'left' );
		var right = resolveChildForEye( 'right' );
		return left && right && String( left.id ) === String( right.id );
	}

	function hasValidPurchasableSelection() {
		if ( isNoPowerMode() ) {
			var noPowerChild = getNoPowerChild();
			return !!( noPowerChild && childHasStock( noPowerChild ) );
		}
		var different = isDifferentPowerMode();
		var samePowers = eyesHaveSameSelection();
		var left = resolveChildForEye( 'left' );
		if ( ! left || ! childHasStock( left ) ) {
			return false;
		}
		if ( different && ! samePowers ) {
			var right = resolveChildForEye( 'right' );
			return !!( right && childHasStock( right ) );
		}
		return true;
	}

	function syncNoPowerChildFields() {
		var child = getNoPowerChild();
		$( '.wc-optic-eye' ).each( function () {
			var $eye = $( this );
			var $hidden = $eye.find( '.wc-optic-resolved-child' );
			var $notice = $eye.find( '.wc-optic-resolution-notice' );
			if ( ! child ) {
				$hidden.val( '' );
				$notice.prop( 'hidden', false );
				$notice.text( getI18n( 'noPowerUnavailable', 'This product is not available without power.' ) );
				return;
			}
			$hidden.val( child.id );
			if ( ! childHasStock( child ) ) {
				$notice.prop( 'hidden', false );
				$notice.text( getI18n( 'outOfStockOption', 'Out of stock' ) );
			} else {
				$notice.prop( 'hidden', true );
				$notice.text( '' );
			}
		} );
	}

	function togglePowerMode() {
		var noPower = isNoPowerMode();
		var $prescription = $( '.wc-optic-prescription-row' );
		var $differentToggle = $( '.wc-optic-toggle--question' );
		var $right = $( '.wc-optic-eye--right' );
		var $singleQty = $( '.wc-optic-qty--single' );
		var $dualQty = $( '.wc-optic-qty--dual' );

		if ( noPower ) {
			$prescription.prop( 'hidden', true );
			$differentToggle.prop( 'hidden', true );
			$( '#wc_optic_different_power' ).prop( 'checked', false );
			$right.prop( 'hidden', true );
			$singleQty.prop( 'hidden', false );
			$dualQty.prop( 'hidden', true );
			$( 'select.wc-optic-power-dropdown' ).prop( 'required', false );
			syncNoPowerChildFields();
		} else {
			$prescription.prop( 'hidden', false );
			if ( $differentToggle.length && ( getMatrix().children || [] ).length > 1 ) {
				$differentToggle.prop( 'hidden', false );
			}
			$( '.wc-optic-eye--left select.wc-optic-power-dropdown' ).prop( 'required', true );
			initEyeCascade( 'left' );
			initPowerDropdowns( $( '.wc-optic-prescription-row' ) );
			toggleSamePower();
		}

		syncQuantityStockLimits();
		syncLineQuantity();
		updateAddToCartState();
		updatePriceDisplay();
	}

	function syncSummaryProductPrice() {
		if ( typeof wcOpticFront === 'undefined' || ! wcOpticFront.summaryPriceSelector ) {
			return;
		}
		var $unitDisplay = $( '#wc_optic_unit_price_display' );
		var $targets = $( wcOpticFront.summaryPriceSelector ).first();
		if ( $targets.length && $unitDisplay.length ) {
			$targets.html( $unitDisplay.html() );
		}
	}

	function updatePriceDisplay() {
		var $label = $( '.wc-optic-price-label' );
		var $unitDisplay = $( '#wc_optic_unit_price_display' );
		var $totalRow = $( '.wc-optic-line-total' );
		var $totalDisplay = $( '#wc_optic_line_total_display' );

		if ( ! $unitDisplay.length ) {
			return;
		}

		if ( hasValidPurchasableSelection() ) {
			var pricing = getPricingState();
			if ( $label.length ) {
				$label.text( getI18n( 'selectedPrice', 'Selected price' ) + ':' );
			}
			$unitDisplay.text( formatPrice( pricing.displayPrice ) );
			if ( $totalRow.length ) {
				$totalRow.prop( 'hidden', false );
				$totalDisplay.text( formatPrice( pricing.total ) );
			}
		} else {
			if ( $label.length ) {
				$label.text( getI18n( 'price', 'Price' ) + ':' );
			}
			var showDefault = isNoPowerMode() || ! supportsNoPowerMode();
			if ( showDefault ) {
				if ( typeof wcOpticFront !== 'undefined' && wcOpticFront.defaultPriceHtml ) {
					$unitDisplay.html( wcOpticFront.defaultPriceHtml );
				} else if ( typeof wcOpticFront !== 'undefined' && wcOpticFront.defaultPrice ) {
					$unitDisplay.text( formatPrice( wcOpticFront.defaultPrice ) );
				}
			} else {
				$unitDisplay.text( '' );
			}
			if ( $totalRow.length ) {
				$totalRow.prop( 'hidden', true );
				$totalDisplay.text( '' );
			}
		}

		syncSummaryProductPrice();
	}

	function getPricingState() {
		var different = isDifferentPowerMode();
		var samePowers = eyesHaveSameSelection();
		var same = ! different || samePowers;
		var perEye = different && ! samePowers;
		var leftPrice = getEyeFieldPrice( 'left' );
		var rightPrice = same ? leftPrice : getEyeFieldPrice( 'right' );
		var qty = Math.max( 1, parseInt( $( '#wc_optic_qty' ).val(), 10 ) || 1 );
		var qtyLeft = Math.max( 1, parseInt( $( '#wc_optic_qty_left' ).val(), 10 ) || 1 );
		var qtyRight = Math.max( 1, parseInt( $( '#wc_optic_qty_right' ).val(), 10 ) || 1 );
		var displayPrice = 0;
		var total = 0;

		if ( perEye ) {
			total = ( leftPrice * qtyLeft ) + ( rightPrice * qtyRight );
			displayPrice = leftPrice + rightPrice;
		} else if ( different && samePowers ) {
			total = leftPrice * ( qtyLeft + qtyRight );
			displayPrice = leftPrice;
		} else if ( same ) {
			total = leftPrice * qty;
			displayPrice = leftPrice;
		} else {
			total = ( leftPrice + rightPrice ) * qty;
			displayPrice = leftPrice + rightPrice;
		}

		return {
			displayPrice: displayPrice,
			total: total,
		};
	}

	function syncLineQuantity() {
		var perEye = isDifferentPowerMode();
		var q = 1;
		if ( perEye ) {
			var l = parseInt( $( '#wc_optic_qty_left' ).val(), 10 ) || 0;
			var r = parseInt( $( '#wc_optic_qty_right' ).val(), 10 ) || 0;
			q = Math.max( 1, l + r );
		} else {
			q = Math.max( 1, parseInt( $( '#wc_optic_qty' ).val(), 10 ) || 1 );
		}
		$( '#wc_optic_line_quantity' ).val( q );
		updateLineTotal();
		updateAddToCartState();
	}

	function applyMaxValue( $input, maxValue ) {
		if ( ! $input.length ) {
			return;
		}
		if ( maxValue === null || typeof maxValue === 'undefined' ) {
			$input.removeAttr( 'max' );
			return;
		}
		maxValue = Math.max( 1, parseInt( maxValue, 10 ) || 1 );
		$input.attr( 'max', maxValue );
		var current = parseInt( $input.val(), 10 ) || 1;
		if ( current > maxValue ) {
			$input.val( maxValue );
		}
	}

	function syncQuantityStockLimits() {
		var different = isDifferentPowerMode();
		var samePowers = eyesHaveSameSelection();
		var leftData = getEyeResolvedData( 'left' );
		var rightData = different && ! samePowers ? getEyeResolvedData( 'right' ) : leftData;

		if ( different && ! samePowers ) {
			applyMaxValue( $( '#wc_optic_qty_left' ), leftData.inStock ? leftData.stock : 0 );
			applyMaxValue( $( '#wc_optic_qty_right' ), rightData.inStock ? rightData.stock : 0 );
			$( '#wc_optic_qty' ).removeAttr( 'max' );
		} else if ( different && samePowers ) {
			var combinedMax = leftData.inStock && null !== leftData.stock ? leftData.stock : null;
			applyMaxValue( $( '#wc_optic_qty_left' ), combinedMax );
			applyMaxValue( $( '#wc_optic_qty_right' ), combinedMax );
			$( '#wc_optic_qty' ).removeAttr( 'max' );
		} else {
			applyMaxValue( $( '#wc_optic_qty' ), leftData.inStock ? leftData.stock : 0 );
			$( '#wc_optic_qty_left, #wc_optic_qty_right' ).removeAttr( 'max' );
		}
	}

	function updateLineTotal() {
		updatePriceDisplay();
	}

	function updateAddToCartState() {
		var $btn = $( 'form.wc-optic-cart-form .single_add_to_cart_button' );
		if ( ! $btn.length ) {
			return;
		}
		var different = isDifferentPowerMode();
		var samePowers = eyesHaveSameSelection();
		var leftOk = getEyeResolvedData( 'left' ).inStock && resolveChildForEye( 'left' );
		var rightOk = different && ! samePowers ? ( getEyeResolvedData( 'right' ).inStock && resolveChildForEye( 'right' ) ) : leftOk;
		var ok = leftOk && rightOk;
		$btn.prop( 'disabled', ! ok );
	}

	function toggleSamePower() {
		var different = isDifferentPowerMode();
		var same = ! different;
		var $right = $( '.wc-optic-eye--right' );
		var $both = $( '.wc-optic-title-both' );
		var $leftTitle = $( '.wc-optic-title-left' );
		var $singleQty = $( '.wc-optic-qty--single' );
		var $dualQty = $( '.wc-optic-qty--dual' );

		if ( same ) {
			$right.prop( 'hidden', true );
			$both.prop( 'hidden', false );
			$leftTitle.prop( 'hidden', true );
			$right.find( 'select.wc-optic-power-dropdown' ).prop( 'required', false );
			$singleQty.prop( 'hidden', false );
			$dualQty.prop( 'hidden', true );
			syncRightPowersFromLeft();
		} else {
			$right.prop( 'hidden', false );
			$both.prop( 'hidden', true );
			$leftTitle.prop( 'hidden', false );
			$right.find( 'select.wc-optic-power-dropdown' ).prop( 'required', true );
			$singleQty.prop( 'hidden', true );
			$dualQty.prop( 'hidden', false );
			initEyeCascade( 'right' );
			initPowerDropdowns( $right );
		}
		syncQuantityStockLimits();
		syncLineQuantity();
		updateAddToCartState();
	}

	function onPowerChange( eye, $select ) {
		var matrix = getMatrix();
		var powers = matrix.powers || [];
		var powerKey = $select.data( 'power' );
		var index = powers.indexOf( powerKey );
		if ( index < 0 ) {
			index = 0;
		}
		refreshEyeCascade( eye, index );
		if ( ! isDifferentPowerMode() && eye === 'left' ) {
			syncRightPowersFromLeft();
		}
		syncQuantityStockLimits();
		syncLineQuantity();
		updateLineTotal();
		updateAddToCartState();
	}

	$( function () {
		var $form = $( 'form.wc-optic-cart-form' );
		if ( ! $form.length ) {
			return;
		}

		initPowerDropdowns( $form );
		if ( supportsNoPowerMode() ) {
			togglePowerMode();
		} else {
			initEyeCascade( 'left' );
			toggleSamePower();
		}
		syncQuantityStockLimits();
		syncLineQuantity();
		updatePriceDisplay();
		updateAddToCartState();

		$( 'input[name="wc_optic_power_mode"]' ).on( 'change', togglePowerMode );
		$( '#wc_optic_different_power' ).on( 'change', toggleSamePower );
		$( '#wc_optic_qty, #wc_optic_qty_left, #wc_optic_qty_right' ).on( 'change input', syncLineQuantity );

		$form.on( 'change', 'select.wc-optic-power-dropdown', function () {
			var $select = $( this );
			var eye = $select.closest( '.wc-optic-eye' ).data( 'eye' ) || 'left';
			onPowerChange( eye, $select );
		} );

		$form.on( 'submit', function ( e ) {
			if ( isNoPowerMode() ) {
				syncNoPowerChildFields();
			} else if ( ! isDifferentPowerMode() ) {
				syncRightPowersFromLeft();
			}
			syncLineQuantity();

			var different = isDifferentPowerMode();
			var samePowers = eyesHaveSameSelection();
			var validateRight = different && ! samePowers;

			syncResolvedChildField( 'left', { showInvalidCombo: true } );
			if ( validateRight ) {
				syncResolvedChildField( 'right', { showInvalidCombo: true } );
			}

			updateAddToCartState();
			var leftOk = getEyeResolvedData( 'left' ).inStock && resolveChildForEye( 'left' );
			var rightOk = validateRight ? ( getEyeResolvedData( 'right' ).inStock && resolveChildForEye( 'right' ) ) : leftOk;
			if ( ! leftOk || ! rightOk ) {
				e.preventDefault();
			}
		} );
	} );
}( jQuery ) );
