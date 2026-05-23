<?php
/**
 * @package LEAStudios\HelpScoutAIDashboard
 */

declare(strict_types=1);

namespace LEAStudios\HelpScoutAIDashboard\Tests\Unit;

use LEAStudios\HelpScoutAIDashboard\Database\Schema;
use LEAStudios\HelpScoutAIDashboard\Tests\TestCase;

final class SchemaTest extends TestCase {

	public function test_install_creates_all_three_tables(): void {
		// WP_UnitTestCase rewrites `CREATE TABLE` to `CREATE TEMPORARY TABLE` so
		// each test gets a clean slate. Temp tables don't show up in `SHOW TABLES`,
		// so verify existence by issuing a trivial SELECT against each table —
		// the query succeeds iff the table exists.
		global $wpdb;
		// Suppress wpdb's "die on error" so failed selects don't kill the test
		// before we can assert; we want to read $wpdb->last_error instead.
		$prev = $wpdb->suppress_errors( true );

		foreach ( [ Schema::table_interactions(), Schema::table_article_refs(), Schema::table_reports() ] as $table ) {
			$wpdb->last_error = '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
			$this->assertSame( '', (string) $wpdb->last_error, "Table {$table} should exist" );
		}

		$wpdb->suppress_errors( $prev );
	}

	public function test_install_is_idempotent(): void {
		Schema::install();
		Schema::install();
		$this->assertTrue( true ); // No exception thrown = success.
	}
}
