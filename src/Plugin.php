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
use LEAStudios\HelpScoutAIDashboard\REST\Reports_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Top-level plugin entry. Phase 2 adds Admin pages + REST routes.
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
	}

	/**
	 * Register REST routes for this plugin.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		( new Reports_Controller() )->register_routes();
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
