<?php
/**
 * 404 errors screen.
 *
 * @package Rescue404
 *
 * @var array<string, mixed> $args
 */

defined( 'ABSPATH' ) || exit;

$items   = isset( $args['items'] ) && is_array( $args['items'] ) ? $args['items'] : array();
$total   = (int) ( $args['total'] ?? 0 );
$paged   = max( 1, (int) ( $args['paged'] ?? 1 ) );
$status  = (string) ( $args['status'] ?? 'all' );
$orderby = (string) ( $args['orderby'] ?? 'recent' );
$search  = (string) ( $args['search'] ?? '' );
$detail  = ( isset( $args['detail'] ) && is_array( $args['detail'] ) ) ? $args['detail'] : null;
$per     = 20;
$pages   = max( 1, (int) ceil( $total / $per ) );
$from    = $total > 0 ? ( ( $paged - 1 ) * $per ) + 1 : 0;
$to      = min( $total, $paged * $per );

$tabs = array(
	'all'        => __( 'All', '404-rescue' ),
	'unresolved' => __( 'Needs attention', '404-rescue' ),
	'resolved'   => __( 'Redirect added', '404-rescue' ),
	'ignored'    => __( 'Ignored', '404-rescue' ),
	'high'       => __( 'Busy', '404-rescue' ),
);
$base_args = array(
	's'       => '' === $search ? null : $search,
	'orderby' => 'recent' === $orderby ? null : $orderby,
);

$export = wp_nonce_url(
	add_query_arg(
		array(
			'action' => 'rescue404_export_errors',
			'status' => 'all' === $status ? null : $status,
			's'      => '' === $search ? null : $search,
		),
		admin_url( 'admin-post.php' )
	),
	'rescue404_export_errors'
);
?>
<div class="wrap rescue404">
	<header class="rescue404-header">
		<div>
			<h1><?php esc_html_e( '404 errors', '404-rescue' ); ?></h1>
			<p class="rescue404-lead"><?php esc_html_e( 'These are pages people tried to open and could not. Create a redirect when you know where they should go.', '404-rescue' ); ?></p>
		</div>
		<a class="rescue404-button" href="<?php echo esc_url( $export ); ?>"><?php esc_html_e( 'Export this list', '404-rescue' ); ?></a>
	</header>

	<?php \Rescue404\Admin::render_notices(); ?>

	<?php if ( $detail ) : ?>
		<section class="rescue404-card rescue404-detail">
			<h2><code><?php echo esc_html( (string) $detail['request_path'] ); ?></code></h2>
			<dl class="rescue404-facts">
				<div>
					<dt><?php esc_html_e( 'Visits', '404-rescue' ); ?></dt>
					<dd><?php echo esc_html( number_format_i18n( (int) $detail['hit_count'] ) ); ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'First seen', '404-rescue' ); ?></dt>
					<dd><?php echo esc_html( \Rescue404\Admin::date_text( (string) $detail['first_detected'] ) ); ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Last seen', '404-rescue' ); ?></dt>
					<dd><?php echo esc_html( \Rescue404\Admin::date_text( (string) $detail['last_detected'] ) ); ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Status', '404-rescue' ); ?></dt>
					<dd><?php echo esc_html( \Rescue404\Admin::status_label( (string) $detail['status'] ) ); ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Came from', '404-rescue' ); ?></dt>
					<dd>
						<?php if ( '' === (string) $detail['referrer'] ) : ?>
							<?php esc_html_e( 'Not available', '404-rescue' ); ?>
						<?php else : ?>
							<?php echo esc_html( (string) $detail['referrer'] ); ?>
						<?php endif; ?>
					</dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Browser', '404-rescue' ); ?></dt>
					<dd><?php echo '' !== (string) $detail['user_agent'] ? esc_html( (string) $detail['user_agent'] ) : esc_html__( 'Not available', '404-rescue' ); ?></dd>
				</div>
			</dl>
			<p class="rescue404-note"><?php esc_html_e( 'The browser string is shortened so you can tell a person from a bot. IP addresses are not stored. The “came from” page does not include any private query string.', '404-rescue' ); ?></p>
			<p>
				<span class="rescue404-badge <?php echo 'strong' === $detail['suggestion_confidence'] ? 'is-success' : ( 'possible' === $detail['suggestion_confidence'] ? 'is-warning' : 'is-muted' ); ?>">
					<?php echo esc_html( \Rescue404\Suggestions::label( (string) $detail['suggestion_confidence'] ) ); ?>
				</span>
				<?php echo esc_html( \Rescue404\Suggestions::note( (string) $detail['suggestion_confidence'] ) ); ?>
			</p>
			<?php if ( '' !== (string) $detail['suggested_destination'] ) : ?>
				<p><a href="<?php echo esc_url( (string) $detail['suggested_destination'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( (string) $detail['suggested_destination'] ); ?></a></p>
			<?php endif; ?>
			<div class="rescue404-inline-actions">
				<a class="rescue404-button is-primary" href="<?php echo esc_url( \Rescue404\Admin::page_url( 'rescue404-redirects', array( 'source' => (string) $detail['request_path'], 'destination' => (string) $detail['suggested_destination'] ) ) ); ?>"><?php esc_html_e( 'Create redirect', '404-rescue' ); ?></a>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'rescue404_error_action' ); ?>
					<input type="hidden" name="action" value="rescue404_error_action">
					<input type="hidden" name="error_id" value="<?php echo esc_attr( (string) (int) $detail['id'] ); ?>">
					<input type="hidden" name="error_action" value="refresh">
					<button type="submit" class="rescue404-button"><?php esc_html_e( 'Check again for a page', '404-rescue' ); ?></button>
				</form>
			</div>
		</section>
	<?php endif; ?>

	<nav class="rescue404-tabs" aria-label="<?php esc_attr_e( 'Filter broken URLs', '404-rescue' ); ?>">
		<?php foreach ( $tabs as $key => $label ) : ?>
			<?php
			$tab_args = $base_args;
			if ( 'all' !== $key ) {
				$tab_args['status'] = $key;
			}
			$url = \Rescue404\Admin::page_url( 'rescue404-errors', $tab_args );
			?>
			<a class="<?php echo $status === $key ? 'is-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>" <?php echo 'high' === $key ? 'title="' . esc_attr__( 'Five or more visits', '404-rescue' ) . '"' : ''; ?>>
				<?php echo esc_html( $label ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<form class="rescue404-filters" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
		<input type="hidden" name="page" value="rescue404-errors">
		<?php if ( 'all' !== $status ) : ?>
			<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
		<?php endif; ?>
		<label>
			<span class="screen-reader-text"><?php esc_html_e( 'Search broken URLs', '404-rescue' ); ?></span>
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search addresses', '404-rescue' ); ?>">
		</label>
		<label>
			<span class="screen-reader-text"><?php esc_html_e( 'Sort', '404-rescue' ); ?></span>
			<select name="orderby">
				<option value="recent" <?php selected( $orderby, 'recent' ); ?>><?php esc_html_e( 'Recently seen', '404-rescue' ); ?></option>
				<option value="hits" <?php selected( $orderby, 'hits' ); ?>><?php esc_html_e( 'Most visits', '404-rescue' ); ?></option>
				<option value="oldest" <?php selected( $orderby, 'oldest' ); ?>><?php esc_html_e( 'Oldest first', '404-rescue' ); ?></option>
			</select>
		</label>
		<button type="submit" class="rescue404-button"><?php esc_html_e( 'Apply', '404-rescue' ); ?></button>
	</form>

	<?php if ( ! $items ) : ?>
		<section class="rescue404-empty">
			<h2><?php echo '' !== $search || 'all' !== $status ? esc_html__( 'Nothing matches this list', '404-rescue' ) : esc_html__( 'No missing pages yet', '404-rescue' ); ?></h2>
			<p><?php esc_html_e( 'Broken addresses appear here after someone visits a page that does not exist.', '404-rescue' ); ?></p>
		</section>
	<?php else : ?>
		<form id="rescue404-bulk" class="rescue404-bulk" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-confirm-delete="<?php echo esc_attr__( 'Delete the selected broken URLs?', '404-rescue' ); ?>">
			<?php wp_nonce_field( 'rescue404_bulk_errors' ); ?>
			<input type="hidden" name="action" value="rescue404_bulk_errors">
			<label>
				<span class="screen-reader-text"><?php esc_html_e( 'Bulk action', '404-rescue' ); ?></span>
				<select name="bulk_action">
					<option value=""><?php esc_html_e( 'Bulk actions', '404-rescue' ); ?></option>
					<option value="ignore"><?php esc_html_e( 'Ignore', '404-rescue' ); ?></option>
					<option value="export"><?php esc_html_e( 'Export', '404-rescue' ); ?></option>
					<option value="delete"><?php esc_html_e( 'Delete', '404-rescue' ); ?></option>
				</select>
			</label>
			<button type="submit" class="rescue404-button"><?php esc_html_e( 'Apply', '404-rescue' ); ?></button>
			<p class="rescue404-count">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: first row number, 2: last row number, 3: total rows */
						__( 'Showing %1$s–%2$s of %3$s', '404-rescue' ),
						number_format_i18n( $from ),
						number_format_i18n( $to ),
						number_format_i18n( $total )
					)
				);
				?>
			</p>
		</form>

		<div class="rescue404-table-wrap">
			<table class="rescue404-table">
				<thead>
					<tr>
						<th class="rescue404-check"><input type="checkbox" id="rescue404-select-all" aria-label="<?php esc_attr_e( 'Select all on this page', '404-rescue' ); ?>"></th>
						<th><?php esc_html_e( 'Broken URL', '404-rescue' ); ?></th>
						<th><?php esc_html_e( 'Visits', '404-rescue' ); ?></th>
						<th><?php esc_html_e( 'First seen', '404-rescue' ); ?></th>
						<th><?php esc_html_e( 'Last seen', '404-rescue' ); ?></th>
						<th><?php esc_html_e( 'Suggested page', '404-rescue' ); ?></th>
						<th><?php esc_html_e( 'Status', '404-rescue' ); ?></th>
						<th><?php esc_html_e( 'Actions', '404-rescue' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $items as $row ) : ?>
						<?php
						$id         = (int) $row['id'];
						$path       = (string) $row['request_path'];
						$suggestion = (string) $row['suggested_destination'];
						$confidence = (string) $row['suggestion_confidence'];
						$row_status = (string) $row['status'];
						?>
						<tr>
							<td><input class="rescue404-row-check" form="rescue404-bulk" type="checkbox" name="error_ids[]" value="<?php echo esc_attr( (string) $id ); ?>" aria-label="<?php echo esc_attr( $path ); ?>"></td>
							<td><code><?php echo esc_html( $path ); ?></code></td>
							<td><?php echo esc_html( number_format_i18n( (int) $row['hit_count'] ) ); ?></td>
							<td><?php echo esc_html( \Rescue404\Admin::date_text( (string) $row['first_detected'] ) ); ?></td>
							<td><?php echo esc_html( \Rescue404\Admin::date_text( (string) $row['last_detected'] ) ); ?></td>
							<td>
								<?php if ( '' !== $suggestion ) : ?>
									<span class="rescue404-badge <?php echo 'strong' === $confidence ? 'is-success' : 'is-warning'; ?>"><?php echo esc_html( \Rescue404\Suggestions::label( $confidence ) ); ?></span>
									<span class="rescue404-url"><?php echo esc_html( $suggestion ); ?></span>
								<?php else : ?>
									<span class="rescue404-muted"><?php echo esc_html( \Rescue404\Suggestions::label( $confidence ) ); ?></span>
								<?php endif; ?>
							</td>
							<td><span class="rescue404-badge <?php echo 'resolved' === $row_status ? 'is-success' : ( 'ignored' === $row_status ? 'is-muted' : 'is-error' ); ?>"><?php echo esc_html( \Rescue404\Admin::status_label( $row_status ) ); ?></span></td>
							<td class="rescue404-row-actions">
								<a href="<?php echo esc_url( \Rescue404\Admin::page_url( 'rescue404-errors', array_merge( $base_args, array( 'status' => 'all' === $status ? null : $status, 'paged' => $paged > 1 ? $paged : null, 'error' => $id ) ) ) ); ?>"><?php esc_html_e( 'Details', '404-rescue' ); ?></a>
								<a href="<?php echo esc_url( \Rescue404\Admin::page_url( 'rescue404-redirects', array( 'source' => $path, 'destination' => $suggestion ) ) ); ?>"><?php esc_html_e( 'Create redirect', '404-rescue' ); ?></a>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<?php wp_nonce_field( 'rescue404_error_action' ); ?>
									<input type="hidden" name="action" value="rescue404_error_action">
									<input type="hidden" name="error_id" value="<?php echo esc_attr( (string) $id ); ?>">
									<input type="hidden" name="error_action" value="<?php echo 'ignored' === $row_status ? 'restore' : 'ignore'; ?>">
									<button type="submit"><?php echo 'ignored' === $row_status ? esc_html__( 'Restore', '404-rescue' ) : esc_html__( 'Ignore', '404-rescue' ); ?></button>
								</form>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="<?php echo esc_attr( \Rescue404\Admin::confirm_js( __( 'Delete this broken URL?', '404-rescue' ) ) ); ?>">
									<?php wp_nonce_field( 'rescue404_error_action' ); ?>
									<input type="hidden" name="action" value="rescue404_error_action">
									<input type="hidden" name="error_id" value="<?php echo esc_attr( (string) $id ); ?>">
									<input type="hidden" name="error_action" value="delete">
									<button type="submit" class="is-danger"><?php esc_html_e( 'Delete', '404-rescue' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<?php if ( $pages > 1 ) : ?>
			<nav class="rescue404-pager" aria-label="<?php esc_attr_e( 'Pages', '404-rescue' ); ?>">
				<?php
				$links = paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%' ),
						'format'    => '',
						'current'   => $paged,
						'total'     => $pages,
						'type'      => 'list',
						'prev_text' => __( 'Previous', '404-rescue' ),
						'next_text' => __( 'Next', '404-rescue' ),
					)
				);
				if ( is_string( $links ) ) {
					echo wp_kses_post( $links );
				}
				?>
			</nav>
		<?php endif; ?>
	<?php endif; ?>
</div>
