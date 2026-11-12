<?php
/**
 * Trait WordPress\SMTP\Test_Utils\Traits\With_PHPMailer
 *
 * @package smtp
 */

namespace WordPress\SMTP\Test_Utils\Traits;

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

trait With_PHPMailer {

	/**
	 * Returns a fresh PHPMailer instance, loading the core classes when needed.
	 */
	protected function get_phpmailer(): PHPMailer {
		// wp_mail() loads the three classes together. Another test may have loaded only PHPMailer.
		if ( ! class_exists( PHPMailer::class, false ) ) {
			require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
		}

		if ( ! class_exists( SMTP::class, false ) ) {
			require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
		}

		if ( ! class_exists( Exception::class, false ) ) {
			require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
		}

		return new PHPMailer( true );
	}
}
