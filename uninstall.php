<?php
/**
 * Removes what this plugin stored, when it is deleted (not merely deactivated).
 *
 * @package LockallyMail
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'lockally_mail_settings' );
delete_option( 'lockally_mail_failures' );

// Multisite: the same two options, per site. get_sites() returns at most 100
// sites unless told otherwise, so 'number' => 0 asks for every site; without it
// a network of more than 100 sites would keep the options on the rest.
if ( is_multisite() ) {
	$lockally_mail_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $lockally_mail_sites as $lockally_mail_site_id ) {
		switch_to_blog( $lockally_mail_site_id );
		delete_option( 'lockally_mail_settings' );
		delete_option( 'lockally_mail_failures' );
		restore_current_blog();
	}
}
