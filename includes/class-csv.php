<?php
/**
 * CSV import and export.
 *
 * @package Rescue404
 */

namespace Rescue404;

defined( 'ABSPATH' ) || exit;

/**
 * Streams CSV files and imports redirect rows after the same checks as the form.
 */
class Csv {

	/**
	 * @var Database
	 */
	private $db;

	/**
	 * @var Redirect_Manager
	 */
	private $redirects;

	public function __construct( ?Database $db = null, ?Redirect_Manager $redirects = null ) {
		$this->db        = $db ?? new Database();
		$this->redirects = $redirects ?? new Redirect_Manager( $this->db );
	}

	/**
	 * Download the errors that match the current list filters.
	 *
	 * @param array<string, mixed> $args status, search.
	 */
	public function stream_errors( array $args ): void {
		$this->headers( '404-rescue-errors-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = $this->open_handle( 'php://output', 'wb' );
		if ( false === $out ) {
			return;
		}

		fputcsv(
			$out,
			array( 'path', 'hits', 'first_detected', 'last_detected', 'status', 'suggested_destination', 'confidence', 'referrer' )
		);

		$last = 0;
		do {
			$rows = $this->db->errors_after( $last, 200, $args );
			foreach ( $rows as $row ) {
				$last = (int) $row['id'];
				fputcsv( $out, $this->error_cells( $row ) );
			}
		} while ( count( $rows ) === 200 );

		$this->close_handle( $out );
	}

	/**
	 * Download only the selected error rows.
	 *
	 * @param array<int, int> $ids
	 */
	public function stream_error_ids( array $ids ): void {
		$this->headers( '404-rescue-errors-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = $this->open_handle( 'php://output', 'wb' );
		if ( false === $out ) {
			return;
		}

		fputcsv(
			$out,
			array( 'path', 'hits', 'first_detected', 'last_detected', 'status', 'suggested_destination', 'confidence', 'referrer' )
		);
		foreach ( $this->db->errors_by_ids( $ids ) as $row ) {
			fputcsv( $out, $this->error_cells( $row ) );
		}
		$this->close_handle( $out );
	}

	/**
	 * Download every redirect rule.
	 */
	public function stream_redirects(): void {
		$this->headers( '404-rescue-redirects-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = $this->open_handle( 'php://output', 'wb' );
		if ( false === $out ) {
			return;
		}

		fputcsv( $out, array( 'source', 'destination', 'type', 'active' ) );
		$last = 0;
		do {
			$rows = $this->db->redirects_after( $last, 200 );
			foreach ( $rows as $row ) {
				$last = (int) $row['id'];
				fputcsv(
					$out,
					array(
						Path::csv_safe( (string) $row['source_path'] ),
						Path::csv_safe( (string) $row['destination_url'] ),
						(string) (int) $row['redirect_type'],
						(string) (int) $row['is_active'],
					)
				);
			}
		} while ( count( $rows ) === 200 );

		$this->close_handle( $out );
	}

	/**
	 * A one-line example people can edit and import.
	 */
	public function stream_sample(): void {
		$this->headers( '404-rescue-redirect-sample.csv' );
		$out = $this->open_handle( 'php://output', 'wb' );
		if ( false === $out ) {
			return;
		}
		fputcsv( $out, array( 'source', 'destination', 'type', 'active' ) );
		fputcsv( $out, array( '/old-page', '/new-page', '301', '1' ) );
		$this->close_handle( $out );
	}

	/**
	 * Import redirect rules from an uploaded CSV file.
	 *
	 * @return array{imported: int, skipped: int, failed: int, errors: array<int, string>}
	 */
	public function import_redirects( string $tmp ): array {
		$summary = array(
			'imported' => 0,
			'skipped'  => 0,
			'failed'   => 0,
			'errors'   => array(),
		);
		$handle  = $this->open_handle( $tmp, 'rb' );
		if ( false === $handle ) {
			$summary['failed']   = 1;
			$summary['errors'][] = __( 'The file could not be read.', '404-rescue' );

			return $summary;
		}

		$header     = null;
		$row_number = 0;
		$seen       = 0;

		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			if ( ! is_array( $row ) || $row === array( null ) ) {
				continue;
			}
			++$row_number;
			if ( isset( $row[0] ) ) {
				$row[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $row[0] );
			}
			if ( 1 === $row_number && $this->looks_like_header( $row ) ) {
				$header = $this->map_header( $row );
				continue;
			}
			if ( $this->row_is_blank( $row ) ) {
				continue;
			}
			++$seen;
			if ( $seen > 2000 ) {
				$this->add_error( $summary, __( 'Import stopped after 2,000 rows.', '404-rescue' ) );
				break;
			}

			$mapped = $this->map_row( $row, $header );
			$result = $this->import_row( $mapped, $row_number, $summary );
			if ( 'imported' === $result ) {
				++$summary['imported'];
			} elseif ( 'skipped' === $result ) {
				++$summary['skipped'];
			} else {
				++$summary['failed'];
			}
		}

		$this->close_handle( $handle );

		if ( 0 === $seen && 0 === $summary['imported'] ) {
			$summary['failed'] = max( 1, $summary['failed'] );
			$this->add_error( $summary, __( 'The file did not contain any redirect rows.', '404-rescue' ) );
		}

		return $summary;
	}

	/**
	 * @param array<string, string>                $mapped
	 * @param array{imported: int, skipped: int, failed: int, errors: array<int, string>} $summary
	 */
	private function import_row( array $mapped, int $row_number, array &$summary ): string {
		$source = Path::csv_unescape( trim( $mapped['source'] ) );
		$dest   = Path::csv_unescape( trim( $mapped['destination'] ) );
		$type   = (int) $mapped['type'];
		if ( ! in_array( $type, array( 301, 302, 307, 308 ), true ) ) {
			$type = 301;
		}
		$active = $this->is_active_value( $mapped['active'] );

		if ( '' === $source && '' === $dest ) {
			return 'skipped';
		}

		$checked = Redirect_Manager::check( $source, $dest, $type, Path::home_url(), $this->db->all_redirects(), 0 );
		if ( ! $checked['ok'] ) {
			if ( '' !== $checked['source_path'] ) {
				$existing = $this->db->redirect_by_source( $checked['source_path'] );
				if (
					$existing
					&& (string) $existing['destination_url'] === $checked['destination_url']
					&& (int) $existing['redirect_type'] === $type
					&& (int) $existing['is_active'] === $active
				) {
					return 'skipped';
				}
			}

			$this->add_error(
				$summary,
				sprintf(
					/* translators: 1: row number, 2: reason */
					__( 'Row %1$d: %2$s', '404-rescue' ),
					$row_number,
					$checked['message']
				)
			);

			return 'failed';
		}

		$created = $this->redirects->create(
			array(
				'source'      => $checked['source_path'],
				'destination' => $checked['destination_url'],
				'type'        => $type,
				'is_active'   => $active,
			)
		);
		if ( ! $created['ok'] ) {
			$existing = $this->db->redirect_by_source( $checked['source_path'] );
			if (
				$existing
				&& (string) $existing['destination_url'] === $checked['destination_url']
				&& (int) $existing['redirect_type'] === $type
			) {
				return 'skipped';
			}
			$this->add_error(
				$summary,
				sprintf(
					/* translators: 1: row number, 2: reason */
					__( 'Row %1$d: %2$s', '404-rescue' ),
					$row_number,
					$created['message']
				)
			);

			return 'failed';
		}

		return 'imported';
	}

	/**
	 * @param array<int, string|null> $row
	 */
	private function looks_like_header( array $row ): bool {
		$first = strtolower( trim( (string) ( $row[0] ?? '' ) ) );

		return in_array( $first, array( 'source', 'source_path', 'path', 'from' ), true );
	}

	/**
	 * @param array<int, string|null> $row
	 * @return array<string, int>
	 */
	private function map_header( array $row ): array {
		$map = array();
		foreach ( $row as $index => $label ) {
			$key = strtolower( trim( (string) $label ) );
			if ( in_array( $key, array( 'source', 'source_path', 'path', 'from' ), true ) ) {
				$map['source'] = (int) $index;
			} elseif ( in_array( $key, array( 'destination', 'destination_url', 'target', 'to' ), true ) ) {
				$map['destination'] = (int) $index;
			} elseif ( in_array( $key, array( 'type', 'redirect_type', 'code', 'status' ), true ) ) {
				$map['type'] = (int) $index;
			} elseif ( in_array( $key, array( 'active', 'is_active', 'enabled' ), true ) ) {
				$map['active'] = (int) $index;
			}
		}

		return $map;
	}

	/**
	 * @param array<int, string|null> $row
	 * @param array<string, int>|null $header
	 * @return array{source: string, destination: string, type: string, active: string}
	 */
	private function map_row( array $row, ?array $header ): array {
		$pick = static function ( array $data, ?array $header, string $key, int $fallback ): string {
			$index = ( null !== $header && isset( $header[ $key ] ) ) ? $header[ $key ] : $fallback;

			return isset( $data[ $index ] ) ? trim( (string) $data[ $index ] ) : '';
		};

		return array(
			'source'      => $pick( $row, $header, 'source', 0 ),
			'destination' => $pick( $row, $header, 'destination', 1 ),
			'type'        => $pick( $row, $header, 'type', 2 ),
			'active'      => $pick( $row, $header, 'active', 3 ),
		);
	}

	/**
	 * @param array<int, string|null> $row
	 */
	private function row_is_blank( array $row ): bool {
		foreach ( $row as $cell ) {
			if ( '' !== trim( (string) $cell ) ) {
				return false;
			}
		}

		return true;
	}

	private function is_active_value( string $value ): int {
		$value = strtolower( trim( $value ) );
		if ( '' === $value ) {
			return 1;
		}

		return in_array( $value, array( '1', 'yes', 'true', 'active', 'on' ), true ) ? 1 : 0;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<int, string>
	 */
	private function error_cells( array $row ): array {
		return array(
			Path::csv_safe( (string) $row['request_path'] ),
			(string) (int) $row['hit_count'],
			(string) $row['first_detected'],
			(string) $row['last_detected'],
			(string) $row['status'],
			Path::csv_safe( (string) $row['suggested_destination'] ),
			(string) $row['suggestion_confidence'],
			Path::csv_safe( (string) ( $row['referrer'] ?? '' ) ),
		);
	}

	/**
	 * @param array{imported: int, skipped: int, failed: int, errors: array<int, string>} $summary
	 */
	private function add_error( array &$summary, string $message ): void {
		if ( count( $summary['errors'] ) < 20 ) {
			$summary['errors'][] = $message;
		}
	}

	/**
	 * Open a CSV stream. WP_Filesystem cannot write php://output or walk an upload row by row.
	 *
	 * @param string $target php://output or an uploaded temporary path.
	 * @param string $mode   fopen mode.
	 * @return resource|false
	 */
	private function open_handle( string $target, string $mode ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		return fopen( $target, $mode );
	}

	/**
	 * @param resource $handle Stream from open_handle().
	 */
	private function close_handle( $handle ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );
	}

	private function headers( string $filename ): void {
		$filename = sanitize_file_name( $filename );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo "\xEF\xBB\xBF";
	}
}
