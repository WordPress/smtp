<?php
/**
 * Class WordPress\SMTP\Admin\Field_Renderer
 *
 * @package smtp
 */

namespace WordPress\SMTP\Admin;

use WordPress\SMTP\Provider_Registry;
use WordPress\SMTP\Rate_Limits;
use WordPress\SMTP\Settings;

/**
 * Renders a settings field, for the Settings tab and for the connections.
 *
 * @since 1.3.0
 */
class Field_Renderer {

	/**
	 * Renders the checkbox that reveals the sending limit fields.
	 *
	 * The box is not saved: it starts checked when a limit is set, and unchecking it clears the limits (see
	 * assets/js/limits.js, which disables the hidden fields).
	 *
	 * @since 1.3.0
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
		<p class="description" style="max-width: 600px;">
			<?php esc_html_e( 'The limits of your provider or plan. Leave a field empty to use the default of the provider, if it has one. The plugin does not queue or throttle emails: other plugins can read these limits to schedule them.', 'smtp' ); ?>
		</p>
		<?php
	}

	/**
	 * Returns the titles of the sections the fields are grouped in, in display order.
	 *
	 * @since 1.3.0
	 *
	 * @return string[] Section titles keyed by the `section` of a field.
	 */
	public static function get_sections(): array {
		return array(
			'connection' => __( 'Connection', 'smtp' ),
			'sender'     => __( 'Sender', 'smtp' ),
			'replies'    => __( 'Replies and bounces', 'smtp' ),
			'limits'     => __( 'Sending limits', 'smtp' ),
		);
	}

	/**
	 * Whether a field gets its own row. Fields with a `parent` are rendered in the row of their parent.
	 *
	 * @since 1.3.0
	 *
	 * @param array $field Normalized field.
	 * @return bool True when the field has its own row.
	 */
	public static function has_row( array $field ): bool {
		return '' === $field['parent'];
	}

	/**
	 * Whether the row header of a field can be a label of its input.
	 *
	 * A checkbox already has its own label, and two labels would both be announced by screen readers.
	 *
	 * @since 1.3.0
	 *
	 * @param array $field Normalized field.
	 * @return bool True when the row header should label the input.
	 */
	public static function uses_row_label( array $field ): bool {
		return 'checkbox' !== $field['type'];
	}

	/**
	 * Renders a settings field, followed by the checkboxes that have it as `parent`.
	 *
	 * Secrets are never printed: password fields render empty, with a placeholder when one is saved.
	 *
	 * @since 1.3.0
	 *
	 * @param string   $key      Field key.
	 * @param array    $field    Normalized field.
	 * @param Settings $settings Settings the value is read from.
	 * @param string   $name     Value of the `name` attribute of the input.
	 * @param array    $args     {
	 *     Optional. Rendering options.
	 *
	 *     @type string $id       Value of the `id` attribute. Default `smtp-{$key}`.
	 *     @type string $provider Slug of the provider the field belongs to, for the provider switch
	 *                            script. Default empty, for general fields.
	 *     @type bool   $inactive Whether the field belongs to a provider that is not selected. It is
	 *                            rendered disabled, so it is not submitted. Default false.
	 * }
	 */
	public static function render( string $key, array $field, Settings $settings, string $name, array $args = array() ) {
		$args     = wp_parse_args(
			$args,
			array(
				'id'       => 'smtp-' . $key,
				'provider' => '',
				'inactive' => false,
			)
		);
		$id       = (string) $args['id'];
		$locked   = $settings->is_locked( $field );
		$value    = $settings->get_field_value( $key, $field );
		$disabled = $locked || $args['inactive'];

		// Attributes read by assets/js/provider-fields.js to switch the provider fields without saving.
		$data = '' === $args['provider'] ? '' : sprintf(
			' data-smtp-provider="%s"%s',
			esc_attr( (string) $args['provider'] ),
			$locked ? ' data-smtp-locked="1"' : ''
		);

		// Read by assets/js/fields.js, which never changes a field defined by a constant.
		$data .= self::get_constant_attribute( $locked );

		switch ( $field['type'] ) {
			case 'checkbox':
				?>
				<fieldset>
					<legend class="screen-reader-text"><?php echo esc_html( $field['label'] ); ?></legend>
					<?php self::render_checkbox( $id, $name, $field, (bool) $value, $disabled, $data ); ?>
				</fieldset>
				<?php
				// The description is the text of the checkbox, unless the checkbox has its own.
				if ( '' === $field['checkbox_label'] ) {
					$field['description'] = '';
				}
				break;

			case 'select':
				?>
				<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" <?php self::render_dependencies( $field ); ?> <?php disabled( $disabled ); ?><?php echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped when built. ?>>
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
					<?php self::render_dependencies( $field ); ?>
					<?php disabled( $disabled ); ?><?php echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped when built. ?>
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
					<?php if ( 'number' === $type ) : ?>
						min="1"
						<?php if ( (int) $field['max'] > 0 ) : ?>
							max="<?php echo esc_attr( (string) (int) $field['max'] ); ?>"
						<?php endif; ?>
					<?php endif; ?>
					<?php self::render_dependencies( $field ); ?>
					<?php disabled( $disabled ); ?><?php echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped when built. ?>
				/>
				<?php
		}

		if ( $locked ) {
			self::render_constant_notice( $field );
		} elseif ( '' !== $field['description'] ) {
			?>
			<p class="description" style="max-width: 600px;"><?php echo esc_html( $field['description'] ); ?></p>
			<?php
		}

		$children = array();
		foreach ( $settings->get_fields() as $child_key => $child ) {
			if ( $key === $child['parent'] && 'checkbox' === $child['type'] ) {
				$children[ $child_key ] = $child;
			}
		}

		if ( ! $children ) {
			return;
		}
		?>
		<fieldset>
			<legend class="screen-reader-text">
				<?php
				/* translators: %s: label of a settings field, for example "From email" */
				echo esc_html( sprintf( __( '%s options', 'smtp' ), $field['label'] ) );
				?>
			</legend>
			<?php foreach ( $children as $child_key => $child ) : ?>
				<?php
				$child_locked = $settings->is_locked( $child );
				?>
				<div>
					<?php
					self::render_checkbox(
						'smtp-' . $child_key,
						str_replace( '[' . $key . ']', '[' . $child_key . ']', $name ),
						$child,
						(bool) $settings->get( $child_key ),
						$child_locked,
						self::get_constant_attribute( $child_locked )
					);

					if ( $child_locked ) {
						self::render_constant_notice( $child );
					}
					?>
				</div>
			<?php endforeach; ?>
		</fieldset>
		<?php
	}

	/**
	 * Renders a checkbox wrapped in its label.
	 *
	 * @since 1.3.0
	 *
	 * @param string $id       ID of the input.
	 * @param string $name     Value of the `name` attribute of the input.
	 * @param array  $field    Normalized field.
	 * @param bool   $checked  Whether the box is checked.
	 * @param bool   $disabled Whether the input is disabled.
	 * @param string $data     Escaped data attributes for the provider switch script.
	 */
	private static function render_checkbox( string $id, string $name, array $field, bool $checked, bool $disabled, string $data = '' ) {
		?>
		<label for="<?php echo esc_attr( $id ); ?>">
			<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $checked ); ?> <?php self::render_dependencies( $field ); ?> <?php disabled( $disabled ); ?><?php echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped when built. ?> />
			<?php echo esc_html( '' !== $field['checkbox_label'] ? $field['checkbox_label'] : $field['description'] ); ?>
		</label>
		<?php
	}

	/**
	 * Renders the attributes that assets/js/fields.js reads to disable an input that can not take effect.
	 *
	 * @since 1.3.0
	 *
	 * @param array $field Normalized field.
	 */
	private static function render_dependencies( array $field ) {
		if ( '' !== $field['enabled_by'] ) {
			echo ' data-smtp-enabled-by="' . esc_attr( 'smtp-' . $field['enabled_by'] ) . '"';
		}

		if ( '' !== $field['disabled_by'] ) {
			echo ' data-smtp-disabled-by="' . esc_attr( 'smtp-' . $field['disabled_by'] ) . '"';
		}
	}

	/**
	 * Returns the attribute that marks an input defined by a constant.
	 *
	 * @since 1.3.0
	 *
	 * @param bool $locked Whether the value is defined by a constant.
	 * @return string The attribute with a leading space, or an empty string.
	 */
	private static function get_constant_attribute( bool $locked ): string {
		return $locked ? ' data-smtp-constant="1"' : '';
	}

	/**
	 * Renders the note that a field is defined by a constant.
	 *
	 * @since 1.3.0
	 *
	 * @param array $field Normalized field.
	 */
	private static function render_constant_notice( array $field ) {
		?>
		<p class="description" style="max-width: 600px;">
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
	}

	/**
	 * Returns the fields of every provider, the selected provider first.
	 *
	 * Every provider is rendered so the form can switch providers without saving. A key used by more
	 * than one provider gets a provider-specific `id` after its first use, so ids stay unique.
	 *
	 * @since 1.3.0
	 *
	 * @param Settings          $settings Settings of the form.
	 * @param Provider_Registry $registry Provider registry.
	 * @return array[] Rows with `provider`, `key`, `field`, `id` and `inactive`.
	 */
	public static function get_provider_field_rows( Settings $settings, Provider_Registry $registry ): array {
		$current = $settings->get_provider_slug();
		$slugs   = array_keys( $registry->get_all() );
		$slugs   = array_merge( array( $current ), array_diff( $slugs, array( $current ) ) );

		$rows = array();
		$used = array();
		foreach ( $slugs as $slug ) {
			foreach ( $settings->get_provider_fields( $slug ) as $key => $field ) {
				$rows[] = array(
					'provider' => $slug,
					'key'      => $key,
					'field'    => $field,
					'id'       => isset( $used[ $key ] ) ? 'smtp-' . sanitize_html_class( $slug ) . '-' . $key : 'smtp-' . $key,
					'inactive' => $slug !== $current,
				);

				$used[ $key ] = true;
			}
		}

		return $rows;
	}

	/**
	 * Returns the fields shared by every provider, like the From address and the sending limits.
	 *
	 * @since 1.3.0
	 *
	 * @param Settings $settings Settings of the form.
	 * @return array[] Normalized fields keyed by field key.
	 */
	public static function get_general_fields( Settings $settings ): array {
		return array_diff_key( $settings->get_fields(), $settings->get_provider_fields( $settings->get_provider_slug() ) );
	}
}
