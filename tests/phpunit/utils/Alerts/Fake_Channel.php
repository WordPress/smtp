<?php
/**
 * Alert channel used by the tests.
 *
 * @package smtp
 */

namespace WordPress\SMTP\Test_Utils\Alerts;

use WordPress\SMTP\Alerts\Alert;
use WordPress\SMTP\Alerts\Alert_Channel;

/**
 * Records the alerts it receives instead of delivering them.
 */
class Fake_Channel implements Alert_Channel {

	/**
	 * Alerts received, with the values they were sent with.
	 *
	 * @var array[]
	 */
	public $sent = array();

	/**
	 * Result returned by send().
	 *
	 * @var true|\WP_Error
	 */
	public $result = true;

	/**
	 * Optional callback run when an alert is sent.
	 *
	 * @var callable|null
	 */
	public $on_send = null;

	/**
	 * Channel ID.
	 *
	 * @var string
	 */
	private $id;

	/**
	 * Constructor.
	 *
	 * @param string $id Channel ID.
	 */
	public function __construct( string $id = 'fake' ) {
		$this->id = $id;
	}

	/**
	 * Returns the channel ID.
	 *
	 * @return string Channel ID.
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * Returns the channel name.
	 *
	 * @return string Channel name.
	 */
	public function get_label(): string {
		return 'Fake';
	}

	/**
	 * Returns the fields: a plain text field and a secret.
	 *
	 * @return array[] Fields.
	 */
	public function get_fields(): array {
		return array(
			'room'  => array(
				'label' => 'Room',
				'type'  => 'text',
			),
			'token' => array(
				'label' => 'Token',
				'type'  => 'password',
			),
		);
	}

	/**
	 * Sanitizes the values.
	 *
	 * @param string[] $input Values.
	 * @return string[] Values.
	 */
	public function sanitize_settings( array $input ): array {
		return array_map( 'sanitize_text_field', $input );
	}

	/**
	 * Configured once a token is saved.
	 *
	 * @param string[] $values Values.
	 * @return bool Whether configured.
	 */
	public function is_configured( array $values ): bool {
		return '' !== ( $values['token'] ?? '' );
	}

	/**
	 * Records the alert.
	 *
	 * @param Alert    $alert  Alert.
	 * @param string[] $values Values.
	 * @return true|\WP_Error Result.
	 */
	public function send( Alert $alert, array $values ) {
		$this->sent[] = array(
			'alert'  => $alert,
			'values' => $values,
		);

		if ( is_callable( $this->on_send ) ) {
			call_user_func( $this->on_send );
		}

		return $this->result;
	}
}
