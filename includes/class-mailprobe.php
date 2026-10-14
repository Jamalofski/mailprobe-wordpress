<?php
/**
 * Settings, counters and the alert the shop manager must see: what every part of
 * the plugin shares.
 *
 * @package MailProbe
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shared state of the plugin.
 */
final class MailProbe {

	/**
	 * Option holding the settings.
	 */
	const OPTION = 'mailprobe_settings';

	/**
	 * Option holding the counters shown on the settings page.
	 */
	const STATS = 'mailprobe_stats';

	/**
	 * Option holding the last error that stops every check (no credits, key refused).
	 */
	const ALERT = 'mailprobe_alert';

	/**
	 * Errors that stop every check until the shop manager acts.
	 *
	 * @var string[]
	 */
	const BLOCKING_ERRORS = array( 'mailprobe_no_credits', 'mailprobe_unauthorized' );

	/**
	 * Settings and their defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array_merge(
			array(
				'api_key'      => '',
				'check_orders' => true,
			),
			MailProbe_Policy::DEFAULTS
		);
	}

	/**
	 * The settings, defaults filled in.
	 *
	 * @return array
	 */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * The API key: the MAILPROBE_API_KEY constant when wp-config.php sets it, the
	 * saved setting otherwise.
	 *
	 * @return string
	 */
	public static function api_key() {
		if ( self::key_from_constant() ) {
			return (string) MAILPROBE_API_KEY;
		}
		$settings = self::settings();
		return (string) $settings['api_key'];
	}

	/**
	 * Whether wp-config.php sets the key.
	 *
	 * @return bool
	 */
	public static function key_from_constant() {
		return defined( 'MAILPROBE_API_KEY' ) && '' !== (string) MAILPROBE_API_KEY;
	}

	/**
	 * Whether checks can run at all.
	 *
	 * @return bool
	 */
	public static function connected() {
		return '' !== self::api_key();
	}

	/**
	 * A client of the API with the site's key.
	 *
	 * @return MailProbe_Client
	 */
	public static function client() {
		return new MailProbe_Client( self::api_key() );
	}

	/**
	 * Adds one to a counter: `checked`, `blocked` or `flagged`.
	 *
	 * @param string $counter The counter.
	 */
	public static function count( $counter ) {
		$stats = get_option( self::STATS, array() );
		if ( ! is_array( $stats ) || empty( $stats['since'] ) ) {
			$stats = array( 'since' => time() );
		}
		$stats[ $counter ] = ( isset( $stats[ $counter ] ) ? (int) $stats[ $counter ] : 0 ) + 1;
		update_option( self::STATS, $stats, false );
	}

	/**
	 * The counters, zero when nothing was counted yet.
	 *
	 * @return array
	 */
	public static function stats() {
		$stats = get_option( self::STATS, array() );
		return wp_parse_args(
			is_array( $stats ) ? $stats : array(),
			array(
				'since'   => 0,
				'checked' => 0,
				'blocked' => 0,
				'flagged' => 0,
			)
		);
	}

	/**
	 * Keeps an error that stops every check, for the admin notice. Other errors
	 * (network, rate limit) pass on their own and are not kept.
	 *
	 * @param WP_Error $error The error.
	 */
	public static function note_error( $error ) {
		if ( ! in_array( $error->get_error_code(), self::BLOCKING_ERRORS, true ) ) {
			return;
		}
		update_option(
			self::ALERT,
			array(
				'code' => $error->get_error_code(),
				'time' => time(),
			),
			false
		);
	}

	/**
	 * A check went through: the alert, if any, no longer holds.
	 */
	public static function clear_alert() {
		if ( false !== get_option( self::ALERT, false ) ) {
			delete_option( self::ALERT );
		}
	}

	/**
	 * The alert, or null.
	 *
	 * @return array|null `{ code, time }`
	 */
	public static function alert() {
		$alert = get_option( self::ALERT, false );
		return is_array( $alert ) && ! empty( $alert['code'] ) ? $alert : null;
	}
}
