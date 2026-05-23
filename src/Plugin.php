<?php
/**
 * Main plugin bootstrap class. Wires hooks; instantiated once per request
 * from the bootstrap file.
 *
 * @package LEAStudios\HelpScoutAIDashboard
 */

declare(strict_types=1);

namespace LEAStudios\HelpScoutAIDashboard;

defined( 'ABSPATH' ) || exit;

/**
 * Top-level plugin entry. Phase 1 only loads the text domain.
 */
final class Plugin {

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'init', [ $this, 'load_textdomain' ] );
		// Admin, REST, CLI wiring added in Phases 2–5.
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
