<?php
/**
 * Plugin activation.
 *
 * @package Rescue404
 */

namespace Rescue404;

defined( 'ABSPATH' ) || exit;

/**
 * Creates tables, default settings, and the cleanup schedule.
 */
class Activator {

	/**
	 * Run for the current site. Data that already exists is kept.
	 */
	public static function activate(): void {
		$db = new Database();
		$db->install();
		Settings::ensure_defaults();
		Cleanup::schedule();
	}
}
