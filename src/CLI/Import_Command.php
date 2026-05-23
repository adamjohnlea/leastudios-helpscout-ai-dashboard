<?php
/**
 * WP-CLI commands. Registered under `wp lshsai`.
 *
 * @package LEAStudios\HelpScoutAIDashboard
 */

declare(strict_types=1);

namespace LEAStudios\HelpScoutAIDashboard\CLI;

use LEAStudios\HelpScoutAIDashboard\CSV\Importer;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Import Help Scout AI Beacon CSVs from the command line.
 */
final class Import_Command {

	/**
	 * Import a single CSV file.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Absolute path to the CSV file.
	 *
	 * [--notes=<notes>]
	 * : Free-text note recorded with the report row.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lshsai import-file /path/to/export.csv
	 *     wp lshsai import-file /path/to/export.csv --notes="backfill 2025-04"
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Associative args.
	 *
	 * @return void
	 */
	public function import_file( array $args, array $assoc_args ): void {
		$file  = (string) ( $args[0] ?? '' );
		$notes = (string) ( $assoc_args['notes'] ?? 'wp-cli import-file' );

		if ( '' === $file || ! is_readable( $file ) ) {
			WP_CLI::error( "Not readable: {$file}" );
		}

		try {
			$result = ( new Importer() )->import( $file, basename( $file ), 0, $notes );
		} catch ( \RuntimeException $e ) {
			WP_CLI::error( $e->getMessage() );
			return; // Unreachable; WP_CLI::error halts. Satisfies PHPStan.
		}

		if ( ! empty( $result['skipped_reason'] ) ) {
			WP_CLI::warning(
				sprintf(
					'Report #%d — skipped (%s).',
					(int) $result['report_id'],
					(string) $result['skipped_reason']
				)
			);
			return;
		}

		WP_CLI::success(
			sprintf(
				'Report #%d — %d rows kept, %d dupes skipped.',
				(int) $result['report_id'],
				(int) $result['rows'],
				(int) $result['dupes']
			)
		);
	}

	/**
	 * Bulk-import every `ai_interactions*.csv` in a folder.
	 *
	 * ## OPTIONS
	 *
	 * <folder>
	 * : Absolute path to the folder containing CSVs.
	 *
	 * [--dry-run]
	 * : Print which files would be imported without writing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lshsai import-folder /path/to/csv-folder
	 *     wp lshsai import-folder /path/to/csv-folder --dry-run
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>         $args       Positional args.
	 * @param array<string, string|true> $assoc_args Associative args.
	 *
	 * @return void
	 */
	public function import_folder( array $args, array $assoc_args ): void {
		$folder = rtrim( (string) ( $args[0] ?? '' ), '/' );

		if ( '' === $folder || ! is_dir( $folder ) ) {
			WP_CLI::error( "Not a directory: {$folder}" );
		}

		$files = glob( $folder . '/ai_interactions*.csv' );
		if ( ! is_array( $files ) || ! $files ) {
			WP_CLI::warning( "No matching ai_interactions*.csv files in {$folder}" );
			return;
		}
		sort( $files );

		$dry = ! empty( $assoc_args['dry-run'] );
		WP_CLI::log( ( $dry ? '[dry-run] ' : '' ) . count( $files ) . ' file(s) queued.' );

		if ( $dry ) {
			foreach ( $files as $file ) {
				WP_CLI::log( ' - would import ' . basename( $file ) );
			}
			return;
		}

		$importer    = new Importer();
		$total_rows  = 0;
		$total_dupes = 0;

		foreach ( $files as $file ) {
			$name = basename( $file );
			try {
				$result = $importer->import( $file, $name, 0, 'wp-cli import-folder' );

				if ( ! empty( $result['skipped_reason'] ) ) {
					WP_CLI::log( sprintf( ' - %s: skipped (%s)', $name, (string) $result['skipped_reason'] ) );
					continue;
				}

				$rows         = (int) $result['rows'];
				$dupes        = (int) $result['dupes'];
				$total_rows  += $rows;
				$total_dupes += $dupes;

				WP_CLI::log( sprintf( ' - %s: %d rows kept, %d dupes', $name, $rows, $dupes ) );
			} catch ( \RuntimeException $e ) {
				WP_CLI::warning( $name . ': ' . $e->getMessage() );
			}
		}

		WP_CLI::success(
			sprintf(
				'Imported %d rows total (%d dupes skipped) across %d files.',
				$total_rows,
				$total_dupes,
				count( $files )
			)
		);
	}
}
