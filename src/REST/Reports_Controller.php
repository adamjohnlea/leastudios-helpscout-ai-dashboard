<?php
/**
 * REST controller for the Reports resource (CSV uploads + delete-by-source).
 * The dashboard read endpoint is added in Phase 4.
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
}
