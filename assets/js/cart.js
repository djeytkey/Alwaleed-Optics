( function ( $ ) {
	'use strict';

	function syncRow( $wrap ) {
		var mode = $wrap.data( 'qty-mode' ) || 'single';
		if ( mode === 'dual' ) {
			var l = parseInt( $wrap.find( '.wc-optic-cart-q-left' ).val(), 10 ) || 0;
			var r = parseInt( $wrap.find( '.wc-optic-cart-q-right' ).val(), 10 ) || 0;
			l = Math.max( 1, l );
			r = Math.max( 1, r );
			$wrap.find( '.wc-optic-cart-line-total' ).val( l + r );
			return;
		}

		var s = parseInt( $wrap.find( '.wc-optic-cart-q-single' ).val(), 10 ) || 0;
		s = Math.max( 1, s );
		$wrap.find( '.wc-optic-cart-line-total' ).val( s );
	}

	$( function () {
		$( document.body ).on( 'change input', '.wc-optic-cart-q-left, .wc-optic-cart-q-right, .wc-optic-cart-q-single', function () {
			syncRow( $( this ).closest( '.wc-optic-cart-qty' ) );
		} );
	} );
}( jQuery ) );
