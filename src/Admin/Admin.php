<?php
/**
 * Admin pages and asset enqueuing. Wires Dashboard (default landing),
 * Reports (CSV upload + management), and Settings (Beacon -> site map)
 * submenus under a single "Help Scout AI" top-level menu.
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

	private const MENU_SLUG_DASHBOARD = 'leastudios-helpscout-ai-dashboard';
	private const MENU_SLUG_REPORTS   = 'leastudios-helpscout-ai-dashboard-reports';
	private const MENU_SLUG_SETTINGS  = 'leastudios-helpscout-ai-dashboard-settings';

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
	 * Register the top-level Dashboard menu + Dashboard/Reports/Settings submenus.
	 *
	 * Dashboard is the landing page (top-level slug == dashboard slug). Reports
	 * and Settings are explicit submenus so each gets its own slug while still
	 * sharing the Dashboard parent.
	 *
	 * @return void
	 */
	public function register_menus(): void {
		add_menu_page(
			__( 'Help Scout AI Dashboard', 'leastudios-helpscout-ai-dashboard' ),
			__( 'Help Scout AI', 'leastudios-helpscout-ai-dashboard' ),
			Capabilities::VIEW,
			self::MENU_SLUG_DASHBOARD,
			[ $this, 'render_dashboard_page' ],
			'dashicons-format-chat',
			58
		);

		add_submenu_page(
			self::MENU_SLUG_DASHBOARD,
			__( 'Dashboard', 'leastudios-helpscout-ai-dashboard' ),
			__( 'Dashboard', 'leastudios-helpscout-ai-dashboard' ),
			Capabilities::VIEW,
			self::MENU_SLUG_DASHBOARD,
			[ $this, 'render_dashboard_page' ]
		);

		add_submenu_page(
			self::MENU_SLUG_DASHBOARD,
			__( 'Reports', 'leastudios-helpscout-ai-dashboard' ),
			__( 'Reports', 'leastudios-helpscout-ai-dashboard' ),
			Capabilities::MANAGE,
			self::MENU_SLUG_REPORTS,
			[ $this, 'render_reports_page' ]
		);

		add_submenu_page(
			self::MENU_SLUG_DASHBOARD,
			__( 'Settings', 'leastudios-helpscout-ai-dashboard' ),
			__( 'Settings', 'leastudios-helpscout-ai-dashboard' ),
			Capabilities::MANAGE,
			self::MENU_SLUG_SETTINGS,
			[ $this, 'render_settings_page' ]
		);
	}

	/**
	 * Render the Dashboard page.
	 *
	 * @return void
	 */
	public function render_dashboard_page(): void {
		if ( ! current_user_can( Capabilities::VIEW ) ) {
			wp_die( esc_html__( 'Forbidden', 'leastudios-helpscout-ai-dashboard' ) );
		}
		require LEASTUDIOS_HELPSCOUT_AI_DASHBOARD_DIR . 'templates/dashboard.php';
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
	 * Order matters: check the most specific slugs first. The Dashboard slug is
	 * a prefix of both Reports and Settings, so $is_dashboard must exclude
	 * those hooks explicitly.
	 *
	 * @param string $hook Current admin page hook suffix.
	 *
	 * @return void
	 */
	public function enqueue( string $hook ): void {
		$is_reports   = false !== strpos( $hook, self::MENU_SLUG_REPORTS );
		$is_settings  = false !== strpos( $hook, self::MENU_SLUG_SETTINGS );
		$is_dashboard = ! $is_reports
			&& ! $is_settings
			&& false !== strpos( $hook, self::MENU_SLUG_DASHBOARD );

		if ( ! $is_dashboard && ! $is_reports && ! $is_settings ) {
			return;
		}

		if ( $is_dashboard ) {
			wp_enqueue_style(
				'lshsaid-dashboard',
				LEASTUDIOS_HELPSCOUT_AI_DASHBOARD_URL . 'assets/css/dashboard.css',
				[],
				LEASTUDIOS_HELPSCOUT_AI_DASHBOARD_VERSION
			);

			// Charts are drawn via the global `Chart` from Chart.js. Loaded from
			// the same CDN as the source plugin for behaviour parity.
			wp_enqueue_script(
				'lshsaid-chartjs',
				'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
				[],
				'4.4.1',
				true
			);

			$handle = 'lshsaid-dashboard';
			$src    = 'assets/js/dashboard.js';
			$deps   = [ 'lshsaid-chartjs' ];
		} elseif ( $is_settings ) {
			$handle = 'lshsaid-settings';
			$src    = 'assets/js/settings.js';
			$deps   = [];
		} else {
			$handle = 'lshsaid-reports';
			$src    = 'assets/js/reports.js';
			$deps   = [];
		}

		wp_enqueue_script(
			$handle,
			LEASTUDIOS_HELPSCOUT_AI_DASHBOARD_URL . $src,
			$deps,
			LEASTUDIOS_HELPSCOUT_AI_DASHBOARD_VERSION,
			true
		);

		wp_localize_script(
			$handle,
			'LSHSAID',
			[
				'rest'        => esc_url_raw( rest_url( Reports_Controller::REST_NAMESPACE . '/' ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'reports_url' => admin_url( 'admin.php?page=' . self::MENU_SLUG_REPORTS ),
			]
		);
	}
}
