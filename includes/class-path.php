<?php
/**
 * Path normalization and redirect-target checks.
 *
 * @package Rescue404
 */

namespace Rescue404;

defined( 'ABSPATH' ) || exit;

/**
 * Turns request URIs into stored paths and rejects unsafe redirect targets.
 */
class Path {

	public const MAX_PATH = 191;

	/**
	 * Path prefix of this site, such as /blog, without a trailing slash.
	 */
	public static function home_path(): string {
		if ( ! function_exists( 'home_url' ) ) {
			return '';
		}

		$home = (string) home_url( '/' );
		$path = wp_parse_url( $home, PHP_URL_PATH );

		return is_string( $path ) ? self::clean( $path ) : '';
	}

	/**
	 * Home URL used when deciding whether a destination stays on this site.
	 */
	public static function home_url(): string {
		return function_exists( 'home_url' ) ? (string) home_url( '/' ) : '';
	}

	/**
	 * Convert a URI, site URL, or typed address into a stored path.
	 *
	 * Query strings are removed. ".." is rejected. The result is empty when
	 * the path is not safe to store.
	 */
	public static function from_uri( string $uri, string $home_path = '' ): string {
		$uri = trim( str_replace( "\0", '', $uri ) );
		if ( '' === $uri ) {
			return '';
		}

		if ( preg_match( '#^[a-z][a-z0-9+\-.]*://#i', $uri ) ) {
			$parts = self::url_parts( $uri );
			$path  = ( is_array( $parts ) && isset( $parts['path'] ) && is_string( $parts['path'] ) ) ? $parts['path'] : '/';
		} else {
			$hash = strpos( $uri, '#' );
			if ( false !== $hash ) {
				$uri = substr( $uri, 0, $hash );
			}
			$query = strpos( $uri, '?' );
			if ( false !== $query ) {
				$uri = substr( $uri, 0, $query );
			}
			$path = $uri;
		}

		$path = rawurldecode( $path );
		if ( false !== strpos( $path, "\0" ) || false !== strpos( $path, '..' ) ) {
			return '';
		}

		$path = str_replace( '\\', '/', $path );
		$home = self::clean( $home_path );
		if ( '' !== $home && '/' !== $home && str_starts_with( $path, $home ) ) {
			$rest = substr( $path, strlen( $home ) );
			if ( '' === $rest || str_starts_with( $rest, '/' ) ) {
				$path = '' === $rest ? '/' : $rest;
			}
		}

		return self::clean( $path );
	}

	/**
	 * Collapse slashes and drop a trailing slash. "/" stays "/".
	 */
	public static function clean( string $path ): string {
		$path = trim( str_replace( "\0", '', $path ) );
		if ( '' === $path ) {
			return '';
		}

		$path = str_replace( '\\', '/', $path );
		if ( false !== strpos( $path, '..' ) ) {
			return '';
		}

		$path = '/' . ltrim( $path, '/' );
		$path = preg_replace( '#/+#', '/', $path );
		if ( ! is_string( $path ) ) {
			return '';
		}

		$path = preg_replace( '#/index\.php(?=/|$)#i', '', $path );
		if ( ! is_string( $path ) || '' === $path ) {
			$path = '/';
		}
		if ( '/' !== $path ) {
			$path = rtrim( $path, '/' );
		}
		if ( strlen( $path ) > self::MAX_PATH ) {
			return '';
		}

		return $path;
	}

	/**
	 * Last path segment, without the leading slash.
	 */
	public static function last_segment( string $path ): string {
		$path = trim( $path, '/' );
		if ( '' === $path ) {
			return '';
		}
		$parts = explode( '/', $path );
		$last  = end( $parts );

		return is_string( $last ) ? $last : '';
	}

	/**
	 * Files the web server should handle. Missing ones are not content 404s.
	 */
	public static function is_static( string $path ): bool {
		return (bool) preg_match(
			'/\.(?:css|js|mjs|map|jpe?g|png|gif|webp|avif|svg|ico|woff2?|ttf|otf|eot|mp4|webm|mp3|wav|pdf|xml|txt|gz|zip|rar|json)$/i',
			$path
		);
	}

	/**
	 * WordPress system paths that must never be logged or redirected.
	 */
	public static function is_reserved( string $path ): bool {
		$lower    = strtolower( $path );
		$prefixes = array(
			'/wp-admin',
			'/wp-json',
			'/wp-includes',
			'/wp-login.php',
			'/wp-cron.php',
			'/wp-signup.php',
			'/wp-activate.php',
			'/xmlrpc.php',
		);

		foreach ( $prefixes as $prefix ) {
			if ( $lower === $prefix || str_starts_with( $lower, $prefix . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a user exclusion pattern matches this path.
	 *
	 * A lone "*" is ignored so one saved line cannot silence the whole site.
	 *
	 * @param string|array<int, string> $patterns Newline text or a list of lines.
	 */
	public static function is_excluded( string $path, $patterns ): bool {
		$lines = is_array( $patterns ) ? $patterns : preg_split( '/\r\n|\r|\n/', (string) $patterns );
		if ( ! is_array( $lines ) ) {
			return false;
		}

		foreach ( $lines as $line ) {
			$raw = trim( (string) $line );
			if ( '' === $raw || '*' === $raw || '/*' === $raw || '/' === $raw ) {
				continue;
			}
			if ( self::matches( $path, $raw ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Match an exact path, a path prefix, or a * / ? wildcard.
	 */
	public static function matches( string $path, string $pattern ): bool {
		$pattern = trim( $pattern );
		if ( '' === $pattern || '*' === $pattern || '/*' === $pattern || '/' === $pattern ) {
			return false;
		}

		if ( false === strpbrk( $pattern, '*?' ) ) {
			$pattern = self::from_uri( $pattern );
			if ( '' === $pattern || '/' === $pattern ) {
				return false;
			}
			$lower  = strtolower( $path );
			$needle = strtolower( $pattern );

			return $lower === $needle || str_starts_with( $lower, $needle . '/' );
		}

		if ( preg_match( '#^https?://#i', $pattern ) ) {
			$parts   = self::url_parts( $pattern );
			$pattern = ( is_array( $parts ) && isset( $parts['path'] ) ) ? (string) $parts['path'] : '';
		}
		if ( '' === $pattern || '*' === $pattern ) {
			return false;
		}
		if ( ! str_starts_with( $pattern, '/' ) ) {
			$pattern = '/' . $pattern;
		}

		$quoted = preg_quote( $pattern, '#' );
		$quoted = str_replace( array( '\*', '\?' ), array( '.*', '.' ), $quoted );

		return (bool) preg_match( '#^' . $quoted . '$#i', $path );
	}

	/**
	 * Stop a spreadsheet from treating an exported cell as a formula.
	 */
	public static function csv_safe( string $value ): string {
		if ( preg_match( '/^[=+\-@\t\r]/', $value ) ) {
			return "'" . $value;
		}

		return $value;
	}

	/**
	 * Strip one spreadsheet-safety apostrophe added by csv_safe().
	 */
	public static function csv_unescape( string $value ): string {
		if ( preg_match( "/^'[=+\\-@\\t\\r]/", $value ) ) {
			return substr( $value, 1 );
		}

		return $value;
	}

	/**
	 * True when two URLs point at the same site host.
	 */
	public static function same_site( string $url, string $home ): bool {
		$left  = self::url_parts( $url );
		$right = self::url_parts( $home );
		if ( ! is_array( $left ) || ! is_array( $right ) || empty( $left['host'] ) || empty( $right['host'] ) ) {
			return false;
		}

		return strtolower( (string) $left['host'] ) === strtolower( (string) $right['host'] );
	}

	/**
	 * Accept an external http(s) URL, or return an empty string.
	 *
	 * Runtime checks stay local: no DNS lookup on the visitor's request.
	 * Saving a rule also asks WordPress to validate the URL.
	 */
	public static function external_url( string $url, bool $strict = false ): string {
		$url = trim( $url );
		if ( '' === $url || strlen( $url ) > 500 ) {
			return '';
		}
		if ( false !== strpos( $url, "\0" ) || false !== stripos( $url, '%00' ) || preg_match( '/[\s\\\\@]/', $url ) ) {
			return '';
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}

		$decoded = rawurldecode( $url );
		if ( preg_match( '/[\s\\\\@]/', $decoded ) || false !== stripos( $decoded, 'javascript:' ) || false !== stripos( $decoded, 'data:' ) ) {
			return '';
		}

		$parts = self::url_parts( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return '';
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}

		$host = strtolower( (string) $parts['host'] );
		if ( '' === $host || 'localhost' === $host || str_ends_with( $host, '.localhost' ) || str_ends_with( $host, '.local' ) ) {
			return '';
		}
		if ( isset( $parts['port'] ) && ( (int) $parts['port'] < 1 || (int) $parts['port'] > 65535 ) ) {
			return '';
		}
		if ( self::is_private_host( $host ) ) {
			return '';
		}

		if ( function_exists( 'wp_kses_bad_protocol' ) ) {
			$clean = wp_kses_bad_protocol( $url, array( 'http', 'https' ) );
			if ( ! is_string( $clean ) || strtolower( $clean ) !== strtolower( $url ) ) {
				return '';
			}
		}

		if ( $strict && function_exists( 'wp_http_validate_url' ) ) {
			$valid = wp_http_validate_url( $url );
			if ( ! is_string( $valid ) || '' === $valid ) {
				return '';
			}
			$url = $valid;
		}

		if ( function_exists( 'esc_url_raw' ) ) {
			$escaped = esc_url_raw( $url );
			if ( ! is_string( $escaped ) || '' === $escaped ) {
				return '';
			}
			if ( ! self::same_site( $escaped, $url ) && self::host_only( $escaped ) !== self::host_only( $url ) ) {
				return '';
			}
			$url = $escaped;
		}

		return $url;
	}

	/**
	 * True for IP addresses in private or reserved ranges.
	 */
	public static function is_private_host( string $host ): bool {
		if ( false === filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		return false === filter_var(
			$host,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}

	/**
	 * Follow internal redirect rules and report a circle.
	 *
	 * An empty destination path means the destination is on another site.
	 *
	 * @param array<string, string> $map Source path => internal destination path.
	 */
	public static function chain_loops( string $source, string $dest_path, array $map ): bool {
		if ( '' === $dest_path ) {
			return false;
		}
		if ( $dest_path === $source ) {
			return true;
		}

		$map[ $source ] = $dest_path;
		$seen           = array();
		$current        = $source;

		for ( $i = 0; $i < 15; $i++ ) {
			if ( ! isset( $map[ $current ] ) ) {
				return false;
			}
			$next = $map[ $current ];
			if ( $next === $source || isset( $seen[ $next ] ) ) {
				return true;
			}
			$seen[ $current ] = true;
			$current          = $next;
		}

		return true;
	}

	/**
	 * URL pieces from WordPress, or false when the address cannot be read.
	 *
	 * @return array<string, mixed>|false
	 */
	private static function url_parts( string $url ) {
		$parts = wp_parse_url( $url );

		return is_array( $parts ) ? $parts : false;
	}

	/**
	 * Host portion, lowercase, or an empty string.
	 */
	private static function host_only( string $url ): string {
		$parts = self::url_parts( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		return strtolower( (string) $parts['host'] );
	}
}
