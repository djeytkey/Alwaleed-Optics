( function ( $ ) {
	'use strict';

	/**
	 * Unique key for new rows (matches PHP `new_` + unique id pattern).
	 *
	 * @return {string}
	 */
	function newRowSuffix() {
		return 'new_' + Date.now() + '_' + Math.random().toString( 36 ).slice( 2, 11 );
	}

	/**
	 * Build one empty division row.
	 *
	 * @return {jQuery}
	 */
	function buildEmptyDivisionRow() {
		var suffix = newRowSuffix();
		var pf = 'wc_optic_division[' + suffix + ']';
		var powersHtml = '';
		( wcOpticAdmin.divisionPowers || [] ).forEach( function ( power ) {
			var id = 'wc-optic-power-' + suffix + '-' + power;
			var label =
				wcOpticAdmin.divisionPowerLabels && wcOpticAdmin.divisionPowerLabels[ power ]
					? wcOpticAdmin.divisionPowerLabels[ power ]
					: power.toUpperCase();
			powersHtml +=
				'<label for="' +
				id +
				'" class="wc-optic-division-power-label">' +
				'<input type="checkbox" id="' +
				id +
				'" name="' +
				pf +
				'[powers][]" value="' +
				power +
				'" /> ' +
				label +
				'</label> ';
		} );
		var html =
			'<tr class="wc-optic-division-row wc-optic-new-division-row">' +
			'<td><input type="text" name="' +
			pf +
			'[label]" value="" class="regular-text wc-optic-division-label" autocomplete="off" />' +
			'<input type="hidden" class="wc-optic-division-hidden-flag" name="' +
			pf +
			'[hidden]" value="0" /></td>' +
			'<td class="wc-optic-division-powers">' +
			powersHtml +
			'</td>' +
			'<td class="wc-optic-division-actions"></td>' +
			'</tr>';
		return $( html );
	}

	/**
	 * Show or hide the hidden-divisions section heading.
	 */
	function updateHiddenDivisionsSection() {
		var $hidden = $( '#wc-optic-divisions-hidden' );
		var $separator = $( '#wc-optic-divisions-hidden-separator' );
		if ( ! $hidden.length || ! $separator.length ) {
			return;
		}
		var hasHidden = $hidden.find( 'tr.wc-optic-division-row' ).length > 0;
		$separator.toggleClass( 'wc-optic-is-empty', ! hasHidden );
	}

	/**
	 * Build hide action button markup.
	 *
	 * @param {string} label Division label for aria-label.
	 * @return {string}
	 */
	function buildHideDivisionButton( label ) {
		var aria = wcOpticAdmin.i18n.hideDivisionLabel || 'Hide division';
		if ( label ) {
			aria += ' ' + label;
		}
		return (
			'<button type="button" class="button-link-delete wc-optic-hide-division" aria-label="' +
			aria +
			'">' +
			'<span class="dashicons dashicons-hidden" aria-hidden="true"></span>' +
			'</button>'
		);
	}

	/**
	 * Toggle one division row between visible and hidden state.
	 *
	 * @param {jQuery} $tr     Table row.
	 * @param {boolean} hidden Hidden state.
	 */
	function setDivisionRowHidden( $tr, hidden ) {
		var $visible = $( '#wc-optic-divisions-visible' );
		var $hiddenBody = $( '#wc-optic-divisions-hidden' );
		var $actions = $tr.find( '.wc-optic-division-actions' );
		var label = $.trim( $tr.find( '.wc-optic-division-label' ).first().val() || '' );

		$tr.find( '.wc-optic-division-hidden-flag' ).val( hidden ? '1' : '0' );
		$tr.toggleClass( 'wc-optic-division-row--hidden', hidden );

		if ( hidden ) {
			$actions.html(
				'<button type="button" class="button wc-optic-restore-division">' +
					( wcOpticAdmin.i18n.restoreDivision || 'Restore' ) +
					'</button>'
			);
			$hiddenBody.append( $tr );
		} else {
			$actions.html( buildHideDivisionButton( label ) );
			if ( $visible.length ) {
				$visible.find( 'tr.wc-optic-new-division-row' ).first().before( $tr );
			}
		}

		updateHiddenDivisionsSection();
	}

	/**
	 * Append one empty catalog row to the settings table.
	 *
	 * @return {jQuery}
	 */
	function buildEmptyRow() {
		var suffix = newRowSuffix();
		var pf = 'wc_optic_row[' + suffix + ']';
		var html =
			'<tr class="wc-optic-new-row">' +
			'<td><input type="text" name="' +
			pf +
			'[name]" value="" class="regular-text wc-optic-catalog-name" autocomplete="off" required /></td>' +
			'<td><input type="text" name="' +
			pf +
			'[sku_fragment]" value="" class="regular-text wc-optic-catalog-fragment" autocomplete="off" required /></td>' +
			'<td><input type="number" name="' +
			pf +
			'[sort_order]" value="0" class="small-text" /></td>' +
			'<td></td>' +
			'</tr>';
		return $( html );
	}

	/**
	 * Parse JSON error message from failed admin-ajax response.
	 *
	 * @param {jqXHR} xhr XHR.
	 * @param {string} fallback Fallback message.
	 * @return {string}
	 */
	function parseAjaxErrorMessage( xhr, fallback ) {
		if ( ! xhr || ! xhr.responseText ) {
			return fallback;
		}
		try {
			var r = JSON.parse( xhr.responseText );
			if ( r && r.data && r.data.message ) {
				return r.data.message;
			}
		} catch ( e ) {
			// ignore
		}
		return fallback;
	}

	/**
	 * After AJAX delete: show products that still referenced the term.
	 *
	 * @param {Object} data Response data from wp_send_json_success.
	 */
	function showDeletionFollowupNotice( data ) {
		var $box = $( '#wc-optic-inline-messages' );
		if ( ! $box.length ) {
			return;
		}
		$box.empty();
		var list = data && data.affected_products ? data.affected_products : [];
		if ( ! list.length ) {
			var $info = $( '<div class="notice notice-info is-dismissible"></div>' );
			$info.append( $( '<p></p>' ).text( wcOpticAdmin.i18n.affectedNone ) );
			$box.append( $info );
			return;
		}
		var $warn = $( '<div class="notice notice-warning is-dismissible"></div>' );
		$warn.append( $( '<p></p>' ).text( wcOpticAdmin.i18n.affectedNoticeTitle ) );
		var $ul = $( '<ul class="wc-optic-affected-list"></ul>' );
		list.forEach( function ( p ) {
			var $li = $( '<li></li>' );
			if ( p.edit_url ) {
				$li.append(
					$( '<a></a>' )
						.attr( 'href', p.edit_url )
						.text( p.name + ' (#' + p.id + ')' )
				);
			} else {
				$li.text( ( p.name || '' ) + ' (#' + p.id + ')' );
			}
			$ul.append( $li );
		} );
		$warn.append( $ul );
		$box.append( $warn );
		if ( $box[ 0 ] && $box[ 0 ].scrollIntoView ) {
			$box[ 0 ].scrollIntoView( { block: 'nearest', behavior: 'smooth' } );
		}
	}

	$( function () {
		var $root = $( '#wc-optic-settings-root' );

		$( '#wc-optic-add-row' ).on( 'click', function () {
			var $tbody = $( 'table.wc-optic-settings-table tbody' );
			if ( ! $tbody.length ) {
				return;
			}
			var $tr = buildEmptyRow();
			$tbody.append( $tr );
			$tr.find( '.wc-optic-catalog-name' ).first().trigger( 'focus' );
		} );

		$( '#wc-optic-add-division' ).on( 'click', function () {
			var $tbody = $( '#wc-optic-divisions-visible' );
			if ( ! $tbody.length ) {
				return;
			}
			var $tr = buildEmptyDivisionRow();
			$tbody.find( 'tr.wc-optic-new-division-row' ).first().before( $tr );
			$tr.find( '.wc-optic-division-label' ).first().trigger( 'focus' );
		} );

		$( document ).on( 'click', '.wc-optic-hide-division', function ( e ) {
			e.preventDefault();
			var $tr = $( this ).closest( 'tr' );
			var name = $.trim( $tr.find( '.wc-optic-division-label' ).first().val() || '' );
			var msg = wcOpticAdmin.i18n.confirmDivisionHide;
			if ( name ) {
				msg += '\n\n' + name;
			}
			if ( ! window.confirm( msg ) ) {
				return;
			}
			setDivisionRowHidden( $tr, true );
		} );

		$( document ).on( 'click', '.wc-optic-restore-division', function ( e ) {
			e.preventDefault();
			setDivisionRowHidden( $( this ).closest( 'tr' ), false );
		} );

		updateHiddenDivisionsSection();

		$( document ).on( 'click', '.wc-optic-delete-row', function ( e ) {
			e.preventDefault();
			var $btn = $( this );
			var $tr = $btn.closest( 'tr' );
			var id = parseInt( $btn.attr( 'data-id' ), 10 );
			var tab = $root.attr( 'data-active-tab' ) || 'section';
			var name = $.trim( $tr.find( 'input[name*="[name]"]' ).first().val() || '' );
			var msg = wcOpticAdmin.i18n.confirmDelete;
			if ( name ) {
				msg += '\n\n' + name;
			}
			if ( ! window.confirm( msg ) ) {
				return;
			}
			$btn.prop( 'disabled', true );
			$.ajax( {
				url: wcOpticAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wc_optic_delete_term',
					nonce: wcOpticAdmin.nonce,
					id: id,
					term_type: tab,
				},
			} )
				.done( function ( res ) {
					if ( res && res.success ) {
						showDeletionFollowupNotice( res.data || {} );
						$tr.fadeOut( 200, function () {
							$( this ).remove();
						} );
					} else {
						var m =
							res && res.data && res.data.message
								? res.data.message
								: wcOpticAdmin.i18n.deleteFailed;
						window.alert( m );
					}
				} )
				.fail( function ( xhr ) {
					window.alert(
						parseAjaxErrorMessage( xhr, wcOpticAdmin.i18n.deleteFailed )
					);
				} )
				.always( function () {
					$btn.prop( 'disabled', false );
				} );
		} );
	} );

	$( function () {
		var $enabled = $( '#wc_optic_global_backorder_enabled' );
		var $panel = $( '#wc-optic-global-backorder-panel' );
		var $qtyWrap = $( '.wc-optic-global-backorder-qty-wrap' );

		function syncGlobalBackorderPanel() {
			if ( ! $enabled.length ) {
				return;
			}
			var isOn = $enabled.is( ':checked' );
			if ( $panel.length ) {
				$panel.toggleClass( 'wc-optic-backorder-panel--enabled', isOn );
			}
			if ( $qtyWrap.length ) {
				$qtyWrap.toggleClass( 'wc-optic-is-hidden', ! isOn );
			}
		}

		syncGlobalBackorderPanel();
		$enabled.on( 'change', syncGlobalBackorderPanel );

		var $alertEnabled = $( '#wc_optic_global_stock_alert_enabled' );
		var $alertPanel = $( '#wc-optic-global-stock-alert-panel' );
		var $alertQtyWrap = $( '.wc-optic-global-stock-alert-qty-wrap' );

		function syncGlobalStockAlertPanel() {
			if ( ! $alertEnabled.length ) {
				return;
			}
			var isOn = $alertEnabled.is( ':checked' );
			if ( $alertPanel.length ) {
				$alertPanel.toggleClass( 'wc-optic-backorder-panel--enabled', isOn );
			}
			if ( $alertQtyWrap.length ) {
				$alertQtyWrap.toggleClass( 'wc-optic-is-hidden', ! isOn );
			}
		}

		syncGlobalStockAlertPanel();
		$alertEnabled.on( 'change', syncGlobalStockAlertPanel );
	} );
}( jQuery ) );
