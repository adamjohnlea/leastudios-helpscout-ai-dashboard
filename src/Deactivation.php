<?php
/**
 * Deactivation hook: no-op. Capabilities and tables persist across
 * deactivations; both are cleared only via uninstall.php.
 *
 * @package LEAStudios\HelpScoutAIDashboard
 */

declare(strict_types=1);

namespace LEAStudios\HelpScoutAIDashboard;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the deactivation routines for the plugin.
 */
final class Deactivation {

	/**
	 * Execute deactivation tasks.
	 *
	 * @return void
	 */
	public function run(): void {
		// Intentionally empty. See class docblock.
	}
}
