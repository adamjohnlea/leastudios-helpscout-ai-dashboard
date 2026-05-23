<?php
/**
 * Uninstall: drop custom tables, delete options, remove capabilities.
 * Runs only when WordPress invokes the plugin uninstall callback.
 *
 * @package LEAStudios\HelpScoutAIDashboard
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$tables = [
	$wpdb->prefix . 'leastudios_helpscout_ai_dashboard_interactions',
	$wpdb->prefix . 'leastudios_helpscout_ai_dashboard_article_refs',
	$wpdb->prefix . 'leastudios_helpscout_ai_dashboard_reports',
];

foreach ( $tables as $table ) {
	// Table name is built from a hard-coded suffix plus $wpdb->prefix; it
	// cannot be parameterised in a prepared statement.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
}

delete_option( 'leastudios_helpscout_ai_dashboard_db_version' );
delete_option( 'leastudios_helpscout_ai_dashboard_beacon_map' );

// Remove caps from every role. Mirrors Capabilities::uninstall() but we can't
// rely on autoload here — uninstall runs in a stripped context.
foreach ( wp_roles()->roles as $role_name => $_unused ) {
	$leastudios_role = get_role( $role_name );
	if ( $leastudios_role instanceof \WP_Role ) {
		$leastudios_role->remove_cap( 'manage_leastudios_helpscout_ai_dashboard' );
		$leastudios_role->remove_cap( 'view_leastudios_helpscout_ai_dashboard' );
	}
}
