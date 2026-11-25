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

		switch ( $field['type'] ) {
			case 'checkbox':
				?>
				<label for="<?php echo esc_attr( $id ); ?>">
					<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( (bool) $value ); ?> <?php disabled( $disabled ); ?><?php echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped when built. ?> />
					<?php echo esc_html( $field['description'] ); ?>
				</label>
				<?php
				$field['description'] = '';
				break;

			case 'select':
				?>
				<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" <?php disabled( $disabled ); ?><?php echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped when built. ?>>
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
					<?php disabled( $disabled ); ?><?php echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped when built. ?>
				/>
				<?php
				if ( '' !== $value && ! $locked ) {
					$remove_id = $id . '-remove';
					?>
					<p>
						<label for="<?php echo esc_attr( $remove_id ); ?>">
							<input type="checkbox" id="<?php echo esc_attr( $remove_id ); ?>" name="<?php echo esc_attr( Settings::OPTION . '[remove_secrets][' . $key . ']' ); ?>" value="1" />
							<?php esc_html_e( 'Remove the saved password', 'smtp' ); ?>
						</label>
					</p>
					<?php
					$field['description'] = __( 'A password is saved. Leave empty to keep it, or check the box above to remove it.', 'smtp' );
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
					<?php disabled( $disabled ); ?><?php echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped when built. ?>
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
