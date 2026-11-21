/**
 * Disables the settings fields that can not take effect.
 *
 * - `data-smtp-enabled-by`: ID of a field that must be checked, or filled in, for this one to be editable.
 * - `data-smtp-disabled-by`: ID of a checkbox that makes this field useless when it is checked.
 *
 * Fields locked by a constant stay as they are. Disabled checkboxes are not submitted, so they save as
 * off. Other disabled inputs are enabled again on submit, so their value is kept.
 */
( function () {
	var fields = document.querySelectorAll( '[data-smtp-enabled-by], [data-smtp-disabled-by]' );
	if ( ! fields.length ) {
		return;
	}

	// Fields disabled on load are locked by a constant.
	document.querySelectorAll( '[name^="smtp_settings["]' ).forEach( function ( input ) {
		input.dataset.smtpLocked = input.disabled ? '1' : '';
	} );

	var isOn = function ( id ) {
		var input = document.getElementById( id );
		if ( ! input ) {
			return true;
		}
		if ( 'checkbox' === input.type ) {
			// A box this script disabled has no effect, even when it shows as checked.
			return input.checked && ( ! input.disabled || '1' === input.dataset.smtpLocked );
		}
		return '' !== input.value.trim();
	};

	var update = function () {
		// Document order, so a box that depends on another one sees its updated state.
		fields.forEach( function ( field ) {
			if ( '1' === field.dataset.smtpLocked ) {
				return;
			}
			var enabledBy  = field.getAttribute( 'data-smtp-enabled-by' );
			var disabledBy = field.getAttribute( 'data-smtp-disabled-by' );

			field.disabled = ( enabledBy && ! isOn( enabledBy ) ) || ( disabledBy && isOn( disabledBy ) );
		} );
	};

	document.addEventListener( 'input', update );
	document.addEventListener( 'change', update );
	update();

	document.querySelectorAll( 'form' ).forEach( function ( form ) {
		form.addEventListener( 'submit', function () {
			fields.forEach( function ( field ) {
				if ( 'checkbox' !== field.type && '1' !== field.dataset.smtpLocked ) {
					field.disabled = false;
				}
			} );
		} );
	} );
} )();
