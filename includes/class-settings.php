<?php
/**
 * The settings screen, and the stored settings themselves.
 *
 * @package LockallyMail
 */

namespace Lockally\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the saved settings and draws the screen that changes them.
 */
class Settings {

	/** How many recent failures the status panel keeps. */
	const MAX_FAILURES = 20;

	/**
	 * Saved settings.
	 *
	 * @var array
	 */
	private $values;

	/**
	 * Load the saved settings, filling in anything not set yet.
	 */
	public function __construct() {
		$saved        = get_option( OPTION, array() );
		$this->values = wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'api_key'    => '',
				'from_email' => '',
				'from_name'  => '',
				'api_base'   => 'https://api.lockally.com',
			)
		);
	}

	/**
	 * Register the screen, the setting, and the test-message handler.
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
		add_action( 'admin_post_lockally_mail_test', array( $this, 'handle_test' ) );
	}

	/**
	 * The saved API key, or ''.
	 *
	 * @return string
	 */
	public function api_key() {
		return (string) $this->values['api_key'];
	}

	/**
	 * The address mail is sent from.
	 *
	 * @return string
	 */
	public function from_email() {
		return (string) $this->values['from_email'];
	}

	/**
	 * The name shown beside the address, or ''.
	 *
	 * @return string
	 */
	public function from_name() {
		return (string) $this->values['from_name'];
	}

	/**
	 * The API host, without a trailing slash.
	 *
	 * @return string
	 */
	public function api_base() {
		return untrailingslashit( (string) $this->values['api_base'] );
	}

	/**
	 * Whether this plugin should take over sending at all.
	 *
	 * Both halves matter. Without a key nothing can be sent; without a From
	 * address on a verified domain every message would be refused, and a
	 * refused password reset is worse than one sent by the web server.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== $this->api_key() && '' !== $this->from_email();
	}

	/**
	 * Add the screen under Settings.
	 */
	public function add_page() {
		add_options_page(
			__( 'Lockally Mail', 'lockally-mail' ),
			__( 'Lockally Mail', 'lockally-mail' ),
			'manage_options',
			'lockally-mail',
			array( $this, 'render' )
		);
	}

	/**
	 * Register the one option everything is stored in.
	 */
	public function register() {
		register_setting(
			'lockally_mail',
			OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Clean what was typed in, and keep the key when the field was left blank.
	 *
	 * The key field is never filled in with the saved key, so that the secret
	 * is not sent back to the browser on every page load. An empty field
	 * therefore means "leave it alone", not "delete it".
	 *
	 * @param mixed $input Raw form input.
	 * @return array
	 */
	public function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = array(
			'api_key'    => $this->api_key(),
			'from_email' => '',
			'from_name'  => '',
			'api_base'   => 'https://api.lockally.com',
		);

		if ( isset( $input['api_key'] ) ) {
			$key = trim( sanitize_text_field( wp_unslash( $input['api_key'] ) ) );
			if ( '' !== $key ) {
				$out['api_key'] = $key;
			}
		}
		if ( isset( $input['remove_api_key'] ) && '1' === $input['remove_api_key'] ) {
			$out['api_key'] = '';
		}
		if ( isset( $input['from_email'] ) ) {
			$out['from_email'] = sanitize_email( wp_unslash( $input['from_email'] ) );
		}
		if ( isset( $input['from_name'] ) ) {
			$out['from_name'] = sanitize_text_field( wp_unslash( $input['from_name'] ) );
		}
		if ( isset( $input['api_base'] ) && '' !== trim( (string) $input['api_base'] ) ) {
			$url = esc_url_raw( wp_unslash( $input['api_base'] ) );
			if ( '' !== $url ) {
				$out['api_base'] = untrailingslashit( $url );
			}
		}

		return $out;
	}

	/**
	 * Keep the last few failures, so somebody can see why mail stopped.
	 *
	 * The subject and the recipients are kept, never the message: enough to
	 * recognise which message failed, and nothing more.
	 *
	 * @param string $subject Message subject.
	 * @param array  $to      Recipients.
	 * @param string $error   Why it failed.
	 */
	public static function record_failure( $subject, array $to, $error ) {
		$log = get_option( LOG_OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		array_unshift(
			$log,
			array(
				'time'    => time(),
				'subject' => (string) $subject,
				'to'      => implode( ', ', $to ),
				'error'   => (string) $error,
			)
		);
		update_option( LOG_OPTION, array_slice( $log, 0, self::MAX_FAILURES ), false );
	}

	/**
	 * Send a test message to the address the form named.
	 */
	public function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'lockally-mail' ) );
		}
		check_admin_referer( 'lockally_mail_test' );

		$to = isset( $_POST['test_to'] ) ? sanitize_email( wp_unslash( $_POST['test_to'] ) ) : '';
		if ( '' === $to ) {
			$this->redirect_with( 'test', 'no-address' );
		}

		// Through wp_mail() on purpose: this tests the path the site actually
		// uses, including any other plugin that filters mail, rather than a
		// private shortcut that could pass while real mail fails.
		$sent = wp_mail(
			$to,
			__( 'Lockally Mail is working', 'lockally-mail' ),
			sprintf(
				/* translators: %s: the site's address. */
				__( 'This test message was sent by the Lockally Mail plugin on %s. If you are reading it, the site can send mail through Lockally.', 'lockally-mail' ),
				home_url( '/' )
			)
		);

		$this->redirect_with( 'test', $sent ? 'sent' : 'failed' );
	}

	/**
	 * Back to the settings screen with a result to show.
	 *
	 * @param string $key    Query argument name.
	 * @param string $result Result to show.
	 */
	private function redirect_with( $key, $result ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'lockally-mail',
					$key   => $result,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * The settings screen.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$result   = isset( $_GET['test'] ) ? sanitize_key( wp_unslash( $_GET['test'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading a result to display, changes nothing.
		$failures = get_option( LOG_OPTION, array() );
		$failures = is_array( $failures ) ? $failures : array();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Lockally Mail', 'lockally-mail' ); ?></h1>

			<?php if ( 'sent' === $result ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Lockally accepted the test message. Check the inbox you sent it to.', 'lockally-mail' ); ?></p></div>
			<?php elseif ( 'failed' === $result ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'The test message was not sent. The reason is in Recent failures below.', 'lockally-mail' ); ?></p></div>
			<?php elseif ( 'no-address' === $result ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Enter an address to send the test to.', 'lockally-mail' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! $this->is_configured() ) : ?>
				<div class="notice notice-warning">
					<p><?php esc_html_e( 'Until an API key and a From address are saved, this site keeps sending mail the way it did before.', 'lockally-mail' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'lockally_mail' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="lockally_mail_api_key"><?php esc_html_e( 'API key', 'lockally-mail' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" id="lockally_mail_api_key"
								name="<?php echo esc_attr( OPTION ); ?>[api_key]" value="" autocomplete="off"
								placeholder="<?php echo '' !== $this->api_key() ? esc_attr__( 'A key is saved. Type a new one to replace it.', 'lockally-mail' ) : 'lk_live_...'; ?>" />
							<p class="description">
								<?php esc_html_e( 'From the Lockally console under API keys. It needs the send:write permission. The key is stored on this site and never shown again here.', 'lockally-mail' ); ?>
							</p>
							<?php if ( '' !== $this->api_key() ) : ?>
								<p>
									<label>
										<input type="checkbox" name="<?php echo esc_attr( OPTION ); ?>[remove_api_key]" value="1" />
										<?php esc_html_e( 'Remove the saved key, and go back to sending the way this site did before.', 'lockally-mail' ); ?>
									</label>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lockally_mail_from_email"><?php esc_html_e( 'From address', 'lockally-mail' ); ?></label></th>
						<td>
							<input type="email" class="regular-text" id="lockally_mail_from_email"
								name="<?php echo esc_attr( OPTION ); ?>[from_email]"
								value="<?php echo esc_attr( $this->from_email() ); ?>" placeholder="hello@yourdomain.com" />
							<p class="description"><?php esc_html_e( 'Must be on a domain you have verified in Lockally. Mail from any other address is refused.', 'lockally-mail' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lockally_mail_from_name"><?php esc_html_e( 'From name', 'lockally-mail' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="lockally_mail_from_name"
								name="<?php echo esc_attr( OPTION ); ?>[from_name]"
								value="<?php echo esc_attr( $this->from_name() ); ?>" />
							<p class="description"><?php esc_html_e( 'Optional. What recipients see beside the address.', 'lockally-mail' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lockally_mail_api_base"><?php esc_html_e( 'API address', 'lockally-mail' ); ?></label></th>
						<td>
							<input type="url" class="regular-text" id="lockally_mail_api_base"
								name="<?php echo esc_attr( OPTION ); ?>[api_base]"
								value="<?php echo esc_attr( $this->api_base() ); ?>" />
							<p class="description"><?php esc_html_e( 'Leave as it is unless Lockally told you otherwise, such as for a private deployment.', 'lockally-mail' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Send a test message', 'lockally-mail' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="lockally_mail_test" />
				<?php wp_nonce_field( 'lockally_mail_test' ); ?>
				<input type="email" name="test_to" class="regular-text" required
					value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" />
				<?php submit_button( __( 'Send test', 'lockally-mail' ), 'secondary', 'submit', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Recent failures', 'lockally-mail' ); ?></h2>
			<?php if ( empty( $failures ) ) : ?>
				<p><?php esc_html_e( 'None. Messages this site failed to send through Lockally would be listed here.', 'lockally-mail' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'When', 'lockally-mail' ); ?></th>
							<th><?php esc_html_e( 'To', 'lockally-mail' ); ?></th>
							<th><?php esc_html_e( 'Subject', 'lockally-mail' ); ?></th>
							<th><?php esc_html_e( 'Why', 'lockally-mail' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $failures as $failure ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $failure['time'] ) ); ?></td>
							<td><?php echo esc_html( $failure['to'] ); ?></td>
							<td><?php echo esc_html( $failure['subject'] ); ?></td>
							<td><?php echo esc_html( $failure['error'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
