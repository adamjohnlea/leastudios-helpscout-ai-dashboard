<?php
/**
 * @package LEAStudios\HelpScoutAIDashboard
 */

declare(strict_types=1);

namespace LEAStudios\HelpScoutAIDashboard\Tests\Integration;

use LEAStudios\HelpScoutAIDashboard\Capabilities;
use LEAStudios\HelpScoutAIDashboard\REST\Reports_Controller;
use LEAStudios\HelpScoutAIDashboard\Tests\TestCase;
use WP_REST_Request;

final class ReportsControllerTest extends TestCase {

	private Reports_Controller $controller;

	protected function setUp(): void {
		parent::setUp();
		// Grant the manage/view caps to administrator + editor for this run.
		// In production this happens during Activation; tests bypass activation.
		Capabilities::add();
		$this->controller = new Reports_Controller();
		// NOTE: register_routes() is exercised via Plugin bootstrap in
		// production. Tests call controller methods directly to avoid the
		// `_doing_it_wrong( register_rest_route )` notice that WP_UnitTestCase
		// turns into a failure outside the rest_api_init action.
	}

	public function test_anonymous_user_cannot_upload(): void {
		wp_set_current_user( 0 );
		$this->assertFalse( $this->controller->check_manage_cap() );
	}

	public function test_admin_user_can_upload(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );
		$this->assertTrue( $this->controller->check_manage_cap() );
	}

	public function test_upload_with_no_file_returns_400(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$request  = new WP_REST_Request( 'POST', '/' . Reports_Controller::REST_NAMESPACE . '/reports' );
		$response = $this->controller->upload_report( $request );

		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertStringContainsString( 'no file uploaded', (string) $data['error'] );
	}

	public function test_upload_with_php_upload_error_returns_400_with_code(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', '/' . Reports_Controller::REST_NAMESPACE . '/reports' );
		$request->set_file_params(
			[
				'file' => [
					'name'     => 'huge.csv',
					'tmp_name' => '',
					'error'    => UPLOAD_ERR_INI_SIZE,
					'size'     => 0,
				],
			]
		);

		$response = $this->controller->upload_report( $request );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'upload error code 1', $response->get_data()['error'] );
	}

	public function test_upload_happy_path(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$tmp = tempnam( sys_get_temp_dir(), 'lshsaid' );
		copy( __DIR__ . '/../Fixtures/csv/happy.csv', $tmp );

		$request = new WP_REST_Request( 'POST', '/' . Reports_Controller::REST_NAMESPACE . '/reports' );
		$request->set_file_params(
			[
				'file' => [
					'name'     => 'happy.csv',
					'tmp_name' => $tmp,
					'error'    => UPLOAD_ERR_OK,
					'size'     => filesize( $tmp ),
				],
			]
		);

		$response = $this->controller->upload_report( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 5, (int) $response->get_data()['rows'] );

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		@unlink( $tmp );
	}
}
