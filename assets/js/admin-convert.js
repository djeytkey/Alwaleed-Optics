( function ( $ ) {
	'use strict';

	var queue = [];
	var index = 0;
	var step = 1;
	var current = null;
	var converted = false;
	var modal = null;
	var $root = null;

	function getAllowedPowers( division ) {
		if ( ! division || ! wcOpticConvert.divisionPowers || ! wcOpticConvert.divisionPowers[ division ] ) {
			return [];
		}
		return wcOpticConvert.divisionPowers[ division ];
	}

	function collectRanges() {
		var ranges = {};
		$root.find( '#wc-optic-wizard-modal .wc-optic-power-range' ).each( function () {
			var $row = $( this );
			if ( $row.is( ':hidden' ) ) {
				return;
			}
			ranges[ $row.data( 'power' ) ] = {
				from: $row.find( '.wc-optic-range-from' ).val() || '',
				to: $row.find( '.wc-optic-range-to' ).val() || '',
				step: $row.find( '.wc-optic-range-step' ).val() || '',
			};
		} );
		return ranges;
	}

	function collectIdentity() {
		var catalog = {};
		$root.find( '#wc-optic-wizard-modal .wc-optic-identity-select' ).each( function () {
			var type = $( this ).data( 'optic-type' );
			if ( type ) {
				catalog[ type ] = $( this ).val() || '';
			}
		} );
		return catalog;
	}

	function applyDivisionRanges( division ) {
		var allowed = getAllowedPowers( division );
		$root.find( '#wc-optic-wizard-modal .wc-optic-power-range' ).each( function () {
			var $row = $( this );
			var power = $row.data( 'power' );
			var show = allowed.indexOf( power ) !== -1;
			$row.toggle( show );
			if ( show && ! $row.find( '.wc-optic-range-step' ).val() && wcOpticConvert.defaultSteps[ power ] ) {
				$row.find( '.wc-optic-range-step' ).val( wcOpticConvert.defaultSteps[ power ] );
			}
		} );
	}

	function findTemplate( id ) {
		var found = null;
		$.each( wcOpticConvert.templates || [], function ( _, tpl ) {
			if ( tpl.id === id ) {
				found = tpl;
			}
		} );
		return found;
	}

	function applyTemplateRanges( template ) {
		if ( ! template || ! template.ranges ) {
			return;
		}
		$.each( template.ranges, function ( power, range ) {
			var $row = $root.find( '#wc-optic-wizard-modal .wc-optic-power-range[data-power="' + power + '"]' );
			$row.find( '.wc-optic-range-from' ).val( range.from || '' );
			$row.find( '.wc-optic-range-to' ).val( range.to || '' );
			$row.find( '.wc-optic-range-step' ).val( range.step || '' );
		} );
	}

	function refreshCount() {
		var $count = $root.find( '#wc-optic-wizard-modal .wc-optic-range-count' );
		var division = $( '#wc_optic_wizard_division' ).val() || '';
		if ( ! $count.length || ! division ) {
			$count.text( '0' ).attr( 'data-count', '0' );
			return;
		}
		$.post(
			wcOpticConvert.ajaxUrl,
			{
				action: 'wc_optic_count_power_ranges',
				nonce: wcOpticConvert.nonce,
				division: division,
				ranges: collectRanges(),
			},
			function ( res ) {
				var n = res && res.success && res.data ? res.data.count : 0;
				$count.text( String( n ) ).attr( 'data-count', String( n ) );
			}
		);
	}

	function initSelect2( $scope ) {
		$scope.find( 'select.wc-optic-select2, select.wc-optic-wizard-select' ).each( function () {
			var $el = $( this );
			if ( $el.hasClass( 'enhanced' ) && $el.data( 'select2' ) ) {
				$el.selectWoo( 'destroy' );
				$el.removeClass( 'enhanced' );
			}
			$el.selectWoo( {
				width: '100%',
				minimumResultsForSearch: 0,
				allowClear: true,
				placeholder: $el.data( 'placeholder' ) || '',
				dropdownParent: $( '#wc-optic-wizard-modal' ),
			} ).addClass( 'enhanced' );
		} );
	}

	function selectedProductIds() {
		var ids = [];
		$( '.wc-optic-convert-product:checked' ).each( function () {
			if ( $( this ).closest( 'tr' ).is( ':visible' ) ) {
				ids.push( $( this ).val() );
			}
		} );
		return ids;
	}

	function sprintf( template ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		return String( template ).replace( /%(\d+)\$d/g, function ( _, n ) {
			return args[ parseInt( n, 10 ) - 1 ];
		} ).replace( '%d', args[ 0 ] );
	}

	function showAlert( message, success ) {
		var $alert = $( '#wc-optic-wizard-alert' );
		if ( ! message ) {
			$alert.attr( 'hidden', 'hidden' ).text( '' ).removeClass( 'is-success' );
			return;
		}
		$alert.text( message ).toggleClass( 'is-success', !! success ).removeAttr( 'hidden' );
	}

	function setStep( next ) {
		step = next;
		$root.find( '.wc-optic-wizard-pane' ).each( function () {
			var paneStep = parseInt( $( this ).data( 'step' ), 10 );
			$( this ).prop( 'hidden', paneStep !== step );
		} );
		$root.find( '.wc-optic-wizard-steps li' ).each( function ( i ) {
			var n = i + 1;
			$( this ).toggleClass( 'is-active', n === step );
			$( this ).toggleClass( 'is-done', n < step );
		} );
		$( '#wc-optic-wizard-bar' ).css( 'width', String( Math.round( ( step / 3 ) * 100 ) ) + '%' );
		$( '#wc-optic-wizard-back' ).prop( 'disabled', step === 1 && ! converted );
		updateNextLabel();
		if ( step === 2 || step === 3 ) {
			setTimeout( function () {
				initSelect2( $( '#wc-optic-wizard-modal' ) );
			}, 50 );
		}
		if ( step === 3 ) {
			applyDivisionRanges( $( '#wc_optic_wizard_division' ).val() || '' );
			refreshCount();
		}
	}

	function updateNextLabel() {
		var $next = $( '#wc-optic-wizard-next' );
		if ( converted && index >= queue.length - 1 ) {
			$next.text( wcOpticConvert.i18n.finish );
			return;
		}
		if ( converted ) {
			$next.text( wcOpticConvert.i18n.nextProduct );
			return;
		}
		if ( step === 3 ) {
			$next.text( wcOpticConvert.i18n.nextProduct );
			return;
		}
		$next.text( wcOpticConvert.i18n.nextStep );
	}

	function updateProgress() {
		$( '#wc-optic-wizard-progress' ).text(
			sprintf( wcOpticConvert.i18n.progress, index + 1, queue.length )
		);
	}

	function renderProductCard( data ) {
		var html = '';
		if ( data.image ) {
			html += '<img src="' + data.image + '" alt="" />';
		}
		html += '<div><strong>' + $( '<div/>' ).text( data.name || '' ).html() + '</strong>';
		if ( data.sku ) {
			html += '<span>SKU: ' + $( '<div/>' ).text( data.sku ).html() + '</span><br />';
		}
		if ( data.price_html ) {
			html += '<span>' + data.price_html + '</span>';
		}
		html += '</div>';
		$( '#wc-optic-wizard-product-card' ).html( html );
	}

	function fillIdentity( identity ) {
		$root.find( '#wc-optic-wizard-modal .wc-optic-identity-select' ).each( function () {
			var type = $( this ).data( 'optic-type' );
			var value = identity && identity[ type ] ? String( identity[ type ] ) : '';
			$( this ).val( value || '' );
		} );
	}

	function loadProduct( done ) {
		converted = false;
		showAlert( '' );
		$.post(
			wcOpticConvert.ajaxUrl,
			{
				action: 'wc_optic_wizard_product',
				nonce: wcOpticConvert.nonce,
				product_id: queue[ index ],
			},
			function ( res ) {
				if ( ! res || ! res.success || ! res.data ) {
					showAlert( ( res && res.data && res.data.message ) || wcOpticConvert.i18n.loadFailed );
					return;
				}
				current = res.data;
				renderProductCard( current );
				$( '#wc_optic_wizard_division' ).val( current.division || '' );
				$( '#wc_optic_wizard_price' ).val( current.price || '' );
				$( '#wc_optic_wizard_stock' ).val( '0' );
				$( '#wc_optic_wizard_replace' ).prop( 'checked', false );
				$( '#wc_optic_wizard_template' ).val( '' );
				fillIdentity( current.identity || {} );
				applyDivisionRanges( current.division || '' );
				updateProgress();
				setStep( 1 );
				setTimeout( function () {
					initSelect2( $( '#wc-optic-wizard-modal' ) );
				}, 80 );
				if ( typeof done === 'function' ) {
					done();
				}
			}
		).fail( function () {
			showAlert( wcOpticConvert.i18n.loadFailed );
		} );
	}

	function identityComplete() {
		var catalog = collectIdentity();
		var ok = true;
		$.each( catalog, function ( _, value ) {
			if ( ! value ) {
				ok = false;
			}
		} );
		return ok;
	}

	function rangesComplete() {
		var ranges = collectRanges();
		var keys = Object.keys( ranges );
		if ( ! keys.length ) {
			return false;
		}
		var ok = true;
		$.each( ranges, function ( _, range ) {
			if ( ! range.from || ! range.to || ! range.step ) {
				ok = false;
			}
		} );
		return ok;
	}

	function convertCurrent( onSuccess ) {
		if ( ! current ) {
			return;
		}
		if ( current.has_children && ! $( '#wc_optic_wizard_replace' ).is( ':checked' ) ) {
			if ( ! window.confirm( wcOpticConvert.i18n.confirmReplace ) ) {
				return;
			}
			$( '#wc_optic_wizard_replace' ).prop( 'checked', true );
		}

		$( '#wc-optic-wizard-next' ).prop( 'disabled', true );
		$.post(
			wcOpticConvert.ajaxUrl,
			{
				action: 'wc_optic_generate_product_children',
				nonce: wcOpticConvert.nonce,
				product_id: current.id,
				division: $( '#wc_optic_wizard_division' ).val() || '',
				catalog: collectIdentity(),
				ranges: collectRanges(),
				template_id: $( '#wc_optic_wizard_template' ).val() || '',
				unit_price: $( '#wc_optic_wizard_price' ).val() || '',
				stock_qty: $( '#wc_optic_wizard_stock' ).val() || 0,
				replace: $( '#wc_optic_wizard_replace' ).is( ':checked' ) ? 1 : 0,
			},
			function ( res ) {
				$( '#wc-optic-wizard-next' ).prop( 'disabled', false );
				if ( ! res || ! res.success || ! res.data ) {
					showAlert( ( res && res.data && res.data.message ) || wcOpticConvert.i18n.convertFailed );
					return;
				}
				converted = true;
				current.has_children = true;
				if ( res.data.skipped ) {
					showAlert( wcOpticConvert.i18n.skipped, true );
				} else {
					showAlert( sprintf( wcOpticConvert.i18n.converted, res.data.child_count || 0 ), true );
				}
				$( '.wc-optic-convert-product[value="' + current.id + '"]' ).closest( 'tr' ).addClass( 'wc-optic-converted-row' );
				updateNextLabel();
				if ( typeof onSuccess === 'function' ) {
					onSuccess();
				}
			}
		).fail( function () {
			$( '#wc-optic-wizard-next' ).prop( 'disabled', false );
			showAlert( wcOpticConvert.i18n.convertFailed );
		} );
	}

	function goNext() {
		showAlert( '' );
		if ( converted ) {
			if ( index >= queue.length - 1 ) {
				modal.hide();
				window.alert( wcOpticConvert.i18n.done );
				return;
			}
			index += 1;
			loadProduct();
			return;
		}

		if ( step === 1 ) {
			if ( ! $( '#wc_optic_wizard_division' ).val() ) {
				showAlert( wcOpticConvert.i18n.needDivision );
				return;
			}
			setStep( 2 );
			return;
		}

		if ( step === 2 ) {
			if ( ! identityComplete() ) {
				showAlert( wcOpticConvert.i18n.needIdentity );
				return;
			}
			setStep( 3 );
			return;
		}

		if ( step === 3 ) {
			if ( ! rangesComplete() ) {
				showAlert( wcOpticConvert.i18n.needRanges );
				return;
			}
			if ( ! $( '#wc_optic_wizard_price' ).val() ) {
				showAlert( wcOpticConvert.i18n.needPrice );
				return;
			}
			convertCurrent();
		}
	}

	function startWizard() {
		queue = selectedProductIds();
		if ( ! queue.length ) {
			window.alert( wcOpticConvert.i18n.selectProducts );
			return;
		}
		index = 0;
		if ( ! modal ) {
			modal = new window.bootstrap.Modal( document.getElementById( 'wc-optic-wizard-modal' ) );
		}
		modal.show();
		loadProduct();
	}

	$( function () {
		$root = $( '#wc-optic-convert-root' );
		if ( ! $root.length ) {
			return;
		}

		initSelect2( $root.find( '.wc-optic-template-form' ) );

		$root.on( 'input', '#wc-optic-convert-search', function () {
			var q = $.trim( $( this ).val() || '' ).toLowerCase();
			$( '.wc-optic-convert-row' ).each( function () {
				var hay = $( this ).data( 'search' ) || '';
				$( this ).toggle( ! q || hay.indexOf( q ) !== -1 );
			} );
		} );

		$root.on( 'change', '#wc-optic-convert-select-all', function () {
			$( '.wc-optic-convert-row:visible .wc-optic-convert-product' ).prop( 'checked', $( this ).is( ':checked' ) );
		} );

		$root.on( 'click', '#wc-optic-start-wizard', function ( e ) {
			e.preventDefault();
			startWizard();
		} );

		$root.on( 'click', '#wc-optic-wizard-next', function ( e ) {
			e.preventDefault();
			goNext();
		} );

		$root.on( 'click', '#wc-optic-wizard-back', function ( e ) {
			e.preventDefault();
			showAlert( '' );
			if ( converted ) {
				converted = false;
				setStep( 3 );
				return;
			}
			if ( step > 1 ) {
				setStep( step - 1 );
			}
		} );

		$root.on( 'click', '#wc-optic-wizard-cancel', function ( e ) {
			e.preventDefault();
			if ( window.confirm( wcOpticConvert.i18n.confirmClose ) ) {
				modal.hide();
			}
		} );

		$root.on( 'change', '#wc_optic_wizard_division', function () {
			applyDivisionRanges( $( this ).val() || '' );
			refreshCount();
		} );

		$root.on( 'change', '#wc_optic_wizard_template', function () {
			var tpl = findTemplate( $( this ).val() );
			if ( ! tpl ) {
				return;
			}
			if ( tpl.division ) {
				$( '#wc_optic_wizard_division' ).val( tpl.division );
			}
			applyTemplateRanges( tpl );
			applyDivisionRanges( $( '#wc_optic_wizard_division' ).val() || '' );
			refreshCount();
		} );

		$root.on( 'input change', '#wc-optic-wizard-modal .wc-optic-range-from, #wc-optic-wizard-modal .wc-optic-range-to, #wc-optic-wizard-modal .wc-optic-range-step', refreshCount );

		$root.on( 'submit', '#wc-optic-template-form', function ( e ) {
			e.preventDefault();
			$.post(
				wcOpticConvert.ajaxUrl,
				{
					action: 'wc_optic_save_power_template',
					nonce: wcOpticConvert.nonce,
					name: $( '#wc_optic_tpl_name' ).val() || '',
					division: $( '#wc_optic_tpl_division' ).val() || '',
					ranges: ( function () {
						var ranges = {};
						$root.find( '.wc-optic-tpl-ranges .wc-optic-power-range' ).each( function () {
							var $row = $( this );
							if ( $row.is( ':hidden' ) ) {
								return;
							}
							ranges[ $row.data( 'power' ) ] = {
								from: $row.find( '.wc-optic-range-from' ).val() || '',
								to: $row.find( '.wc-optic-range-to' ).val() || '',
								step: $row.find( '.wc-optic-range-step' ).val() || '',
							};
						} );
						return ranges;
					}() ),
				},
				function ( res ) {
					if ( ! res || ! res.success ) {
						window.alert( ( res && res.data && res.data.message ) || wcOpticConvert.i18n.saveFailed );
						return;
					}
					window.location.reload();
				}
			);
		} );

		$root.on( 'change', '#wc_optic_tpl_division', function () {
			var division = $( this ).val() || '';
			var allowed = getAllowedPowers( division );
			$root.find( '.wc-optic-tpl-ranges .wc-optic-power-range' ).each( function () {
				$( this ).toggle( allowed.indexOf( $( this ).data( 'power' ) ) !== -1 );
			} );
		} );

		$root.on( 'click', '.wc-optic-delete-template', function ( e ) {
			e.preventDefault();
			if ( ! window.confirm( wcOpticConvert.i18n.deleteConfirm ) ) {
				return;
			}
			var id = $( this ).closest( 'tr' ).data( 'template-id' );
			$.post(
				wcOpticConvert.ajaxUrl,
				{
					action: 'wc_optic_delete_power_template',
					nonce: wcOpticConvert.nonce,
					id: id,
				},
				function ( res ) {
					if ( res && res.success ) {
						window.location.reload();
					}
				}
			);
		} );
	} );
}( jQuery ) );
