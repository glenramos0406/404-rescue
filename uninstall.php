<?php
/**
 * Remove plugin data only when the site owner asked for that.
 *
 * Deactivation never reaches this file. Tables stay unless "Delete all 404 Rescue
 * data if I delete this plugin" is checked.
 *
 * @package Rescue404
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Drop this site's tables and options when its own setting allows it.
 * The cleanup event is cleared either way, because the plugin is gone.
 */
function rescue404_uninstall_current_site() {
	$settings = get_option( 'rescue404_settings', array() );
	$delete   = is_array( $settings ) && ! empty( $settings['delete_data'] );

	wp_clear_scheduled_hook( 'rescue404_cleanup' );
	delete_transient( 'rescue404_redirect_map' );
	delete_transient( 'rescue404_error_count' );
	delete_transient( 'rescue404_suggest_budget' );

	if ( ! $delete ) {
		return;
	}

	global $wpdb;
	foreach ( array( 'rescue404_errors', 'rescue404_redirects', 'rescue404_daily' ) as $suffix ) {
		$table = '`' . str_replace( '`', '``', $wpdb->prefix . $suffix ) . '`';
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	delete_option( 'rescue404_settings' );
	delete_option( 'rescue404_db_version' );
}

if ( is_multisite() ) {
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
			rescue404_uninstall_current_site();
			restore_current_blog();
		}
		$offset += 100;
	} while ( 100 === count( $ids ) );
} else {
	rescue404_uninstall_current_site();
}
