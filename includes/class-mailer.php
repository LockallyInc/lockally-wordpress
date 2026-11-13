<?php
/**
 * Taking over wp_mail.
 *
 * @package LockallyMail
 */

namespace Lockally\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a wp_mail() call into a Lockally send.
 */
class Mailer {

	/** Lockally accepts up to 10 MB per attachment. */
	const MAX_ATTACHMENT_BYTES = 10485760;

	/**
	 * The API client this mailer sends through.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * Build the mailer.
	 *
	 * @param Client $client API client.
	 */
	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * Hook in.
	 *
	 * `pre_wp_mail` (WordPress 5.7 and later) is the supported way to replace
	 * sending: returning a boolean stops core from touching PHPMailer at all,
	 * so nothing is sent twice and nothing depends on the server's own mail
	 * setup.
	 */
	public function hooks() {
		add_filter( 'pre_wp_mail', array( $this, 'send' ), 10, 2 );
	}

	/**
	 * Send a message through Lockally.
	 *
	 * @param null|bool $short_circuit Whatever an earlier plugin decided.
	 * @param array     $atts          The wp_mail() arguments.
	 * @return null|bool Null to leave it alone, true sent, false failed.
	 */
	public function send( $short_circuit, $atts ) {
		// Another plugin already took the message. Two plugins both sending it
		// is worse than this one standing aside.
		if ( null !== $short_circuit ) {
			return $short_circuit;
		}

		$settings = new Settings();
		$parsed   = self::parse_headers( isset( $atts['headers'] ) ? $atts['headers'] : '' );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core wp_mail() filter, applied here exactly as core applies it.
		$content_type = apply_filters( 'wp_mail_content_type', $parsed['content_type'] );
		$is_html      = false !== strpos( strtolower( (string) $content_type ), 'text/html' );

		$from_email = $parsed['from_email'];
		if ( '' === $from_email ) {
			$from_email = $settings->from_email();
		}
		/** This filter is documented in wp-includes/pluggable.php */
		$from_email = apply_filters( 'wp_mail_from', $from_email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core wp_mail() filter.

		// Only the address goes to Lockally. Its send API takes a bare
		// local@domain and refuses "Name <address>", so a display name from a
		// From header or the wp_mail_from_name filter would stop every message,
		// password resets included. Recipients see the name set on the sending
		// mailbox in Lockally instead.

		$to = self::addresses( isset( $atts['to'] ) ? $atts['to'] : array() );
		if ( empty( $to ) ) {
			return $this->fail( $atts, new \WP_Error( 'lockally_mail_no_recipient', __( 'That message had no recipient.', 'lockally-mail' ) ) );
		}

		$message = isset( $atts['message'] ) ? (string) $atts['message'] : '';

		$payload = array(
			'from'    => $from_email,
			'to'      => $to,
			'subject' => isset( $atts['subject'] ) ? (string) $atts['subject'] : '',
		);

		if ( $is_html ) {
			$payload['html'] = $message;
		} else {
			$payload['text'] = $message;
		}

		if ( ! empty( $parsed['cc'] ) ) {
			$payload['cc'] = $parsed['cc'];
		}
		if ( ! empty( $parsed['bcc'] ) ) {
			$payload['bcc'] = $parsed['bcc'];
		}
		if ( ! empty( $parsed['headers'] ) ) {
			$payload['headers'] = $parsed['headers'];
		}

		$attachments = $this->attachments( isset( $atts['attachments'] ) ? $atts['attachments'] : array() );
		if ( is_wp_error( $attachments ) ) {
			return $this->fail( $atts, $attachments );
		}
		if ( ! empty( $attachments ) ) {
			$payload['attachments'] = $attachments;
		}

		$result = $this->client->send( $payload );
		if ( is_wp_error( $result ) ) {
			return $this->fail( $atts, $result );
		}

		return true;
	}

	/**
	 * Record a failure and tell WordPress, the way core does.
	 *
	 * `wp_mail_failed` is what other plugins listen on, so a site that already
	 * reports mail problems keeps reporting them.
	 *
	 * @param array     $atts  Original wp_mail arguments.
	 * @param \WP_Error $error What went wrong.
	 * @return false
	 */
	private function fail( $atts, $error ) {
		Settings::record_failure(
			isset( $atts['subject'] ) ? (string) $atts['subject'] : '',
			self::addresses( isset( $atts['to'] ) ? $atts['to'] : array() ),
			$error->get_error_message()
		);

		$data                             = is_array( $atts ) ? $atts : array();
		$data['phpmailer_exception_code'] = 0;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core wp_mail() action, fired on failure as core fires it.
		do_action( 'wp_mail_failed', new \WP_Error( $error->get_error_code(), $error->get_error_message(), $data ) );

		return false;
	}

	/**
	 * Read the files WordPress wants attached.
	 *
	 * @param array|string $attachments Paths, or name => path since WordPress 6.3.
	 * @return array|\WP_Error
	 */
	private function attachments( $attachments ) {
		if ( ! is_array( $attachments ) ) {
			$attachments = explode( "\n", str_replace( "\r\n", "\n", (string) $attachments ) );
		}

		$out = array();
		foreach ( $attachments as $name => $path ) {
			$path = trim( (string) $path );
			if ( '' === $path ) {
				continue;
			}
			if ( ! is_readable( $path ) ) {
				return new \WP_Error(
					'lockally_mail_attachment_missing',
					sprintf(
						/* translators: %s: file path. */
						__( 'The attachment %s could not be read, so the message was not sent.', 'lockally-mail' ),
						basename( $path )
					)
				);
			}

			$size = (int) filesize( $path );
			if ( $size > self::MAX_ATTACHMENT_BYTES ) {
				return new \WP_Error(
					'lockally_mail_attachment_too_large',
					sprintf(
						/* translators: 1: file name, 2: size in megabytes. */
						__( 'The attachment %1$s is %2$s MB. Lockally accepts up to 10 MB per attachment.', 'lockally-mail' ),
						basename( $path ),
						number_format_i18n( $size / 1048576, 1 )
					)
				);
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local attachment, not a remote resource.
			$contents = file_get_contents( $path );
			if ( false === $contents ) {
				return new \WP_Error(
					'lockally_mail_attachment_unreadable',
					sprintf(
						/* translators: %s: file name. */
						__( 'The attachment %s could not be read, so the message was not sent.', 'lockally-mail' ),
						basename( $path )
					)
				);
			}

			$filename = is_string( $name ) && '' !== $name ? $name : basename( $path );
			$type     = wp_check_filetype( $filename );

			$out[] = array(
				'filename'       => $filename,
				'content_type'   => ! empty( $type['type'] ) ? $type['type'] : 'application/octet-stream',
				'content_base64' => base64_encode( $contents ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the API takes attachments base64 encoded.
			);
		}

		return $out;
	}

	/**
	 * Addresses from whatever wp_mail was given.
	 *
	 * @param array|string $value One address, several separated by commas, or a list.
	 * @return array
	 */
	public static function addresses( $value ) {
		if ( ! is_array( $value ) ) {
			$value = explode( ',', (string) $value );
		}
		$out = array();
		foreach ( $value as $address ) {
			$address = trim( (string) $address );
			if ( '' !== $address ) {
				$out[] = $address;
			}
		}
		return $out;
	}

	/**
	 * Pull apart the headers wp_mail was given.
	 *
	 * This is the part of wp_mail that plugins get wrong, so it is written to
	 * be read on its own and is covered by tests: headers arrive as a string or
	 * an array, in any case, sometimes with a display name, and anything this
	 * misses is a header the message silently loses.
	 *
	 * Deliberately kept free of WordPress functions so it can be tested without
	 * loading WordPress.
	 *
	 * @param array|string $headers Raw headers.
	 * @return array {
	 *     @type string $from_email   The address in a From header, or ''.
	 *     @type string $from_name    The display name in a From header, or ''.
	 *     @type array  $cc           Cc addresses.
	 *     @type array  $bcc          Bcc addresses.
	 *     @type string $content_type The Content-Type, defaulting to text/plain.
	 *     @type array  $headers      Any other header worth passing on.
	 * }
	 */
	public static function parse_headers( $headers ) {
		$out = array(
			'from_email'   => '',
			'from_name'    => '',
			'cc'           => array(),
			'bcc'          => array(),
			'content_type' => 'text/plain',
			'headers'      => array(),
		);

		if ( ! is_array( $headers ) ) {
			$headers = explode( "\n", str_replace( "\r\n", "\n", (string) $headers ) );
		}

		// Headers WordPress or Lockally sets itself; passing them on would
		// either duplicate or contradict what actually goes out.
		$ours = array( 'content-type', 'mime-version', 'content-transfer-encoding', 'to', 'subject', 'from', 'cc', 'bcc', 'date', 'message-id' );

		foreach ( $headers as $header ) {
			$header = trim( (string) $header );
			if ( '' === $header || false === strpos( $header, ':' ) ) {
				continue;
			}

			list( $name, $value ) = explode( ':', $header, 2 );
			$name                 = strtolower( trim( $name ) );
			$value                = trim( $value );

			switch ( $name ) {
				case 'from':
					$parsed            = self::split_address( $value );
					$out['from_name']  = $parsed['name'];
					$out['from_email'] = $parsed['email'];
					break;
				case 'cc':
					$out['cc'] = array_merge( $out['cc'], self::header_addresses( $value ) );
					break;
				case 'bcc':
					$out['bcc'] = array_merge( $out['bcc'], self::header_addresses( $value ) );
					break;
				case 'content-type':
					$out['content_type'] = trim( explode( ';', $value )[0] );
					break;
				case 'reply-to':
					$out['headers']['Reply-To'] = $value;
					break;
				default:
					if ( ! in_array( $name, $ours, true ) ) {
						// Keep the sender's own capitalisation: some receiving
						// systems match on it.
						$out['headers'][ trim( explode( ':', $header, 2 )[0] ) ] = $value;
					}
			}
		}

		return $out;
	}

	/**
	 * The addresses in one Cc or Bcc header.
	 *
	 * @param string $value Header value.
	 * @return array
	 */
	private static function header_addresses( $value ) {
		$out = array();
		foreach ( explode( ',', $value ) as $part ) {
			$parsed = self::split_address( $part );
			if ( '' !== $parsed['email'] ) {
				$out[] = '' !== $parsed['name'] ? sprintf( '%s <%s>', $parsed['name'], $parsed['email'] ) : $parsed['email'];
			}
		}
		return $out;
	}

	/**
	 * Split "Name <address>" into its two halves.
	 *
	 * @param string $value One address, with or without a display name.
	 * @return array {
	 *     @type string $name
	 *     @type string $email
	 * }
	 */
	public static function split_address( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '/^(.*)<(.+)>$/', $value, $matches ) ) {
			return array(
				'name'  => trim( trim( $matches[1] ), '"\'' ),
				'email' => trim( $matches[2] ),
			);
		}
		return array(
			'name'  => '',
			'email' => $value,
		);
	}
}
