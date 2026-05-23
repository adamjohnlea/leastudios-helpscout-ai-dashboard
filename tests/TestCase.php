<?php
/**
 * Shared abstract test case. Subclasses extend this; this extends WP_UnitTestCase.
 *
 * Calls Schema::install() in setUp so every test starts against the live tables.
 * WP_UnitTestCase wraps each test in a transaction that's rolled back in tearDown,
 * so dbDelta-created tables persist but row state is clean between tests.
 *
 * @package LEAStudios\HelpScoutAIDashboard
 */

declare(strict_types=1);

namespace LEAStudios\Tests;

use LEAStudios\HelpScoutAIDashboard\Database\Schema;

abstract class TestCase extends \WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Schema::install();
	}
}
