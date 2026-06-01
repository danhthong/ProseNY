<?php
/**
 * AI audit log admin template.
 *
 * @package ProseCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap courtflow-admin">
	<h1><?php esc_html_e( 'AI Audit Log', 'prose-core' ); ?></h1>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Time', 'prose-core' ); ?></th>
				<th><?php esc_html_e( 'Agent', 'prose-core' ); ?></th>
				<th><?php esc_html_e( 'Model', 'prose-core' ); ?></th>
				<th><?php esc_html_e( 'Tokens', 'prose-core' ); ?></th>
				<th><?php esc_html_e( 'Cost', 'prose-core' ); ?></th>
				<th><?php esc_html_e( 'Latency', 'prose-core' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $entries as $entry ) : ?>
				<tr>
					<td><?php echo esc_html( $entry['created_at'] ); ?></td>
					<td><?php echo esc_html( $entry['agent'] ); ?></td>
					<td><?php echo esc_html( $entry['model'] ); ?></td>
					<td><?php echo esc_html( (string) ( (int) $entry['tokens_in'] + (int) $entry['tokens_out'] ) ); ?></td>
					<td>$<?php echo esc_html( number_format( (float) $entry['cost_usd'], 4 ) ); ?></td>
					<td><?php echo esc_html( (string) $entry['latency_ms'] ); ?>ms</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
