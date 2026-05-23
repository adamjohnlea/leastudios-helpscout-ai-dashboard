<?php
/**
 * @package LEAStudios\HelpScoutAIDashboard
 */

declare(strict_types=1);

namespace LEAStudios\HelpScoutAIDashboard\Tests\Integration;

use LEAStudios\HelpScoutAIDashboard\Capabilities;
use LEAStudios\HelpScoutAIDashboard\Database\Schema;
use LEAStudios\HelpScoutAIDashboard\REST\Reports_Controller;
use LEAStudios\HelpScoutAIDashboard\REST\Settings_Controller;
use LEAStudios\HelpScoutAIDashboard\Tests\TestCase;
use WP_REST_Request;

final class SettingsControllerTest extends TestCase {

	private Settings_Controller $controller;

	protected function setUp(): void {
		parent::setUp();
		Capabilities::add();
		$this->controller = new Settings_Controller();
	}

	public function test_get_returns_current_beacon_map(): void {
		update_option( Schema::OPT_BEACON_MAP, [ 'beacon-x' => 'Site X' ] );
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$response = $this->controller->get_settings();
		$this->assertSame( [ 'beacon_map' => [ 'beacon-x' => 'Site X' ] ], $response->get_data() );
	}

	public function test_put_persists_beacon_map(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', '/' . Reports_Controller::REST_NAMESPACE . '/settings' );
		$request->set_body( wp_json_encode( [ 'beacon_map' => [ 'beacon-y' => 'Site Y' ] ] ) );
		$request->set_header( 'Content-Type', 'application/json' );

		$response = $this->controller->put_settings( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'beacon-y' => 'Site Y' ], get_option( Schema::OPT_BEACON_MAP ) );
	}

	public function test_put_with_invalid_body_returns_400(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', '/' . Reports_Controller::REST_NAMESPACE . '/settings' );
		$request->set_body( wp_json_encode( [ 'beacon_map' => 'not-an-object' ] ) );
		$request->set_header( 'Content-Type', 'application/json' );

		$response = $this->controller->put_settings( $request );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'beacon_map must be an object', $response->get_data()['error'] );
	}
}
