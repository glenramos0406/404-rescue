<?php
/**
 * Custom tables and the queries that read them.
 *
 * @package Rescue404
 */

namespace Rescue404;

defined( 'ABSPATH' ) || exit;

// WHERE, ORDER BY, and IN lists are built from fixed fragments. Their values are bound
// by prepare(). Table names in those statements use the %i identifier placeholder.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

/**
 * Owns the plugin tables. Callers pass already-normalized values.
 */
class Database {

	public const HIGH_TRAFFIC = 5;

	/**
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * @param \wpdb|null $wpdb Optional database handle, used by tests.
	 */
	public function __construct( $wpdb = null ) {
		if ( null !== $wpdb ) {
			$this->wpdb = $wpdb;
			return;
		}
		global $wpdb;
		$this->wpdb = $wpdb;
	}

	/**
	 * Create or update tables. Safe to run more than once.
	 */
	public function install(): void {
		$wpdb = $this->wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$errors  = $this->table( 'errors' );
		$rules   = $this->table( 'redirects' );
		$daily   = $this->table( 'daily' );

		$sql = "CREATE TABLE {$errors} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			request_path varchar(191) NOT NULL DEFAULT '',
			hit_count bigint(20) unsigned NOT NULL DEFAULT 1,
			first_detected datetime NOT NULL,
			last_detected datetime NOT NULL,
			referrer varchar(500) NOT NULL DEFAULT '',
			user_agent varchar(255) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'unresolved',
			suggested_destination varchar(500) NOT NULL DEFAULT '',
			suggestion_confidence varchar(20) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY request_path (request_path),
			KEY status (status),
			KEY last_detected (last_detected),
			KEY hit_count (hit_count)
		) {$charset};";
		dbDelta( $sql );

		$sql = "CREATE TABLE {$rules} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_path varchar(191) NOT NULL DEFAULT '',
			destination_url varchar(500) NOT NULL DEFAULT '',
			redirect_type smallint(3) unsigned NOT NULL DEFAULT 301,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_path (source_path),
			KEY is_active (is_active)
		) {$charset};";
		dbDelta( $sql );

		$sql = "CREATE TABLE {$daily} (
			stat_date date NOT NULL,
			hit_count bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (stat_date)
		) {$charset};";
		dbDelta( $sql );

		update_option( 'rescue404_db_version', RESCUE404_DB_VERSION, true );
	}

	/**
	 * Run dbDelta after an update when the stored schema version differs.
	 */
	public function maybe_upgrade(): void {
		$wpdb = $this->wpdb;
		$version = get_option( 'rescue404_db_version', '' );
		if ( RESCUE404_DB_VERSION !== $version ) {
			$this->install();
		}
	}

	/**
	 * Record one visit to a missing path.
	 *
	 * @return array{result: string, id: int}
	 */
	public function record_hit( string $path, string $referrer, string $user_agent ): array {
		$wpdb = $this->wpdb;
		if ( '' === $path || '/' === $path ) {
			return array(
				'result' => 'skipped',
				'id'     => 0,
			);
		}

		$table = $this->table( 'errors' );
		$now   = current_time( 'mysql' );
		$id    = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE request_path = %s LIMIT 1", $path )
		);

		if ( $id > 0 ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table}
					SET hit_count = hit_count + 1,
						last_detected = %s,
						referrer = IF(%s = '', referrer, %s),
						user_agent = IF(%s = '', user_agent, %s)
					WHERE id = %d",
					$now,
					$referrer,
					$referrer,
					$user_agent,
					$user_agent,
					$id
				)
			);
			$this->increment_day( $now );

			return array(
				'result' => 'updated',
				'id'     => $id,
			);
		}

		$max = (int) Settings::get()['max_errors'];
		if ( $max > 0 && $this->error_count() >= $max ) {
			return array(
				'result' => 'skipped',
				'id'     => 0,
			);
		}

		$inserted = $wpdb->insert(
			$table,
			array(
				'request_path'            => $path,
				'hit_count'               => 1,
				'first_detected'          => $now,
				'last_detected'           => $now,
				'referrer'                => $referrer,
				'user_agent'              => $user_agent,
				'status'                  => 'unresolved',
				'suggested_destination'   => '',
				'suggestion_confidence'   => '',
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET hit_count = hit_count + 1, last_detected = %s WHERE request_path = %s",
					$now,
					$path
				)
			);
			if ( $updated ) {
				$this->increment_day( $now );
				$id = (int) $wpdb->get_var(
					$wpdb->prepare( "SELECT id FROM {$table} WHERE request_path = %s LIMIT 1", $path )
				);

				return array(
					'result' => 'updated',
					'id'     => $id,
				);
			}

			return array(
				'result' => 'skipped',
				'id'     => 0,
			);
		}

		$this->bump_error_count( 1 );
		$this->increment_day( $now );

		return array(
			'result' => 'created',
			'id'     => (int) $wpdb->insert_id,
		);
	}

	/**
	 * @return array<string, int>
	 */
	public function dashboard_counts(): array {
		$wpdb = $this->wpdb;
		$errors    = $this->table( 'errors' );
		$redirects = $this->table( 'redirects' );
		$row       = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE(SUM(hit_count), 0) AS hits,
					COALESCE(SUM(CASE WHEN status = 'unresolved' THEN 1 ELSE 0 END), 0) AS unresolved,
					COALESCE(SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END), 0) AS resolved,
					COUNT(*) AS rows_total
				FROM %i",
				$errors
			),
			ARRAY_A
		);
		$active = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE is_active = 1', $redirects ) );

		return array(
			'hits'       => isset( $row['hits'] ) ? (int) $row['hits'] : 0,
			'unresolved' => isset( $row['unresolved'] ) ? (int) $row['unresolved'] : 0,
			'resolved'   => isset( $row['resolved'] ) ? (int) $row['resolved'] : 0,
			'rows'       => isset( $row['rows_total'] ) ? (int) $row['rows_total'] : 0,
			'active'     => $active,
		);
	}

	/**
	 * Newest broken URLs for the dashboard table.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function recent_errors( int $limit = 8 ): array {
		$wpdb = $this->wpdb;
		$limit = max( 1, min( 20, $limit ) );
		$table = $this->table( 'errors' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, request_path, hit_count, first_detected, last_detected, suggested_destination, suggestion_confidence, status
				FROM {$table}
				ORDER BY last_detected DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Hit totals for the last seven site-local days, including quiet days.
	 *
	 * @return array<int, array{date: string, label: string, count: int}>
	 */
	public function last_seven_days(): array {
		$wpdb = $this->wpdb;
		$table = $this->table( 'daily' );
		$zone  = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		$today = ( new \DateTimeImmutable( 'now', $zone ) )->setTime( 0, 0 );
		$start = $today->modify( '-6 days' )->format( 'Y-m-d' );
		$end   = $today->format( 'Y-m-d' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT stat_date, hit_count FROM {$table} WHERE stat_date >= %s AND stat_date <= %s",
				$start,
				$end
			),
			ARRAY_A
		);

		$counts = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$counts[ (string) $row['stat_date'] ] = (int) $row['hit_count'];
			}
		}

		$days = array();
		for ( $i = 6; $i >= 0; $i-- ) {
			$day    = $today->modify( '-' . $i . ' days' );
			$key    = $day->format( 'Y-m-d' );
			$days[] = array(
				'date'  => $key,
				'label' => function_exists( 'wp_date' ) ? wp_date( 'D', $day->getTimestamp() ) : $day->format( 'D' ),
				'count' => $counts[ $key ] ?? 0,
			);
		}

		return $days;
	}

	/**
	 * @param array<string, mixed> $args Search, status, order, page.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function query_errors( array $args ): array {
		$wpdb = $this->wpdb;
		$table  = $this->table( 'errors' );
		$where  = $this->error_where( $args );
		$order  = $this->error_order( isset( $args['orderby'] ) ? (string) $args['orderby'] : 'recent' );
		$page   = max( 1, (int) ( $args['paged'] ?? 1 ) );
		$per    = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$offset = ( $page - 1 ) * $per;

		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where['sql']}", $where['params'] )
		);

		$params   = $where['params'];
		$params[] = $per;
		$params[] = $offset;
		$items    = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, request_path, hit_count, first_detected, last_detected, referrer, user_agent, status, suggested_destination, suggestion_confidence
				FROM {$table}
				WHERE {$where['sql']}
				ORDER BY {$order}
				LIMIT %d OFFSET %d",
				$params
			),
			ARRAY_A
		);

		return array(
			'items' => is_array( $items ) ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_error( int $id ): ?array {
		$wpdb = $this->wpdb;
		if ( $id < 1 ) {
			return null;
		}
		$table = $this->table( 'errors' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, request_path, hit_count, first_detected, last_detected, referrer, user_agent, status, suggested_destination, suggestion_confidence
				FROM {$table} WHERE id = %d",
				$id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param array<int, int> $ids
	 */
	public function delete_errors( array $ids ): int {
		$wpdb = $this->wpdb;
		$ids = $this->id_list( $ids );
		if ( ! $ids ) {
			return 0;
		}
		$table        = $this->table( 'errors' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids )
		);
		$deleted = (int) $wpdb->rows_affected;
		if ( $deleted > 0 ) {
			$this->bump_error_count( 0 - $deleted );
		}

		return $deleted;
	}

	/**
	 * @param array<int, int> $ids
	 */
	public function set_error_status( array $ids, string $status ): int {
		$wpdb = $this->wpdb;
		if ( ! in_array( $status, array( 'unresolved', 'resolved', 'ignored' ), true ) ) {
			return 0;
		}
		$ids = $this->id_list( $ids );
		if ( ! $ids ) {
			return 0;
		}
		$table        = $this->table( 'errors' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$params       = array_merge( array( $status ), $ids );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s WHERE id IN ({$placeholders})",
				$params
			)
		);

		return (int) $wpdb->rows_affected;
	}

	public function save_suggestion( int $id, string $url, string $confidence ): void {
		$wpdb = $this->wpdb;
		if ( ! in_array( $confidence, array( 'strong', 'possible', 'none', '' ), true ) ) {
			$confidence = 'none';
		}
		$wpdb->update(
			$this->table( 'errors' ),
			array(
				'suggested_destination' => substr( $url, 0, 500 ),
				'suggestion_confidence' => $confidence,
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	public function mark_resolved( string $path ): void {
		$wpdb = $this->wpdb;
		if ( '' === $path ) {
			return;
		}
		$wpdb->update(
			$this->table( 'errors' ),
			array( 'status' => 'resolved' ),
			array( 'request_path' => $path ),
			array( '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Only a resolved row goes back to needing attention. Ignored stays ignored.
	 */
	public function mark_unresolved( string $path ): void {
		$wpdb = $this->wpdb;
		if ( '' === $path ) {
			return;
		}
		$table = $this->table( 'errors' );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'unresolved' WHERE request_path = %s AND status = 'resolved'",
				$path
			)
		);
	}

	/**
	 * @param array<string, mixed> $args Same filters as the errors screen.
	 * @return array<int, array<string, mixed>>
	 */
	public function errors_after( int $last_id, int $limit, array $args ): array {
		$wpdb = $this->wpdb;
		$table          = $this->table( 'errors' );
		$where          = $this->error_where( $args );
		$where['sql']  .= ' AND id > %d';
		$where['params'][] = $last_id;
		$where['params'][] = max( 1, min( 500, $limit ) );
		$rows           = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, request_path, hit_count, first_detected, last_detected, referrer, status, suggested_destination, suggestion_confidence
				FROM {$table}
				WHERE {$where['sql']}
				ORDER BY id ASC
				LIMIT %d",
				$where['params']
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param array<int, int> $ids
	 * @return array<int, array<string, mixed>>
	 */
	public function errors_by_ids( array $ids ): array {
		$wpdb = $this->wpdb;
		$ids = $this->id_list( $ids );
		if ( ! $ids ) {
			return array();
		}
		$table        = $this->table( 'errors' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, request_path, hit_count, first_detected, last_detected, referrer, status, suggested_destination, suggestion_confidence
				FROM {$table} WHERE id IN ({$placeholders}) ORDER BY id ASC",
				$ids
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	public function delete_expired( string $cutoff, int $limit ): int {
		$wpdb = $this->wpdb;
		$table = $this->table( 'errors' );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE last_detected < %s ORDER BY last_detected ASC LIMIT %d",
				$cutoff,
				max( 1, min( 500, $limit ) )
			)
		);
		$deleted = (int) $wpdb->rows_affected;
		if ( $deleted > 0 ) {
			$this->bump_error_count( 0 - $deleted );
		}

		return $deleted;
	}

	public function delete_oldest( int $limit ): int {
		$wpdb = $this->wpdb;
		$table = $this->table( 'errors' );
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} ORDER BY last_detected ASC, id ASC LIMIT %d",
				max( 1, min( 500, $limit ) )
			)
		);
		if ( ! is_array( $ids ) || ! $ids ) {
			return 0;
		}

		return $this->delete_errors( array_map( 'intval', $ids ) );
	}

	public function prune_daily( string $cutoff_date ): void {
		$wpdb = $this->wpdb;
		$table = $this->table( 'daily' );
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE stat_date < %s", $cutoff_date )
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function active_redirects(): array {
		$wpdb = $this->wpdb;
		$table = $this->table( 'redirects' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT source_path, destination_url, redirect_type FROM %i WHERE is_active = 1',
				$table
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function active_redirect_by_path( string $path ): ?array {
		$wpdb = $this->wpdb;
		$table = $this->table( 'redirects' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT source_path, destination_url, redirect_type FROM {$table} WHERE source_path = %s AND is_active = 1 LIMIT 1",
				$path
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Every rule, used when checking a new rule for a loop.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all_redirects(): array {
		$wpdb = $this->wpdb;
		$table = $this->table( 'redirects' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, source_path, destination_url, redirect_type, is_active FROM %i',
				$table
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_redirect( int $id ): ?array {
		$wpdb = $this->wpdb;
		if ( $id < 1 ) {
			return null;
		}
		$table = $this->table( 'redirects' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, source_path, destination_url, redirect_type, is_active, created_at, updated_at FROM {$table} WHERE id = %d",
				$id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function redirect_by_source( string $path ): ?array {
		$wpdb = $this->wpdb;
		$table = $this->table( 'redirects' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, source_path, destination_url, redirect_type, is_active FROM {$table} WHERE source_path = %s LIMIT 1",
				$path
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function query_redirects( array $args ): array {
		$wpdb = $this->wpdb;
		$table  = $this->table( 'redirects' );
		$where  = array( '1=1' );
		$params = array();
		$search = isset( $args['search'] ) ? (string) $args['search'] : '';
		$state  = isset( $args['state'] ) ? (string) $args['state'] : 'all';

		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(source_path LIKE %s OR destination_url LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}
		if ( 'active' === $state ) {
			$where[] = 'is_active = 1';
		} elseif ( 'inactive' === $state ) {
			$where[] = 'is_active = 0';
		}

		$sql  = implode( ' AND ', $where );
		$page = max( 1, (int) ( $args['paged'] ?? 1 ) );
		$per  = 20;
		$off  = ( $page - 1 ) * $per;

		if ( $params ) {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i WHERE {$sql}",
					array_merge( array( $table ), $params )
				)
			);
		} else {
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$sql}", $table ) );
		}

		$list_params   = $params;
		$list_params[] = $per;
		$list_params[] = $off;
		$items         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, source_path, destination_url, redirect_type, is_active, created_at, updated_at
				FROM {$table}
				WHERE {$sql}
				ORDER BY updated_at DESC, id DESC
				LIMIT %d OFFSET %d",
				$list_params
			),
			ARRAY_A
		);

		return array(
			'items' => is_array( $items ) ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public function insert_redirect( array $row ): int {
		$wpdb = $this->wpdb;
		$ok = $wpdb->insert(
			$this->table( 'redirects' ),
			array(
				'source_path'     => $row['source_path'],
				'destination_url' => $row['destination_url'],
				'redirect_type'   => (int) $row['redirect_type'],
				'is_active'       => empty( $row['is_active'] ) ? 0 : 1,
				'created_at'      => $row['created_at'],
				'updated_at'      => $row['updated_at'],
			),
			array( '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public function update_redirect( int $id, array $row ): bool {
		$wpdb = $this->wpdb;
		$result = $wpdb->update(
			$this->table( 'redirects' ),
			array(
				'source_path'     => $row['source_path'],
				'destination_url' => $row['destination_url'],
				'redirect_type'   => (int) $row['redirect_type'],
				'is_active'       => empty( $row['is_active'] ) ? 0 : 1,
				'updated_at'      => $row['updated_at'],
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%d', '%d', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	public function set_redirect_active( int $id, bool $active ): bool {
		$wpdb = $this->wpdb;
		$result = $wpdb->update(
			$this->table( 'redirects' ),
			array(
				'is_active'  => $active ? 1 : 0,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	public function delete_redirect( int $id ): bool {
		$wpdb = $this->wpdb;
		$result = $wpdb->delete( $this->table( 'redirects' ), array( 'id' => $id ), array( '%d' ) );

		return false !== $result && $result > 0;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function redirects_after( int $last_id, int $limit ): array {
		$wpdb = $this->wpdb;
		$table = $this->table( 'redirects' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, source_path, destination_url, redirect_type, is_active
				FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d",
				$last_id,
				max( 1, min( 500, $limit ) )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	public function error_count(): int {
		$wpdb = $this->wpdb;
		$count = get_transient( 'rescue404_error_count' );
		if ( false === $count ) {
			$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->table( 'errors' ) ) );
			set_transient( 'rescue404_error_count', $count, DAY_IN_SECONDS );
		}

		return (int) $count;
	}

	public function refresh_error_count(): void {
		$wpdb = $this->wpdb;
		delete_transient( 'rescue404_error_count' );
		$this->error_count();
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array{sql: string, params: array<int, mixed>}
	 */
	private function error_where( array $args ): array {
		$wpdb = $this->wpdb;
		$sql    = '1=1';
		$params = array();
		$status = isset( $args['status'] ) ? (string) $args['status'] : 'all';
		$search = isset( $args['search'] ) ? (string) $args['search'] : '';

		if ( in_array( $status, array( 'unresolved', 'resolved', 'ignored' ), true ) ) {
			$sql     .= ' AND status = %s';
			$params[] = $status;
		} elseif ( 'high' === $status ) {
			$sql     .= ' AND hit_count >= %d';
			$params[] = self::HIGH_TRAFFIC;
		}

		if ( '' !== $search ) {
			$sql     .= ' AND request_path LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		if ( ! $params ) {
			// prepare() still needs a placeholder when this fragment is reused.
			$sql     .= ' AND %d = %d';
			$params[] = 1;
			$params[] = 1;
		}

		return array(
			'sql'    => $sql,
			'params' => $params,
		);
	}

	private function error_order( string $orderby ): string {
		$wpdb = $this->wpdb;
		if ( 'hits' === $orderby ) {
			return 'hit_count DESC, last_detected DESC';
		}
		if ( 'oldest' === $orderby ) {
			return 'first_detected ASC, id ASC';
		}

		return 'last_detected DESC, id DESC';
	}

	private function increment_day( string $mysql ): void {
		$wpdb = $this->wpdb;
		$date  = substr( $mysql, 0, 10 );
		$table = $this->table( 'daily' );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (stat_date, hit_count) VALUES (%s, 1)
				ON DUPLICATE KEY UPDATE hit_count = hit_count + 1",
				$date
			)
		);
	}

	private function bump_error_count( int $delta ): void {
		$wpdb = $this->wpdb;
		$count = $this->error_count() + $delta;
		set_transient( 'rescue404_error_count', max( 0, $count ), DAY_IN_SECONDS );
	}

	/**
	 * @param array<int, mixed> $ids
	 * @return array<int, int>
	 */
	private function id_list( array $ids ): array {
		$wpdb = $this->wpdb;
		$clean = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$clean[] = $id;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Whitelisted table name, including the blog prefix.
	 */
	private function table( string $name ): string {
		$wpdb = $this->wpdb;
		$allowed = array(
			'errors'    => 'rescue404_errors',
			'redirects' => 'rescue404_redirects',
			'daily'     => 'rescue404_daily',
		);
		if ( ! isset( $allowed[ $name ] ) ) {
			return $wpdb->prefix . 'rescue404_errors';
		}

		return $wpdb->prefix . $allowed[ $name ];
	}
}
