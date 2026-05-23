<?php
/**
 * Capability registration. Write cap goes to admins only; read cap to admins
 * and editors. Removed from all roles on uninstall.
 *
 * @package LEAStudios\HelpScoutAIDashboard
 */

declare(strict_types=1);

namespace LEAStudios\HelpScoutAIDashboard;

defined( 'ABSPATH' ) || exit;

/**
 * Capability constants and add/remove helpers.
 */
final class Capabilities {

	public const MANAGE = 'manage_leastudios_helpscout_ai_dashboard';
	public const VIEW   = 'view_leastudios_helpscout_ai_dashboard';

	/**
	 * Grant capabilities on activation.
	 *
	 * @return void
	 */
	public static function add(): void {
		$admin  = get_role( 'administrator' );
		$editor = get_role( 'editor' );

		if ( $admin instanceof \WP_Role ) {
			$admin->add_cap( self::MANAGE );
			$admin->add_cap( self::VIEW );
		}

		if ( $editor instanceof \WP_Role ) {
			$editor->add_cap( self::VIEW );
		}
	}

	/**
	 * Remove capabilities from every role on uninstall.
	 *
	 * @return void
	 */
	public static function remove(): void {
		global $wp_roles;

		if ( ! $wp_roles instanceof \WP_Roles ) {
			return;
		}

		foreach ( array_keys( $wp_roles->roles ) as $role_name ) {
			$role = get_role( (string) $role_name );
			if ( null === $role ) {
				continue;
			}

			$role->remove_cap( self::MANAGE );
			$role->remove_cap( self::VIEW );
		}
	}
}
