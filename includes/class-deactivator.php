<?php
/**
 * Plugin deactivation.
 *
 * @package Rescue404
 */

namespace Rescue404;

defined( 'ABSPATH' ) || exit;

/**
 * Stops scheduled cleanup. Tables and settings stay in the database.
 */
class Deactivator {

	/**
	 * Deactivation must not delete logs or redirect rules.
	 */
	public static function deactivate(): void {
		Cleanup::unschedule();
	}
}
