<?php
/**
 * Settings admin template.
 *
 * @package ProseCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap courtflow-admin">
	<h1><?php esc_html_e( 'CourtFlow Settings', 'prose-core' ); ?></h1>
	<form method="post" action="options.php">
		<?php settings_fields( 'courtflow_settings' ); ?>
		<table class="form-table">
			<tr>
				<th><label for="openai_api_key"><?php esc_html_e( 'OpenAI API Key', 'prose-core' ); ?></label></th>
				<td><input type="password" name="<?php echo esc_attr( \Prose\Core\Support\Config::OPTION_KEY ); ?>[openai_api_key]" value="<?php echo esc_attr( $settings['openai_api_key'] ); ?>" class="regular-text" autocomplete="off" /></td>
			</tr>
			<tr>
				<th><label for="openai_model"><?php esc_html_e( 'OpenAI Model', 'prose-core' ); ?></label></th>
				<td><input type="text" name="<?php echo esc_attr( \Prose\Core\Support\Config::OPTION_KEY ); ?>[openai_model]" value="<?php echo esc_attr( $settings['openai_model'] ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="session_token_budget"><?php esc_html_e( 'Session Token Budget', 'prose-core' ); ?></label></th>
				<td><input type="number" name="<?php echo esc_attr( \Prose\Core\Support\Config::OPTION_KEY ); ?>[session_token_budget]" value="<?php echo esc_attr( (string) $settings['session_token_budget'] ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="session_cost_budget"><?php esc_html_e( 'Session Cost Budget (USD)', 'prose-core' ); ?></label></th>
				<td><input type="number" step="0.01" name="<?php echo esc_attr( \Prose\Core\Support\Config::OPTION_KEY ); ?>[session_cost_budget]" value="<?php echo esc_attr( (string) $settings['session_cost_budget'] ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="disclaimer_text"><?php esc_html_e( 'Disclaimer Text', 'prose-core' ); ?></label></th>
				<td><textarea name="<?php echo esc_attr( \Prose\Core\Support\Config::OPTION_KEY ); ?>[disclaimer_text]" rows="4" class="large-text"><?php echo esc_textarea( $settings['disclaimer_text'] ); ?></textarea></td>
			</tr>
			<tr>
				<th><label for="rate_limit_per_min"><?php esc_html_e( 'Rate Limit (per minute)', 'prose-core' ); ?></label></th>
				<td><input type="number" name="<?php echo esc_attr( \Prose\Core\Support\Config::OPTION_KEY ); ?>[rate_limit_per_min]" value="<?php echo esc_attr( (string) $settings['rate_limit_per_min'] ); ?>" /></td>
			</tr>
		</table>
		<?php submit_button(); ?>
	</form>
</div>
