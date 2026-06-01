<?php
/**
 * Uninstall cleanup. Runs only when the user deletes the plugin from wp-admin.
 *
 * Removes our options. We deliberately do NOT revoke Application Passwords here
 * (the user may still want them) and we never touch theme files or their backups.
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
	'ncpilot_cap_plugins',
	'ncpilot_cap_delete',
	'ncpilot_last_seen',
);

foreach ( $ncpilot_options as $ncpilot_option ) {
	delete_option( $ncpilot_option );
}

// Remove any leftover one-time password transients (normally expired already).
global $wpdb;
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_ncpilot\_newpass\_%' OR option_name LIKE '\_transient\_timeout\_ncpilot\_newpass\_%'"
);

// We deliberately leave in place:
//  - the Application Password named "WP NC-Pilot MCP" (revoke it under
//    Users -> Profile -> Application Passwords if you want to cut access), and
//  - any theme-file backups in wp-content/uploads/wp-nc-pilot-backups/
//    (so changes remain recoverable after uninstall).
