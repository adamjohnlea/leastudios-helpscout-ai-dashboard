<?php
/**
 * Help Scout AI Beacon CSV importer.
 *
 * Parses a Help Scout AI Beacon CSV export, dedupes against existing rows
 * via the (session_id, occurred_at) unique key, and inserts via batched
 * INSERT IGNORE. Returns a report record summarizing the upload.
 *
 * Expected CSV columns (Help Scout export, header row required):
 *   Session ID, Date, Beacon ID, Beacon Device ID, Question, Answer,
 *   Answer Type, Customer ID, Session Resolution, Session End Reason,
 *   Rating, Comment, Article 1, Article 1 URL, Article 2, Article 2 URL,
 *   Article 3, Article 3 URL, Conversation ID, Conversation URL
 *
 * @package LEAStudios\HelpScoutAIDashboard
 */

declare(strict_types=1);

namespace LEAStudios\HelpScoutAIDashboard\CSV;

use LEAStudios\HelpScoutAIDashboard\Database\Schema;
use LEAStudios\HelpScoutAIDashboard\Shared\Week_Helper;

defined( 'ABSPATH' ) || exit;

/**
 * Streaming CSV importer that writes to interactions / article_refs / reports.
 */
final class Importer {

	/**
	 * Required header columns.
	 */
	private const REQUIRED = [
		'Session ID',
		'Date',
		'Beacon ID',
		'Question',
		'Answer',
		'Answer Type',
		'Session Resolution',
	];

	/**
	 * Insert chunk size — keeps statements under MySQL's max_allowed_packet.
	 */
	private const CHUNK = 200;

	/**
	 * Map of Beacon UUID → site name.
	 *
	 * @var array<string, string>
	 */
	private array $beacon_map;

	/**
	 * Load the Beacon → site map from options.
	 */
	public function __construct() {
		$raw   = get_option( Schema::OPT_BEACON_MAP, [] );
		$clean = [];
		if ( is_array( $raw ) ) {
			foreach ( $raw as $beacon => $site ) {
				if ( is_scalar( $site ) ) {
					$clean[ (string) $beacon ] = (string) $site;
				}
			}
		}
		$this->beacon_map = $clean;
	}

	/**
	 * Import a CSV file.
	 *
	 * @param string $path        Absolute path on disk.
	 * @param string $orig_name   Original filename for the report record.
	 * @param int    $user_id     WP user id who triggered the import (0 for CLI).
	 * @param string $notes       Free-form notes saved on the report.
	 *
	 * @return array{report_id:int, rows:int, dupes:int, date_min:string|null, date_max:string|null, sites:list<string>, hash:string, skipped_reason?:string}
	 *
	 * @throws \RuntimeException When the file cannot be read, hashed, or parsed.
	 */
	public function import( string $path, string $orig_name, int $user_id = 0, string $notes = '' ): array {
		global $wpdb;

		if ( ! is_readable( $path ) ) {
			throw new \RuntimeException( esc_html( "CSV not readable: {$path}" ) );
		}

		$hash = sha1_file( $path );
		if ( false === $hash ) {
			throw new \RuntimeException( esc_html( "Failed to hash CSV: {$path}" ) );
		}

		// Skip re-uploads of the exact same file.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE file_hash = %s',
				Schema::table_reports(),
				$hash
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $existing_id ) {
			return [
				'report_id'      => $existing_id,
				'rows'           => 0,
				'dupes'          => 0,
				'date_min'       => null,
				'date_max'       => null,
				'sites'          => [],
				'hash'           => $hash,
				'skipped_reason' => 'duplicate-upload',
			];
		}

		$fh = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			throw new \RuntimeException( esc_html( "fopen failed: {$path}" ) );
		}

		// PHP 8.4 requires the $escape parameter explicitly; '' matches the
		// historical default while silencing the deprecation notice.
		$header = fgetcsv( $fh, 0, ',', '"', '' );
		if ( ! $header ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			throw new \RuntimeException( esc_html( "Empty or unreadable CSV: {$path}" ) );
		}

		$idx = self::index_header( $header );
		foreach ( self::REQUIRED as $col ) {
			if ( ! isset( $idx[ $col ] ) ) {
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				throw new \RuntimeException( esc_html( "CSV missing required column: {$col}" ) );
			}
		}

		// Insert a placeholder report row so interactions can FK to it.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			Schema::table_reports(),
			[
				'filename'      => $orig_name,
				'file_hash'     => $hash,
				'uploaded_by'   => $user_id,
				'uploaded_at'   => current_time( 'mysql', true ),
				'row_count'     => 0,
				'dupes_skipped' => 0,
				'notes'         => $notes,
			]
		);
		$report_id = (int) $wpdb->insert_id;

		$rows_kept    = 0;
		$rows_dupes   = 0;
		$dates_seen   = [];
		$sites_seen   = [];
		$pending      = [];
		$pending_arts = [];

		while ( ( $row = fgetcsv( $fh, 0, ',', '"', '' ) ) !== false ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			$normalized = self::row_at( $row, $idx );
			if ( '' === $normalized['Session ID'] || '' === $normalized['Date'] ) {
				continue;
			}

			$beacon      = $normalized['Beacon ID'];
			$site        = $this->beacon_map[ $beacon ] ?? ( $beacon ? "Unknown ({$beacon})" : 'Unknown' );
			$week        = Week_Helper::week_ending( $normalized['Date'] ) ?? '';
			$occurred_at = self::iso_to_mysql_utc( $normalized['Date'] );
			if ( '' === $week || null === $occurred_at ) {
				continue;
			}

			$pending[] = [
				'session_id'         => $normalized['Session ID'],
				'occurred_at'        => $occurred_at,
				'week_ending'        => $week,
				'beacon_id'          => $beacon,
				'beacon_device_id'   => $normalized['Beacon Device ID'],
				'site'               => $site,
				'question'           => $normalized['Question'],
				'answer'             => $normalized['Answer'],
				'answer_type'        => $normalized['Answer Type'],
				'customer_id'        => $normalized['Customer ID'],
				'session_resolution' => $normalized['Session Resolution'],
				'session_end_reason' => $normalized['Session End Reason'],
				'rating'             => $normalized['Rating'],
				'comment'            => $normalized['Comment'],
				'conversation_id'    => $normalized['Conversation ID'],
				'conversation_url'   => $normalized['Conversation URL'],
				'report_id'          => $report_id,
			];

			// Articles (1-3) — collect by the row's natural key so we can
			// resolve the inserted interaction id after the chunk insert.
			$arts = [];
			for ( $i = 1; $i <= 3; $i++ ) {
				$t = $normalized[ "Article {$i}" ];
				$u = $normalized[ "Article {$i} URL" ];
				if ( '' !== $t || '' !== $u ) {
					$arts[] = [
						'position' => $i,
						'title'    => $t,
						'url'      => $u,
					];
				}
			}
			$pending_arts[] = $arts;

			$central_day = Week_Helper::central_day( $normalized['Date'] );
			if ( null !== $central_day ) {
				$dates_seen[] = $central_day;
			}
			if ( '' !== $site ) {
				$sites_seen[ $site ] = true;
			}

			if ( count( $pending ) >= self::CHUNK ) {
				$result       = $this->flush( $pending, $pending_arts );
				$rows_kept   += $result['kept'];
				$rows_dupes  += $result['dupes'];
				$pending      = [];
				$pending_arts = [];
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( $pending ) {
			$result      = $this->flush( $pending, $pending_arts );
			$rows_kept  += $result['kept'];
			$rows_dupes += $result['dupes'];
		}

		$dates_seen = array_values( array_filter( $dates_seen ) );
		sort( $dates_seen );
		$date_min = $dates_seen[0] ?? null;
		$date_max = $dates_seen[ count( $dates_seen ) - 1 ] ?? null;
		$sites    = array_keys( $sites_seen );
		sort( $sites );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Schema::table_reports(),
			[
				'row_count'     => $rows_kept,
				'dupes_skipped' => $rows_dupes,
				'date_min'      => $date_min,
				'date_max'      => $date_max,
				'sites_json'    => wp_json_encode( $sites ),
			],
			[ 'id' => $report_id ]
		);

		return [
			'report_id' => $report_id,
			'rows'      => $rows_kept,
			'dupes'     => $rows_dupes,
			'date_min'  => $date_min,
			'date_max'  => $date_max,
			'sites'     => $sites,
			'hash'      => $hash,
		];
	}

	/**
	 * Insert a chunk of pending rows + their article refs.
	 *
	 * Each interaction is inserted with a single-row `INSERT IGNORE` whose
	 * format string is a complete static literal — Plugin Check and WPCS can
	 * statically verify every placeholder. INSERT IGNORE preserves the
	 * previous semantics for both duplicate-key collisions on
	 * `(session_id, occurred_at)` and MySQL's default truncation behaviour
	 * for over-long values (which `$wpdb->insert()` would reject outright
	 * via its pre-flight charset/length check). `$wpdb->insert_id` is read
	 * directly after a successful insert, so the per-chunk SELECT lookup
	 * the previous bulk version needed is gone.
	 *
	 * Trade-off vs. the previous bulk INSERT IGNORE: more individual queries
	 * (one per row + one per article) instead of a single multi-row insert,
	 * but the CSV importer is an admin/CLI operation, not a hot path.
	 *
	 * @param list<array<string, scalar>>                               $rows          Interactions ready to insert; keys match the column list below.
	 * @param list<list<array{position:int, title:string, url:string}>> $article_lists Per-row article refs, parallel-indexed to $rows.
	 *
	 * @return array{kept:int, dupes:int}
	 */
	private function flush( array $rows, array $article_lists ): array {
		global $wpdb;
		$t_int = Schema::table_interactions();
		$t_art = Schema::table_article_refs();

		$kept  = 0;
		$dupes = 0;

		foreach ( $rows as $i => $r ) {
			// Single-row INSERT IGNORE with a fully static format string.
			// `%i` identifies the table, the 16 `%s` placeholders cover the
			// VARCHAR/TEXT columns, and `%d` covers report_id. INSERT IGNORE
			// preserves the previous behaviour of (a) silently skipping
			// duplicate-key collisions on (session_id, occurred_at) and (b)
			// letting MySQL truncate values that exceed column widths instead
			// of refusing the row outright (which `$wpdb->insert()` would).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$inserted = $wpdb->query(
				$wpdb->prepare(
					'INSERT IGNORE INTO %i (session_id, occurred_at, week_ending, beacon_id, beacon_device_id, site, question, answer, answer_type, customer_id, session_resolution, session_end_reason, rating, comment, conversation_id, conversation_url, report_id) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %d)',
					$t_int,
					(string) $r['session_id'],
					(string) $r['occurred_at'],
					(string) $r['week_ending'],
					(string) $r['beacon_id'],
					(string) $r['beacon_device_id'],
					(string) $r['site'],
					(string) $r['question'],
					(string) $r['answer'],
					(string) $r['answer_type'],
					(string) $r['customer_id'],
					(string) $r['session_resolution'],
					(string) $r['session_end_reason'],
					(string) $r['rating'],
					(string) $r['comment'],
					(string) $r['conversation_id'],
					(string) $r['conversation_url'],
					(int) $r['report_id']
				)
			);

			if ( false === $inserted || 0 === $inserted ) {
				++$dupes;
				continue;
			}

			++$kept;
			$interaction_id = (int) $wpdb->insert_id;

			$arts = $article_lists[ $i ] ?? [];
			foreach ( $arts as $a ) {
				// Single-row INSERT for the article ref — static format
				// string, all values bound through `prepare()`.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query(
					$wpdb->prepare(
						'INSERT INTO %i (interaction_id, position, title, url) VALUES (%d, %d, %s, %s)',
						$t_art,
						$interaction_id,
						(int) $a['position'],
						(string) $a['title'],
						(string) $a['url']
					)
				);
			}
		}

		return [
			'kept'  => $kept,
			'dupes' => $dupes,
		];
	}

	/**
	 * Map header row to column-name => column-index.
	 *
	 * @param list<string|null> $header Raw header row from fgetcsv().
	 *
	 * @return array<string, int> Lookup keyed by column name.
	 */
	private static function index_header( array $header ): array {
		$out = [];
		foreach ( $header as $i => $name ) {
			$name = trim( (string) $name );
			if ( '' !== $name ) {
				$out[ $name ] = $i;
			}
		}
		return $out;
	}

	/**
	 * Pull every needed column out of a row, defaulting to '' when absent.
	 *
	 * @param list<string|null>  $row Raw data row from fgetcsv().
	 * @param array<string, int> $idx Header lookup from index_header().
	 *
	 * @return array<string, string> Every required + optional column, defaulted to ''.
	 */
	private static function row_at( array $row, array $idx ): array {
		$want = [
			'Session ID',
			'Date',
			'Beacon ID',
			'Beacon Device ID',
			'Question',
			'Answer',
			'Answer Type',
			'Customer ID',
			'Session Resolution',
			'Session End Reason',
			'Rating',
			'Comment',
			'Article 1',
			'Article 1 URL',
			'Article 2',
			'Article 2 URL',
			'Article 3',
			'Article 3 URL',
			'Conversation ID',
			'Conversation URL',
		];

		$out = [];
		foreach ( $want as $col ) {
			$i           = $idx[ $col ] ?? null;
			$v           = ( null !== $i && isset( $row[ $i ] ) ) ? trim( (string) $row[ $i ] ) : '';
			$out[ $col ] = $v;
		}
		return $out;
	}

	/**
	 * Convert "2026-04-23T04:22:37Z" → "2026-04-23 04:22:37" (UTC, MySQL DATETIME).
	 *
	 * @param string $iso ISO-8601 timestamp.
	 *
	 * @return string|null Null on parse failure.
	 */
	private static function iso_to_mysql_utc( string $iso ): ?string {
		try {
			$dt = new \DateTimeImmutable( $iso );
		} catch ( \Exception $e ) {
			return null;
		}
		return $dt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}
}
