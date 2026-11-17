<?php
/**
 * Class WordPress\SMTP\Admin\Field_Renderer
 *
 * @package smtp
 */

namespace WordPress\SMTP\Admin;

use WordPress\SMTP\Rate_Limits;
use WordPress\SMTP\Settings;

/**
 * Renders a settings field, for the Settings tab and for the connections.
 *
 * @since 1.2.0
 */
class Field_Renderer {

	/**
	 * Renders the checkbox that reveals the sending limit fields.
	 *
	 * The box is not saved: it starts checked when a limit is set, and unchecking it clears the limits (see
	 * assets/js/limits.js, which disables the hidden fields).
	 *
	 * @since 1.2.0
	 *
	 * @param Settings $settings Settings the limits are read from.
	 */
	public static function render_limits_toggle( Settings $settings ) {
		$customized = false;
		foreach ( array_keys( Rate_Limits::get_override_fields() ) as $key ) {
			if ( (int) $settings->get( $key ) > 0 ) {
				$customized = true;
			}
		}

		?>
		<label for="smtp-customize-limits">
			<input type="checkbox" id="smtp-customize-limits" value="1" <?php checked( $customized ); ?> />
			<?php esc_html_e( 'Customize sending limits', 'smtp' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'The limits of your provider or plan. Leave a field empty to use the default of the provider, if it has one. The plugin does not queue or throttle emails: other plugins can read these limits to schedule them.', 'smtp' ); ?>
		</p>
		<?php
	}

	/**
	 * Renders a settings field.
	 *
	 * Secrets are never printed: password fields render empty, with a placeholder when one is saved.
	 *
	 * @since 1.2.0
	 *
	 * @param string   $key      Field key.
	 * @param array    $field    Normalized field.
	 * @param Settings $settings Settings the value is read from.
	 * @param string   $name     Value of the `name` attribute of the input.
	 */
	public static function render( string $key, array $field, Settings $settings, string $name ) {
		$id     = 'smtp-' . $key;
		$locked = $settings->is_locked( $field );
		$value  = $settings->get( $key );

		switch ( $field['type'] ) {
			case 'checkbox':
				?>
				<label for="<?php echo esc_attr( $id ); ?>">
					<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( (bool) $value ); ?> <?php disabled( $locked ); ?> />
					<?php echo esc_html( $field['description'] ); ?>
				</label>
				<?php
				$field['description'] = '';
				break;

			case 'select':
				?>
				<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" <?php disabled( $locked ); ?>>
					<?php foreach ( $field['options'] as $option_value => $option_label ) : ?>
						<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $value, (string) $option_value ); ?>>
							<?php echo esc_html( $option_label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<?php
				break;

			case 'password':
				// The saved secret is never printed back into the page.
				?>
				<input
					type="password"
					class="regular-text"
					id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					value=""
					autocomplete="new-password"
					placeholder="<?php echo '' !== $value ? esc_attr( str_repeat( '•', 8 ) ) : ''; ?>"
					<?php disabled( $locked ); ?>
				/>
				<?php
				if ( '' !== $value && ! $locked ) {
					$field['description'] = __( 'A password is saved. Leave empty to keep it.', 'smtp' );
				}
				break;

			default:
				$type = in_array( $field['type'], array( 'number', 'email' ), true ) ? $field['type'] : 'text';
				?>
				<input
					type="<?php echo esc_attr( $type ); ?>"
					class="regular-text"
					id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					value="<?php echo esc_attr( 'number' === $field['type'] && ! (int) $value ? '' : (string) $value ); ?>"
					<?php disabled( $locked ); ?>
				/>
				<?php
		}

		if ( $locked ) {
			?>
			<p class="description">
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: PHP constant name */
						__( 'Defined by the %s constant.', 'smtp' ),
						'<code>' . esc_html( $field['constant'] ) . '</code>'
					),
					array( 'code' => array() )
				);
				?>
			</p>
			<?php
		} elseif ( '' !== $field['description'] ) {
			?>
			<p class="description"><?php echo esc_html( $field['description'] ); ?></p>
			<?php
		}
	}
}
