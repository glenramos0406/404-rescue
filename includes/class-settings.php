<?php
/**
 * Plugin settings.
 *
 * @package Rescue404
 */

namespace Rescue404;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and sanitizes the small autoloaded settings array.
 */
class Settings {

	public const OPTION = 'rescue404_settings';

	/**
	 * Default settings used on a new install.
	 *
	 * @return array<string, int|string>
	 */
	public static function defaults(): array {
		return array(
			'monitor_enabled'     => 1,
			'redirects_enabled'   => 1,
			'excluded_patterns'   => "/xmlrpc.php\n/apple-touch-icon*\n/favicon.ico\n/robots.txt",
			'retention_days'      => 30,
			'max_errors'          => 5000,
			'email_notifications' => 0,
			'delete_data'         => 0,
		);
	}

	/**
	 * Saved settings merged over the defaults.
	 *
	 * @return array<string, int|string>
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$clean = self::defaults();
		foreach ( $clean as $key => $default ) {
			if ( array_key_exists( $key, $stored ) ) {
				$clean[ $key ] = $stored[ $key ];
			}
		}

		$clean['monitor_enabled']     = empty( $clean['monitor_enabled'] ) ? 0 : 1;
		$clean['redirects_enabled']   = empty( $clean['redirects_enabled'] ) ? 0 : 1;
		$clean['email_notifications'] = empty( $clean['email_notifications'] ) ? 0 : 1;
		$clean['delete_data']         = empty( $clean['delete_data'] ) ? 0 : 1;
		$clean['retention_days']      = self::retention( $clean['retention_days'] );
		$clean['max_errors']          = self::maximum( $clean['max_errors'] );
		$clean['excluded_patterns']   = self::patterns( (string) $clean['excluded_patterns'] );

		return $clean;
	}

	/**
	 * Settings API callback. Missing checkboxes are stored as off.
	 *
	 * @param mixed $input Posted values.
	 * @return array<string, int|string>
	 */
	public static function sanitize( $input ): array {
		$clean = self::get();
		if ( ! is_array( $input ) ) {
			return $clean;
		}

		$clean['monitor_enabled']     = empty( $input['monitor_enabled'] ) ? 0 : 1;
		$clean['redirects_enabled']   = empty( $input['redirects_enabled'] ) ? 0 : 1;
		$clean['email_notifications'] = empty( $input['email_notifications'] ) ? 0 : 1;
		$clean['delete_data']         = empty( $input['delete_data'] ) ? 0 : 1;
		$clean['retention_days']      = self::retention( $input['retention_days'] ?? 30 );
		$clean['max_errors']          = self::maximum( $input['max_errors'] ?? 5000 );
		$clean['excluded_patterns']   = self::patterns( isset( $input['excluded_patterns'] ) ? (string) $input['excluded_patterns'] : '' );

		return $clean;
	}

	/**
	 * Create the option on first activation without overwriting later edits.
	 */
	public static function ensure_defaults(): void {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::defaults(), '', true );
		}
	}

	/**
	 * Register the option with the Settings API.
	 */
	public static function register(): void {
		register_setting(
			'rescue404_settings_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => self::defaults(),
				'capability'        => 'manage_options',
			)
		);
	}

	/**
	 * Keep a known retention value. Zero means keep logs until someone deletes them.
	 *
	 * @param mixed $value Posted or stored value.
	 */
	private static function retention( $value ): int {
		$days = (int) $value;

		return in_array( $days, array( 0, 7, 30, 60, 90 ), true ) ? $days : 30;
	}

	/**
	 * Cap the log so a flood of random addresses cannot grow without limit.
	 *
	 * @param mixed $value Posted or stored value.
	 */
	private static function maximum( $value ): int {
		$max = (int) $value;
		if ( $max < 100 ) {
			return 100;
		}
		if ( $max > 50000 ) {
			return 50000;
		}

		return $max;
	}

	/**
	 * One pattern per line. Lines that would match every page are dropped.
	 */
	private static function patterns( string $raw ): string {
		$raw   = str_replace( array( "\r\n", "\r" ), "\n", $raw );
		$lines = explode( "\n", $raw );
		$kept  = array();
		$dropped = false;

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( function_exists( 'wp_strip_all_tags' ) ) {
				$line = trim( wp_strip_all_tags( $line ) );
			}
			if ( function_exists( 'sanitize_text_field' ) ) {
				$line = sanitize_text_field( $line );
			}
			if ( '' === $line ) {
				continue;
			}
			if ( strlen( $line ) > 200 ) {
				$line = substr( $line, 0, 200 );
			}
			if ( '*' === $line || '/*' === $line || '/' === $line ) {
				$dropped = true;
				continue;
			}
			$kept[] = $line;
			if ( count( $kept ) >= 100 ) {
				break;
			}
		}

		if ( $dropped && function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				self::OPTION,
				'rescue404_patterns',
				__( 'A pattern that would hide every page was removed.', '404-rescue' )
			);
		}

		return implode( "\n", $kept );
	}
}
