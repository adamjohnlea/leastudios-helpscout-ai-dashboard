<?php
/**
 * Custom-table schema and table-name accessors.
 *
 * Three tables:
 *   {prefix}leastudios_helpscout_ai_dashboard_interactions  — core Q&A turns, deduped by (session_id, occurred_at)
 *   {prefix}leastudios_helpscout_ai_dashboard_article_refs  — normalized article references (1-3 per interaction)
 *   {prefix}leastudios_helpscout_ai_dashboard_reports       — one row per CSV upload, for audit + delete-by-source
 *
 * @package LEAStudios\HelpScoutAIDashboard
 */

declare(strict_types=1);

namespace LEAStudios\HelpScoutAIDashboard\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Schema installer and table-name accessor for the plugin's custom tables.
 */
final class Schema {

	public const DB_VERSION     = '1';
	public const OPT_DB_VERSION = 'leastudios_helpscout_ai_dashboard_db_version';
	public const OPT_BEACON_MAP = 'leastudios_helpscout_ai_dashboard_beacon_map';

	/**
	 * Fully-qualified `interactions` table name.
	 *
	 * @return string
	 */
	public static function table_interactions(): string {
		global $wpdb;
		return $wpdb->prefix . 'leastudios_helpscout_ai_dashboard_interactions';
	}

	/**
	 * Fully-qualified `article_refs` table name.
	 *
	 * @return string
	 */
	public static function table_article_refs(): string {
		global $wpdb;
		return $wpdb->prefix . 'leastudios_helpscout_ai_dashboard_article_refs';
	}

	/**
	 * Fully-qualified `reports` table name.
	 *
	 * @return string
	 */
	public static function table_reports(): string {
		global $wpdb;
		return $wpdb->prefix . 'leastudios_helpscout_ai_dashboard_reports';
	}

	/**
	 * Create or migrate tables via dbDelta. Idempotent.
	 *
	 * @return void
	 */
	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$t_int           = self::table_interactions();
		$t_art           = self::table_article_refs();
		$t_rep           = self::table_reports();

		$sql_reports = "CREATE TABLE {$t_rep} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			filename VARCHAR(255) NOT NULL DEFAULT '',
			file_hash CHAR(40) NOT NULL DEFAULT '',
			uploaded_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			row_count INT NOT NULL DEFAULT 0,
			dupes_skipped INT NOT NULL DEFAULT 0,
			date_min DATE NULL,
			date_max DATE NULL,
			sites_json TEXT NULL,
			notes VARCHAR(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY file_hash (file_hash)
		) {$charset_collate};";

		$sql_interactions = "CREATE TABLE {$t_int} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id VARCHAR(64) NOT NULL DEFAULT '',
			occurred_at DATETIME NOT NULL,
			week_ending DATE NOT NULL,
			beacon_id VARCHAR(64) NOT NULL DEFAULT '',
			beacon_device_id VARCHAR(64) NOT NULL DEFAULT '',
			site VARCHAR(64) NOT NULL DEFAULT '',
			question TEXT NULL,
			answer LONGTEXT NULL,
			answer_type VARCHAR(32) NOT NULL DEFAULT '',
			customer_id VARCHAR(32) NOT NULL DEFAULT '',
			session_resolution VARCHAR(32) NOT NULL DEFAULT '',
			session_end_reason VARCHAR(64) NOT NULL DEFAULT '',
			rating VARCHAR(16) NOT NULL DEFAULT '',
			comment TEXT NULL,
			conversation_id VARCHAR(32) NOT NULL DEFAULT '',
			conversation_url VARCHAR(255) NOT NULL DEFAULT '',
			report_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY interaction (session_id, occurred_at),
			KEY week_ending (week_ending),
			KEY site (site),
			KEY occurred_at (occurred_at),
			KEY rating (rating),
			KEY report_id (report_id)
		) {$charset_collate};";

		$sql_articles = "CREATE TABLE {$t_art} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			interaction_id BIGINT UNSIGNED NOT NULL,
			position TINYINT UNSIGNED NOT NULL DEFAULT 1,
			title VARCHAR(512) NOT NULL DEFAULT '',
			url VARCHAR(1024) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY interaction_id (interaction_id),
			KEY title (title(191))
		) {$charset_collate};";

		dbDelta( $sql_reports );
		dbDelta( $sql_interactions );
		dbDelta( $sql_articles );

		update_option( self::OPT_DB_VERSION, self::DB_VERSION );

		// Seed Beacon → site map if absent. These four are the production seed
		// from the source plugin; the cutover in Phase 7 will overwrite this
		// with the snapshotted map from the droplet anyway, but having a seed
		// is convenient for fresh installs on other sites.
		if ( get_option( self::OPT_BEACON_MAP, null ) === null ) {
			update_option(
				self::OPT_BEACON_MAP,
				[
					'3385ee56-3426-497b-8421-a461de52b28b' => 'CG Cookie',
					'9c45c870-83e9-4e22-870b-3c2d24bd8a4f' => 'CG Cookie Docs',
					'cc7ce422-91cb-4d72-9b94-cd45894f2fe7' => 'Superhive',
					'f9c9fe3d-8a95-466d-aca7-0264cf304f53' => 'Superhive Docs',
				]
			);
		}
	}
}
