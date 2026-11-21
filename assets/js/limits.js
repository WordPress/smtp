/**
 * Shows the sending limit fields only when "Customize sending limits" is checked.
 *
 * Hidden fields are disabled, so they are not submitted and the limits are cleared when the box is unchecked.
 */
( function () {
	var toggle = document.getElementById( 'smtp-customize-limits' );
	if ( ! toggle ) {
		return;
	}

	var inputs = document.querySelectorAll( '[name^="smtp_settings[rate_limit_"]' );

	inputs.forEach( function ( input ) {
		input.dataset.smtpWasDisabled = input.disabled ? '1' : '';
	} );

	var update = function () {
		inputs.forEach( function ( input ) {
			var row = input.closest( 'tr' );
			if ( row ) {
				row.hidden = ! toggle.checked;
			}
			input.disabled = ! toggle.checked || '1' === input.dataset.smtpWasDisabled;
		} );
	};

	toggle.addEventListener( 'change', update );
	update();
} )();
