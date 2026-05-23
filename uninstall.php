<?php
/**
 * Uninstall handler — runs when the plugin is deleted via WP admin.
 *
 * @package LEAStudios\HelpScoutAIDashboard
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

use LEAStudios\HelpScoutAIDashboard\Capabilities;
use LEAStudios\HelpScoutAIDashboard\Database\Schema;

Schema::drop_tables();

delete_option( Schema::OPT_DB_VERSION );
delete_option( Schema::OPT_BEACON_MAP );

Capabilities::remove();
