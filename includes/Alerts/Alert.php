<?php
/**
 * Class WordPress\SMTP\Alerts\Alert
 *
 * @package smtp
 */

namespace WordPress\SMTP\Alerts;

/**
 * Describes an event that alert channels report.
 *
 * Channels only format and deliver an alert; they never decide when one is sent.
 *
 * @since 1.2.0
 */
class Alert {

	/**
	 * An email could not be sent.
	 *
	 * @since 1.2.0
	 */
	const TYPE_FAILURE = 'failure';

	/**
	 * A test alert requested from the Alerts tab.
	 *
	 * @since 1.2.0
	 */
	const TYPE_TEST = 'test';

	/**
	 * Alert type, one of the TYPE_* constants.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	private $type;

	/**
	 * Error message.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	private $error;

	/**
	 * Recipients of the failed email.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	private $recipients;

	/**
	 * Subject of the failed email.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	private $subject;

	/**
	 * Number of failures this alert reports, including the ones throttled since the previous alert.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	private $failures;

	/**
	 * Unix timestamp of the event.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	private $time;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 *
	 * @param string   $type       Alert type, one of the TYPE_* constants.
	 * @param string   $error      Optional. Error message. Default empty.
	 * @param string[] $recipients Optional. Recipients of the failed email. Default empty.
	 * @param string   $subject    Optional. Subject of the failed email. Default empty.
	 * @param int      $failures   Optional. Number of failures reported. Default 1.
	 * @param int      $time       Optional. Unix timestamp of the event. Default now.
	 */
	public function __construct( string $type, string $error = '', array $recipients = array(), string $subject = '', int $failures = 1, int $time = 0 ) {
		$this->type       = $type;
		$this->error      = $error;
		$this->recipients = array_values( array_filter( array_map( 'strval', $recipients ) ) );
		$this->subject    = $subject;
		$this->failures   = max( 1, $failures );
		$this->time       = $time > 0 ? $time : time();
	}

	/**
	 * Builds a failure alert from the error passed to `wp_mail_failed`.
	 *
	 * @since 1.2.0
	 *
	 * @param \WP_Error $error Error, as passed to `wp_mail_failed`.
	 * @return Alert Failure alert.
	 */
	public static function from_mail_error( \WP_Error $error ): Alert {
		$mail_data = $error->get_error_data();
		$mail_data = is_array( $mail_data ) ? $mail_data : array();

		return new self(
			self::TYPE_FAILURE,
			$error->get_error_message(),
			isset( $mail_data['to'] ) ? (array) $mail_data['to'] : array(),
			isset( $mail_data['subject'] ) && is_string( $mail_data['subject'] ) ? $mail_data['subject'] : ''
		);
	}

	/**
	 * Returns a copy of the alert that reports a different number of failures.
	 *
	 * @since 1.2.0
	 *
	 * @param int $failures Number of failures.
	 * @return Alert New alert.
	 */
	public function with_failures( int $failures ): Alert {
		$alert           = clone $this;
		$alert->failures = max( 1, $failures );

		return $alert;
	}

	/**
	 * Returns the alert type.
	 *
	 * @since 1.2.0
	 *
	 * @return string One of the TYPE_* constants.
	 */
	public function get_type(): string {
		return $this->type;
	}

	/**
	 * Returns the error message.
	 *
	 * @since 1.2.0
	 *
	 * @return string Error message.
	 */
	public function get_error(): string {
		return $this->error;
	}

	/**
	 * Returns the recipients of the failed email.
	 *
	 * @since 1.2.0
	 *
	 * @return string[] Recipients.
	 */
	public function get_recipients(): array {
		return $this->recipients;
	}

	/**
	 * Returns the subject of the failed email.
	 *
	 * @since 1.2.0
	 *
	 * @return string Subject.
	 */
	public function get_subject(): string {
		return $this->subject;
	}

	/**
	 * Returns the number of failures this alert reports.
	 *
	 * @since 1.2.0
	 *
	 * @return int Number of failures, at least 1.
	 */
	public function get_failures(): int {
		return $this->failures;
	}

	/**
	 * Returns the time of the event.
	 *
	 * @since 1.2.0
	 *
	 * @return int Unix timestamp.
	 */
	public function get_time(): int {
		return $this->time;
	}
}
