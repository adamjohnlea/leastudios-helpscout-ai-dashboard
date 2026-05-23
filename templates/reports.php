<?php
/**
 * Reports admin page.
 *
 * The `aiad-` CSS class / id prefixes are intentionally retained here. They
 * are internal coupling between this template, assets/js/reports.js, and any
 * future CSS — renaming them requires updating all three together.
 *
 * @package LEAStudios\HelpScoutAIDashboard
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap aiad-wrap">
	<h1><?php esc_html_e( 'Help Scout AI Dashboard · Reports', 'leastudios-helpscout-ai-dashboard' ); ?></h1>
	<p><?php esc_html_e( 'Each row below is one CSV upload. Deleting a report removes its rows from the dashboard. Same-file re-uploads are detected by SHA-1 hash and skipped.', 'leastudios-helpscout-ai-dashboard' ); ?></p>

	<div class="aiad-card">
		<h2 class="title"><?php esc_html_e( 'Upload a Help Scout CSV', 'leastudios-helpscout-ai-dashboard' ); ?></h2>
		<form id="aiad-upload-form">
			<input type="file" name="file" accept=".csv" id="aiad-upload-input" required />
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Upload', 'leastudios-helpscout-ai-dashboard' ); ?></button>
			<span id="aiad-upload-status" class="aiad-status"></span>
		</form>
		<p class="description">
			<?php esc_html_e( 'Expected format: the standard Help Scout AI Beacon export. Filename can be anything.', 'leastudios-helpscout-ai-dashboard' ); ?>
			<?php esc_html_e( 'For consistency, recommended naming is', 'leastudios-helpscout-ai-dashboard' ); ?>
			<code>ai_interactions_weekly_&lt;YYYY-MM-DD&gt;_&lt;SITE&gt;.csv</code>
			<?php esc_html_e( '(weekly) or', 'leastudios-helpscout-ai-dashboard' ); ?>
			<code>ai_interactions_monthly_&lt;YYYY-MM&gt;_&lt;SITE&gt;.csv</code>
			<?php esc_html_e( '(legacy monthly).', 'leastudios-helpscout-ai-dashboard' ); ?>
		</p>
	</div>

	<div class="aiad-card">
		<h2 class="title"><?php esc_html_e( 'Uploaded reports', 'leastudios-helpscout-ai-dashboard' ); ?></h2>
		<table class="widefat striped" id="aiad-reports-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Filename', 'leastudios-helpscout-ai-dashboard' ); ?></th>
					<th><?php esc_html_e( 'Uploaded', 'leastudios-helpscout-ai-dashboard' ); ?></th>
					<th><?php esc_html_e( 'Rows', 'leastudios-helpscout-ai-dashboard' ); ?></th>
					<th><?php esc_html_e( 'Dupes skipped', 'leastudios-helpscout-ai-dashboard' ); ?></th>
					<th><?php esc_html_e( 'Date range', 'leastudios-helpscout-ai-dashboard' ); ?></th>
					<th><?php esc_html_e( 'Sites', 'leastudios-helpscout-ai-dashboard' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody><tr><td colspan="7"><em><?php esc_html_e( 'Loading…', 'leastudios-helpscout-ai-dashboard' ); ?></em></td></tr></tbody>
		</table>
		<div class="tablenav bottom">
			<div class="tablenav-pages" id="aiad-reports-pager"></div>
		</div>
	</div>
</div>
