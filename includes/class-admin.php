<?php
/**
 * WordPress admin screens.
 *
 * @package Rescue404
 */

namespace Rescue404;

defined( 'ABSPATH' ) || exit;

/**
 * Menus, forms, and the plugin's admin pages.
 */
class Admin {

	/**
	 * @var Database
	 */
	private $db;

	/**
	 * @var Redirect_Manager
	 */
	private $redirects;

	/**
	 * @var Suggestions
	 */
	private $suggestions;

	public function __construct( ?Database $db = null ) {
		$this->db          = $db ?? new Database();
		$this->redirects   = new Redirect_Manager( $this->db );
		$this->suggestions = new Suggestions( $this->db );
	}

	/**
	 * Register menus, assets, and form handlers.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_init', array( Settings::class, 'register' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( RESCUE404_FILE ), array( $this, 'plugin_links' ) );

		add_action( 'admin_post_rescue404_save_redirect', array( $this, 'save_redirect' ) );
		add_action( 'admin_post_rescue404_delete_redirect', array( $this, 'delete_redirect' ) );
		add_action( 'admin_post_rescue404_toggle_redirect', array( $this, 'toggle_redirect' ) );
		add_action( 'admin_post_rescue404_bulk_errors', array( $this, 'bulk_errors' ) );
		add_action( 'admin_post_rescue404_error_action', array( $this, 'error_action' ) );
		add_action( 'admin_post_rescue404_export_errors', array( $this, 'export_errors' ) );
		add_action( 'admin_post_rescue404_export_redirects', array( $this, 'export_redirects' ) );
		add_action( 'admin_post_rescue404_import_redirects', array( $this, 'import_redirects' ) );
		add_action( 'admin_post_rescue404_sample_csv', array( $this, 'sample_csv' ) );

		add_action( 'wp_ajax_rescue404_toggle_redirect', array( $this, 'ajax_toggle_redirect' ) );
		add_action( 'wp_ajax_rescue404_suggest', array( $this, 'ajax_suggest' ) );
	}

	/**
	 * @param array<int, string> $links
	 * @return array<int, string>
	 */
	public function plugin_links( array $links ): array {
		$dashboard = '<a href="' . esc_url( self::page_url( 'rescue404' ) ) . '">' . esc_html__( 'Dashboard', '404-rescue' ) . '</a>';
		$settings  = '<a href="' . esc_url( self::page_url( 'rescue404-settings' ) ) . '">' . esc_html__( 'Settings', '404-rescue' ) . '</a>';
		array_unshift( $links, $settings );
		array_unshift( $links, $dashboard );

		return $links;
	}

	/**
	 * Top-level menu and the four screens.
	 */
	public function menu(): void {
		$cap = 'manage_options';
		add_menu_page(
			__( '404 Rescue', '404-rescue' ),
			__( '404 Rescue', '404-rescue' ),
			$cap,
			'rescue404',
			array( $this, 'render_dashboard' ),
			'dashicons-randomize',
			81
		);
		add_submenu_page( 'rescue404', __( 'Dashboard', '404-rescue' ), __( 'Dashboard', '404-rescue' ), $cap, 'rescue404', array( $this, 'render_dashboard' ) );
		add_submenu_page( 'rescue404', __( '404 Errors', '404-rescue' ), __( '404 Errors', '404-rescue' ), $cap, 'rescue404-errors', array( $this, 'render_errors' ) );
		add_submenu_page( 'rescue404', __( 'Redirects', '404-rescue' ), __( 'Redirects', '404-rescue' ), $cap, 'rescue404-redirects', array( $this, 'render_redirects' ) );
		add_submenu_page( 'rescue404', __( 'Settings', '404-rescue' ), __( 'Settings', '404-rescue' ), $cap, 'rescue404-settings', array( $this, 'render_settings' ) );
	}

	/**
	 * Load CSS and JavaScript only on 404 Rescue screens.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function assets( string $hook ): void {
		if ( ! str_contains( $hook, 'rescue404' ) ) {
			return;
		}

		wp_enqueue_style( 'rescue404-admin', RESCUE404_URL . 'admin/css/admin.css', array(), RESCUE404_VERSION );
		wp_enqueue_script( 'rescue404-admin', RESCUE404_URL . 'admin/js/admin.js', array(), RESCUE404_VERSION, true );
		wp_localize_script(
			'rescue404-admin',
			'rescue404Admin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'rescue404_ajax' ),
				'working' => __( 'Working…', '404-rescue' ),
				'failed'  => __( 'Something went wrong. Reload the page and try again.', '404-rescue' ),
				'empty'   => __( 'No close page was found. Type the destination yourself.', '404-rescue' ),
			)
		);
	}

	/**
	 * Dashboard.
	 */
	public function render_dashboard(): void {
		$this->require_admin();
		$counts = $this->db->dashboard_counts();
		$days   = $this->db->last_seven_days();
		$max    = 0;
		foreach ( $days as $day ) {
			$max = max( $max, (int) $day['count'] );
		}
		foreach ( $days as $index => $day ) {
			$days[ $index ]['height'] = ( $max > 0 && $day['count'] > 0 ) ? max( 8, (int) round( ( $day['count'] / $max ) * 100 ) ) : 0;
		}

		$this->view(
			'dashboard',
			array(
				'counts' => $counts,
				'recent' => $this->db->recent_errors( 8 ),
				'days'   => $days,
				'max'    => $max,
			)
		);
	}

	/**
	 * 404 list.
	 */
	public function render_errors(): void {
		$this->require_admin();
		$status  = $this->query_text( 'status' );
		$allowed = array( 'all', 'unresolved', 'resolved', 'ignored', 'high' );
		if ( ! in_array( $status, $allowed, true ) ) {
			$status = 'all';
		}
		$orderby = $this->query_text( 'orderby' );
		if ( ! in_array( $orderby, array( 'hits', 'recent', 'oldest' ), true ) ) {
			$orderby = 'recent';
		}
		$search = substr( $this->query_text( 's' ), 0, 100 );
		$paged  = max( 1, absint( $this->query_text( 'paged' ) ) );
		$result = $this->db->query_errors(
			array(
				'status'   => $status,
				'orderby'  => $orderby,
				'search'   => $search,
				'paged'    => $paged,
				'per_page' => 20,
			)
		);
		$detail = $this->db->get_error( absint( $this->query_text( 'error' ) ) );

		$this->view(
			'errors',
			array(
				'items'   => $result['items'],
				'total'   => $result['total'],
				'paged'   => $paged,
				'status'  => $status,
				'orderby' => $orderby,
				'search'  => $search,
				'detail'  => $detail,
			)
		);
	}

	/**
	 * Redirect list and form.
	 */
	public function render_redirects(): void {
		$this->require_admin();
		$state = $this->query_text( 'state' );
		if ( ! in_array( $state, array( 'all', 'active', 'inactive' ), true ) ) {
			$state = 'all';
		}
		$search = substr( $this->query_text( 's' ), 0, 100 );
		$paged  = max( 1, absint( $this->query_text( 'paged' ) ) );
		$result = $this->db->query_redirects(
			array(
				'state'  => $state,
				'search' => $search,
				'paged'  => $paged,
			)
		);

		$edit_id = absint( $this->query_text( 'edit' ) );
		$edit    = $edit_id > 0 ? $this->db->get_redirect( $edit_id ) : null;
		$form    = get_transient( 'rescue404_form_' . get_current_user_id() );
		if ( is_array( $form ) ) {
			delete_transient( 'rescue404_form_' . get_current_user_id() );
		} else {
			$form = array();
		}

		$prefill = array(
			'source'      => '',
			'destination' => '',
			'type'        => 301,
			'is_active'   => 1,
			'edit_id'     => 0,
		);
		if ( $form ) {
			$prefill = array_merge( $prefill, $form );
		} elseif ( $edit ) {
			$prefill = array(
				'source'      => (string) $edit['source_path'],
				'destination' => (string) $edit['destination_url'],
				'type'        => (int) $edit['redirect_type'],
				'is_active'   => (int) $edit['is_active'],
				'edit_id'     => (int) $edit['id'],
			);
		} else {
			$prefill['source']      = substr( $this->query_text( 'source' ), 0, Path::MAX_PATH );
			$prefill['destination'] = substr( $this->query_text( 'destination' ), 0, 500 );
		}

		$matches = array();
		if ( '' !== $prefill['source'] ) {
			$matches = $this->suggestions->find( (string) $prefill['source'], 3 );
		}

		$this->view(
			'redirects',
			array(
				'items'   => $result['items'],
				'total'   => $result['total'],
				'paged'   => $paged,
				'state'   => $state,
				'search'  => $search,
				'prefill' => $prefill,
				'matches' => $matches,
			)
		);
	}

	/**
	 * Settings.
	 */
	public function render_settings(): void {
		$this->require_admin();
		$this->view(
			'settings',
			array(
				'settings' => Settings::get(),
			)
		);
	}

	/**
	 * Create or update a redirect.
	 */
	public function save_redirect(): void {
		$this->authorize( 'rescue404_save_redirect' );
		if ( isset( $_POST['find_page'] ) ) {
			$this->back(
				'rescue404-redirects',
				array(
					'source'      => substr( $this->post_raw( 'source' ), 0, Path::MAX_PATH ),
					'destination' => substr( $this->post_raw( 'destination' ), 0, 500 ),
				)
			);
		}
		$input = array(
			'source'      => $this->post_raw( 'source' ),
			'destination' => $this->post_raw( 'destination' ),
			'type'        => isset( $_POST['redirect_type'] ) ? (int) wp_unslash( $_POST['redirect_type'] ) : 301,
			'is_active'   => isset( $_POST['is_active'] ) ? 1 : 0,
		);
		$edit_id = absint( $this->post_raw( 'edit_id' ) );
		$result  = $edit_id > 0 ? $this->redirects->update( $edit_id, $input ) : $this->redirects->create( $input );

		if ( ! $result['ok'] ) {
			set_transient(
				'rescue404_form_' . get_current_user_id(),
				array(
					'source'      => substr( $input['source'], 0, 500 ),
					'destination' => substr( $input['destination'], 0, 500 ),
					'type'        => (int) $input['type'],
					'is_active'   => (int) $input['is_active'],
					'edit_id'     => $edit_id,
				),
				MINUTE_IN_SECONDS
			);
			self::flash( 'error', $result['message'] );
			$this->back( 'rescue404-redirects', $edit_id > 0 ? array( 'edit' => $edit_id ) : array() );
		}

		self::flash( 'success', $result['message'] );
		$this->back( 'rescue404-redirects' );
	}

	/**
	 * Delete one redirect.
	 */
	public function delete_redirect(): void {
		$this->authorize( 'rescue404_delete_redirect' );
		$result = $this->redirects->delete( absint( $this->post_raw( 'redirect_id' ) ) );
		self::flash( $result['ok'] ? 'success' : 'error', $result['message'] );
		$this->back( 'rescue404-redirects' );
	}

	/**
	 * Turn a redirect on or off.
	 */
	public function toggle_redirect(): void {
		$this->authorize( 'rescue404_toggle_redirect' );
		$active = '1' === $this->post_raw( 'active' );
		$result = $this->redirects->set_active( absint( $this->post_raw( 'redirect_id' ) ), $active );
		self::flash( $result['ok'] ? 'success' : 'error', $result['message'] );
		$this->back( 'rescue404-redirects' );
	}

	/**
	 * Bulk delete, ignore, or export.
	 */
	public function bulk_errors(): void {
		$this->authorize( 'rescue404_bulk_errors' );
		$action = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
		$ids    = $this->posted_ids( 'error_ids' );

		if ( 'export' === $action ) {
			if ( ! $ids ) {
				self::flash( 'error', __( 'Select at least one broken URL to export.', '404-rescue' ) );
				$this->back( 'rescue404-errors' );
			}
			( new Csv( $this->db, $this->redirects ) )->stream_error_ids( $ids );
			exit;
		}

		if ( ! $ids ) {
			self::flash( 'error', __( 'Select at least one broken URL first.', '404-rescue' ) );
			$this->back( 'rescue404-errors' );
		}

		if ( 'delete' === $action ) {
			$count = $this->db->delete_errors( $ids );
			self::flash(
				'success',
				sprintf(
					/* translators: %d: number of rows */
					_n( 'Deleted %d broken URL.', 'Deleted %d broken URLs.', $count, '404-rescue' ),
					$count
				)
			);
		} elseif ( 'ignore' === $action ) {
			$count = $this->db->set_error_status( $ids, 'ignored' );
			self::flash(
				'success',
				sprintf(
					/* translators: %d: number of rows */
					_n( 'Ignored %d broken URL.', 'Ignored %d broken URLs.', $count, '404-rescue' ),
					$count
				)
			);
		} else {
			self::flash( 'error', __( 'Choose an action first.', '404-rescue' ) );
		}

		$this->back( 'rescue404-errors' );
	}

	/**
	 * One-row actions from the errors table.
	 */
	public function error_action(): void {
		$this->authorize( 'rescue404_error_action' );
		$id     = absint( $this->post_raw( 'error_id' ) );
		$action = isset( $_POST['error_action'] ) ? sanitize_key( wp_unslash( $_POST['error_action'] ) ) : '';
		$row    = $this->db->get_error( $id );
		if ( ! $row ) {
			self::flash( 'error', __( 'That broken URL could not be found.', '404-rescue' ) );
			$this->back( 'rescue404-errors' );
		}

		if ( 'delete' === $action ) {
			$this->db->delete_errors( array( $id ) );
			self::flash( 'success', __( 'Broken URL deleted.', '404-rescue' ) );
			$this->back( 'rescue404-errors' );
		}
		if ( 'ignore' === $action ) {
			$this->db->set_error_status( array( $id ), 'ignored' );
			self::flash( 'success', __( 'Broken URL ignored. It stays in the log and will not be listed as needing attention.', '404-rescue' ) );
		} elseif ( 'restore' === $action ) {
			$this->db->set_error_status( array( $id ), 'unresolved' );
			self::flash( 'success', __( 'Broken URL restored to the list that needs attention.', '404-rescue' ) );
		} elseif ( 'refresh' === $action ) {
			$this->suggestions->attach( $id, (string) $row['request_path'], false );
			self::flash( 'success', __( 'Suggestion updated. Check that the page is the right one before you redirect.', '404-rescue' ) );
		} else {
			self::flash( 'error', __( 'That action is not available.', '404-rescue' ) );
		}

		$this->back( 'rescue404-errors', array( 'error' => $id ) );
	}

	/**
	 * Export errors. A POST with ids exports the selection. Otherwise the current filter is used.
	 */
	public function export_errors(): void {
		$this->authorize( 'rescue404_export_errors' );
		$csv = new Csv( $this->db, $this->redirects );
		$ids = $this->posted_ids( 'error_ids' );
		if ( $ids ) {
			$csv->stream_error_ids( $ids );
			exit;
		}

		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all';
		if ( ! in_array( $status, array( 'all', 'unresolved', 'resolved', 'ignored', 'high' ), true ) ) {
			$status = 'all';
		}
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$csv->stream_errors(
			array(
				'status' => $status,
				'search' => substr( $search, 0, 100 ),
			)
		);
		exit;
	}

	/**
	 * Export redirect rules.
	 */
	public function export_redirects(): void {
		$this->authorize( 'rescue404_export_redirects' );
		( new Csv( $this->db, $this->redirects ) )->stream_redirects();
		exit;
	}

	/**
	 * Import redirect rules from CSV.
	 */
	public function import_redirects(): void {
		$this->authorize( 'rescue404_import_redirects' );
		if ( ! isset( $_FILES['rescue404_csv'] ) || ! is_array( $_FILES['rescue404_csv'] ) ) {
			self::flash( 'error', __( 'Choose a CSV file to import.', '404-rescue' ) );
			$this->back( 'rescue404-redirects' );
		}

		$file = $_FILES['rescue404_csv'];
		$tmp  = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		$name = isset( $file['name'] ) ? (string) $file['name'] : '';
		$size = isset( $file['size'] ) ? (int) $file['size'] : 0;

		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			self::flash( 'error', __( 'Choose a CSV file to import.', '404-rescue' ) );
			$this->back( 'rescue404-redirects' );
		}
		if ( $size > 2 * MB_IN_BYTES ) {
			self::flash( 'error', __( 'That file is larger than 2 MB. Split it and try again.', '404-rescue' ) );
			$this->back( 'rescue404-redirects' );
		}
		$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( 'csv' !== $ext ) {
			self::flash( 'error', __( 'Use a file that ends in .csv.', '404-rescue' ) );
			$this->back( 'rescue404-redirects' );
		}

		$summary = ( new Csv( $this->db, $this->redirects ) )->import_redirects( $tmp );
		set_transient( 'rescue404_import_' . get_current_user_id(), $summary, MINUTE_IN_SECONDS );
		$this->back( 'rescue404-redirects' );
	}

	/**
	 * Download a sample redirect CSV.
	 */
	public function sample_csv(): void {
		$this->authorize( 'rescue404_sample_csv' );
		( new Csv( $this->db, $this->redirects ) )->stream_sample();
		exit;
	}

	/**
	 * Turn a redirect on or off without reloading the page.
	 */
	public function ajax_toggle_redirect(): void {
		$this->authorize_ajax();
		$id     = isset( $_POST['redirect_id'] ) ? absint( wp_unslash( $_POST['redirect_id'] ) ) : 0;
		$active = isset( $_POST['active'] ) && '1' === (string) wp_unslash( $_POST['active'] );
		$result = $this->redirects->set_active( $id, $active );
		if ( ! $result['ok'] ) {
			wp_send_json_error( array( 'message' => $result['message'] ), 400 );
		}

		wp_send_json_success(
			array(
				'active' => $active ? 1 : 0,
				'status' => $active ? __( 'On', '404-rescue' ) : __( 'Off', '404-rescue' ),
				'label'  => $active ? __( 'Turn off', '404-rescue' ) : __( 'Turn on', '404-rescue' ),
				'message'=> $result['message'],
			)
		);
	}

	/**
	 * Suggest pages for an address typed into the redirect form.
	 */
	public function ajax_suggest(): void {
		$this->authorize_ajax();
		$raw  = isset( $_POST['source'] ) ? (string) wp_unslash( $_POST['source'] ) : '';
		$path = Path::from_uri( $raw, Path::home_path() );
		if ( '' === $path || '/' === $path || Path::is_reserved( $path ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Enter a page address on this site, such as /old-page.', '404-rescue' ) ),
				400
			);
		}

		wp_send_json_success( array( 'items' => $this->suggestions->find( $path, 3 ) ) );
	}

	/**
	 * Store a one-time notice for the current user.
	 */
	public static function flash( string $type, string $message ): void {
		set_transient(
			'rescue404_flash_' . get_current_user_id(),
			array(
				'type'    => 'error' === $type ? 'error' : 'success',
				'message' => wp_strip_all_tags( $message ),
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Print saved notices at the top of a screen.
	 */
	public static function render_notices(): void {
		$user  = get_current_user_id();
		$flash = get_transient( 'rescue404_flash_' . $user );
		if ( is_array( $flash ) && ! empty( $flash['message'] ) ) {
			delete_transient( 'rescue404_flash_' . $user );
			$class = 'error' === ( $flash['type'] ?? '' ) ? 'is-error' : 'is-success';
			$role  = 'is-error' === $class ? 'alert' : 'status';
			echo '<div class="rescue404-banner ' . esc_attr( $class ) . '" role="' . esc_attr( $role ) . '">';
			echo esc_html( (string) $flash['message'] );
			echo '</div>';
		}

		$import = get_transient( 'rescue404_import_' . $user );
		if ( is_array( $import ) ) {
			delete_transient( 'rescue404_import_' . $user );
			echo '<div class="rescue404-banner is-success" role="status">';
			echo esc_html(
				sprintf(
					/* translators: 1: imported count, 2: skipped count, 3: failed count */
					__( 'Import finished. %1$d added, %2$d skipped, %3$d failed.', '404-rescue' ),
					(int) ( $import['imported'] ?? 0 ),
					(int) ( $import['skipped'] ?? 0 ),
					(int) ( $import['failed'] ?? 0 )
				)
			);
			if ( ! empty( $import['errors'] ) && is_array( $import['errors'] ) ) {
				echo '<ul class="rescue404-import-errors">';
				foreach ( $import['errors'] as $error ) {
					echo '<li>' . esc_html( (string) $error ) . '</li>';
				}
				echo '</ul>';
			}
			echo '</div>';
		}

		if ( isset( $_GET['settings-updated'] ) ) {
			echo '<div class="rescue404-banner is-success" role="status">' . esc_html__( 'Settings saved.', '404-rescue' ) . '</div>';
			settings_errors( Settings::OPTION );
		}
	}

	/**
	 * Format a MySQL datetime in the site's date settings.
	 */
	public static function date_text( string $mysql ): string {
		if ( '' === $mysql || '0000-00-00 00:00:00' === $mysql ) {
			return '—';
		}
		$format = trim( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ) );

		return mysql2date( $format, $mysql );
	}

	/**
	 * Human status for a logged 404.
	 */
	public static function status_label( string $status ): string {
		switch ( $status ) {
			case 'resolved':
				return __( 'Redirect added', '404-rescue' );
			case 'ignored':
				return __( 'Ignored', '404-rescue' );
			default:
				return __( 'Needs attention', '404-rescue' );
		}
	}

	/**
	 * Admin URL for one of our screens.
	 *
	 * @param array<string, mixed> $args
	 */
	public static function page_url( string $page, array $args = array() ): string {
		$allowed = array( 'rescue404', 'rescue404-errors', 'rescue404-redirects', 'rescue404-settings' );
		if ( ! in_array( $page, $allowed, true ) ) {
			$page = 'rescue404';
		}
		$args['page'] = $page;

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * onclick value that asks the browser to confirm. Escape it with esc_attr().
	 */
	public static function confirm_js( string $message ): string {
		return 'return confirm(' . wp_json_encode( $message ) . ');';
	}

	/**
	 * @param array<string, mixed> $args
	 */
	private function view( string $name, array $args ): void {
		$file = RESCUE404_DIR . 'admin/views/' . $name . '.php';
		if ( ! is_readable( $file ) ) {
			return;
		}
		require $file;
	}

	private function require_admin(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to do that.', '404-rescue' ),
				esc_html__( 'Forbidden', '404-rescue' ),
				array( 'response' => 403 )
			);
		}
	}

	private function authorize( string $action ): void {
		$this->require_admin();
		check_admin_referer( $action );
	}

	private function authorize_ajax(): void {
		check_ajax_referer( 'rescue404_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to do that.', '404-rescue' ) ),
				403
			);
		}
	}

	/**
	 * @param array<string, int|string> $args
	 */
	private function back( string $page, array $args = array() ): void {
		wp_safe_redirect( self::page_url( $page, $args ) );
		exit;
	}

	private function post_raw( string $key ): string {
		if ( ! isset( $_POST[ $key ] ) ) {
			return '';
		}
		$value = wp_unslash( $_POST[ $key ] );
		if ( ! is_string( $value ) ) {
			return '';
		}

		return trim( str_replace( "\0", '', $value ) );
	}

	private function query_text( string $key ): string {
		if ( ! isset( $_GET[ $key ] ) ) {
			return '';
		}
		$value = wp_unslash( $_GET[ $key ] );
		if ( ! is_string( $value ) ) {
			return '';
		}

		return sanitize_text_field( $value );
	}

	/**
	 * @return array<int, int>
	 */
	private function posted_ids( string $key ): array {
		if ( ! isset( $_POST[ $key ] ) || ! is_array( $_POST[ $key ] ) ) {
			return array();
		}
		$ids = array();
		foreach ( wp_unslash( $_POST[ $key ] ) as $id ) {
			$id = absint( $id );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}
}
