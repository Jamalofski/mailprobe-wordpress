<?php
/**
 * The check at registration: the WordPress form (wp-login.php) and the
 * WooCommerce "My account" form. Here, and only here, the plugin refuses.
 *
 * @package MailProbe
 */

defined( 'ABSPATH' ) || exit;

/**
 * Refuses registrations whose address cannot receive emails, is disposable, or
 * (if the site asks) is a shared address.
 */
class MailProbe_Registration {

	/**
	 * Seconds asked of the API, the shortest it accepts.
	 */
	const ANSWER_WITHIN = 5;

	/**
	 * Seconds the visitor waits at most, by default. A probe still running at that
	 * point is not charged, and the visitor goes through unverified: most answers
	 * come in under a second, and the slow ones are mail servers that make every
	 * sender wait, real customers included.
	 */
	const WAIT = 3;

	/**
	 * Hooks into both forms.
	 */
	public static function hooks() {
		// Priority 20: the other checks of the form (CAPTCHA, honeypot, address
		// already taken) speak first, and a form they refuse costs no credit.
		add_filter( 'registration_errors', array( __CLASS__, 'on_wordpress_form' ), 20, 3 );
		add_filter( 'woocommerce_registration_errors', array( __CLASS__, 'on_woocommerce_form' ), 20, 3 );
	}

	/**
	 * The WordPress registration form.
	 *
	 * @param WP_Error $errors The errors so far.
	 * @param string   $login  The username.
	 * @param string   $email  The email address.
	 * @return WP_Error
	 */
	public static function on_wordpress_form( $errors, $login, $email ) {
		$refusal = self::refusal( $errors, $email );
		if ( null !== $refusal ) {
			$errors->add( 'mailprobe_email', '<strong>' . esc_html__( 'Error:', 'mailprobe' ) . '</strong> ' . esc_html( $refusal ) );
		}
		return $errors;
	}

	/**
	 * The WooCommerce registration, from the "My account" page.
	 *
	 * WooCommerce runs the same filter when an account is created at checkout. The
	 * order comes first there: nothing is refused, the order is checked afterwards
	 * and flagged if needed (MailProbe_Orders).
	 *
	 * @param WP_Error $errors   The errors so far.
	 * @param string   $username The username.
	 * @param string   $email    The email address.
	 * @return WP_Error
	 */
	public static function on_woocommerce_form( $errors, $username, $email ) {
		if ( self::in_checkout() ) {
			return $errors;
		}
		$refusal = self::refusal( $errors, $email );
		if ( null !== $refusal ) {
			$errors->add( 'mailprobe_email', esc_html( $refusal ) );
		}
		return $errors;
	}

	/**
	 * Whether the request is a checkout: the classic one defines WOOCOMMERCE_CHECKOUT,
	 * the block one goes through the Store API, a REST request.
	 *
	 * @return bool
	 */
	private static function in_checkout() {
		if ( defined( 'WOOCOMMERCE_CHECKOUT' ) && WOOCOMMERCE_CHECKOUT ) {
			return true;
		}
		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}

	/**
	 * Checks the address and says why it is refused, or null to let it through.
	 *
	 * Nothing is refused on an error of MailProbe (network, no credits, key
	 * refused): the visitor goes through, and the shop manager is told in the
	 * admin when the error stops every check.
	 *
	 * @param WP_Error $errors The errors so far.
	 * @param string   $email  The email address.
	 * @return string|null
	 */
	private static function refusal( $errors, $email ) {
		if ( ! MailProbe::connected() || ! is_wp_error( $errors ) || $errors->has_errors() ) {
			return null;
		}
		$email = trim( (string) $email );
		if ( '' === $email || ! is_email( $email ) ) {
			return null;
		}

		/**
		 * Seconds a visitor waits at most for the check at registration.
		 *
		 * @param int|float $seconds Default 3.
		 */
		$wait   = apply_filters( 'mailprobe_registration_wait', self::WAIT );
		$result = MailProbe::client()->verify( $email, self::ANSWER_WITHIN, $wait );
		if ( is_wp_error( $result ) ) {
			MailProbe::note_error( $result );
			return null;
		}
		MailProbe::clear_alert();
		MailProbe::count( 'checked' );

		$word = MailProbe_Policy::registration_block( $result, MailProbe::settings() );
		if ( null === $word ) {
			return null;
		}
		MailProbe::count( 'blocked' );

		/**
		 * The message shown to a visitor whose registration is refused.
		 *
		 * @param string $message The message, plain text.
		 * @param string $word    Why: `invalid`, `disposable` or `role`.
		 * @param array  $result  The MailProbe result.
		 */
		return (string) apply_filters( 'mailprobe_registration_message', self::message( $word, $result ), $word, $result );
	}

	/**
	 * The message for a refusal.
	 *
	 * @param string $word   `invalid`, `disposable` or `role`.
	 * @param array  $result The MailProbe result.
	 * @return string
	 */
	private static function message( $word, $result ) {
		if ( 'disposable' === $word ) {
			return __( 'Disposable email addresses are not accepted. Please use your usual email address.', 'mailprobe' );
		}
		if ( 'role' === $word ) {
			return __( 'Please use a personal email address, not a shared one such as info@ or contact@.', 'mailprobe' );
		}
		$suggestion = MailProbe_Policy::suggestion( $result );
		if ( null !== $suggestion ) {
			/* translators: %s: the email address with the likely typo corrected. */
			return sprintf( __( 'This email address cannot receive emails. Did you mean %s?', 'mailprobe' ), $suggestion );
		}
		return __( 'This email address cannot receive emails. Please check it and try again.', 'mailprobe' );
	}
}
