<?php
/**
 * Redirect rules and the front-end lookup.
 *
 * @package Rescue404
 */

namespace Rescue404;

defined( 'ABSPATH' ) || exit;

/**
 * Validates, stores, and runs redirect rules.
 */
class Redirect_Manager {

	public const CACHE_KEY = 'rescue404_redirect_map';

	/**
	 * @var Database
	 */
	private $db;

	public function __construct( ?Database $db = null ) {
		$this->db = $db ?? new Database();
	}

	/**
	 * Check a rule without writing it.
	 *
	 * @param array<int, array<string, mixed>> $existing Other rules already stored.
	 * @return array{ok: bool, message: string, source_path: string, destination_url: string, destination_path: string, is_external: bool}
	 */
	public static function check( string $source_raw, string $destination_raw, int $type, string $home, array $existing, int $ignore_id = 0 ): array {
		$fail = static function ( string $message ): array {
			return array(
				'ok'               => false,
				'message'          => $message,
				'source_path'      => '',
				'destination_url'  => '',
				'destination_path' => '',
				'is_external'      => false,
			);
		};

		if ( ! in_array( $type, array( 301, 302, 307, 308 ), true ) ) {
			return $fail( __( 'Choose 301, 302, 307, or 308.', '404-rescue' ) );
		}

		$home_path = Path::from_uri( $home );
		$source    = self::source_path( $source_raw, $home_path );
		if ( '' === $source ) {
			return $fail( __( 'Enter a page address from this site, such as /old-page.', '404-rescue' ) );
		}
		if ( '/' === $source ) {
			return $fail( __( 'The home page cannot be the broken address.', '404-rescue' ) );
		}
		if ( Path::is_reserved( $source ) ) {
			return $fail( __( 'That address belongs to WordPress and cannot be redirected.', '404-rescue' ) );
		}

		$built = self::build_destination( $destination_raw, $home, $home_path );
		if ( ! $built['ok'] ) {
			return $fail( $built['message'] );
		}

		if ( ! $built['is_external'] && $built['destination_path'] === $source ) {
			return $fail( __( 'The destination is the same as the broken address.', '404-rescue' ) );
		}

		foreach ( $existing as $row ) {
			$row_id = isset( $row['id'] ) ? (int) $row['id'] : 0;
			if ( $row_id === $ignore_id ) {
				continue;
			}
			if ( isset( $row['source_path'] ) && (string) $row['source_path'] === $source ) {
				return array(
					'ok'               => false,
					'message'          => __( 'That address already has a redirect. Edit the existing one instead.', '404-rescue' ),
					'source_path'      => $source,
					'destination_url'  => $built['destination_url'],
					'destination_path' => $built['destination_path'],
					'is_external'      => $built['is_external'],
				);
			}
		}

		if ( Path::chain_loops( $source, $built['destination_path'], self::loop_map( $existing, $ignore_id, $home, $home_path ) ) ) {
			return $fail( __( 'That redirect would send visitors in a circle.', '404-rescue' ) );
		}

		return array(
			'ok'               => true,
			'message'          => '',
			'source_path'      => $source,
			'destination_url'  => $built['destination_url'],
			'destination_path' => $built['destination_path'],
			'is_external'      => $built['is_external'],
		);
	}

	/**
	 * Send the visitor when an active rule matches. Exits on success.
	 */
	public function maybe_redirect( string $path ): void {
		if ( '' === $path || '/' === $path || Path::is_reserved( $path ) || headers_sent() ) {
			return;
		}

		$rule = $this->lookup( $path );
		if ( ! $rule ) {
			return;
		}

		$type   = in_array( (int) $rule['type'], array( 301, 302, 307, 308 ), true ) ? (int) $rule['type'] : 301;
		$target = self::runtime_target( (string) $rule['url'], Path::home_url() );
		if ( '' === $target ) {
			return;
		}

		if ( Path::same_site( $target, Path::home_url() ) ) {
			wp_safe_redirect( $target, $type );
			exit;
		}

		wp_redirect( $target, $type );
		exit;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array{ok: bool, message: string, id: int}
	 */
	public function create( array $input ): array {
		$checked = $this->validate_input( $input, 0 );
		if ( ! $checked['ok'] ) {
			return array(
				'ok'      => false,
				'message' => $checked['message'],
				'id'      => 0,
			);
		}

		$now    = current_time( 'mysql' );
		$active = empty( $input['is_active'] ) ? 0 : 1;
		$id     = $this->db->insert_redirect(
			array(
				'source_path'     => $checked['source_path'],
				'destination_url' => $checked['destination_url'],
				'redirect_type'   => (int) $input['type'],
				'is_active'       => $active,
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);

		if ( $id < 1 ) {
			return array(
				'ok'      => false,
				'message' => __( 'That address already has a redirect. Edit the existing one instead.', '404-rescue' ),
				'id'      => 0,
			);
		}

		if ( $active ) {
			$this->db->mark_resolved( $checked['source_path'] );
		}
		$this->forget_map();

		return array(
			'ok'      => true,
			'message' => $active
				? __( 'Redirect saved. Visitors to the old address will be sent to the new one.', '404-rescue' )
				: __( 'Redirect saved, and it is turned off.', '404-rescue' ),
			'id'      => $id,
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array{ok: bool, message: string, id: int}
	 */
	public function update( int $id, array $input ): array {
		$current = $this->db->get_redirect( $id );
		if ( ! $current ) {
			return array(
				'ok'      => false,
				'message' => __( 'That redirect could not be found.', '404-rescue' ),
				'id'      => 0,
			);
		}

		$checked = $this->validate_input( $input, $id );
		if ( ! $checked['ok'] ) {
			return array(
				'ok'      => false,
				'message' => $checked['message'],
				'id'      => 0,
			);
		}

		$active = empty( $input['is_active'] ) ? 0 : 1;
		$saved  = $this->db->update_redirect(
			$id,
			array(
				'source_path'     => $checked['source_path'],
				'destination_url' => $checked['destination_url'],
				'redirect_type'   => (int) $input['type'],
				'is_active'       => $active,
				'updated_at'      => current_time( 'mysql' ),
			)
		);
		if ( ! $saved ) {
			return array(
				'ok'      => false,
				'message' => __( 'The redirect could not be saved.', '404-rescue' ),
				'id'      => 0,
			);
		}

		if ( (string) $current['source_path'] !== $checked['source_path'] ) {
			$this->db->mark_unresolved( (string) $current['source_path'] );
		}
		if ( $active ) {
			$this->db->mark_resolved( $checked['source_path'] );
		} else {
			$this->db->mark_unresolved( $checked['source_path'] );
		}
		$this->forget_map();

		return array(
			'ok'      => true,
			'message' => __( 'Redirect updated.', '404-rescue' ),
			'id'      => $id,
		);
	}

	/**
	 * @return array{ok: bool, message: string}
	 */
	public function set_active( int $id, bool $active ): array {
		$row = $this->db->get_redirect( $id );
		if ( ! $row ) {
			return array(
				'ok'      => false,
				'message' => __( 'That redirect could not be found.', '404-rescue' ),
			);
		}

		if ( $active ) {
			$checked = $this->validate_input(
				array(
					'source'      => (string) $row['source_path'],
					'destination' => (string) $row['destination_url'],
					'type'        => (int) $row['redirect_type'],
				),
				$id
			);
			if ( ! $checked['ok'] ) {
				return array(
					'ok'      => false,
					'message' => $checked['message'],
				);
			}
		}

		$this->db->set_redirect_active( $id, $active );
		if ( $active ) {
			$this->db->mark_resolved( (string) $row['source_path'] );
		} else {
			$this->db->mark_unresolved( (string) $row['source_path'] );
		}
		$this->forget_map();

		return array(
			'ok'      => true,
			'message' => $active
				? __( 'Redirect turned on.', '404-rescue' )
				: __( 'Redirect turned off. The old address will be a missing page again.', '404-rescue' ),
		);
	}

	/**
	 * @return array{ok: bool, message: string}
	 */
	public function delete( int $id ): array {
		$row = $this->db->get_redirect( $id );
		if ( ! $row ) {
			return array(
				'ok'      => false,
				'message' => __( 'That redirect could not be found.', '404-rescue' ),
			);
		}

		$this->db->delete_redirect( $id );
		$this->db->mark_unresolved( (string) $row['source_path'] );
		$this->forget_map();

		return array(
			'ok'      => true,
			'message' => __( 'Redirect deleted.', '404-rescue' ),
		);
	}

	/**
	 * @return array{url: string, type: int}|null
	 */
	public function lookup( string $path ): ?array {
		$map = $this->map();
		if ( null === $map ) {
			$row = $this->db->active_redirect_by_path( $path );
			if ( ! $row ) {
				return null;
			}

			return array(
				'url'  => (string) $row['destination_url'],
				'type' => (int) $row['redirect_type'],
			);
		}

		return $map[ $path ] ?? null;
	}

	public function forget_map(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Cached active rules. Null means there are too many to cache, so look up one path.
	 *
	 * @return array<string, array{url: string, type: int}>|null
	 */
	private function map(): ?array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) && array_key_exists( 'rules', $cached ) ) {
			if ( ! empty( $cached['single'] ) ) {
				return null;
			}

			return is_array( $cached['rules'] ) ? $cached['rules'] : array();
		}

		$rows = $this->db->active_redirects();
		if ( count( $rows ) > 1000 ) {
			set_transient(
				self::CACHE_KEY,
				array(
					'rules'  => array(),
					'single' => 1,
				),
				HOUR_IN_SECONDS
			);

			return null;
		}

		$rules = array();
		foreach ( $rows as $row ) {
			$rules[ (string) $row['source_path'] ] = array(
				'url'  => (string) $row['destination_url'],
				'type' => (int) $row['redirect_type'],
			);
		}

		set_transient(
			self::CACHE_KEY,
			array(
				'rules'  => $rules,
				'single' => 0,
			),
			12 * HOUR_IN_SECONDS
		);

		return $rules;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array{ok: bool, message: string, source_path: string, destination_url: string, destination_path: string, is_external: bool}
	 */
	private function validate_input( array $input, int $ignore_id ): array {
		$type = isset( $input['type'] ) ? (int) $input['type'] : 301;

		return self::check(
			isset( $input['source'] ) ? (string) $input['source'] : '',
			isset( $input['destination'] ) ? (string) $input['destination'] : '',
			$type,
			Path::home_url(),
			$this->db->all_redirects(),
			$ignore_id
		);
	}

	/**
	 * Re-check a stored target before sending a visitor.
	 */
	public static function runtime_target( string $url, string $home ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		if ( preg_match( '#^[a-z][a-z0-9+\-.]*:#i', $url ) && ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}

		$home_path   = Path::from_uri( $home );
		$is_absolute = (bool) preg_match( '#^https?://#i', $url );
		if ( $is_absolute && '' !== $home && ! Path::same_site( $url, $home ) ) {
			return Path::external_url( $url, false );
		}

		$local = self::local_destination( $url, $home, $home_path );
		if ( '' === $local['url'] || Path::is_reserved( $local['path'] ) ) {
			return '';
		}

		return $local['url'];
	}

	/**
	 * @return array{ok: bool, message: string, destination_url: string, destination_path: string, is_external: bool}
	 */
	private static function build_destination( string $raw, string $home, string $home_path ): array {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return self::destination_error( __( 'Choose where visitors should go.', '404-rescue' ) );
		}
		if ( preg_match( '#^[a-z][a-z0-9+\-.]*:#i', $raw ) && ! preg_match( '#^https?://#i', $raw ) ) {
			return self::destination_error( __( 'That destination address is not allowed. Use a page on this site, or a full https:// address.', '404-rescue' ) );
		}
		if ( preg_match( '#^[a-z0-9.-]+\.[a-z]{2,}(/|$)#i', $raw ) && ! preg_match( '#^https?://#i', $raw ) ) {
			return self::destination_error( __( 'Add https:// at the start to send visitors to another website.', '404-rescue' ) );
		}

		if ( preg_match( '#^https?://#i', $raw ) && ! Path::same_site( $raw, $home ) ) {
			$external = Path::external_url( $raw, true );
			if ( '' === $external ) {
				return self::destination_error( __( 'That destination address is not allowed. Use a normal https:// page.', '404-rescue' ) );
			}

			return array(
				'ok'               => true,
				'message'          => '',
				'destination_url'  => $external,
				'destination_path' => '',
				'is_external'      => true,
			);
		}

		$local = self::local_destination( $raw, $home, $home_path );
		if ( '' === $local['url'] ) {
			return self::destination_error( __( 'Enter a page on this site, such as /new-page, or a full https:// address.', '404-rescue' ) );
		}
		if ( Path::is_reserved( $local['path'] ) ) {
			return self::destination_error( __( 'Visitors cannot be sent to a WordPress system address.', '404-rescue' ) );
		}

		return array(
			'ok'               => true,
			'message'          => '',
			'destination_url'  => $local['url'],
			'destination_path' => $local['path'],
			'is_external'      => false,
		);
	}

	/**
	 * @return array{url: string, path: string}
	 */
	private static function local_destination( string $raw, string $home, string $home_path ): array {
		$path = Path::from_uri( $raw, $home_path );
		if ( '' === $path ) {
			return array(
				'url'  => '',
				'path' => '',
			);
		}

		if ( function_exists( 'home_url' ) ) {
			$url = (string) home_url( $path );
		} else {
			$url = '' !== $home ? self::url_for_path( $home, $path ) : $path;
		}
		if ( function_exists( 'esc_url_raw' ) ) {
			$escaped = esc_url_raw( $url );
			if ( is_string( $escaped ) && '' !== $escaped ) {
				$url = $escaped;
			}
		}

		return array(
			'url'  => $url,
			'path' => $path,
		);
	}

	/**
	 * Build an absolute URL on the home host for a stored path.
	 */
	private static function url_for_path( string $home, string $path ): string {
		$parts = wp_parse_url( $home );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return function_exists( 'home_url' ) ? (string) home_url( $path ) : $path;
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : 'https';
		$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$prefix = '';
		if ( isset( $parts['path'] ) && is_string( $parts['path'] ) && '/' !== $parts['path'] ) {
			$prefix = rtrim( $parts['path'], '/' );
		}

		return $scheme . '://' . $parts['host'] . $port . $prefix . ( '/' === $path ? '/' : $path );
	}

	private static function source_path( string $raw, string $home_path ): string {
		$raw = trim( $raw );
		if ( preg_match( '#^https?://#i', $raw ) ) {
			return Path::from_uri( $raw, $home_path );
		}

		return Path::from_uri( $raw, $home_path );
	}

	/**
	 * @param array<int, array<string, mixed>> $existing
	 * @return array<string, string>
	 */
	private static function loop_map( array $existing, int $ignore_id, string $home, string $home_path ): array {
		$map = array();
		foreach ( $existing as $row ) {
			$row_id = isset( $row['id'] ) ? (int) $row['id'] : 0;
			if ( $row_id === $ignore_id ) {
				continue;
			}
			if ( isset( $row['is_active'] ) && ! (int) $row['is_active'] ) {
				continue;
			}
			$source = isset( $row['source_path'] ) ? (string) $row['source_path'] : '';
			$dest   = isset( $row['destination_url'] ) ? (string) $row['destination_url'] : '';
			if ( '' === $source || '' === $dest ) {
				continue;
			}
			if ( preg_match( '#^https?://#i', $dest ) && '' !== $home && ! Path::same_site( $dest, $home ) ) {
				continue;
			}
			$path = Path::from_uri( $dest, $home_path );
			if ( '' !== $path ) {
				$map[ $source ] = $path;
			}
		}

		return $map;
	}

	/**
	 * @return array{ok: bool, message: string, destination_url: string, destination_path: string, is_external: bool}
	 */
	private static function destination_error( string $message ): array {
		return array(
			'ok'               => false,
			'message'          => $message,
			'destination_url'  => '',
			'destination_path' => '',
			'is_external'      => false,
		);
	}
}
