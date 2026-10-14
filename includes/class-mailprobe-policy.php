<?php
/**
 * What a verification result means for this site: one word per result, and the
 * decision at registration.
 *
 * @package MailProbe
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pure functions over a MailProbe result (the `/api/v1/verify` entry): no HTTP,
 * no database, so the rules can be read and tested on their own.
 */
class MailProbe_Policy {

	/**
	 * Settings that block at registration, and their defaults.
	 *
	 * `catch-all` and `unknown` have no setting on purpose: a third of real
	 * addresses come back that way (a domain that accepts everything, a provider
	 * that does not let itself be probed such as Microsoft, a slow server), and
	 * blocking them would turn real customers away.
	 *
	 * @var array<string,bool>
	 */
	const DEFAULTS = array(
		'block_invalid'    => true,
		'block_disposable' => true,
		'block_role'       => false,
	);

	/**
	 * One word for a result, the first that applies:
	 * `invalid` (bad format, no mail server, mailbox that does not exist),
	 * `disposable`, `role` (info@, contact@...), then `valid`, `risky` (accepted
	 * but unconfirmed: catch-all domain, provider that blocks probes) or `unknown`.
	 *
	 * @param array $result A verification result.
	 * @return string
	 */
	public static function classify( $result ) {
		$status = isset( $result['status'] ) ? $result['status'] : 'unknown';
		if ( 'invalid' === $status ) {
			return 'invalid';
		}
		if ( ! empty( $result['disposable'] ) ) {
			return 'disposable';
		}
		if ( ! empty( $result['role_based'] ) ) {
			return 'role';
		}
		if ( in_array( $status, array( 'valid', 'risky' ), true ) ) {
			return $status;
		}
		return 'unknown';
	}

	/**
	 * Whether a registration with this result is refused.
	 *
	 * @param array $result   A verification result.
	 * @param array $settings The plugin settings (see DEFAULTS).
	 * @return string|null The word that blocks (`invalid`, `disposable`, `role`), or null to let it through.
	 */
	public static function registration_block( $result, $settings ) {
		$settings = wp_parse_args( $settings, self::DEFAULTS );
		$word     = self::classify( $result );
		$blocking = array(
			'invalid'    => ! empty( $settings['block_invalid'] ),
			'disposable' => ! empty( $settings['block_disposable'] ),
			'role'       => ! empty( $settings['block_role'] ),
		);
		return ! empty( $blocking[ $word ] ) ? $word : null;
	}

	/**
	 * Whether an order with this result is flagged for the shop manager.
	 *
	 * A role address on an order is ordinary (a company buying), so only
	 * addresses that cannot be written to are flagged.
	 *
	 * @param string $word The word from classify().
	 * @return bool
	 */
	public static function order_flagged( $word ) {
		return in_array( $word, array( 'invalid', 'disposable' ), true );
	}

	/**
	 * The suggested correction for a likely typo (`gmial.com` -> `gmail.com`), as a
	 * full address, or null.
	 *
	 * @param array $result A verification result.
	 * @return string|null
	 */
	public static function suggestion( $result ) {
		if ( empty( $result['did_you_mean'] ) || ! is_string( $result['did_you_mean'] ) ) {
			return null;
		}
		$suggested = $result['did_you_mean'];
		// The API documents a corrected domain; accept a full address as well.
		if ( false === strpos( $suggested, '@' ) && ! empty( $result['email'] ) && false !== strpos( $result['email'], '@' ) ) {
			$suggested = substr( $result['email'], 0, strrpos( $result['email'], '@' ) + 1 ) . $suggested;
		}
		return is_email( $suggested ) ? $suggested : null;
	}

	/**
	 * Why the address was judged as it was, in words a shop manager reads.
	 *
	 * @param array $result A verification result.
	 * @return string
	 */
	public static function reason_text( $result ) {
		$reason = isset( $result['reason'] ) && is_string( $result['reason'] ) ? $result['reason'] : '';
		$texts  = array(
			'invalid_syntax'        => __( 'the address is not well formed', 'mailprobe' ),
			'no_mx'                 => __( 'the domain has no mail server', 'mailprobe' ),
			'mx_unresolved'         => __( 'the mail server of the domain cannot be found', 'mailprobe' ),
			'mx_private_ip'         => __( 'the mail server of the domain is not reachable from the internet', 'mailprobe' ),
			'mailbox_full'          => __( 'the mailbox is full', 'mailprobe' ),
			'accept_all'            => __( 'the domain accepts every address, so this mailbox cannot be confirmed', 'mailprobe' ),
			'provider_blocks_probe' => __( 'the email provider does not allow checks', 'mailprobe' ),
			'greylisting'           => __( 'the mail server asked to try again later', 'mailprobe' ),
			'deferred'              => __( 'the mail server asked to try again later', 'mailprobe' ),
			'timeout'               => __( 'the mail server did not answer in time', 'mailprobe' ),
		);
		if ( isset( $texts[ $reason ] ) ) {
			return $texts[ $reason ];
		}
		if ( isset( $result['result'] ) && 'undeliverable' === $result['result'] ) {
			return __( 'the mailbox does not exist', 'mailprobe' );
		}
		if ( '' !== $reason ) {
			return $reason;
		}
		return __( 'no detail', 'mailprobe' );
	}
}
