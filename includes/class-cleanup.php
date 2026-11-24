<?php
/**
 * Scheduled log cleanup.
 *
 * @package Rescue404
 */

namespace Rescue404;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes old 404 rows on a cron event. Redirect rules are left alone.
 */
class Cleanup {

	public const HOOK = 'rescue404_cleanup';

	/**
	 * @var Database
	 */
	private $db;

	public function __construct( ?Database $db = null ) {
		$this->db = $db ?? new Database();
	}

	/**
	 * Listen for the daily event and for a settings save.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
	}

	/**
	 * Put the daily job on the calendar during activation.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Activation promised a scheduled cleanup. Replace it if it was lost.
	 */
	public function ensure_scheduled(): void {
		self::schedule();
	}

	/**
	 * Remove the scheduled job. Used on deactivation and uninstall.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Delete expired rows in batches. Never deletes redirect rules.
	 */
	public function run(): void {
		$settings = Settings::get();
		$days     = (int) $settings['retention_days'];
		if ( $days > 0 ) {
			$cutoff = wp_date( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
			for ( $i = 0; $i < 10; $i++ ) {
				$deleted = $this->db->delete_expired( $cutoff, 500 );
				if ( $deleted < 500 ) {
					break;
				}
			}
			$daily_cutoff = wp_date( 'Y-m-d', time() - ( max( $days, 90 ) * DAY_IN_SECONDS ) );
			$this->db->prune_daily( $daily_cutoff );
		} else {
			$this->db->prune_daily( wp_date( 'Y-m-d', time() - ( 180 * DAY_IN_SECONDS ) ) );
		}

		$max   = (int) $settings['max_errors'];
		$extra = $this->db->error_count() - $max;
		if ( $max > 0 && $extra > 0 ) {
			$loops = (int) min( 10, ceil( $extra / 500 ) );
			for ( $i = 0; $i < $loops; $i++ ) {
				$deleted = $this->db->delete_oldest( 500 );
				if ( $deleted < 1 ) {
					break;
				}
			}
		}

		$this->db->refresh_error_count();
	}
}
