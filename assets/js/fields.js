/**
 * Disables the settings fields that can not take effect.
 *
 * - `data-smtp-enabled-by`: ID of a field that must be checked, or filled in, for this one to be editable.
 * - `data-smtp-disabled-by`: ID of a checkbox that makes this field useless when it is checked.
 *
 * Fields defined by a constant (`data-smtp-constant`) stay as they are, and so do the fields of a provider
 * that is not selected, which assets/js/provider-fields.js disables. Disabled checkboxes are not
 * submitted, so they save as off. Other disabled inputs are enabled again on submit, so their value is kept.
 */
( function () {
	var fields = document.querySelectorAll( '[data-smtp-enabled-by], [data-smtp-disabled-by]' );
	if ( ! fields.length ) {
		return;
	}

	var provider = document.getElementById( 'smtp-provider' );

	var isLocked = function ( input ) {
		return input.hasAttribute( 'data-smtp-constant' );
	};

	var isInactive = function ( input ) {
		var slug = input.getAttribute( 'data-smtp-provider' );
		return !! slug && !! provider && slug !== provider.value;
	};

	var isOn = function ( id ) {
		var input = document.getElementById( id );
		if ( ! input ) {
			return true;
		}
		if ( 'checkbox' === input.type ) {
			// A box this script disabled has no effect, even when it shows as checked.
			return input.checked && ( ! input.disabled || isLocked( input ) );
		}
		return '' !== input.value.trim();
	};

	var update = function () {
		// Document order, so a box that depends on another one sees its updated state.
		fields.forEach( function ( field ) {
			if ( isLocked( field ) || isInactive( field ) ) {
				return;
			}
			var enabledBy  = field.getAttribute( 'data-smtp-enabled-by' );
			var disabledBy = field.getAttribute( 'data-smtp-disabled-by' );

			field.disabled = !! ( ( enabledBy && ! isOn( enabledBy ) ) || ( disabledBy && isOn( disabledBy ) ) );
		} );
	};

	document.addEventListener( 'input', update );
	document.addEventListener( 'change', update );
	update();

	document.querySelectorAll( 'form' ).forEach( function ( form ) {
		form.addEventListener( 'submit', function () {
			fields.forEach( function ( field ) {
				if ( 'checkbox' !== field.type && ! isLocked( field ) && ! isInactive( field ) ) {
					field.disabled = false;
				}
			} );
		} );
	} );
} )();
