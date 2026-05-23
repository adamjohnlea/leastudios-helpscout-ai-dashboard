<?php
/**
 * Plugin Name:       leaStudios Help Scout AI Dashboard
 * Plugin URI:        https://leastudios.com/plugins/leastudios-helpscout-ai-dashboard
 * Description:       Imports Help Scout AI Beacon CSV exports and renders a weekly dashboard of customer interactions across Beacons.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            leaStudios
 * Author URI:        https://leastudios.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       leastudios-helpscout-ai-dashboard
 * Domain Path:       /languages
 *
 * @package LEAStudios\HelpScoutAIDashboard
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'LEASTUDIOS_HELPSCOUT_AI_DASHBOARD_VERSION', '1.0.0' );
define( 'LEASTUDIOS_HELPSCOUT_AI_DASHBOARD_FILE', __FILE__ );
define( 'LEASTUDIOS_HELPSCOUT_AI_DASHBOARD_DIR', plugin_dir_path( __FILE__ ) );
define( 'LEASTUDIOS_HELPSCOUT_AI_DASHBOARD_URL', plugin_dir_url( __FILE__ ) );

if ( ! file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		function () {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong>: %s</p></div>',
				esc_html__( 'leaStudios Help Scout AI Dashboard', 'leastudios-helpscout-ai-dashboard' ),
				esc_html__( 'Plugin dependencies are missing. Run "composer install" in the plugin directory.', 'leastudios-helpscout-ai-dashboard' )
			);
		}
	);
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

/**
 * Initialize the plugin.
 *
 * @return void
 */
function leastudios_helpscout_ai_dashboard_init(): void {
	if ( version_compare( PHP_VERSION, '8.2', '<' ) ) {
		add_action( 'admin_notices', 'leastudios_helpscout_ai_dashboard_php_version_notice' );
		return;
	}

	$plugin = new LEAStudios\HelpScoutAIDashboard\Plugin();
	$plugin->init();
}
add_action( 'plugins_loaded', 'leastudios_helpscout_ai_dashboard_init' );

/**
 * Display PHP version notice.
 *
 * @return void
 */
function leastudios_helpscout_ai_dashboard_php_version_notice(): void {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'leaStudios Help Scout AI Dashboard requires PHP 8.2 or higher.', 'leastudios-helpscout-ai-dashboard' )
	);
}

/**
 * Run on plugin activation.
 *
 * @return void
 */
function leastudios_helpscout_ai_dashboard_activate(): void {
	( new LEAStudios\HelpScoutAIDashboard\Activation() )->run();
}
register_activation_hook( __FILE__, 'leastudios_helpscout_ai_dashboard_activate' );

/**
 * Run on plugin deactivation.
 *
 * @return void
 */
function leastudios_helpscout_ai_dashboard_deactivate(): void {
	( new LEAStudios\HelpScoutAIDashboard\Deactivation() )->run();
}
register_deactivation_hook( __FILE__, 'leastudios_helpscout_ai_dashboard_deactivate' );
