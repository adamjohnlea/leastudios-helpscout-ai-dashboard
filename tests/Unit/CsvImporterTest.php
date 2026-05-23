<?php
/**
 * @package LEAStudios\HelpScoutAIDashboard
 */

declare(strict_types=1);

namespace LEAStudios\HelpScoutAIDashboard\Tests\Unit;

use LEAStudios\HelpScoutAIDashboard\CSV\Importer;
use LEAStudios\HelpScoutAIDashboard\Database\Schema;
use LEAStudios\HelpScoutAIDashboard\Tests\TestCase;
use RuntimeException;

final class CsvImporterTest extends TestCase {

	public function test_happy_path_imports_all_rows(): void {
		$result = ( new Importer() )->import(
			__DIR__ . '/../Fixtures/csv/happy.csv',
			'happy.csv',
			0,
			'unit-test'
		);

		$this->assertSame( 5, (int) $result['rows'] );
		$this->assertSame( 0, (int) $result['dupes'] );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table_interactions() );
		$this->assertSame( 5, $count );
	}

	public function test_reimporting_same_csv_is_skipped_as_duplicate_upload(): void {
		$importer = new Importer();
		$importer->import( __DIR__ . '/../Fixtures/csv/happy.csv', 'happy.csv', 0, '' );

		// The importer dedupes on whole-file SHA-1 hash and short-circuits with
		// skipped_reason rather than throwing. Verify the contract.
		$result = $importer->import( __DIR__ . '/../Fixtures/csv/happy.csv', 'happy.csv', 0, '' );

		$this->assertSame( 0, (int) $result['rows'] );
		$this->assertSame( 0, (int) $result['dupes'] );
		$this->assertSame( 'duplicate-upload', $result['skipped_reason'] ?? null );
	}

	public function test_partially_overlapping_csv_dedupes_per_row(): void {
		$importer = new Importer();
		$importer->import( __DIR__ . '/../Fixtures/csv/happy.csv', 'happy.csv', 0, '' );

		$result = $importer->import(
			__DIR__ . '/../Fixtures/csv/dedupe-second.csv',
			'dedupe-second.csv',
			0,
			''
		);

		$this->assertSame( 1, (int) $result['rows'] );
		$this->assertSame( 2, (int) $result['dupes'] );
	}

	public function test_missing_required_column_throws(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/missing required column/i' );
		( new Importer() )->import(
			__DIR__ . '/../Fixtures/csv/missing-column.csv',
			'missing-column.csv',
			0,
			''
		);
	}

	public function test_known_beacon_id_maps_to_site_name(): void {
		( new Importer() )->import( __DIR__ . '/../Fixtures/csv/happy.csv', 'happy.csv', 0, '' );

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$site = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT site FROM ' . Schema::table_interactions() . ' WHERE beacon_id = %s LIMIT 1',
				'3385ee56-3426-497b-8421-a461de52b28b'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( 'CG Cookie', $site );
	}

	public function test_unknown_beacon_id_labels_unknown(): void {
		( new Importer() )->import( __DIR__ . '/../Fixtures/csv/happy.csv', 'happy.csv', 0, '' );

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$site = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT site FROM ' . Schema::table_interactions() . ' WHERE beacon_id = %s LIMIT 1',
				'00000000-0000-0000-0000-000000000001'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( 'Unknown (00000000-0000-0000-0000-000000000001)', $site );
	}
}
