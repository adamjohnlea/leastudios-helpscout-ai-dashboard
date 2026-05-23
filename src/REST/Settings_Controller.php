<?php
/**
 * REST controller for the Settings resource (Beacon -> site map).
 *
 * @package LEAStudios\HelpScoutAIDashboard
 */

declare(strict_types=1);

namespace LEAStudios\HelpScoutAIDashboard\REST;

use LEAStudios\HelpScoutAIDashboard\Capabilities;
use LEAStudios\HelpScoutAIDashboard\Database\Schema;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Handles GET /settings (read map) and POST /settings (write map).
 */
final class Settings_Controller extends WP_REST_Controller {

	/**
	 * The REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = Reports_Controller::REST_NAMESPACE;

	/**
	 * The REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'settings';

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
					'callback'            => [ $this, 'get_settings' ],
					'permission_callback' => [ $this, 'check_view_cap' ],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'put_settings' ],
					'permission_callback' => [ $this, 'check_manage_cap' ],
				],
			]
		);
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
	 * Permission check: caller must hold the manage capability.
	 *
	 * @return bool
	 */
	public function check_manage_cap(): bool {
		return current_user_can( Capabilities::MANAGE );
	}

	/**
	 * Handle GET /settings — return the current Beacon -> site map.
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings(): WP_REST_Response {
		return new WP_REST_Response(
			[
				'beacon_map' => (array) get_option( Schema::OPT_BEACON_MAP, [] ),
			],
			200
		);
	}

	/**
	 * Handle POST /settings — persist a sanitized Beacon -> site map.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 *
	 * @return WP_REST_Response
	 */
	public function put_settings( WP_REST_Request $request ): WP_REST_Response {
		$body = (array) $request->get_json_params();
		$map  = $body['beacon_map'] ?? null;

		if ( ! is_array( $map ) ) {
			return new WP_REST_Response( [ 'error' => 'beacon_map must be an object' ], 400 );
		}

		$clean = [];
		foreach ( $map as $beacon => $site ) {
			if ( ! is_scalar( $site ) ) {
				continue;
			}
			$beacon = trim( (string) $beacon );
			$site   = trim( (string) $site );
			if ( '' !== $beacon && '' !== $site ) {
				$clean[ $beacon ] = $site;
			}
		}

		update_option( Schema::OPT_BEACON_MAP, $clean );

		return new WP_REST_Response( [ 'beacon_map' => $clean ], 200 );
	}
}
