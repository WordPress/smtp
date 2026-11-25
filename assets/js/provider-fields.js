/**
 * Shows the fields of the selected provider and hides the others, without saving.
 *
 * The fields of the other providers are disabled, so they are not submitted. Fields locked by a
 * constant stay disabled.
 */
( function () {
	var select = document.getElementById( 'smtp-provider' );
	if ( ! select ) {
		return;
	}

	var inputs = document.querySelectorAll( '[data-smtp-provider]' );

	var update = function () {
		inputs.forEach( function ( input ) {
			var selected = input.getAttribute( 'data-smtp-provider' ) === select.value;
			var row = input.closest( 'tr' );

			if ( row ) {
				row.classList.toggle( 'hidden', ! selected );
			}
			input.disabled = ! selected || '1' === input.getAttribute( 'data-smtp-locked' );
		} );
	};

	select.addEventListener( 'change', update );
	update();
} )();
