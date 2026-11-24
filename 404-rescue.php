<?php
/**
 * Plugin Name:       404 Rescue
 * Description:       Records missing pages, suggests a page on your site, and lets you send visitors there.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * Author:            Glenn Lawrence Ramos
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       404-rescue
 * Domain Path:       /languages
 *
 * @package Rescue404
 */

defined( 'ABSPATH' ) || exit;

define( 'RESCUE404_VERSION', '1.0.0' );
define( 'RESCUE404_DB_VERSION', '1.0.0' );
define( 'RESCUE404_FILE', __FILE__ );
define( 'RESCUE404_DIR', plugin_dir_path( __FILE__ ) );
define( 'RESCUE404_URL', plugin_dir_url( __FILE__ ) );

require_once RESCUE404_DIR . 'includes/class-path.php';
require_once RESCUE404_DIR . 'includes/class-settings.php';
require_once RESCUE404_DIR . 'includes/class-database.php';
require_once RESCUE404_DIR . 'includes/class-suggestions.php';
require_once RESCUE404_DIR . 'includes/class-redirect-manager.php';
require_once RESCUE404_DIR . 'includes/class-monitor.php';
require_once RESCUE404_DIR . 'includes/class-cleanup.php';
require_once RESCUE404_DIR . 'includes/class-csv.php';
require_once RESCUE404_DIR . 'includes/class-activator.php';
require_once RESCUE404_DIR . 'includes/class-deactivator.php';
require_once RESCUE404_DIR . 'includes/class-admin.php';

/**
 * Run a callback for every site when the plugin is network-activated.
 *
 * @param callable $callback Callback with no arguments.
 */
function rescue404_each_site( $callback ) {
	if ( ! is_multisite() ) {
		$callback();
		return;
	}

	$offset = 0;
	do {
		$ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $offset,
			)
		);
		if ( ! is_array( $ids ) || array() === $ids ) {
			break;
		}
		foreach ( $ids as $blog_id ) {
			switch_to_blog( (int) $blog_id );
			$callback();
			restore_current_blog();
		}
		$offset += 100;
	} while ( 100 === count( $ids ) );
}

/**
 * Create tables and schedule cleanup. Existing logs and redirects are kept.
 *
 * @param bool $network_wide Whether this is a network activation.
 */
function rescue404_activate( $network_wide = false ) {
	if ( is_multisite() && $network_wide ) {
		rescue404_each_site( array( \Rescue404\Activator::class, 'activate' ) );
		return;
	}
	\Rescue404\Activator::activate();
}

/**
 * Stop scheduled cleanup. Does not delete logs or redirects.
 *
 * @param bool $network_wide Whether this is a network deactivation.
 */
function rescue404_deactivate( $network_wide = false ) {
	if ( is_multisite() && $network_wide ) {
		rescue404_each_site( array( \Rescue404\Deactivator::class, 'deactivate' ) );
		return;
	}
	\Rescue404\Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'rescue404_activate' );
register_deactivation_hook( __FILE__, 'rescue404_deactivate' );

add_action(
	'plugins_loaded',
	static function () {
		( new \Rescue404\Database() )->maybe_upgrade();
		( new \Rescue404\Monitor() )->register();
		( new \Rescue404\Cleanup() )->register();
		if ( is_admin() ) {
			( new \Rescue404\Admin() )->register();
		}
	}
);

add_action(
	'init',
	static function () {
		load_plugin_textdomain( '404-rescue', false, dirname( plugin_basename( RESCUE404_FILE ) ) . '/languages' );
	}
);

add_action(
	'wp_initialize_site',
	static function ( $site ) {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_plugin_active_for_network( plugin_basename( RESCUE404_FILE ) ) ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		\Rescue404\Activator::activate();
		restore_current_blog();
	}
);
