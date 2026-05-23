<?php
/**
 * Main plugin bootstrap class. Wires hooks; instantiated once per request
 * from the bootstrap file.
 *
 * @package LEAStudios\HelpScoutAIDashboard
 */

declare(strict_types=1);

namespace LEAStudios\HelpScoutAIDashboard;

use LEAStudios\HelpScoutAIDashboard\Admin\Admin;
use LEAStudios\HelpScoutAIDashboard\CLI\Import_Command;
use LEAStudios\HelpScoutAIDashboard\REST\Reports_Controller;
use LEAStudios\HelpScoutAIDashboard\REST\Settings_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin bootstrap. Loads textdomain, registers REST controllers
 * (Reports, Settings), instantiates the Admin pages when in wp-admin,
 * and registers WP-CLI commands when WP_CLI is defined.
 */
final class Plugin {

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'init', [ $this, 'load_textdomain' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

		if ( is_admin() ) {
			( new Admin() )->init();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$cli = new Import_Command();
			\WP_CLI::add_command( 'lshsai import-file', [ $cli, 'import_file' ] );
			\WP_CLI::add_command( 'lshsai import-folder', [ $cli, 'import_folder' ] );
		}
	}

	/**
	 * Register REST routes for this plugin.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		( new Reports_Controller() )->register_routes();
		( new Settings_Controller() )->register_routes();
	}

	/**
	 * Load the plugin's text domain for translations.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'leastudios-helpscout-ai-dashboard',
			false,
			dirname( plugin_basename( LEASTUDIOS_HELPSCOUT_AI_DASHBOARD_FILE ) ) . '/languages'
		);
	}
}
