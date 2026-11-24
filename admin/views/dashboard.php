<?php
/**
 * Dashboard screen.
 *
 * @package Rescue404
 *
 * @var array<string, int>                               $args
 * @var array<string, int>                               $counts
 * @var array<int, array<string, mixed>>                 $recent
 * @var array<int, array{date: string, label: string, count: int, height: int}> $days
 * @var int                                              $max
 */

defined( 'ABSPATH' ) || exit;

$counts = isset( $args['counts'] ) && is_array( $args['counts'] ) ? $args['counts'] : array();
$recent = isset( $args['recent'] ) && is_array( $args['recent'] ) ? $args['recent'] : array();
$days   = isset( $args['days'] ) && is_array( $args['days'] ) ? $args['days'] : array();
$hits   = (int) ( $counts['hits'] ?? 0 );
$rows   = (int) ( $counts['rows'] ?? 0 );
$export = wp_nonce_url( admin_url( 'admin-post.php?action=rescue404_export_errors' ), 'rescue404_export_errors' );
?>
<div class="wrap rescue404">
	<header class="rescue404-header">
		<div>
			<h1><?php esc_html_e( '404 Rescue', '404-rescue' ); ?></h1>
			<p class="rescue404-lead"><?php esc_html_e( 'See which pages visitors could not find, and send them somewhere useful.', '404-rescue' ); ?></p>
		</div>
		<div class="rescue404-actions">
			<a class="rescue404-button" href="<?php echo esc_url( \Rescue404\Admin::page_url( 'rescue404-errors' ) ); ?>"><?php esc_html_e( 'View all errors', '404-rescue' ); ?></a>
			<a class="rescue404-button is-primary" href="<?php echo esc_url( \Rescue404\Admin::page_url( 'rescue404-redirects' ) . '#rescue404-add-redirect' ); ?>"><?php esc_html_e( 'Add redirect', '404-rescue' ); ?></a>
		</div>
	</header>

	<?php \Rescue404\Admin::render_notices(); ?>

	<section class="rescue404-stats" aria-label="<?php esc_attr_e( 'Summary', '404-rescue' ); ?>">
		<article class="rescue404-stat">
			<p><?php esc_html_e( 'Total 404 visits', '404-rescue' ); ?></p>
			<strong><?php echo esc_html( number_format_i18n( $hits ) ); ?></strong>
			<span><?php esc_html_e( 'Times a visitor opened a missing page', '404-rescue' ); ?></span>
		</article>
		<article class="rescue404-stat">
			<p><?php esc_html_e( 'Needs attention', '404-rescue' ); ?></p>
			<strong><?php echo esc_html( number_format_i18n( (int) ( $counts['unresolved'] ?? 0 ) ) ); ?></strong>
			<span><?php esc_html_e( 'Different broken addresses still waiting', '404-rescue' ); ?></span>
		</article>
		<article class="rescue404-stat">
			<p><?php esc_html_e( 'Active redirects', '404-rescue' ); ?></p>
			<strong><?php echo esc_html( number_format_i18n( (int) ( $counts['active'] ?? 0 ) ) ); ?></strong>
			<span><?php esc_html_e( 'Old addresses that send people to a new page', '404-rescue' ); ?></span>
		</article>
		<article class="rescue404-stat">
			<p><?php esc_html_e( 'Redirects added', '404-rescue' ); ?></p>
			<strong><?php echo esc_html( number_format_i18n( (int) ( $counts['resolved'] ?? 0 ) ) ); ?></strong>
			<span><?php esc_html_e( 'Broken addresses you have already handled', '404-rescue' ); ?></span>
		</article>
	</section>

	<?php if ( $rows < 1 ) : ?>
		<section class="rescue404-empty">
			<h2><?php esc_html_e( 'No missing pages yet', '404-rescue' ); ?></h2>
			<p><?php esc_html_e( 'When someone visits a page that does not exist, it will show up here. Try opening a made-up address on your site, then come back.', '404-rescue' ); ?></p>
			<p><a class="rescue404-button" href="<?php echo esc_url( \Rescue404\Admin::page_url( 'rescue404-settings' ) ); ?>"><?php esc_html_e( 'Configure settings', '404-rescue' ); ?></a></p>
		</section>
	<?php else : ?>
		<div class="rescue404-grid">
			<section class="rescue404-card">
				<h2><?php esc_html_e( 'Last 7 days', '404-rescue' ); ?></h2>
				<div class="rescue404-chart" role="img" aria-label="<?php esc_attr_e( 'Missing-page visits for the last seven days', '404-rescue' ); ?>">
					<?php foreach ( $days as $day ) : ?>
						<div class="rescue404-chart-col">
							<span class="rescue404-chart-count"><?php echo esc_html( number_format_i18n( (int) $day['count'] ) ); ?></span>
							<div class="rescue404-chart-bar<?php echo 0 === (int) $day['count'] ? ' is-zero' : ''; ?>" style="height: <?php echo esc_attr( (string) (int) $day['height'] ); ?>%"></div>
							<span class="rescue404-chart-label"><?php echo esc_html( (string) $day['label'] ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
				<table class="screen-reader-text">
					<caption><?php esc_html_e( 'Missing-page visits for the last seven days', '404-rescue' ); ?></caption>
					<tbody>
						<?php foreach ( $days as $day ) : ?>
							<tr>
								<th scope="row"><?php echo esc_html( (string) $day['date'] ); ?></th>
								<td><?php echo esc_html( number_format_i18n( (int) $day['count'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</section>

			<section class="rescue404-card">
				<h2><?php esc_html_e( 'Quick actions', '404-rescue' ); ?></h2>
				<div class="rescue404-action-list">
					<a class="rescue404-button" href="<?php echo esc_url( \Rescue404\Admin::page_url( 'rescue404-errors' ) ); ?>"><?php esc_html_e( 'View all errors', '404-rescue' ); ?></a>
					<a class="rescue404-button" href="<?php echo esc_url( \Rescue404\Admin::page_url( 'rescue404-redirects' ) . '#rescue404-add-redirect' ); ?>"><?php esc_html_e( 'Add redirect', '404-rescue' ); ?></a>
					<a class="rescue404-button" href="<?php echo esc_url( $export ); ?>"><?php esc_html_e( 'Export errors', '404-rescue' ); ?></a>
					<a class="rescue404-button" href="<?php echo esc_url( \Rescue404\Admin::page_url( 'rescue404-settings' ) ); ?>"><?php esc_html_e( 'Configure settings', '404-rescue' ); ?></a>
				</div>
			</section>
		</div>

		<section class="rescue404-card">
			<h2><?php esc_html_e( 'Recent 404 activity', '404-rescue' ); ?></h2>
			<div class="rescue404-table-wrap">
				<table class="rescue404-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Broken URL', '404-rescue' ); ?></th>
							<th><?php esc_html_e( 'Visits', '404-rescue' ); ?></th>
							<th><?php esc_html_e( 'First seen', '404-rescue' ); ?></th>
							<th><?php esc_html_e( 'Last seen', '404-rescue' ); ?></th>
							<th><?php esc_html_e( 'Suggested page', '404-rescue' ); ?></th>
							<th><?php esc_html_e( 'Action', '404-rescue' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $recent as $row ) : ?>
							<?php
							$path       = (string) $row['request_path'];
							$suggestion = (string) $row['suggested_destination'];
							$confidence = (string) $row['suggestion_confidence'];
							$create     = \Rescue404\Admin::page_url(
								'rescue404-redirects',
								array(
									'source'      => $path,
									'destination' => $suggestion,
								)
							);
							?>
							<tr>
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
								<td><a href="<?php echo esc_url( $create ); ?>"><?php esc_html_e( 'Create redirect', '404-rescue' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</section>
	<?php endif; ?>

	<?php \Rescue404\Admin::donate_note(); ?>
</div>
