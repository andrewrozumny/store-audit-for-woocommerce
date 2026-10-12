/* Store Audit for WooCommerce - print view. */
( function () {
	'use strict';
	var button = document.getElementById( 'store-audit-print-button' );
	if ( button ) {
		button.addEventListener( 'click', function () {
			window.print();
		} );
	}
}() );
