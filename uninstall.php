<?php
/**
 * Removes what this plugin stored, when it is deleted (not merely deactivated).
 *
 * @package LockallyMail
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'lockally_mail_settings' );
delete_option( 'lockally_mail_failures' );

// Multisite: the same two options, per site.
if ( is_multisite() ) {
	$sites = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $sites as $site_id ) {
		switch_to_blog( $site_id );
		delete_option( 'lockally_mail_settings' );
		delete_option( 'lockally_mail_failures' );
		restore_current_blog();
	}
}
