<?php
/**
 * Settings admin page - Beacon -> site map editor.
 *
 * The `aiad-` CSS class / id prefixes are intentionally retained here. They
 * are an internal contract between this template, assets/js/settings.js, and
 * the dashboard stylesheet; renaming them requires updating all three in
 * lockstep with no behavioral benefit.
 *
 * @package LEAStudios\HelpScoutAIDashboard
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap aiad-wrap">
	<h1><?php esc_html_e( 'Help Scout AI Dashboard - Settings', 'leastudios-helpscout-ai-dashboard' ); ?></h1>
	<p><?php esc_html_e( 'Each row maps a Help Scout Beacon ID to a human-readable site name. Rows from CSVs whose Beacon isn\'t in this map show up as "Unknown (<hash>)" on the dashboard.', 'leastudios-helpscout-ai-dashboard' ); ?></p>

	<div class="aiad-card">
		<h2 class="title"><?php esc_html_e( 'Beacon - site map', 'leastudios-helpscout-ai-dashboard' ); ?></h2>
		<table class="widefat" id="aiad-beacon-table">
			<thead>
				<tr>
					<th style="width: 360px;"><?php esc_html_e( 'Beacon ID', 'leastudios-helpscout-ai-dashboard' ); ?></th>
					<th><?php esc_html_e( 'Site name', 'leastudios-helpscout-ai-dashboard' ); ?></th>
					<th style="width: 80px;"></th>
				</tr>
			</thead>
			<tbody></tbody>
			<tfoot>
				<tr>
					<td colspan="3">
						<button type="button" class="button" id="aiad-add-row">+ <?php esc_html_e( 'Add row', 'leastudios-helpscout-ai-dashboard' ); ?></button>
					</td>
				</tr>
			</tfoot>
		</table>
		<p>
			<button type="button" class="button button-primary" id="aiad-save"><?php esc_html_e( 'Save', 'leastudios-helpscout-ai-dashboard' ); ?></button>
			<span id="aiad-save-status" class="aiad-status"></span>
		</p>
	</div>

	<div class="aiad-card">
		<h2 class="title"><?php esc_html_e( 'Cached dashboard data', 'leastudios-helpscout-ai-dashboard' ); ?></h2>
		<p>
			<?php esc_html_e( 'The dashboard caches its payload in your browser (IndexedDB) so repeat visits render instantly. The cache automatically refreshes in the background when the underlying data changes. Use this if the dashboard ever shows stale data and a hard reload doesn\'t help.', 'leastudios-helpscout-ai-dashboard' ); ?>
		</p>
		<p>
			<button type="button" class="button" id="aiad-clear-cache"><?php esc_html_e( 'Clear cached dashboard data', 'leastudios-helpscout-ai-dashboard' ); ?></button>
			<span id="aiad-clear-cache-status" class="aiad-status"></span>
		</p>
	</div>
</div>
