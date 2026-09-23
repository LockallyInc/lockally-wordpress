<?php
/**
 * Plugin Name:       Lockally Mail
 * Plugin URI:        https://lockally.com/integrations/wordpress
 * Description:       Sends the mail your site sends through Lockally, so it arrives from your own domain instead of the web server.
 * Version:           1.0.0
 * Requires at least: 5.7
 * Requires PHP:      7.4
 * Author:            Lockally
 * Author URI:        https://lockally.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lockally-mail
 *
 * @package LockallyMail
 */

namespace Lockally\Mail;

defined( 'ABSPATH' ) || exit;

const VERSION = '1.0.0';

/** The option holding every setting, so uninstall has one thing to remove. */
const OPTION = 'lockally_mail_settings';

/** Where failures are kept for the Status page. Never the message body. */
const LOG_OPTION = 'lockally_mail_failures';

define( 'LOCKALLY_MAIL_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-client.php';
require_once __DIR__ . '/includes/class-mailer.php';
require_once __DIR__ . '/includes/class-settings.php';

/**
 * Wire the plugin up.
 *
 * Sending is taken over only when there is a key to send with. A plugin that
 * captures wp_mail and then fails because it was never configured would stop
 * password resets on the site that installed it, which is the worst thing a
 * mail plugin can do.
 */
function bootstrap() {
	load_plugin_textdomain( 'lockally-mail', false, dirname( plugin_basename( LOCKALLY_MAIL_FILE ) ) . '/languages' );

	$settings = new Settings();
	$settings->hooks();

	if ( $settings->is_configured() ) {
		( new Mailer( new Client( $settings ) ) )->hooks();
	}
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap' );

/**
 * Settings link on the Plugins screen, because the first thing anybody does
 * after activating this is look for where to put the key.
 */
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		$url = admin_url( 'options-general.php?page=lockally-mail' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'lockally-mail' ) . '</a>' );
		return $links;
	}
);
