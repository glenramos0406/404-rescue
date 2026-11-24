<?php
/**
 * Redirects screen.
 *
 * @package Rescue404
 *
 * @var array<string, mixed> $args
 */

defined( 'ABSPATH' ) || exit;

$items    = isset( $args['items'] ) && is_array( $args['items'] ) ? $args['items'] : array();
$total    = (int) ( $args['total'] ?? 0 );
$paged    = max( 1, (int) ( $args['paged'] ?? 1 ) );
$state    = (string) ( $args['state'] ?? 'all' );
$search   = (string) ( $args['search'] ?? '' );
$prefill  = isset( $args['prefill'] ) && is_array( $args['prefill'] ) ? $args['prefill'] : array();
$matches  = isset( $args['matches'] ) && is_array( $args['matches'] ) ? $args['matches'] : array();
$pages    = max( 1, (int) ceil( $total / 20 ) );
$edit_id  = (int) ( $prefill['edit_id'] ?? 0 );
$type     = (int) ( $prefill['type'] ?? 301 );
$active   = ! empty( $prefill['is_active'] );
$export   = wp_nonce_url( admin_url( 'admin-post.php?action=rescue404_export_redirects' ), 'rescue404_export_redirects' );
$sample   = wp_nonce_url( admin_url( 'admin-post.php?action=rescue404_sample_csv' ), 'rescue404_sample_csv' );
$types    = array(
	301 => __( '301 Permanent — the usual choice', '404-rescue' ),
	302 => __( '302 Temporary', '404-rescue' ),
	307 => __( '307 Temporary, keep the request type', '404-rescue' ),
	308 => __( '308 Permanent, keep the request type', '404-rescue' ),
);
?>
<div class="wrap rescue404">
	<header class="rescue404-header">
		<div>
			<h1><?php esc_html_e( 'Redirects', '404-rescue' ); ?></h1>
			<p class="rescue404-lead"><?php esc_html_e( 'Choose where visitors should go when they visit a broken link. Nothing is sent automatically from a suggestion.', '404-rescue' ); ?></p>
		</div>
		<a class="rescue404-button" href="<?php echo esc_url( $export ); ?>"><?php esc_html_e( 'Export redirects', '404-rescue' ); ?></a>
	</header>

	<?php \Rescue404\Admin::render_notices(); ?>

	<section class="rescue404-card" id="rescue404-add-redirect">
		<h2><?php echo $edit_id > 0 ? esc_html__( 'Edit redirect', '404-rescue' ) : esc_html__( 'Add a redirect', '404-rescue' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rescue404-form">
			<?php wp_nonce_field( 'rescue404_save_redirect' ); ?>
			<input type="hidden" name="action" value="rescue404_save_redirect">
			<input type="hidden" name="edit_id" value="<?php echo esc_attr( (string) $edit_id ); ?>">
			<label>
				<span><?php esc_html_e( 'When someone visits', '404-rescue' ); ?></span>
				<input type="text" name="source" id="rescue404-source" value="<?php echo esc_attr( (string) ( $prefill['source'] ?? '' ) ); ?>" placeholder="/old-page">
			</label>
			<p class="rescue404-field-help"><?php esc_html_e( 'Use the path on this site, such as /old-page.', '404-rescue' ); ?></p>
			<div class="rescue404-suggest-row">
				<div id="rescue404-suggestions" class="rescue404-suggestions" aria-live="polite">
					<?php foreach ( $matches as $match ) : ?>
						<button type="button" class="rescue404-suggestion" data-url="<?php echo esc_url( (string) $match['url'] ); ?>">
							<strong><?php echo esc_html( (string) $match['title'] ); ?></strong>
							<span><?php echo esc_html( (string) $match['label'] ); ?><?php echo '' !== (string) $match['kind'] ? ' · ' . esc_html( (string) $match['kind'] ) : ''; ?></span>
						</button>
					<?php endforeach; ?>
				</div>
			</div>
			<label>
				<span><?php esc_html_e( 'Send them to', '404-rescue' ); ?></span>
				<input type="text" name="destination" id="rescue404-destination" value="<?php echo esc_attr( (string) ( $prefill['destination'] ?? '' ) ); ?>" placeholder="/new-page">
			</label>
			<p class="rescue404-field-help"><?php esc_html_e( 'A page on this site, or a full https:// address on another site. Check a suggested page before you save it.', '404-rescue' ); ?></p>
			<label>
				<span><?php esc_html_e( 'Redirect type', '404-rescue' ); ?></span>
				<select name="redirect_type">
					<?php foreach ( $types as $code => $label ) : ?>
						<option value="<?php echo esc_attr( (string) $code ); ?>" <?php selected( $type, $code ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label class="rescue404-checkline">
				<input type="checkbox" name="is_active" value="1" <?php checked( $active ); ?>>
				<span><?php esc_html_e( 'Turn this redirect on', '404-rescue' ); ?></span>
			</label>
			<div class="rescue404-inline-actions">
				<button type="submit" class="rescue404-button is-primary"><?php echo $edit_id > 0 ? esc_html__( 'Update redirect', '404-rescue' ) : esc_html__( 'Save redirect', '404-rescue' ); ?></button>
				<button type="submit" class="rescue404-button" name="find_page" value="1" id="rescue404-find-page"><?php esc_html_e( 'Find a page on this site', '404-rescue' ); ?></button>
				<?php if ( $edit_id > 0 ) : ?>
					<a class="rescue404-button" href="<?php echo esc_url( \Rescue404\Admin::page_url( 'rescue404-redirects' ) ); ?>"><?php esc_html_e( 'Cancel', '404-rescue' ); ?></a>
				<?php endif; ?>
			</div>
		</form>
	</section>

	<section class="rescue404-card">
		<h2><?php esc_html_e( 'Import from a spreadsheet', '404-rescue' ); ?></h2>
		<p class="rescue404-field-help"><?php esc_html_e( 'Use columns named source, destination, type, and active. Invalid rows are skipped and listed. Nothing in the file is run as code.', '404-rescue' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="rescue404-import">
			<?php wp_nonce_field( 'rescue404_import_redirects' ); ?>
			<input type="hidden" name="action" value="rescue404_import_redirects">
			<input type="file" name="rescue404_csv" accept=".csv,text/csv" required>
			<button type="submit" class="rescue404-button"><?php esc_html_e( 'Import CSV', '404-rescue' ); ?></button>
			<a href="<?php echo esc_url( $sample ); ?>"><?php esc_html_e( 'Download a sample file', '404-rescue' ); ?></a>
		</form>
	</section>

	<nav class="rescue404-tabs" aria-label="<?php esc_attr_e( 'Filter redirects', '404-rescue' ); ?>">
		<?php
		$tabs = array(
			'all'      => __( 'All', '404-rescue' ),
			'active'   => __( 'On', '404-rescue' ),
			'inactive' => __( 'Off', '404-rescue' ),
		);
		foreach ( $tabs as $key => $label ) :
			$tab_args = array( 's' => '' === $search ? null : $search );
			if ( 'all' !== $key ) {
				$tab_args['state'] = $key;
			}
			?>
			<a class="<?php echo $state === $key ? 'is-active' : ''; ?>" href="<?php echo esc_url( \Rescue404\Admin::page_url( 'rescue404-redirects', $tab_args ) ); ?>"><?php echo esc_html( $label ); ?></a>
		<?php endforeach; ?>
	</nav>

	<form class="rescue404-filters" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
		<input type="hidden" name="page" value="rescue404-redirects">
		<?php if ( 'all' !== $state ) : ?>
			<input type="hidden" name="state" value="<?php echo esc_attr( $state ); ?>">
		<?php endif; ?>
		<label>
			<span class="screen-reader-text"><?php esc_html_e( 'Search redirects', '404-rescue' ); ?></span>
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search addresses', '404-rescue' ); ?>">
		</label>
		<button type="submit" class="rescue404-button"><?php esc_html_e( 'Search', '404-rescue' ); ?></button>
	</form>

	<?php if ( ! $items ) : ?>
		<section class="rescue404-empty">
			<h2><?php esc_html_e( 'No redirects yet', '404-rescue' ); ?></h2>
			<p><?php esc_html_e( 'Add one above, or import a CSV file.', '404-rescue' ); ?></p>
		</section>
	<?php else : ?>
		<div class="rescue404-table-wrap">
			<table class="rescue404-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'From', '404-rescue' ); ?></th>
						<th><?php esc_html_e( 'To', '404-rescue' ); ?></th>
						<th><?php esc_html_e( 'Type', '404-rescue' ); ?></th>
						<th><?php esc_html_e( 'Status', '404-rescue' ); ?></th>
						<th><?php esc_html_e( 'Actions', '404-rescue' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $items as $row ) : ?>
						<?php
						$id       = (int) $row['id'];
						$is_on    = ! empty( $row['is_active'] );
						$next     = $is_on ? '0' : '1';
						?>
						<tr data-redirect-id="<?php echo esc_attr( (string) $id ); ?>">
							<td><code><?php echo esc_html( (string) $row['source_path'] ); ?></code></td>
							<td class="rescue404-url"><?php echo esc_html( (string) $row['destination_url'] ); ?></td>
							<td><?php echo esc_html( (string) (int) $row['redirect_type'] ); ?></td>
							<td><span class="rescue404-badge <?php echo $is_on ? 'is-success' : 'is-muted'; ?> rescue404-redirect-status"><?php echo $is_on ? esc_html__( 'On', '404-rescue' ) : esc_html__( 'Off', '404-rescue' ); ?></span></td>
							<td class="rescue404-row-actions">
								<a href="<?php echo esc_url( \Rescue404\Admin::page_url( 'rescue404-redirects', array( 'edit' => $id ) ) ); ?>"><?php esc_html_e( 'Edit', '404-rescue' ); ?></a>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-ajax="toggle">
									<?php wp_nonce_field( 'rescue404_toggle_redirect' ); ?>
									<input type="hidden" name="action" value="rescue404_toggle_redirect">
									<input type="hidden" name="redirect_id" value="<?php echo esc_attr( (string) $id ); ?>">
									<input type="hidden" name="active" value="<?php echo esc_attr( $next ); ?>">
									<button type="submit"><?php echo $is_on ? esc_html__( 'Turn off', '404-rescue' ) : esc_html__( 'Turn on', '404-rescue' ); ?></button>
								</form>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="<?php echo esc_attr( \Rescue404\Admin::confirm_js( __( 'Delete this redirect?', '404-rescue' ) ) ); ?>">
									<?php wp_nonce_field( 'rescue404_delete_redirect' ); ?>
									<input type="hidden" name="action" value="rescue404_delete_redirect">
									<input type="hidden" name="redirect_id" value="<?php echo esc_attr( (string) $id ); ?>">
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
