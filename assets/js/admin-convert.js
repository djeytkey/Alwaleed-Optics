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
		if ( ! $table.length || ! $table.find( 'tbody tr.wc-optic-convert-row' ).length ) {
			return;
		}

		if ( ! $.fn.DataTable ) {
			return;
		}

		if ( $.fn.DataTable.isDataTable( $table[ 0 ] ) ) {
			return;
		}

		dtLang = wcOpticConvert.dt || {};

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

	function collectRanges() {
		var ranges = {};
		var noPowerOnly = isSphNoPowerOnly();
		$root.find( '#wc-optic-wizard-modal .wc-optic-power-range' ).each( function () {
			var $row = $( this );
			var power = $row.data( 'power' );
			if ( noPowerOnly && power !== 'sph' ) {
				return;
			}
			if ( $row.is( ':hidden' ) ) {
				return;
			}
			ranges[ power ] = {
				from: rangeFieldValue( $row.find( '.wc-optic-range-from' ).val() ),
				to: rangeFieldValue( $row.find( '.wc-optic-range-to' ).val() ),
				step: rangeFieldValue( $row.find( '.wc-optic-range-step' ).val() ),
			};
		} );
		return ranges;
	}

	function isSphNoPowerOnly() {
		var $row = $root.find( '#wc-optic-wizard-modal .wc-optic-power-range[data-power="sph"]' );
		if ( ! $row.length ) {
			return false;
		}
		var from = rangeFieldValue( $row.find( '.wc-optic-range-from' ).val() ).trim();
		var to = rangeFieldValue( $row.find( '.wc-optic-range-to' ).val() ).trim();
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

	function applyNoPowerRangeUi() {
		var noPower = isSphNoPowerOnly();
		var division = $( '#wc_optic_wizard_division' ).val() || '';
		var allowed = getAllowedPowers( division );
		$root.find( '#wc-optic-wizard-modal .wc-optic-power-range' ).each( function () {
			var $row = $( this );
			var power = $row.data( 'power' );
			if ( power === 'sph' ) {
				return;
			}
			var show = allowed.indexOf( power ) !== -1 && ! noPower;
			$row.toggle( show );
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
		var catalog = {};
		var division = $( '#wc_optic_wizard_division' ).val() || '';
		$root.find( '#wc-optic-wizard-modal .wc-optic-identity-select' ).each( function () {
			var type = $( this ).data( 'optic-type' );
			if ( type === 'color' && ! divisionShowsColor( division ) ) {
				return;
			}
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

	function applyTemplateRanges( template ) {
		if ( ! template || ! template.ranges ) {
			return;
		}
		$.each( template.ranges, function ( power, range ) {
			var $row = $root.find( '#wc-optic-wizard-modal .wc-optic-power-range[data-power="' + power + '"]' );
			$row.find( '.wc-optic-range-from' ).val( rangeFieldValue( range.from ) );
			$row.find( '.wc-optic-range-to' ).val( rangeFieldValue( range.to ) );
			$row.find( '.wc-optic-range-step' ).val( rangeFieldValue( range.step ) );
		} );
	}

	function fillRanges( ranges ) {
		if ( ! ranges ) {
			return;
		}
		applyTemplateRanges( { ranges: ranges } );
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
				allowClear: true,
				placeholder: $el.data( 'placeholder' ) || '',
			};
			if ( $el.closest( '#wc-optic-wizard-modal' ).length ) {
				args.dropdownParent = $( document.body );
			}
			$el.selectWoo( args ).addClass( 'enhanced' );
		} );
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

	function wizardDivisionValue() {
		return $( '#wc_optic_wizard_division' ).val() || '';
	}

	function prepareSpecificsRanges( ranges ) {
		var prepared = ranges ? $.extend( true, {}, ranges ) : {};
		// Prefill CYL/AXIS/ADD from the product; leave SPH empty so the operator sets extras (e.g. 0.00).
		if ( prepared.sph ) {
			prepared.sph = {
				from: '',
				to: '',
				step: prepared.sph.step || ( wcOpticConvert.defaultSteps && wcOpticConvert.defaultSteps.sph ) || '0.25',
			};
		}
		return prepared;
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
				if ( step === 2 ) {
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
				if ( isSpecificsMode() ) {
					$( '#wc-optic-wizard-title' ).text( wcOpticConvert.i18n.wizardSpecifics || 'Add specifics' );
				} else if ( isRebuildMode() ) {
					$( '#wc_optic_wizard_replace' ).prop( 'checked', true );
					$( '#wc-optic-wizard-title' ).text( wcOpticConvert.i18n.wizardRebuild || 'Rebuild product' );
				} else {
					$( '#wc_optic_wizard_replace' ).prop( 'checked', false );
					$( '#wc-optic-wizard-title' ).text( wcOpticConvert.i18n.wizardConvert || 'Convert product' );
				}
				$( '#wc_optic_wizard_template' ).val( '' );
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
		var ok = true;
		$.each( ranges, function ( _, range ) {
			if ( ! rangeFieldFilled( range.from ) || ! rangeFieldFilled( range.to ) || ! rangeFieldFilled( range.step ) ) {
				ok = false;
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
			template_id: $( '#wc_optic_wizard_template' ).val() || '',
			unit_price: $( '#wc_optic_wizard_price' ).val() || '',
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
			wcOpticConvert.ajaxUrl,
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
					convertTable.row( $row ).invalidate().draw( false );
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
			var division = $( this ).val() || '';
			applyDivisionRanges( division );
			applyDivisionIdentityFields( division );
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
			applyNoPowerRangeUi();
			refreshCount();
		} );

		$root.on( 'input change', '#wc-optic-wizard-modal .wc-optic-range-from, #wc-optic-wizard-modal .wc-optic-range-to, #wc-optic-wizard-modal .wc-optic-range-step', function () {
			applyNoPowerRangeUi();
			refreshCount();
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
