<?php
/**
 * Local destination suggestions.
 *
 * @package Rescue404
 */

namespace Rescue404;

defined( 'ABSPATH' ) || exit;

/**
 * Compares a broken path with published content on this site.
 *
 * Nothing here calls an outside service, and nothing here redirects a visitor.
 */
class Suggestions {

	/**
	 * @var Database
	 */
	private $db;

	public function __construct( ?Database $db = null ) {
		$this->db = $db ?? new Database();
	}

	/**
	 * Score one published item against a broken path. 0 means "do not suggest".
	 */
	public static function score_candidate( string $request_path, string $post_name, string $post_title ): int {
		$slug = strtolower( Path::last_segment( $request_path ) );
		$name = strtolower( $post_name );
		if ( '' === $slug || '' === $name ) {
			return 0;
		}
		if ( $name === $slug && strlen( $slug ) >= 2 ) {
			return 100;
		}
		if ( strlen( $slug ) < 4 || strlen( $name ) < 4 ) {
			return 0;
		}

		similar_text( $slug, $name, $name_percent );
		$title_slug = strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '-', $post_title ) );
		$title_slug = trim( $title_slug, '-' );
		similar_text( $slug, $title_slug, $title_percent );
		$best = max( (float) $name_percent, (float) $title_percent );

		if ( $best >= 88 ) {
			return 92;
		}
		if ( $best >= 70 ) {
			return 75;
		}

		return 0;
	}

	/**
	 * strong, possible, or none.
	 */
	public static function confidence_for( int $score ): string {
		if ( $score >= 90 ) {
			return 'strong';
		}
		if ( $score >= 60 ) {
			return 'possible';
		}

		return 'none';
	}

	/**
	 * Short label for a stored confidence value.
	 */
	public static function label( string $confidence ): string {
		switch ( $confidence ) {
			case 'strong':
				return __( 'Strong match', '404-rescue' );
			case 'possible':
				return __( 'Possible match', '404-rescue' );
			case 'none':
				return __( 'No match', '404-rescue' );
			default:
				return __( 'Not checked yet', '404-rescue' );
		}
	}

	/**
	 * Plain-language note so a suggestion is never treated as certain.
	 */
	public static function note( string $confidence ): string {
		switch ( $confidence ) {
			case 'strong':
				return __( 'The names line up. Open the page and confirm it is the right one.', '404-rescue' );
			case 'possible':
				return __( 'This might be the right page. Check it before you save a redirect.', '404-rescue' );
			case 'none':
				return __( 'No close page was found. Choose the destination yourself.', '404-rescue' );
			default:
				return __( 'A suggestion has not been saved for this address yet.', '404-rescue' );
		}
	}

	/**
	 * Save the best suggestion for a newly logged path.
	 *
	 * A burst of new 404s skips the search after 20 lookups in a minute.
	 * The row stays "not checked yet" and can be refreshed from the errors screen.
	 */
	public function attach( int $error_id, string $path, bool $respect_budget = true ): void {
		if ( $error_id < 1 || '' === $path ) {
			return;
		}
		if ( $respect_budget && $this->over_budget() ) {
			return;
		}

		try {
			$items = $this->find( $path, 1 );
		} catch ( \Throwable $error ) {
			unset( $error );
			return;
		}

		if ( ! $items ) {
			$this->db->save_suggestion( $error_id, '', 'none' );
			return;
		}

		$this->db->save_suggestion( $error_id, $items[0]['url'], $items[0]['confidence'] );
	}

	/**
	 * Best published matches for a broken path.
	 *
	 * @return array<int, array{url: string, title: string, kind: string, confidence: string, label: string, score: int}>
	 */
	public function find( string $path, int $limit = 3 ): array {
		$limit = max( 1, min( 5, $limit ) );
		$path  = Path::from_uri( $path, Path::home_path() );
		if ( '' === $path || '/' === $path ) {
			return array();
		}

		$types = $this->public_types();
		if ( ! $types ) {
			return array();
		}

		$found = array();
		$exact = get_page_by_path( trim( $path, '/' ), OBJECT, $types );
		if ( $exact instanceof \WP_Post && 'publish' === $exact->post_status ) {
			$item = $this->item_from_post( $exact, $path, 100 );
			if ( null !== $item ) {
				$found[ $item['url'] ] = $item;
			}
		}

		$slug = Path::last_segment( $path );
		if ( strlen( $slug ) >= 2 ) {
			foreach ( $this->search_posts( $types, $slug ) as $post ) {
				$score = self::score_candidate( $path, (string) $post->post_name, (string) $post->post_title );
				$item  = $this->item_from_post( $post, $path, $score );
				if ( null === $item ) {
					continue;
				}
				if ( ! isset( $found[ $item['url'] ] ) || $item['score'] > $found[ $item['url'] ]['score'] ) {
					$found[ $item['url'] ] = $item;
				}
			}
		}

		$items = array_values( $found );
		usort(
			$items,
			static function ( array $left, array $right ): int {
				return $right['score'] <=> $left['score'];
			}
		);

		return array_slice( $items, 0, $limit );
	}

	/**
	 * @return array<int, string>
	 */
	private function public_types(): array {
		$types = get_post_types( array( 'public' => true ), 'names' );
		if ( ! is_array( $types ) ) {
			return array();
		}
		unset( $types['attachment'] );
		$clean = array();
		foreach ( $types as $type ) {
			$key = sanitize_key( (string) $type );
			if ( '' !== $key ) {
				$clean[] = $key;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * @param array<int, string> $types
	 * @return array<int, \WP_Post>
	 */
	private function search_posts( array $types, string $slug ): array {
		global $wpdb;

		// The IN list is one %s for each public post type. Values are bound below.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$params       = array_merge( array( $wpdb->posts ), $types, array( $slug ) );
		if ( strlen( $slug ) < 3 ) {
			$sql = "SELECT ID, post_title, post_name, post_type, post_status
				FROM %i
				WHERE post_status = 'publish' AND post_type IN ({$placeholders}) AND post_name = %s
				LIMIT 10";
		} else {
			$like     = '%' . $wpdb->esc_like( $slug ) . '%';
			$title    = '%' . $wpdb->esc_like( str_replace( '-', ' ', $slug ) ) . '%';
			$params[] = $like;
			$params[] = $title;
			$sql = "SELECT ID, post_title, post_name, post_type, post_status
				FROM %i
				WHERE post_status = 'publish' AND post_type IN ({$placeholders})
				AND (post_name = %s OR post_name LIKE %s OR post_title LIKE %s)
				LIMIT 20";
		}

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$posts = array();
		foreach ( $rows as $row ) {
			if ( is_object( $row ) && isset( $row->ID ) ) {
				$posts[] = $row;
			}
		}

		return $posts;
	}

	/**
	 * @param object $post Post row or WP_Post.
	 * @return array{url: string, title: string, kind: string, confidence: string, label: string, score: int}|null
	 */
	private function item_from_post( object $post, string $request_path, int $score ): ?array {
		$confidence = self::confidence_for( $score );
		if ( 'none' === $confidence ) {
			return null;
		}

		$url = get_permalink( (int) $post->ID );
		if ( ! is_string( $url ) || '' === $url ) {
			return null;
		}

		$suggested_path = Path::from_uri( $url, Path::home_path() );
		if ( '' === $suggested_path || $suggested_path === $request_path ) {
			return null;
		}

		$kind = '';
		$type = get_post_type_object( $post->post_type );
		if ( $type && isset( $type->labels->singular_name ) ) {
			$kind = (string) $type->labels->singular_name;
		}

		$title = (string) get_post_field( 'post_title', $post );
		if ( function_exists( 'wp_strip_all_tags' ) ) {
			$title = wp_strip_all_tags( $title );
		}
		if ( function_exists( 'mb_substr' ) ) {
			$title = mb_substr( $title, 0, 140 );
		} else {
			$title = substr( $title, 0, 140 );
		}

		return array(
			'url'         => $url,
			'title'       => $title,
			'kind'        => $kind,
			'confidence'  => $confidence,
			'label'       => self::label( $confidence ),
			'score'       => $score,
		);
	}

	/**
	 * True when this request should skip the content search.
	 */
	private function over_budget(): bool {
		$key   = 'rescue404_suggest_budget';
		$count = (int) get_transient( $key );
		if ( $count >= 20 ) {
			return true;
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

		return false;
	}
}
