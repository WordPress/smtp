/**
 * Shows the fields of the selected alert channel and hides the others.
 */
( function () {
	var select = document.getElementById( 'smtp-alert-channel' );
	if ( ! select ) {
		return;
	}
	var toggle = function () {
		document.querySelectorAll( '[data-smtp-alert-channel]' ).forEach( function ( group ) {
			group.hidden = group.getAttribute( 'data-smtp-alert-channel' ) !== select.value;
		} );
	};
	select.addEventListener( 'change', toggle );
	toggle();
} )();
