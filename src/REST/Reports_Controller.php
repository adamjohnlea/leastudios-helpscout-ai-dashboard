<?php
/**
 * REST controller for the Reports resource. Exposes CSV upload
 * (POST /reports), report deletion with cascade through interactions
 * and article_refs (DELETE /reports/{id}), and the dashboard
 * aggregation read (GET /dashboard).
 *
 * @package LEAStudios\HelpScoutAIDashboard
 */

declare(strict_types=1);

namespace LEAStudios\HelpScoutAIDashboard\REST;

use LEAStudios\HelpScoutAIDashboard\Capabilities;
use LEAStudios\HelpScoutAIDashboard\CSV\Importer;
use LEAStudios\HelpScoutAIDashboard\Database\Schema;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Handles POST /reports (upload) and DELETE /reports/{id} (cascade delete).
 */
final class Reports_Controller extends WP_REST_Controller {

	public const REST_NAMESPACE = 'leastudios-helpscout-ai-dashboard/v1';

	/**
	 * The REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = self::REST_NAMESPACE;

	/**
	 * The REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'reports';

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'list_reports' ],
					'permission_callback' => [ $this, 'check_view_cap' ],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'upload_report' ],
					'permission_callback' => [ $this, 'check_manage_cap' ],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)',
			[
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_report' ],
					'permission_callback' => [ $this, 'check_manage_cap' ],
					'args'                => [
						'id' => [
							'validate_callback' => static fn( $v ) => is_numeric( $v ) && (int) $v > 0,
						],
					],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/dashboard',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_dashboard' ],
					'permission_callback' => [ $this, 'check_view_cap' ],
				],
			]
		);
	}

	/**
	 * Permission check: caller must hold the manage capability.
	 *
	 * @return bool
	 */
	public function check_manage_cap(): bool {
		return current_user_can( Capabilities::MANAGE );
	}

	/**
	 * Permission check: caller must hold the view capability.
	 *
	 * @return bool
	 */
	public function check_view_cap(): bool {
		return current_user_can( Capabilities::VIEW );
	}

	/**
	 * Handle GET /reports — paginated list of uploaded reports for the Reports admin page.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 *
	 * @return WP_REST_Response
	 */
	public function list_reports( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$t_rep = Schema::table_reports();

		$page_param     = $request->get_param( 'page' );
		$per_page_param = $request->get_param( 'per_page' );
		$page           = is_scalar( $page_param ) ? max( 1, (int) $page_param ) : 1;
		$per_page       = is_scalar( $per_page_param ) ? (int) $per_page_param : 25;
		$per_page       = max( 1, min( 200, $per_page ) );

		// Table name from Schema; no user input.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$total       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t_rep}" );
		$total_pages = (int) max( 1, (int) ceil( $total / $per_page ) );
		// Server-side clamp: if the client asks past the last page (e.g. after
		// a delete), give them the last page rather than an empty body.
		$page   = min( $page, $total_pages );
		$offset = ( $page - 1 ) * $per_page;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, filename, uploaded_at, uploaded_by, row_count, dupes_skipped, date_min, date_max, sites_json, notes
				 FROM {$t_rep} ORDER BY uploaded_at DESC
				 LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		foreach ( $rows as &$r ) {
			$r['id']            = (int) $r['id'];
			$r['row_count']     = (int) $r['row_count'];
			$r['dupes_skipped'] = (int) $r['dupes_skipped'];
			$r['uploaded_by']   = (int) $r['uploaded_by'];
			$sites_decoded      = json_decode( (string) $r['sites_json'], true );
			$r['sites']         = is_array( $sites_decoded ) ? $sites_decoded : [];
			unset( $r['sites_json'] );
		}
		unset( $r );

		return new WP_REST_Response(
			[
				'rows'        => $rows,
				'total'       => $total,
				'page'        => $page,
				'per_page'    => $per_page,
				'total_pages' => $total_pages,
			],
			200
		);
	}

	/**
	 * Handle POST /reports — accept a multipart CSV upload and run the importer.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 *
	 * @return WP_REST_Response
	 */
	public function upload_report( WP_REST_Request $request ): WP_REST_Response {
		$files = $request->get_file_params();
		$file  = $files['file'] ?? null;

		if ( ! is_array( $file ) ) {
			return new WP_REST_Response( [ 'error' => 'no file uploaded (expected multipart field "file")' ], 400 );
		}

		if ( ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) {
			return new WP_REST_Response(
				[ 'error' => 'upload error code ' . (int) $file['error'] ],
				400
			);
		}

		try {
			$importer = new Importer();
			$result   = $importer->import(
				(string) $file['tmp_name'],
				sanitize_file_name( (string) ( $file['name'] ?? 'upload.csv' ) ),
				get_current_user_id(),
				''
			);
		} catch ( \RuntimeException $e ) {
			return new WP_REST_Response( [ 'error' => $e->getMessage() ], 400 );
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Handle DELETE /reports/{id} — cascade-delete the report and its data.
	 *
	 * Cascades article_refs → interactions → reports, matching source behavior.
	 * The article_refs delete must run BEFORE the interactions delete because
	 * it joins on interactions to find the rows to remove.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 *
	 * @return WP_REST_Response
	 */
	public function delete_report( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$report_id = (int) $request->get_param( 'id' );

		$t_int = Schema::table_interactions();
		$t_art = Schema::table_article_refs();

		// Cascade: article_refs -> interactions -> report.
		// Table names are controlled by Schema; $report_id is properly placeholdered.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE a FROM {$t_art} a JOIN {$t_int} i ON a.interaction_id = i.id WHERE i.report_id = %d", $report_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $t_int . ' WHERE report_id = %d', $report_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( Schema::table_reports(), [ 'id' => $report_id ], [ '%d' ] );

		return new WP_REST_Response( [ 'deleted' => $report_id ], 200 );
	}

	/**
	 * Handle GET /dashboard — return the full payload the dashboard frontend expects.
	 *
	 * Ported from the source plugin's get_payload() method. The shape mirrors
	 * what the legacy build.py produced so the frontend filter/render logic is
	 * reused untouched: { generated_at, generated_at_display, timezone,
	 * reports, rows, sites, beacon_map, weeks }.
	 *
	 * Uses a cheap ETag fingerprint (max-id + counts + max-uploaded-at + beacon-map hash)
	 * so unchanged datasets return 304 and skip the full JSON serialize.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 *
	 * @return WP_REST_Response
	 */
	public function get_dashboard( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$t_int = Schema::table_interactions();
		$t_art = Schema::table_article_refs();
		$t_rep = Schema::table_reports();

		$beacon_map = (array) get_option( Schema::OPT_BEACON_MAP, [] );

		// Cheap fingerprint of the dataset: changes when interactions are
		// added/removed (id+count), when a report is uploaded/deleted, or
		// when the Beacon map changes. Costs three indexed scalar queries.
		// Browsers send `If-None-Match: <etag>` and we 304 on a match.
		// Table names from Schema::table_*(); no user input.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$fp_parts = [
			(int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM {$t_int}" ),
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t_int}" ),
			(string) $wpdb->get_var( "SELECT COALESCE(MAX(uploaded_at), '') FROM {$t_rep}" ),
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t_rep}" ),
			md5( (string) wp_json_encode( $beacon_map ) ),
		];
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$etag = '"' . substr( md5( implode( '|', $fp_parts ) ), 0, 16 ) . '"';

		// Apache's mod_deflate (and some CDNs) append `-gzip` / `-br` to ETags
		// on compressed responses, so the value the browser sends back may not
		// match what we minted. Strip those suffixes before comparing.
		$inm = $request->get_header( 'if_none_match' );
		if ( is_string( $inm ) && '' !== $inm ) {
			$inm = trim( $inm );
			$inm = (string) preg_replace( '/-(?:gzip|br|deflate)("?)$/', '$1', $inm );
			if ( $etag === $inm ) {
				$resp = new WP_REST_Response( null, 304 );
				$resp->header( 'ETag', $etag );
				$resp->header( 'Cache-Control', 'private, must-revalidate' );
				return $resp;
			}
		}

		// Stable site order to match the legacy frontend constants.
		$site_order = [ 'CG Cookie', 'CG Cookie Docs', 'Superhive', 'Superhive Docs' ];

		// Single SELECT for all interactions. occurred_at is stored in UTC;
		// re-emit as ISO with `Z` suffix so the frontend's existing toCentral()
		// helper handles it identically to the old payload.
		// Table names from Schema::table_*(); no user input.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows_raw = $wpdb->get_results(
			"SELECT id, session_id, occurred_at, week_ending, beacon_id, site,
			        question, answer, answer_type, customer_id,
			        session_resolution, session_end_reason, rating, comment,
			        conversation_url, report_id
			 FROM {$t_int}
			 ORDER BY occurred_at DESC",
			ARRAY_A
		);

		// Pull article refs in one query, group by interaction id.
		$arts_raw = $wpdb->get_results(
			"SELECT interaction_id, position, title, url FROM {$t_art} ORDER BY interaction_id, position",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$arts_by_id = [];
		foreach ( (array) $arts_raw as $a ) {
			$iid                  = (int) $a['interaction_id'];
			$arts_by_id[ $iid ][] = [
				't' => $a['title'],
				'u' => $a['url'],
			];
		}

		$rows  = [];
		$weeks = [];
		foreach ( (array) $rows_raw as $r ) {
			$id     = (int) $r['id'];
			$rows[] = [
				'sid'     => $r['session_id'],
				'date'    => self::utc_datetime_to_iso( (string) $r['occurred_at'] ),
				'q'       => $r['question'] ?? '',
				'a'       => $r['answer'] ?? '',
				'type'    => $r['answer_type'],
				'cust'    => $r['customer_id'],
				'res'     => $r['session_resolution'],
				'end'     => $r['session_end_reason'],
				'rating'  => $r['rating'],
				'comment' => $r['comment'] ?? '',
				'arts'    => $arts_by_id[ $id ] ?? [],
				'conv'    => $r['conversation_url'],
				'src'     => 'report:' . (int) $r['report_id'],
				'beacon'  => $r['beacon_id'],
				'site'    => $r['site'],
				'week'    => $r['week_ending'],
			];
			if ( ! empty( $r['week_ending'] ) ) {
				$weeks[ $r['week_ending'] ] = true;
			}
		}
		$weeks = array_keys( $weeks );
		sort( $weeks );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$reports_raw = $wpdb->get_results(
			"SELECT id, filename, uploaded_at, row_count, dupes_skipped,
			        date_min, date_max, sites_json
			 FROM {$t_rep}
			 ORDER BY uploaded_at ASC",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$reports = [];
		foreach ( (array) $reports_raw as $rep ) {
			$sites_decoded = json_decode( (string) $rep['sites_json'], true );
			$reports[]     = [
				'id'    => (int) $rep['id'],
				'name'  => $rep['filename'],
				'rows'  => (int) $rep['row_count'],
				'dupes' => (int) $rep['dupes_skipped'],
				'from'  => $rep['date_min'],
				'to'    => $rep['date_max'],
				'mtime' => $rep['uploaded_at'],
				'sites' => is_array( $sites_decoded ) ? $sites_decoded : [],
			];
		}

		$now     = current_time( 'mysql' );
		$payload = [
			'generated_at'         => mysql_to_rfc3339( $now ),
			'generated_at_display' => $now,
			'timezone'             => 'America/Chicago',
			'reports'              => $reports,
			'rows'                 => $rows,
			'sites'                => $site_order,
			'beacon_map'           => $beacon_map,
			'weeks'                => $weeks,
		];

		$resp = new WP_REST_Response( $payload, 200 );
		$resp->header( 'ETag', $etag );
		$resp->header( 'Cache-Control', 'private, must-revalidate' );
		return $resp;
	}

	/**
	 * Convert a "YYYY-MM-DD HH:MM:SS" UTC MySQL datetime to ISO 8601 with Z suffix.
	 *
	 * @param string $mysql_dt MySQL datetime string in UTC.
	 *
	 * @return string ISO 8601 with `Z` suffix.
	 */
	private static function utc_datetime_to_iso( string $mysql_dt ): string {
		return str_replace( ' ', 'T', $mysql_dt ) . 'Z';
	}
}
