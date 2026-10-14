<?php
/**
 * The MailProbe API, as the plugin needs it: verify one address, read the credit balance.
 *
 * @package MailProbe
 */

defined( 'ABSPATH' ) || exit;

/**
 * Talks to https://mailprobe.dev/api/v1 with the site's API key.
 *
 * Every failure comes back as a WP_Error whose code says what to do about it:
 * the callers never block anyone on an error, they let the visitor through.
 */
class MailProbe_Client {

	/**
	 * The API key, `mp_live_...`.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * The API root, without a trailing slash.
	 *
	 * @var string
	 */
	private $base;

	/**
	 * @param string      $api_key The MailProbe API key.
	 * @param string|null $base    The API root; MAILPROBE_API_BASE when null.
	 */
	public function __construct( $api_key, $base = null ) {
		$this->api_key = (string) $api_key;
		$this->base    = rtrim( null === $base ? MAILPROBE_API_BASE : $base, '/' );
	}

	/**
	 * Verifies one address.
	 *
	 * Two clocks. `$answer_within` is asked of the API (5 seconds at least): an
	 * address that has no verdict by then comes back `unknown` with the reason
	 * `timeout`, at no charge. `$wait` is how long this site waits for the answer,
	 * which can be shorter: a call cut while the address is still being probed is
	 * not charged either, and comes back here as an error, so the visitor goes
	 * through unverified.
	 *
	 * @param string    $email         The address.
	 * @param int       $answer_within Seconds the API may take, from 5 to 95.
	 * @param int|float $wait          Seconds this site waits; the API's time plus a margin when null.
	 * @return array|WP_Error The verification result, or why there is none.
	 */
	public function verify( $email, $answer_within, $wait = null ) {
		$answer_within = max( 5, min( 95, (int) $answer_within ) );
		$response      = $this->request(
			'POST',
			'/verify',
			array(
				'emails'  => array( (string) $email ),
				'timeout' => $answer_within,
			),
			null === $wait ? $answer_within + 5 : $wait
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! is_array( $response ) || ! isset( $response[0] ) || ! is_array( $response[0] ) || ! isset( $response[0]['status'] ) ) {
			return new WP_Error( 'mailprobe_bad_response', __( 'MailProbe sent an answer the plugin cannot read.', 'mailprobe' ) );
		}
		return $response[0];
	}

	/**
	 * Reads the credit balance.
	 *
	 * @return int|WP_Error Credits left, or why the balance could not be read.
	 */
	public function credits() {
		$response = $this->request( 'GET', '/credits', null, 10 );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! is_array( $response ) || ! isset( $response['credits'] ) || ! is_numeric( $response['credits'] ) ) {
			return new WP_Error( 'mailprobe_bad_response', __( 'MailProbe sent an answer the plugin cannot read.', 'mailprobe' ) );
		}
		return (int) $response['credits'];
	}

	/**
	 * The User-Agent of every call: the product token first, so that MailProbe can
	 * tell the plugin's calls from the others. No site address, unlike the default
	 * User-Agent of WordPress.
	 *
	 * @return string
	 */
	public static function user_agent() {
		$agent = 'mailprobe-wordpress/' . MAILPROBE_VERSION . ' WordPress/' . get_bloginfo( 'version' );
		if ( defined( 'WC_VERSION' ) ) {
			$agent .= ' WooCommerce/' . WC_VERSION;
		}
		return $agent;
	}

	/**
	 * One call to the API.
	 *
	 * @param string     $method  GET or POST.
	 * @param string     $path    Path under the API root.
	 * @param array|null $body    JSON body, or null.
	 * @param int        $timeout Seconds before the HTTP call gives up.
	 * @return array|WP_Error The decoded JSON body, or the error.
	 */
	private function request( $method, $path, $body, $timeout ) {
		if ( '' === $this->api_key ) {
			return new WP_Error( 'mailprobe_no_key', __( 'No MailProbe API key is set.', 'mailprobe' ) );
		}
		$args = array(
			'method'     => $method,
			'timeout'    => $timeout,
			'user-agent' => self::user_agent(),
			'headers'    => array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $this->base . $path, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'mailprobe_unreachable',
				/* translators: %s: the network error message. */
				sprintf( __( 'MailProbe could not be reached: %s', 'mailprobe' ), $response->get_error_message() ),
				array( 'transient' => true )
			);
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $status >= 200 && $status < 300 ) {
			return $decoded;
		}
		return $this->error_for( $status, is_array( $decoded ) ? $decoded : array(), $response );
	}

	/**
	 * Turns a refusal of the API into a WP_Error.
	 *
	 * @param int   $status   HTTP status.
	 * @param array $body     The JSON body: `{ error, code }`.
	 * @param array $response The raw response, for the Retry-After header.
	 * @return WP_Error
	 */
	private function error_for( $status, $body, $response ) {
		$api_code = isset( $body['code'] ) && is_string( $body['code'] ) ? $body['code'] : '';
		$message  = isset( $body['error'] ) && is_string( $body['error'] ) ? $body['error'] : '';
		$data     = array(
			'status'   => $status,
			'api_code' => $api_code,
		);

		if ( 401 === $status ) {
			return new WP_Error( 'mailprobe_unauthorized', __( 'MailProbe refused the API key. Check it in Settings > MailProbe.', 'mailprobe' ), $data );
		}
		if ( 402 === $status && 'PAID_ONLY_DOMAIN' === $api_code ) {
			// One domain the free credits do not cover. Not a reason to stop the other checks.
			return new WP_Error( 'mailprobe_paid_only_domain', $message ? $message : __( 'This domain can only be verified with purchased credits.', 'mailprobe' ), $data );
		}
		if ( 402 === $status ) {
			return new WP_Error( 'mailprobe_no_credits', __( 'Your MailProbe account has no credits left.', 'mailprobe' ), $data );
		}
		if ( 429 === $status ) {
			$retry_after       = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			$data['transient'] = true;
			$data['retry']     = $retry_after > 0 ? $retry_after : 60;
			return new WP_Error( 'mailprobe_rate_limited', __( 'MailProbe asked to slow down (rate limit).', 'mailprobe' ), $data );
		}
		if ( $status >= 500 ) {
			$data['transient'] = true;
			/* translators: %d: HTTP status code. */
			return new WP_Error( 'mailprobe_server_error', sprintf( __( 'MailProbe had a server error (HTTP %d).', 'mailprobe' ), $status ), $data );
		}
		/* translators: 1: HTTP status code, 2: message from the API. */
		return new WP_Error( 'mailprobe_refused', sprintf( __( 'MailProbe refused the request (HTTP %1$d): %2$s', 'mailprobe' ), $status, $message ), $data );
	}
}
