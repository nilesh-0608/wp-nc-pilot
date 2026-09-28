<?php
/**
 * Uninstall cleanup. Runs only when the user deletes the plugin from wp-admin.
 *
 * Removes our options. We never touch Application Passwords (the plugin does
 * not create them — the user does, on their profile screen), theme files, or
 * theme-file backups.
 *
 * @package WP_NC_Pilot
 */

// Bail if not called by WordPress uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$ncpilot_options = array(
	'ncpilot_cap_read_theme',
	'ncpilot_cap_edit_theme',
	'ncpilot_cap_plugins', // Legacy (removed in 2.4.0); cleaned up for older installs.
	'ncpilot_cap_delete',
	'ncpilot_cap_upload',
	'ncpilot_cap_install',
	'ncpilot_last_seen',
);

foreach ( $ncpilot_options as $ncpilot_option ) {
	delete_option( $ncpilot_option );
}

// We deliberately leave in place:
//  - any Application Password you created for NC-Pilot (revoke it under
//    Users -> Profile -> Application Passwords if you want to cut access), and
//  - any theme-file backups in wp-content/uploads/wp-nc-pilot-backups/
//    (so changes remain recoverable after uninstall).
