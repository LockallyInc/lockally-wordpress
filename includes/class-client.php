<?php
/**
 * Talking to the Lockally API.
 *
 * @package LockallyMail
 */

namespace Lockally\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * The only place in this plugin that makes a request to Lockally.
 */
class Client {

	/**
	 * Settings, for the key and the API host.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Build the client.
	 *
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Send one message.
	 *
	 * @param array $payload The Lockally send request.
	 * @return true|\WP_Error True when Lockally accepted it.
	 */
	public function send( array $payload ) {
		return $this->request( 'POST', '/v1/send', $payload );
	}

	/**
	 * Check that a key works, and say whose account it is.
	 *
	 * @param string $api_key Key to test, which may not be the saved one.
	 * @return array|\WP_Error The whoami response.
	 */
	public function whoami( $api_key = '' ) {
		return $this->request( 'GET', '/v1/auth/whoami', null, $api_key );
	}

	/**
	 * One request, with the error wording this plugin shows.
	 *
	 * @param string     $method  HTTP method.
	 * @param string     $path    Path under the API host.
	 * @param array|null $body    JSON body, or null.
	 * @param string     $api_key Key to use instead of the saved one.
	 * @return mixed|\WP_Error
	 */
	private function request( $method, $path, $body = null, $api_key = '' ) {
		$key = '' !== $api_key ? $api_key : $this->settings->api_key();
		if ( '' === $key ) {
			return new \WP_Error(
				'lockally_mail_no_key',
				__( 'No Lockally API key is saved. Add one under Settings, then Lockally Mail.', 'lockally-mail' )
			);
		}

		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/json',
				'User-Agent'    => 'Lockally-Mail-WordPress/' . VERSION . '; ' . home_url( '/' ),
			),
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
			// Lockally requires this and replays the first answer for 24 hours
			// if it sees the same one again. A fresh value per attempt is
			// deliberate: WordPress sends the same message twice when somebody
			// asks for a second password reset, and both should arrive.
			$args['headers']['Idempotency-Key'] = wp_generate_uuid4();
		}

		$response = wp_remote_request( $this->settings->api_base() . $path, $args );

		if ( is_wp_error( $response ) ) {
			// The site could not reach Lockally at all: DNS, a firewall, or an
			// outbound block on the host. Say that rather than blaming the key.
			return new \WP_Error(
				'lockally_mail_unreachable',
				sprintf(
					/* translators: %s: the underlying error from WordPress. */
					__( 'Could not reach Lockally: %s. If this site blocks outbound requests, allow api.lockally.com.', 'lockally-mail' ),
					$response->get_error_message()
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$parsed = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status >= 200 && $status < 300 ) {
			return is_array( $parsed ) ? $parsed : true;
		}

		return new \WP_Error( 'lockally_mail_refused', $this->refusal_message( $status, is_array( $parsed ) ? $parsed : array() ), array( 'status' => $status ) );
	}

	/**
	 * What a refusal should say to somebody running a website.
	 *
	 * Lockally answers with a title and a detail already written for a person,
	 * so those are passed through; what is added is the next step, because this
	 * message usually surfaces in an email that never arrived.
	 *
	 * @param int   $status  HTTP status.
	 * @param array $problem Decoded problem details.
	 * @return string
	 */
	private function refusal_message( $status, array $problem ) {
		$title  = isset( $problem['title'] ) ? (string) $problem['title'] : '';
		$detail = isset( $problem['detail'] ) ? (string) $problem['detail'] : '';
		$said   = trim( $title . ( '' !== $detail ? '. ' . $detail : '' ) );
		if ( '' === $said ) {
			/* translators: %d: HTTP status code. */
			$said = sprintf( __( 'Lockally refused the message (HTTP %d).', 'lockally-mail' ), $status );
		}

		switch ( $status ) {
			case 401:
				return $said . ' ' . __( 'Check the API key saved under Settings, then Lockally Mail.', 'lockally-mail' );
			case 402:
			case 403:
				if ( isset( $problem['reason'] ) && 'plan' === $problem['reason'] ) {
					return $said . ' ' . __( 'Open Billing in the Lockally console to change plan.', 'lockally-mail' );
				}
				return $said . ' ' . __( 'The key needs the send:write permission, and the From address must be on a domain verified in Lockally.', 'lockally-mail' );
			case 429:
				return $said . ' ' . __( 'Lockally is rate limiting this account. The message was not sent; WordPress will not retry it on its own.', 'lockally-mail' );
			default:
				return $said;
		}
	}
}
