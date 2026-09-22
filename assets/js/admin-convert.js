( function ( $ ) {
	'use strict';

	var queue = [];
	var index = 0;
	var step = 1;
	var current = null;
	var converted = false;
	var modal = null;
	var $root = null;
	var convertTable = null;
	var dtLang = {};
	var resetAllModal = null;

	/**
	 * Prefer WordPress admin global ajaxurl (same as heartbeat).
	 * Localized admin_url() can disagree with the browser origin on staging
	 * (scheme / host / leftover language prefix) and trigger redirect loops.
	 */
	function getAjaxUrl() {
		if ( typeof window.ajaxurl === 'string' && window.ajaxurl ) {
			return window.ajaxurl;
		}
		if ( wcOpticConvert && wcOpticConvert.ajaxUrl ) {
			return wcOpticConvert.ajaxUrl;
		}
		return '/wp-admin/admin-ajax.php';
	}

	function getAllowedPowers( division ) {
		if ( ! division || ! wcOpticConvert.divisionPowers || ! wcOpticConvert.divisionPowers[ division ] ) {
			return [];
		}
		return wcOpticConvert.divisionPowers[ division ];
	}

	function divisionShowsColor( division ) {
		if ( ! division || ! wcOpticConvert.divisionShowColor ) {
			return true;
		}
		if ( typeof wcOpticConvert.divisionShowColor[ division ] === 'undefined' ) {
			return true;
		}
		return !! wcOpticConvert.divisionShowColor[ division ];
	}

	function applyDivisionIdentityFields( division ) {
		var showColor = divisionShowsColor( division );
		var $color = $root.find( '#wc-optic-wizard-modal .wc-optic-identity-field--color' );
		$color.toggle( showColor );
		var $select = $color.find( '.wc-optic-identity-select' );
		setSelectRequired( $select, showColor );
		if ( ! showColor ) {
			$select.val( '' );
		}
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
		if ( $select.hasClass( 'enhanced' ) && $select.data( 'select2' ) ) {
			$select.selectWoo( 'destroy' );
			$select.removeClass( 'enhanced' );
			initSelect2( $select.closest( '#wc-optic-wizard-modal' ) );
		}
	}

	function getFilteredRows() {
		if ( convertTable ) {
			return convertTable.rows( { search: 'applied' } ).nodes().to$();
		}
		return $( '.wc-optic-convert-row' );
	}

	function initConvertDataTable() {
		if ( ! wcOpticConvert.convertTab ) {
			return;
		}

		var $table = $( '#wc-optic-convert-table' );
		if ( ! $table.length ) {
			return;
		}

		if ( ! $.fn.DataTable ) {
			return;
		}

		if ( $.fn.DataTable.isDataTable( $table[ 0 ] ) ) {
			return;
		}

		dtLang = wcOpticConvert.dt || {};
		var serverSide = !!wcOpticConvert.serverSideList;

		if ( serverSide ) {
			convertTable = $table.DataTable( {
				serverSide: true,
				processing: true,
				pageLength: 25,
				lengthMenu: [
					[ 10, 25, 50, 100 ],
					[ 10, 25, 50, 100 ],
				],
				language: dtLang,
				autoWidth: false,
				order: [ [ 0, 'asc' ] ],
				ajax: {
					url: getAjaxUrl(),
					type: 'POST',
					data: function ( d ) {
						d.action = 'wc_optic_convert_list_products';
						d.nonce = wcOpticConvert.nonce;
					},
				},
				columnDefs: [
					{ orderable: false, targets: [ 1, 2, 4 ] },
				],
			} );
		} else {
			if ( ! $table.find( 'tbody tr.wc-optic-convert-row' ).length ) {
				return;
			}
			convertTable = $table.DataTable( {
				pageLength: 25,
				lengthMenu: [
					[ 10, 25, 50, 100, -1 ],
					[ 10, 25, 50, 100, wcOpticConvert.i18n.allProducts || 'All' ],
				],
				language: dtLang,
				autoWidth: false,
				order: [ [ 0, 'asc' ] ],
				columnDefs: [
					{ orderable: false, targets: [ 2 ] },
				],
			} );
		}

		convertTable.on( 'draw', function () {
			$( '#wc-optic-convert-select-all' ).prop( 'checked', false );
		} );
	}

	function selectAllMatchingRows( checked ) {
		getFilteredRows().find( '.wc-optic-convert-product' ).prop( 'checked', checked );
		$( '#wc-optic-convert-select-all' ).prop( 'checked', checked );
	}

	function rangeFieldValue( raw ) {
		if ( raw === undefined || raw === null ) {
			return '';
		}
		return String( raw );
	}

	function rangeFieldFilled( raw ) {
		return rangeFieldValue( raw ).trim() !== '';
	}

	function normalizeSegmentsInput( raw ) {
		if ( ! raw ) {
			return [];
		}
		if ( $.isArray( raw ) ) {
			return raw;
		}
		if ( typeof raw === 'object' && ( raw.from !== undefined || raw.to !== undefined || raw.step !== undefined ) ) {
			return [ raw ];
		}
		var list = [];
		$.each( raw, function ( _, item ) {
			if ( item && typeof item === 'object' ) {
				list.push( item );
			}
		} );
		return list;
	}

	function collectRanges() {
		var ranges = {};
		var noPowerOnly = isSphNoPowerOnly();
		$root.find( '#wc-optic-wizard-modal .wc-optic-power-range' ).each( function () {
			var $group = $( this );
			var power = $group.data( 'power' );
			if ( noPowerOnly && power !== 'sph' ) {
				return;
			}
			if ( $group.is( ':hidden' ) ) {
				return;
			}
			var segments = [];
			$group.find( '.wc-optic-power-range__segment' ).each( function () {
				var $seg = $( this );
				var from = rangeFieldValue( $seg.find( '.wc-optic-range-from' ).val() );
				var to = rangeFieldValue( $seg.find( '.wc-optic-range-to' ).val() );
				var step = noPowerOnly && power === 'sph' ? '' : rangeFieldValue( $seg.find( '.wc-optic-range-step' ).val() );
				if ( ! rangeFieldFilled( from ) && ! rangeFieldFilled( to ) ) {
					return;
				}
				segments.push( {
					from: from,
					to: to,
					step: step,
				} );
			} );
			if ( segments.length ) {
				ranges[ power ] = segments;
			}
		} );
		return ranges;
	}

	function segmentIsZeroOnly( segment ) {
		if ( ! segment ) {
			return false;
		}
		var from = rangeFieldValue( segment.from ).trim();
		var to = rangeFieldValue( segment.to ).trim();
		if ( ! rangeFieldFilled( from ) || ! rangeFieldFilled( to ) ) {
			return false;
		}
		var fromN = parseFloat( from );
		var toN = parseFloat( to );
		if ( isNaN( fromN ) || isNaN( toN ) ) {
			return false;
		}
		return Math.abs( fromN ) < 0.0001 && Math.abs( toN ) < 0.0001;
	}

	function isSphNoPowerOnly() {
		var $group = $root.find( '#wc-optic-wizard-modal .wc-optic-power-range[data-power="sph"]' );
		if ( ! $group.length || $group.is( ':hidden' ) ) {
			return false;
		}
		var segments = [];
		$group.find( '.wc-optic-power-range__segment' ).each( function () {
			var $seg = $( this );
			var from = rangeFieldValue( $seg.find( '.wc-optic-range-from' ).val() );
			var to = rangeFieldValue( $seg.find( '.wc-optic-range-to' ).val() );
			if ( ! rangeFieldFilled( from ) && ! rangeFieldFilled( to ) ) {
				return;
			}
			segments.push( { from: from, to: to } );
		} );
		if ( ! segments.length ) {
			return false;
		}
		var allZero = true;
		$.each( segments, function ( _, segment ) {
			if ( ! segmentIsZeroOnly( segment ) ) {
				allZero = false;
				return false;
			}
		} );
		return allZero;
	}

	function reindexRangeSegments( $group ) {
		var prefix = $group.closest( '.wc-optic-power-ranges' ).data( 'name-prefix' ) || 'wizard_ranges';
		var power = $group.data( 'power' );
		var $segments = $group.find( '.wc-optic-power-range__segment' );
		var multi = $segments.length > 1;
		$segments.each( function ( index ) {
			var $seg = $( this );
			$seg.attr( 'data-segment-index', String( index ) );
			$seg.find( '.wc-optic-range-from' ).attr( 'name', prefix + '[' + power + '][' + index + '][from]' );
			$seg.find( '.wc-optic-range-to' ).attr( 'name', prefix + '[' + power + '][' + index + '][to]' );
			$seg.find( '.wc-optic-range-step' ).attr( 'name', prefix + '[' + power + '][' + index + '][step]' );
			$seg.find( '.wc-optic-remove-range-segment' ).prop( 'hidden', ! multi );
		} );
	}

	function defaultStepForPower( power ) {
		return ( wcOpticConvert.defaultSteps && wcOpticConvert.defaultSteps[ power ] ) || '0.25';
	}

	function buildRangeSegmentHtml( power, index, segment, canRemove, $context ) {
		var prefix = 'wizard_ranges';
		var $wrapper = null;
		if ( $context && $context.length ) {
			$wrapper = $context.closest( '.wc-optic-power-ranges' );
		}
		if ( ! $wrapper || ! $wrapper.length ) {
			$wrapper = $root.find( '#wc-optic-wizard-modal .wc-optic-power-ranges' );
		}
		if ( $wrapper.length && $wrapper.data( 'name-prefix' ) ) {
			prefix = $wrapper.data( 'name-prefix' );
		}
		segment = segment || {};
		var from = rangeFieldValue( segment.from );
		var to = rangeFieldValue( segment.to );
		var step = rangeFieldValue( segment.step );
		if ( ! rangeFieldFilled( step ) ) {
			step = defaultStepForPower( power === 'shared' ? 'sph' : power );
		}
		var i18n = wcOpticConvert.i18n || {};
		var labelFrom = i18n.rangeFrom || 'From';
		var labelTo = i18n.rangeTo || 'To';
		var labelStep = i18n.rangeStep || 'Step';
		var labelRemove = i18n.removeRange || 'Remove range';
		var base = prefix + '[' + power + '][' + index + ']';
		var html = '<div class="wc-optic-power-range__segment" data-segment-index="' + index + '">';
		html += '<div class="wc-optic-power-range__grid">';
		html += '<label class="wc-optic-power-range__field"><span>' + $( '<div/>' ).text( labelFrom ).html() + '</span>';
		html += '<input type="text" name="' + base + '[from]" value="' + $( '<div/>' ).text( from ).html() + '" class="wc-optic-range-from" /></label>';
		html += '<label class="wc-optic-power-range__field"><span>' + $( '<div/>' ).text( labelTo ).html() + '</span>';
		html += '<input type="text" name="' + base + '[to]" value="' + $( '<div/>' ).text( to ).html() + '" class="wc-optic-range-to" /></label>';
		html += '<label class="wc-optic-power-range__field"><span>' + $( '<div/>' ).text( labelStep ).html() + '</span>';
		html += '<input type="text" name="' + base + '[step]" value="' + $( '<div/>' ).text( step ).html() + '" class="wc-optic-range-step" /></label>';
		html += '</div>';
		html += '<button type="button" class="button-link-delete wc-optic-remove-range-segment"' + ( canRemove ? '' : ' hidden' ) + ' aria-label="' + $( '<div/>' ).text( labelRemove ).html() + '">&times;</button>';
		html += '</div>';
		return html;
	}

	function setPowerSegments( power, segments ) {
		var $group = $root.find( '#wc-optic-wizard-modal .wc-optic-power-range[data-power="' + power + '"]' );
		if ( ! $group.length ) {
			return;
		}
		segments = normalizeSegmentsInput( segments );
		if ( ! segments.length ) {
			segments = [ { from: '', to: '', step: defaultStepForPower( power ) } ];
		}
		var $wrap = $group.find( '.wc-optic-power-range__segments' );
		$wrap.empty();
		$.each( segments, function ( index, segment ) {
			$wrap.append( buildRangeSegmentHtml( power, index, segment, segments.length > 1 ) );
		} );
		reindexRangeSegments( $group );
	}

	function applyNoPowerRangeUi() {
		var noPower = isSphNoPowerOnly();
		var division = $( '#wc_optic_wizard_division' ).val() || '';
		var allowed = getAllowedPowers( division );
		$root.find( '#wc-optic-wizard-modal .wc-optic-power-range' ).each( function () {
			var $group = $( this );
			var power = $group.data( 'power' );
			if ( power === 'sph' ) {
				$group.find( '.wc-optic-range-step' ).closest( '.wc-optic-power-range__field' ).toggle( ! noPower );
				$group.find( '.wc-optic-add-range-segment' ).toggle( ! noPower );
				return;
			}
			var show = allowed.indexOf( power ) !== -1 && ! noPower;
			$group.toggle( show );
		} );
		$root.find( '#wc-optic-wizard-modal .wc-optic-wizard-power-template' ).each( function () {
			var $row = $( this );
			var power = $row.data( 'power' );
			var show = allowed.indexOf( power ) !== -1 && ( power === 'sph' || ! noPower );
			$row.toggle( show );
			if ( ! show ) {
				$row.find( '.wc-optic-wizard-tpl-select' ).val( '' );
			}
		} );
		var $note = $( '#wc-optic-wizard-nopower-note' );
		if ( $note.length ) {
			if ( noPower ) {
				$note.removeAttr( 'hidden' );
			} else {
				$note.attr( 'hidden', 'hidden' );
			}
		}
	}

	function collectIdentity() {
		if ( isSpecificsMode() && current && current.identity ) {
			var locked = {};
			$.each( current.identity, function ( type, value ) {
				locked[ type ] = String( value || '' );
			} );
			return locked;
		}

		var catalog = {};
		var division = $( '#wc_optic_wizard_division' ).val() || '';
		$root.find( '#wc-optic-wizard-modal .wc-optic-identity-select' ).each( function () {
			var type = $( this ).data( 'optic-type' );
			if ( type === 'color' && ! divisionShowsColor( division ) ) {
				return;
			}
			if ( ! type ) {
				return;
			}
			var val = $( this ).val();
			if ( type === 'color' && $( this ).prop( 'multiple' ) ) {
				catalog[ type ] = $.isArray( val ) ? val.filter( Boolean ) : val ? [ val ] : [];
			} else {
				catalog[ type ] = val || '';
			}
		} );
		return catalog;
	}

	function selectedColorCount() {
		var division = $( '#wc_optic_wizard_division' ).val() || '';
		if ( ! divisionShowsColor( division ) ) {
			return 1;
		}
		var $color = $root.find( '#wc-optic-wizard-modal .wc-optic-identity-select[data-optic-type="color"]' );
		if ( ! $color.length ) {
			return 1;
		}
		var val = $color.val();
		if ( $.isArray( val ) ) {
			return Math.max( 0, val.filter( Boolean ).length );
		}
		return val ? 1 : 0;
	}

	function refreshCount() {
		var $count = $root.find( '#wc-optic-wizard-modal .wc-optic-range-count' );
		var division = $( '#wc_optic_wizard_division' ).val() || '';
		if ( ! $count.length || ! division ) {
			$count.text( '0' ).attr( 'data-count', '0' );
			return;
		}
		var colorCount = selectedColorCount();
		$.post(
			getAjaxUrl(),
			{
				action: 'wc_optic_count_power_ranges',
				nonce: wcOpticConvert.nonce,
				division: division,
				ranges: collectRanges(),
				color_count: colorCount > 0 ? colorCount : 1,
			},
			function ( res ) {
				var n = res && res.success && res.data ? res.data.count : 0;
				$count.text( String( n ) ).attr( 'data-count', String( n ) );
			}
		);
	}

	function applyDivisionRanges( division ) {
		var allowed = getAllowedPowers( division );
		$root.find( '#wc-optic-wizard-modal .wc-optic-power-range' ).each( function () {
			var $group = $( this );
			var power = $group.data( 'power' );
			var show = allowed.indexOf( power ) !== -1;
			$group.toggle( show );
			if ( show ) {
				$group.find( '.wc-optic-power-range__segment' ).each( function () {
					var $step = $( this ).find( '.wc-optic-range-step' );
					if ( ! $step.val() && wcOpticConvert.defaultSteps[ power ] ) {
						$step.val( wcOpticConvert.defaultSteps[ power ] );
					}
				} );
			}
		} );
		syncWizardTemplatePickers( division );
		applyNoPowerRangeUi();
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

	function segmentIsBlank( segment ) {
		if ( ! segment ) {
			return true;
		}
		return ! rangeFieldFilled( segment.from ) && ! rangeFieldFilled( segment.to );
	}

	function collectPowerSegmentsFromUi( power ) {
		var segments = [];
		var $group = $root.find( '#wc-optic-wizard-modal .wc-optic-power-range[data-power="' + power + '"]' );
		$group.find( '.wc-optic-power-range__segment' ).each( function () {
			var $seg = $( this );
			segments.push( {
				from: rangeFieldValue( $seg.find( '.wc-optic-range-from' ).val() ),
				to: rangeFieldValue( $seg.find( '.wc-optic-range-to' ).val() ),
				step: rangeFieldValue( $seg.find( '.wc-optic-range-step' ).val() ),
			} );
		} );
		return segments;
	}

	function appendPowerSegments( power, segments ) {
		var current = collectPowerSegmentsFromUi( power ).filter( function ( segment ) {
			return ! segmentIsBlank( segment );
		} );
		var toAdd = normalizeSegmentsInput( segments ).filter( function ( segment ) {
			return ! segmentIsBlank( segment );
		} );
		setPowerSegments( power, current.concat( toAdd ) );
	}

	function resetWizardTemplatePickers() {
		$root.find( '#wc-optic-wizard-modal .wc-optic-wizard-tpl-select' ).val( '' );
	}

	function syncWizardTemplatePickers( division ) {
		var allowed = getAllowedPowers( division || '' );
		$root.find( '#wc-optic-wizard-modal .wc-optic-wizard-power-template' ).each( function () {
			var $row = $( this );
			var power = $row.data( 'power' );
			var show = allowed.indexOf( power ) !== -1;
			$row.toggle( show );
			if ( ! show ) {
				$row.find( '.wc-optic-wizard-tpl-select' ).val( '' );
			}
		} );
	}

	function fillRanges( ranges ) {
		if ( ! ranges ) {
			return;
		}
		$.each( ranges, function ( power, range ) {
			setPowerSegments( power, normalizeSegmentsInput( range ) );
		} );
	}


	function isReplaceChecked() {
		if ( isSpecificsMode() ) {
			return false;
		}
		if ( $( '#wc_optic_wizard_replace_forced' ).length && $( '#wc_optic_wizard_replace_forced' ).val() === '1' ) {
			return true;
		}
		return $( '#wc_optic_wizard_replace' ).is( ':checked' );
	}

	function initSelect2( $scope ) {
		$scope.find( 'select.wc-optic-select2, select.wc-optic-wizard-select' ).each( function () {
			var $el = $( this );
			var args;
			if ( $el.hasClass( 'enhanced' ) && $el.data( 'select2' ) ) {
				$el.selectWoo( 'destroy' );
				$el.removeClass( 'enhanced' );
			}
			// Append dropdown to <body> when inside the wizard modal so it is not
			// clipped by .modal-dialog-scrollable overflow and is not stretched by
			// modal width rules (dropdownParent on #modal broke alignment).
			args = {
				width: '100%',
				minimumResultsForSearch: 0,
				placeholder: $el.data( 'placeholder' ) || '',
				allowClear: ! $el.prop( 'multiple' ),
			};
			if ( $el.closest( '#wc-optic-wizard-modal' ).length ) {
				args.dropdownParent = $( document.body );
			}
			$el.selectWoo( args ).addClass( 'enhanced' );
			if ( $el.closest( '#wc-optic-wizard-modal' ).length ) {
				$el.off( 'select2:open.wcOpticWizard' ).on( 'select2:open.wcOpticWizard', function () {
					setTimeout( function () {
						$( '.select2-container--open .select2-search__field' ).trigger( 'focus' );
					}, 0 );
				} );
			}
		} );
	}

	// Bootstrap modal focus trap blocks keyboard input on Select2 dropdowns appended to <body>.
	function enableWizardSelect2Focus() {
		$( document ).on( 'focusin.wcOpticWizardSelect2', function ( e ) {
			if ( $( e.target ).closest( '.select2-container' ).length ) {
				e.stopImmediatePropagation();
			}
		} );
	}

	function disableWizardSelect2Focus() {
		$( document ).off( 'focusin.wcOpticWizardSelect2' );
	}

	function selectedProductIds() {
		var ids = [];
		$( '.wc-optic-convert-product:checked' ).each( function () {
			ids.push( $( this ).val() );
		} );
		return ids;
	}

	function sprintf( template ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		return String( template ).replace( /%(\d+)\$d/g, function ( _, n ) {
			return args[ parseInt( n, 10 ) - 1 ];
		} ).replace( /%d/g, function () {
			return args.shift();
		} );
	}

	function showAlert( message, success ) {
		var $alert = $( '#wc-optic-wizard-alert' );
		if ( ! message ) {
			$alert.attr( 'hidden', 'hidden' ).text( '' ).removeClass( 'is-success' );
			return;
		}
		$alert.text( message ).toggleClass( 'is-success', !! success ).removeAttr( 'hidden' );
	}

	function isRebuildMode() {
		return !!( wcOpticConvert && wcOpticConvert.rebuildMode );
	}

	function isSpecificsMode() {
		return !!( wcOpticConvert && wcOpticConvert.specificsMode );
	}

	function wizardStepCount() {
		return isSpecificsMode() ? 2 : 3;
	}

	function wizardProgressStep( logicalStep ) {
		if ( ! isSpecificsMode() ) {
			return logicalStep;
		}
		return logicalStep === 3 ? 2 : 1;
	}

	function nextWizardStep( logicalStep ) {
		if ( isSpecificsMode() && logicalStep === 1 ) {
			return 3;
		}
		return logicalStep + 1;
	}

	function prevWizardStep( logicalStep ) {
		if ( isSpecificsMode() && logicalStep === 3 ) {
			return 1;
		}
		return logicalStep - 1;
	}

	function wizardDivisionValue() {
		return $( '#wc_optic_wizard_division' ).val() || '';
	}

	function prepareSpecificsRanges( ranges ) {
		var prepared = ranges ? $.extend( true, {}, ranges ) : {};
		// Prefill CYL/AXIS/ADD from the product; leave SPH empty so the operator sets extras (e.g. 0.00).
		if ( prepared.sph ) {
			var sphSeg = normalizeSegmentsInput( prepared.sph );
			var step = defaultStepForPower( 'sph' );
			if ( sphSeg.length && rangeFieldFilled( sphSeg[0].step ) ) {
				step = rangeFieldValue( sphSeg[0].step );
			}
			prepared.sph = [ { from: '', to: '', step: step } ];
		}
		return prepared;
	}

	function setStep( next ) {
		step = next;
		$root.find( '.wc-optic-wizard-pane' ).each( function () {
			var paneStep = parseInt( $( this ).data( 'step' ), 10 );
			$( this ).prop( 'hidden', paneStep !== step );
		} );
		$root.find( '.wc-optic-wizard-steps li' ).each( function () {
			var logical = parseInt( $( this ).attr( 'data-logical-step' ), 10 );
			if ( ! logical ) {
				return;
			}
			$( this ).toggleClass( 'is-active', logical === step );
			$( this ).toggleClass( 'is-done', logical < step );
		} );
		$( '#wc-optic-wizard-bar' ).css(
			'width',
			String( Math.round( ( wizardProgressStep( step ) / wizardStepCount() ) * 100 ) ) + '%'
		);
		$( '#wc-optic-wizard-back' ).prop( 'disabled', step === 1 && ! converted );
		updateNextLabel();
		if ( step === 2 || step === 3 ) {
			setTimeout( function () {
				initSelect2( $( '#wc-optic-wizard-modal' ) );
				if ( step === 2 && ! isSpecificsMode() ) {
					applyDivisionIdentityFields( $( '#wc_optic_wizard_division' ).val() || '' );
				}
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

	function parseAjaxErrorMessage( xhr, fallback ) {
		if ( xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) {
			return xhr.responseJSON.data.message;
		}
		if ( xhr && xhr.responseText ) {
			try {
				var parsed = JSON.parse( xhr.responseText );
				if ( parsed && parsed.data && parsed.data.message ) {
					return parsed.data.message;
				}
			} catch ( e ) {
				// ignore
			}
			if ( xhr.responseText === '-1' || xhr.responseText === '0' ) {
				return fallback;
			}
		}
		return fallback;
	}

	function setWizardLoading( loading ) {
		var $modal = $( '#wc-optic-wizard-modal' );
		var $loader = $( '#wc-optic-wizard-loading' );
		var label = ( wcOpticConvert.i18n && wcOpticConvert.i18n.loadingProduct ) || 'Loading product…';

		$modal.toggleClass( 'is-loading', !! loading );
		if ( loading ) {
			$loader.find( '.wc-optic-wizard-loading__text' ).text( label );
			$loader.removeAttr( 'hidden' );
			$( '#wc-optic-wizard-product-card' ).empty();
			$( '#wc_optic_wizard_division' ).val( '' );
			$( '#wc-optic-wizard-next, #wc-optic-wizard-back' ).prop( 'disabled', true );
			return;
		}

		$loader.attr( 'hidden', 'hidden' );
		$( '#wc-optic-wizard-next' ).prop( 'disabled', ! current );
		$( '#wc-optic-wizard-back' ).prop( 'disabled', step === 1 && ! converted );
	}

	function loadProduct( done ) {
		converted = false;
		current = null;
		showAlert( '' );
		var productId = parseInt( queue[ index ], 10 ) || 0;
		if ( productId < 1 ) {
			setWizardLoading( false );
			showAlert( wcOpticConvert.i18n.loadFailed );
			return;
		}
		setWizardLoading( true );
		setStep( 1 );
		$.post(
			getAjaxUrl(),
			{
				action: 'wc_optic_wizard_product',
				nonce: wcOpticConvert.nonce,
				product_id: productId,
			},
			function ( res ) {
				if ( ! res || ! res.success || ! res.data ) {
					setWizardLoading( false );
					showAlert( ( res && res.data && res.data.message ) || wcOpticConvert.i18n.loadFailed );
					return;
				}
				current = res.data;
				renderProductCard( current );
				$( '#wc_optic_wizard_division' ).val( current.division || '' );
				$( '#wc_optic_wizard_price' ).val( current.price || '' );
				$( '#wc_optic_wizard_sale_price' ).val( current.sale_price || '' );
				$( '#wc_optic_wizard_stock' ).val( '0' );
				if ( isSpecificsMode() ) {
					$( '#wc-optic-wizard-title' ).text( wcOpticConvert.i18n.wizardSpecifics || 'Add specifics' );
				} else if ( isRebuildMode() ) {
					$( '#wc_optic_wizard_replace' ).prop( 'checked', true );
					$( '#wc-optic-wizard-title' ).text( wcOpticConvert.i18n.wizardRebuild || 'Rebuild product' );
				} else {
					$( '#wc_optic_wizard_replace' ).prop( 'checked', false );
					$( '#wc-optic-wizard-title' ).text( wcOpticConvert.i18n.wizardConvert || 'Convert product' );
				}
				resetWizardTemplatePickers();
				fillIdentity( current.identity || {} );
				applyDivisionRanges( current.division || '' );
				applyDivisionIdentityFields( current.division || '' );
				if ( isSpecificsMode() ) {
					fillRanges( prepareSpecificsRanges( current.ranges || {} ) );
				} else if ( current.ranges ) {
					fillRanges( current.ranges );
				}
				applyNoPowerRangeUi();
				updateProgress();
				setStep( 1 );
				setWizardLoading( false );
				setTimeout( function () {
					initSelect2( $( '#wc-optic-wizard-modal' ) );
				}, 80 );
				if ( typeof done === 'function' ) {
					done();
				}
			}
		).fail( function ( xhr ) {
			setWizardLoading( false );
			showAlert( parseAjaxErrorMessage( xhr, wcOpticConvert.i18n.loadFailed ) );
		} );
	}

	function identityComplete() {
		var catalog = collectIdentity();
		var division = $( '#wc_optic_wizard_division' ).val() || '';
		var ok = true;
		$.each( catalog, function ( type, value ) {
			if ( type === 'color' && ! divisionShowsColor( division ) ) {
				return;
			}
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
		var noPowerOnly = isSphNoPowerOnly();
		var ok = true;
		$.each( ranges, function ( power, segments ) {
			segments = normalizeSegmentsInput( segments );
			if ( ! segments.length ) {
				ok = false;
				return false;
			}
			$.each( segments, function ( _, range ) {
				if ( ! rangeFieldFilled( range.from ) || ! rangeFieldFilled( range.to ) ) {
					ok = false;
					return false;
				}
				if ( noPowerOnly && power === 'sph' ) {
					return;
				}
				if ( ! rangeFieldFilled( range.step ) ) {
					ok = false;
					return false;
				}
			} );
			if ( ! ok ) {
				return false;
			}
		} );
		return ok;
	}

	function convertCurrent( onSuccess ) {
		if ( ! current ) {
			return;
		}
		if ( isSpecificsMode() ) {
			if ( ! window.confirm( wcOpticConvert.i18n.confirmSpecifics ) ) {
				return;
			}
		} else if ( isRebuildMode() ) {
			if ( ! window.confirm( wcOpticConvert.i18n.confirmRebuild || wcOpticConvert.i18n.confirmReplace ) ) {
				return;
			}
		} else if ( current.has_children && ! isReplaceChecked() ) {
			if ( ! window.confirm( wcOpticConvert.i18n.confirmReplace ) ) {
				return;
			}
			$( '#wc_optic_wizard_replace' ).prop( 'checked', true );
		}

		var payload = {
			action: 'wc_optic_generate_product_children',
			nonce: wcOpticConvert.nonce,
			product_id: current.id,
			division: wizardDivisionValue() || current.division || '',
			catalog: collectIdentity(),
			ranges: collectRanges(),
			unit_price: $( '#wc_optic_wizard_price' ).val() || '',
			sale_price: $( '#wc_optic_wizard_sale_price' ).val() || '',
			stock_qty: $( '#wc_optic_wizard_stock' ).val() || 0,
		};
		if ( isSpecificsMode() ) {
			payload.mode = 'append';
			payload.replace = 0;
		} else {
			payload.replace = isReplaceChecked() || isRebuildMode() ? 1 : 0;
		}

		$( '#wc-optic-wizard-next' ).prop( 'disabled', true );
		$.post(
			getAjaxUrl(),
			payload,
			function ( res ) {
				$( '#wc-optic-wizard-next' ).prop( 'disabled', false );
				if ( ! res || ! res.success || ! res.data ) {
					showAlert( ( res && res.data && res.data.message ) || wcOpticConvert.i18n.convertFailed );
					return;
				}
				converted = true;
				current.has_children = true;
				current.child_count = res.data.child_count || 0;
				current.division = wizardDivisionValue() || current.division;
				if ( res.data.skipped ) {
					showAlert( wcOpticConvert.i18n.skipped, true );
				} else if ( isSpecificsMode() ) {
					showAlert(
						sprintf(
							wcOpticConvert.i18n.specificsAdded || 'Added %1$d internals (%2$d duplicates skipped). Total: %3$d.',
							res.data.added || 0,
							res.data.skipped_duplicates || 0,
							res.data.child_count || 0
						),
						true
					);
				} else {
					var msgTpl = isRebuildMode()
						? ( wcOpticConvert.i18n.rebuilt || wcOpticConvert.i18n.converted )
						: wcOpticConvert.i18n.converted;
					showAlert( sprintf( msgTpl, res.data.child_count || 0 ), true );
				}
				var $row = $( '.wc-optic-convert-product[value="' + current.id + '"]' ).closest( 'tr' );
				$row.addClass( 'wc-optic-converted-row' );
				if ( $row.find( '.wc-optic-convert-child-count' ).length ) {
					$row.find( '.wc-optic-convert-child-count' ).text( String( current.child_count ) ).attr( 'data-order', String( current.child_count ) );
				}
				if ( $row.find( '.wc-optic-convert-division' ).length && ! isSpecificsMode() ) {
					var divLabel = $( '#wc_optic_wizard_division option:selected' ).text() || current.division || '';
					$row.find( '.wc-optic-convert-division' ).text( divLabel ).attr( 'data-division', current.division || '' );
				}
				if ( convertTable ) {
					if ( wcOpticConvert.serverSideList && convertTable.ajax && convertTable.ajax.reload ) {
						convertTable.ajax.reload( null, false );
					} else {
						convertTable.row( $row ).invalidate().draw( false );
					}
				}
				updateNextLabel();
				if ( typeof onSuccess === 'function' ) {
					onSuccess();
				}
			}
		).fail( function ( xhr ) {
			$( '#wc-optic-wizard-next' ).prop( 'disabled', false );
			var message = wcOpticConvert.i18n.convertFailed;
			if ( xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) {
				message = xhr.responseJSON.data.message;
			}
			showAlert( message );
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
			setStep( nextWizardStep( 1 ) );
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
			modal = new window.bootstrap.Modal( document.getElementById( 'wc-optic-wizard-modal' ), {
				focus: false,
			} );
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

		if ( wcOpticConvert.convertTab ) {
			initConvertDataTable();
		}

		$root.on( 'change', '#wc-optic-convert-select-all', function () {
			selectAllMatchingRows( $( this ).is( ':checked' ) );
		} );

		$root.on( 'change', '.wc-optic-convert-product', function () {
			var $filtered = getFilteredRows();
			var $checked = $filtered.find( '.wc-optic-convert-product:checked' );
			var allChecked = $filtered.length > 0 && $checked.length === $filtered.find( '.wc-optic-convert-product' ).length;
			$( '#wc-optic-convert-select-all' ).prop( 'checked', allChecked );
		} );

		$root.on( 'click', '#wc-optic-start-wizard', function ( e ) {
			e.preventDefault();
			startWizard();
		} );

		$( '#wc-optic-wizard-modal' ).on( 'shown.bs.modal', enableWizardSelect2Focus );
		$( '#wc-optic-wizard-modal' ).on( 'hidden.bs.modal', disableWizardSelect2Focus );

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
				setStep( prevWizardStep( step ) );
			}
		} );

		$root.on( 'click', '#wc-optic-wizard-cancel', function ( e ) {
			e.preventDefault();
			if ( window.confirm( wcOpticConvert.i18n.confirmClose ) ) {
				modal.hide();
			}
		} );

		$root.on( 'change', '#wc_optic_wizard_division', function () {
			var division = $( this ).val() || '';
			applyDivisionRanges( division );
			applyDivisionIdentityFields( division );
			refreshCount();
		} );

		$root.on( 'change', '.wc-optic-wizard-tpl-select', function () {
			var $select = $( this );
			var power = $select.data( 'power' );
			var tpl = findTemplate( $select.val() );
			if ( ! tpl || ! tpl.segments ) {
				return;
			}
			appendPowerSegments( power, tpl.segments );
			$select.val( '' );
			applyNoPowerRangeUi();
			refreshCount();
		} );

		$root.on( 'input change', '#wc-optic-wizard-modal .wc-optic-range-from, #wc-optic-wizard-modal .wc-optic-range-to, #wc-optic-wizard-modal .wc-optic-range-step', function () {
			applyNoPowerRangeUi();
			refreshCount();
		} );
		$root.on( 'change', '#wc-optic-wizard-modal .wc-optic-identity-select[data-optic-type="color"]', function () {
			refreshCount();
		} );

		$root.on( 'click', '.wc-optic-add-range-segment', function ( e ) {
			e.preventDefault();
			var $group = $( this ).closest( '.wc-optic-power-range' );
			if ( $group.closest( '.wc-optic-tpl-ranges' ).length ) {
				return;
			}
			var power = $group.data( 'power' );
			var $wrap = $group.find( '.wc-optic-power-range__segments' );
			var index = $wrap.find( '.wc-optic-power-range__segment' ).length;
			$wrap.append( buildRangeSegmentHtml( power, index, { from: '', to: '', step: defaultStepForPower( power === 'shared' ? 'sph' : power ) }, true, $group ) );
			reindexRangeSegments( $group );
			applyNoPowerRangeUi();
			refreshCount();
		} );

		$root.on( 'click', '.wc-optic-remove-range-segment', function ( e ) {
			e.preventDefault();
			var $group = $( this ).closest( '.wc-optic-power-range' );
			var $seg = $( this ).closest( '.wc-optic-power-range__segment' );
			if ( $group.find( '.wc-optic-power-range__segment' ).length < 2 ) {
				return;
			}
			$seg.remove();
			reindexRangeSegments( $group );
			applyNoPowerRangeUi();
			refreshCount();
		} );

		$root.on( 'submit', '#wc-optic-template-form', function ( e ) {
			e.preventDefault();
			var segments = [];
			$root.find( '.wc-optic-tpl-ranges .wc-optic-power-range__segment' ).each( function () {
				var $seg = $( this );
				var from = $seg.find( '.wc-optic-range-from' ).val() || '';
				var to = $seg.find( '.wc-optic-range-to' ).val() || '';
				var step = $seg.find( '.wc-optic-range-step' ).val() || '';
				if ( ! String( from ).trim() && ! String( to ).trim() ) {
					return;
				}
				segments.push( { from: from, to: to, step: step } );
			} );
			if ( ! segments.length ) {
				window.alert( wcOpticConvert.i18n.needTemplateRange || wcOpticConvert.i18n.saveFailed );
				return;
			}
			$.post(
				getAjaxUrl(),
				{
					action: 'wc_optic_save_power_template',
					nonce: wcOpticConvert.nonce,
					name: $( '#wc_optic_tpl_name' ).val() || '',
					segments: segments,
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
				getAjaxUrl(),
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

		if ( wcOpticConvert.canResetAll ) {
			initResetAllPanel();
		}
	} );

	function showResetAlert( message, success ) {
		var $alert = $( '#wc-optic-reset-all-alert' );
		if ( ! message ) {
			$alert.attr( 'hidden', 'hidden' ).text( '' ).removeClass( 'is-success' );
			return;
		}
		$alert.text( message ).toggleClass( 'is-success', !! success ).removeAttr( 'hidden' );
	}

	function initResetAllPanel() {
		var $modal = $( '#wc-optic-reset-all-modal' );
		if ( ! $modal.length || ! window.bootstrap ) {
			return;
		}

		resetAllModal = new window.bootstrap.Modal( document.getElementById( 'wc-optic-reset-all-modal' ) );

		$root.on( 'click', '#wc-optic-reset-all-open', function ( e ) {
			e.preventDefault();
			showResetAlert( '' );
			$( '#wc_optic_reset_all_password' ).val( '' );
			$( '#wc_optic_reset_all_confirm' ).prop( 'checked', false );
			resetAllModal.show();
		} );

		$root.on( 'click', '#wc-optic-reset-all-submit', function ( e ) {
			e.preventDefault();
			showResetAlert( '' );

			var password = $( '#wc_optic_reset_all_password' ).val() || '';
			if ( ! password ) {
				showResetAlert( wcOpticConvert.i18n.resetAllNeedPass );
				return;
			}
			if ( ! $( '#wc_optic_reset_all_confirm' ).is( ':checked' ) ) {
				showResetAlert( wcOpticConvert.i18n.resetAllNeedCheck );
				return;
			}

			var $btn = $( '#wc-optic-reset-all-submit' );
			$btn.prop( 'disabled', true );

			$.post(
				getAjaxUrl(),
				{
					action: 'wc_optic_reset_all_internals',
					nonce: wcOpticConvert.nonce,
					password: password,
				},
				function ( res ) {
					$btn.prop( 'disabled', false );
					if ( ! res || ! res.success || ! res.data ) {
						showResetAlert( ( res && res.data && res.data.message ) || wcOpticConvert.i18n.resetAllFailed );
						return;
					}
					window.alert( res.data.message || sprintf( wcOpticConvert.i18n.resetAllSuccess, res.data.products || 0, res.data.internals || 0 ) );
					window.location.reload();
				}
			).fail( function ( xhr ) {
				$btn.prop( 'disabled', false );
				var message = wcOpticConvert.i18n.resetAllFailed;
				if ( xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) {
					message = xhr.responseJSON.data.message;
				}
				showResetAlert( message );
			} );
		} );
	}
}( jQuery ) );
