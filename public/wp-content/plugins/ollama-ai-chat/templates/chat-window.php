<?php
/**
 * Chat window template.
 *
 * @package Ollama_AI_Chat
 *
 * @var array $config Chat instance configuration.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div
	id="<?php echo esc_attr( $config['id'] ); ?>"
	class="ollama-ai-chat-root<?php echo 'inline' === $config['layout'] ? ' ollama-ai-chat-root--inline' : ''; ?>"
	data-ollama-chat
	data-instance-id="<?php echo esc_attr( $config['id'] ); ?>"
	data-title="<?php echo esc_attr( $config['title'] ); ?>"
	data-height="<?php echo esc_attr( $config['height'] ); ?>"
	data-theme="<?php echo esc_attr( $config['theme'] ); ?>"
	data-model="<?php echo esc_attr( $config['model'] ); ?>"
	data-layout="<?php echo esc_attr( $config['layout'] ); ?>"
	<?php if ( 'inline' === $config['layout'] ) : ?>
		style="min-height: <?php echo esc_attr( $config['height'] ); ?>;"
	<?php endif; ?>
></div>
