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
 * Capability constants and install/uninstall helpers.
 */
final class Capabilities {

	public const MANAGE = 'manage_leastudios_helpscout_ai_dashboard';
	public const VIEW   = 'view_leastudios_helpscout_ai_dashboard';

	/**
	 * Grant capabilities on activation.
	 *
	 * @return void
	 */
	public static function install(): void {
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
	public static function uninstall(): void {
		foreach ( wp_roles()->roles as $role_name => $_unused ) {
			$role = get_role( $role_name );
			if ( $role instanceof \WP_Role ) {
				$role->remove_cap( self::MANAGE );
				$role->remove_cap( self::VIEW );
			}
		}
	}
}
