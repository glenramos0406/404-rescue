<?php
/**
 * Settings screen.
 *
 * @package Rescue404
 *
 * @var array<string, mixed> $args
 */

defined( 'ABSPATH' ) || exit;

$settings = isset( $args['settings'] ) && is_array( $args['settings'] ) ? $args['settings'] : array();
$days     = (int) ( $settings['retention_days'] ?? 30 );
?>
<div class="wrap rescue404">
	<header class="rescue404-header">
		<div>
			<h1><?php esc_html_e( 'Settings', '404-rescue' ); ?></h1>
			<p class="rescue404-lead"><?php esc_html_e( 'Logging and redirects are on by default. Change only what you need.', '404-rescue' ); ?></p>
		</div>
	</header>

	<?php \Rescue404\Admin::render_notices(); ?>

	<form method="post" action="options.php" class="rescue404-card rescue404-form">
		<?php settings_fields( 'rescue404_settings_group' ); ?>

		<fieldset>
			<legend><?php esc_html_e( 'General', '404-rescue' ); ?></legend>
			<label class="rescue404-checkline">
				<input type="checkbox" name="<?php echo esc_attr( \Rescue404\Settings::OPTION ); ?>[monitor_enabled]" value="1" <?php checked( ! empty( $settings['monitor_enabled'] ) ); ?>>
				<span>
					<strong><?php esc_html_e( 'Record missing pages', '404-rescue' ); ?></strong>
					<small><?php esc_html_e( 'Keep a log when a visitor opens a page that does not exist.', '404-rescue' ); ?></small>
				</span>
			</label>
			<label class="rescue404-checkline">
				<input type="checkbox" name="<?php echo esc_attr( \Rescue404\Settings::OPTION ); ?>[redirects_enabled]" value="1" <?php checked( ! empty( $settings['redirects_enabled'] ) ); ?>>
				<span>
					<strong><?php esc_html_e( 'Send visitors to the new page', '404-rescue' ); ?></strong>
					<small><?php esc_html_e( 'Use the redirects you have saved. Turn this off to keep the rules without using them.', '404-rescue' ); ?></small>
				</span>
			</label>
		</fieldset>

		<label>
			<span><?php esc_html_e( 'Addresses to ignore', '404-rescue' ); ?></span>
			<textarea name="<?php echo esc_attr( \Rescue404\Settings::OPTION ); ?>[excluded_patterns]" rows="6"><?php echo esc_textarea( (string) ( $settings['excluded_patterns'] ?? '' ) ); ?></textarea>
		</label>
		<p class="rescue404-field-help"><?php esc_html_e( 'One address per line. Use * as a wildcard, for example /temporary/*. These visits are not recorded. A redirect you create for the same address still works. A line of just * is ignored, so you cannot hide the whole site by accident.', '404-rescue' ); ?></p>

		<label>
			<span><?php esc_html_e( 'How long to keep logs', '404-rescue' ); ?></span>
			<select name="<?php echo esc_attr( \Rescue404\Settings::OPTION ); ?>[retention_days]">
				<option value="7" <?php selected( $days, 7 ); ?>><?php esc_html_e( '7 days', '404-rescue' ); ?></option>
				<option value="30" <?php selected( $days, 30 ); ?>><?php esc_html_e( '30 days', '404-rescue' ); ?></option>
				<option value="60" <?php selected( $days, 60 ); ?>><?php esc_html_e( '60 days', '404-rescue' ); ?></option>
				<option value="90" <?php selected( $days, 90 ); ?>><?php esc_html_e( '90 days', '404-rescue' ); ?></option>
				<option value="0" <?php selected( $days, 0 ); ?>><?php esc_html_e( 'Keep until I delete them', '404-rescue' ); ?></option>
			</select>
		</label>
		<p class="rescue404-field-help"><?php esc_html_e( 'Old broken-address logs are removed on a daily cleanup. Redirect rules are never removed by cleanup.', '404-rescue' ); ?></p>

		<label>
			<span><?php esc_html_e( 'Maximum stored broken addresses', '404-rescue' ); ?></span>
			<input type="number" min="100" max="50000" step="1" name="<?php echo esc_attr( \Rescue404\Settings::OPTION ); ?>[max_errors]" value="<?php echo esc_attr( (string) (int) ( $settings['max_errors'] ?? 5000 ) ); ?>">
		</label>
		<p class="rescue404-field-help"><?php esc_html_e( 'When the log is full, new addresses are not added until you raise the limit or old ones are removed. Repeat visits to an address already in the log are still counted.', '404-rescue' ); ?></p>

		<label class="rescue404-checkline">
			<input type="checkbox" name="<?php echo esc_attr( \Rescue404\Settings::OPTION ); ?>[email_notifications]" value="1" <?php checked( ! empty( $settings['email_notifications'] ) ); ?>>
			<span>
				<strong><?php esc_html_e( 'Email reports', '404-rescue' ); ?></strong>
				<small><?php echo esc_html( apply_filters( 'rescue404_email_help', __( 'Your choice is saved for a later version. This version does not send email.', '404-rescue' ) ) ); ?></small>
			</span>
		</label>

		<label class="rescue404-checkline">
			<input type="checkbox" name="<?php echo esc_attr( \Rescue404\Settings::OPTION ); ?>[delete_data]" value="1" <?php checked( ! empty( $settings['delete_data'] ) ); ?>>
			<span>
				<strong><?php esc_html_e( 'Delete all 404 Rescue data if I delete this plugin', '404-rescue' ); ?></strong>
				<small><?php esc_html_e( 'Leave this off to keep logs and redirects in the database. Deactivating the plugin never deletes them.', '404-rescue' ); ?></small>
			</span>
		</label>

		<?php submit_button( __( 'Save settings', '404-rescue' ) ); ?>
		<p class="rescue404-version"><?php echo esc_html( sprintf( /* translators: %s: version number */ __( '404 Rescue %s', '404-rescue' ), RESCUE404_VERSION ) ); ?></p>
	</form>
	<?php \Rescue404\Admin::donate_note(); ?>
</div>
