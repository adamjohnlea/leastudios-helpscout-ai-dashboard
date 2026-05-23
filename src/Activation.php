<?php
/**
 * Activation hook: install schema + register capabilities.
 *
 * @package LEAStudios\HelpScoutAIDashboard
 */

declare(strict_types=1);

namespace LEAStudios\HelpScoutAIDashboard;

use LEAStudios\HelpScoutAIDashboard\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the activation routines for the plugin.
 */
final class Activation {

	/**
	 * Execute activation tasks.
	 *
	 * @return void
	 */
	public function run(): void {
		Schema::install();
		Capabilities::install();
	}
}
