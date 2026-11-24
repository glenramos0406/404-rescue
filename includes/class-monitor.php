<?php
/**
 * Front-end 404 detection.
 *
 * @package Rescue404
 */

namespace Rescue404;

defined( 'ABSPATH' ) || exit;

/**
 * Logs a genuine public 404 after any matching redirect has had its chance.
 */
class Monitor {

	/**
	 * @var Redirect_Manager
	 */
	private $redirects;

	/**
	 * @var Database
	 */
	private $db;

	/**
	 * @var Suggestions
	 */
	private $suggestions;

	public function __construct( ?Redirect_Manager $redirects = null, ?Database $db = null, ?Suggestions $suggestions = null ) {
		$this->db          = $db ?? new Database();
		$this->redirects   = $redirects ?? new Redirect_Manager( $this->db );
		$this->suggestions = $suggestions ?? new Suggestions( $this->db );
	}

	/**
	 * Redirect early, then log only if the request is still a 404.
	 *
	 * Logging waits until late on template_redirect so another plugin can
	 * redirect and exit before a visit is stored as a missing page.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'send_redirect' ), 0 );
		add_action( 'template_redirect', array( $this, 'log_miss' ), 99 );
	}

	/**
	 * Send a saved redirect before the theme renders a 404 template.
	 */
	public function send_redirect(): void {
		if ( $this->is_background_request() ) {
			return;
		}

		$settings = Settings::get();
		if ( empty( $settings['redirects_enabled'] ) ) {
			return;
		}

		$method = $this->request_method();
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return;
		}

		$path = Path::from_uri( $this->request_uri(), Path::home_path() );
		if ( '' === $path ) {
			return;
		}

		$this->redirects->maybe_redirect( $path );
	}

	/**
	 * Record a genuine public 404.
	 */
	public function log_miss(): void {
		if ( $this->is_background_request() ) {
			return;
		}

		$settings = Settings::get();
		if ( 'GET' !== $this->request_method() || $this->is_prefetch() || empty( $settings['monitor_enabled'] ) || ! is_404() ) {
			return;
		}

		$path = Path::from_uri( $this->request_uri(), Path::home_path() );
		if ( '' === $path || '/' === $path ) {
			return;
		}
		if ( Path::is_static( $path ) || Path::is_reserved( $path ) || Path::is_excluded( $path, (string) $settings['excluded_patterns'] ) ) {
			return;
		}

		/**
		 * Let another plugin skip a path without turning monitoring off.
		 *
		 * @param bool   $should_log Whether to store this 404.
		 * @param string $path       Normalized path.
		 */
		if ( ! apply_filters( 'rescue404_should_log_404', true, $path ) ) {
			return;
		}

		$result = $this->db->record_hit( $path, $this->referrer(), $this->user_agent() );
		if ( 'created' === $result['result'] ) {
			$this->suggestions->attach( (int) $result['id'], $path, true );
		}
	}

	/**
	 * Admin, AJAX, REST, cron, and CLI requests are not visitor 404s.
	 */
	private function is_background_request(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return true;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}

		return false;
	}

	private function request_method(): string {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) ) {
			return 'GET';
		}

		return strtoupper( (string) $_SERVER['REQUEST_METHOD'] );
	}

	private function is_prefetch(): bool {
		$purpose = '';
		if ( isset( $_SERVER['HTTP_SEC_PURPOSE'] ) ) {
			$purpose = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_PURPOSE'] ) ) );
		} elseif ( isset( $_SERVER['HTTP_PURPOSE'] ) ) {
			$purpose = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_PURPOSE'] ) ) );
		}

		return 'prefetch' === $purpose;
	}

	private function request_uri(): string {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		return (string) wp_unslash( $_SERVER['REQUEST_URI'] );
	}

	/**
	 * Referring page without its query string, so tokens in the URL are not stored.
	 */
	private function referrer(): string {
		if ( empty( $_SERVER['HTTP_REFERER'] ) ) {
			return '';
		}

		$raw   = substr( (string) wp_unslash( $_SERVER['HTTP_REFERER'] ), 0, 1000 );
		$parts = wp_parse_url( $raw );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return '';
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return '';
		}

		$path = isset( $parts['path'] ) && is_string( $parts['path'] ) ? $parts['path'] : '/';
		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$ref  = $scheme . '://' . $parts['host'] . $port . $path;

		return substr( $ref, 0, 500 );
	}

	/**
	 * Short browser string. Useful for spotting bots, not for identifying a person.
	 */
	private function user_agent(): string {
		if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return '';
		}

		$agent = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );

		return substr( $agent, 0, 180 );
	}
}
