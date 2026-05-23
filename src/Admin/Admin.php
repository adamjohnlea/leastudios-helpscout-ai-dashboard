<?php
/**
 * Admin menu pages and asset enqueueing. Pages are added in phases; this
 * file is updated in Phase 3 (settings) and Phase 4 (dashboard).
 *
 * @package LEAStudios\HelpScoutAIDashboard
 */

declare(strict_types=1);

namespace LEAStudios\HelpScoutAIDashboard\Admin;

use LEAStudios\HelpScoutAIDashboard\Capabilities;
use LEAStudios\HelpScoutAIDashboard\REST\Reports_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Help Scout AI Dashboard admin menu and enqueues its assets.
 */
final class Admin {

	private const MENU_SLUG_REPORTS  = 'leastudios-helpscout-ai-dashboard-reports';
	private const MENU_SLUG_SETTINGS = 'leastudios-helpscout-ai-dashboard-settings';

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register_menus' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * Register the top-level menu + Reports + Settings submenus.
	 *
	 * @return void
	 */
	public function register_menus(): void {
		add_menu_page(
			__( 'Help Scout AI Dashboard', 'leastudios-helpscout-ai-dashboard' ),
			__( 'Help Scout AI', 'leastudios-helpscout-ai-dashboard' ),
			Capabilities::VIEW,
			self::MENU_SLUG_REPORTS,
			[ $this, 'render_reports_page' ],
			'dashicons-format-chat',
			58
		);

		add_submenu_page(
			self::MENU_SLUG_REPORTS,
			__( 'Reports', 'leastudios-helpscout-ai-dashboard' ),
			__( 'Reports', 'leastudios-helpscout-ai-dashboard' ),
			Capabilities::MANAGE,
			self::MENU_SLUG_REPORTS,
			[ $this, 'render_reports_page' ]
		);

		add_submenu_page(
			self::MENU_SLUG_REPORTS,
			__( 'Settings', 'leastudios-helpscout-ai-dashboard' ),
			__( 'Settings', 'leastudios-helpscout-ai-dashboard' ),
			Capabilities::MANAGE,
			self::MENU_SLUG_SETTINGS,
			[ $this, 'render_settings_page' ]
		);
	}

	/**
	 * Render the Reports page.
	 *
	 * @return void
	 */
	public function render_reports_page(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'Forbidden', 'leastudios-helpscout-ai-dashboard' ) );
		}
		require LEASTUDIOS_HELPSCOUT_AI_DASHBOARD_DIR . 'templates/reports.php';
	}

	/**
	 * Render the Settings page.
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'Forbidden', 'leastudios-helpscout-ai-dashboard' ) );
		}
		require LEASTUDIOS_HELPSCOUT_AI_DASHBOARD_DIR . 'templates/settings.php';
	}

	/**
	 * Enqueue assets only on this plugin's admin pages.
	 *
	 * @param string $hook Current admin page hook suffix.
	 *
	 * @return void
	 */
	public function enqueue( string $hook ): void {
		$is_settings = false !== strpos( $hook, self::MENU_SLUG_SETTINGS );
		$is_reports  = ! $is_settings && false !== strpos( $hook, self::MENU_SLUG_REPORTS );

		if ( ! $is_reports && ! $is_settings ) {
			return;
		}

		$handle = $is_settings ? 'lshsaid-settings' : 'lshsaid-reports';
		$src    = $is_settings ? 'assets/js/settings.js' : 'assets/js/reports.js';

		wp_enqueue_script(
			$handle,
			LEASTUDIOS_HELPSCOUT_AI_DASHBOARD_URL . $src,
			[],
			LEASTUDIOS_HELPSCOUT_AI_DASHBOARD_VERSION,
			true
		);

		wp_localize_script(
			$handle,
			'LSHSAID',
			[
				'rest'  => esc_url_raw( rest_url( Reports_Controller::REST_NAMESPACE . '/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			]
		);
	}
}
