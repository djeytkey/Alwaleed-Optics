( function ( $ ) {
	'use strict';

	function getAllowedPowers( division ) {
		if ( ! division || ! wcOpticConvert.divisionPowers || ! wcOpticConvert.divisionPowers[ division ] ) {
			return [];
		}
		return wcOpticConvert.divisionPowers[ division ];
	}

	function collectRanges( $root ) {
		var ranges = {};
		$root.find( '.wc-optic-power-range' ).each( function () {
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

	function collectIdentity( $root ) {
		var catalog = {};
		$root.find( '.wc-optic-identity-select' ).each( function () {
			var type = $( this ).data( 'optic-type' );
			if ( type ) {
				catalog[ type ] = $( this ).val() || '';
			}
		} );
		return catalog;
	}

	function applyDivisionRanges( division, $root ) {
		var allowed = getAllowedPowers( division );
		$root.find( '.wc-optic-power-range' ).each( function () {
			var $row = $( this );
			var power = $row.data( 'power' );
			var show = allowed.indexOf( power ) !== -1;
			$row.toggle( show );
			if ( show && ! $row.find( '.wc-optic-range-step' ).val() && wcOpticConvert.defaultSteps[ power ] ) {
				$row.find( '.wc-optic-range-step' ).val( wcOpticConvert.defaultSteps[ power ] );
			}
		} );
	}

	function applyTemplateRanges( template, $root ) {
		if ( ! template || ! template.ranges ) {
			return;
		}
		$.each( template.ranges, function ( power, range ) {
			var $row = $root.find( '.wc-optic-power-range[data-power="' + power + '"]' );
			if ( ! $row.length ) {
				return;
			}
			$row.find( '.wc-optic-range-from' ).val( range.from || '' );
			$row.find( '.wc-optic-range-to' ).val( range.to || '' );
			$row.find( '.wc-optic-range-step' ).val( range.step || '' );
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

	function refreshCount( $root, division ) {
		var $count = $root.find( '.wc-optic-range-count' );
		if ( ! $count.length ) {
			return;
		}
		if ( ! division ) {
			$count.text( '0' ).attr( 'data-count', '0' );
			return;
		}
		$.post(
			wcOpticConvert.ajaxUrl,
			{
				action: 'wc_optic_count_power_ranges',
				nonce: wcOpticConvert.nonce,
				division: division,
				ranges: collectRanges( $root ),
			},
			function ( res ) {
				if ( res && res.success && res.data ) {
					$count.text( String( res.data.count ) ).attr( 'data-count', String( res.data.count ) );
					return;
				}
				$count.text( '0' ).attr( 'data-count', '0' );
			}
		);
	}

	function initSelect2( $scope ) {
		$scope.find( 'select.wc-optic-select2' ).each( function () {
			var $el = $( this );
			if ( $el.hasClass( 'enhanced' ) ) {
				return;
			}
			$el.selectWoo( {
				width: '100%',
				minimumResultsForSearch: 0,
				allowClear: true,
				placeholder: $el.data( 'placeholder' ) || '',
			} ).addClass( 'enhanced' );
		} );
	}

	function selectedProductIds() {
		var ids = [];
		$( '.wc-optic-convert-product:checked' ).each( function () {
			ids.push( $( this ).val() );
		} );
		return ids;
	}

	function convertPayload() {
		return {
			nonce: wcOpticConvert.nonce,
			division: $( '#wc_optic_convert_division' ).val() || '',
			catalog: collectIdentity( $( '#wc-optic-convert-root' ) ),
			ranges: collectRanges( $( '#wc-optic-convert-root' ) ),
			template_id: $( '#wc_optic_convert_template' ).val() || '',
			stock_qty: $( '#wc_optic_convert_stock' ).val() || 0,
			replace: $( '#wc_optic_convert_replace' ).is( ':checked' ) ? 1 : 0,
			product_ids: selectedProductIds(),
		};
	}

	function appendLog( text ) {
		var $log = $( '#wc-optic-convert-log' );
		$log.append( $( '<p/>' ).text( text ) );
	}

	function runBatches( ids, args, offset ) {
		var size = wcOpticConvert.batchSize || 5;
		var chunk = ids.slice( offset, offset + size );
		if ( ! chunk.length ) {
			appendLog( wcOpticConvert.i18n.done );
			return;
		}
		var data = $.extend( {}, args, {
			action: 'wc_optic_run_convert_batch',
			product_ids: chunk,
		} );
		$.post( wcOpticConvert.ajaxUrl, data, function ( res ) {
			if ( ! res || ! res.success || ! res.data || ! res.data.results ) {
				appendLog( ( res && res.data && res.data.message ) || wcOpticConvert.i18n.runFailed );
				return;
			}
			$.each( res.data.results, function ( _, row ) {
				appendLog(
					'#' + row.product_id + ' — ' + ( row.status || '' ) + ( row.message ? ': ' + row.message : '' ) +
					( row.child_count ? ' (' + row.child_count + ')' : '' )
				);
			} );
			runBatches( ids, args, offset + size );
		} ).fail( function () {
			appendLog( wcOpticConvert.i18n.runFailed );
		} );
	}

	$( function () {
		var $root = $( '#wc-optic-convert-root' );
		if ( ! $root.length ) {
			return;
		}

		initSelect2( $root );

		$root.on( 'change', '#wc_optic_tpl_division, #wc_optic_convert_division', function () {
			var division = $( this ).val() || '';
			applyDivisionRanges( division, $root );
			refreshCount( $root, division );
		} );

		$root.on( 'input change', '.wc-optic-range-from, .wc-optic-range-to, .wc-optic-range-step', function () {
			var division = $( '#wc_optic_tpl_division' ).val() || $( '#wc_optic_convert_division' ).val() || '';
			refreshCount( $root, division );
		} );

		$root.on( 'change', '#wc_optic_convert_template', function () {
			var tpl = findTemplate( $( this ).val() );
			if ( ! tpl ) {
				return;
			}
			$( '#wc_optic_convert_division' ).val( tpl.division ).trigger( 'change' );
			applyTemplateRanges( tpl, $root );
			refreshCount( $root, tpl.division );
		} );

		$root.on( 'change', '#wc-optic-convert-select-all', function () {
			$( '.wc-optic-convert-product' ).prop( 'checked', $( this ).is( ':checked' ) );
		} );

		$root.on( 'submit', '#wc-optic-template-form', function ( e ) {
			e.preventDefault();
			$.post(
				wcOpticConvert.ajaxUrl,
				{
					action: 'wc_optic_save_power_template',
					nonce: wcOpticConvert.nonce,
					name: $( '#wc_optic_tpl_name' ).val() || '',
					division: $( '#wc_optic_tpl_division' ).val() || '',
					ranges: collectRanges( $root ),
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

		$root.on( 'click', '#wc-optic-convert-preview', function ( e ) {
			e.preventDefault();
			var payload = convertPayload();
			if ( ! payload.product_ids.length ) {
				window.alert( wcOpticConvert.i18n.selectProducts );
				return;
			}
			payload.action = 'wc_optic_preview_convert';
			$( '#wc-optic-convert-log' ).empty();
			$.post( wcOpticConvert.ajaxUrl, payload, function ( res ) {
				if ( ! res || ! res.success || ! res.data ) {
					appendLog( ( res && res.data && res.data.message ) || wcOpticConvert.i18n.previewFailed );
					return;
				}
				appendLog( ( res.data.per_product || 0 ) + ' × ' + payload.product_ids.length );
				$.each( res.data.products || [], function ( _, row ) {
					appendLog( ( row.name || row.id ) + ' — ' + row.status + ': ' + ( row.message || '' ) );
				} );
			} );
		} );

		$root.on( 'click', '#wc-optic-convert-run', function ( e ) {
			e.preventDefault();
			var payload = convertPayload();
			if ( ! payload.product_ids.length ) {
				window.alert( wcOpticConvert.i18n.selectProducts );
				return;
			}
			if ( payload.replace && ! window.confirm( wcOpticConvert.i18n.confirmReplace ) ) {
				return;
			}
			$( '#wc-optic-convert-log' ).empty();
			runBatches( payload.product_ids, payload, 0 );
		} );
	} );
}( jQuery ) );
