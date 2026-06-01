<?php
/**
 * Sessions admin template.
 *
 * @package ProseCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap courtflow-admin">
	<h1><?php esc_html_e( 'Intake Sessions', 'prose-core' ); ?></h1>

	<?php if ( $detail ) : ?>
		<h2><?php printf( esc_html__( 'Session #%d Timeline', 'prose-core' ), (int) $detail['id'] ); ?></h2>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=courtflow-sessions' ) ); ?>">&larr; <?php esc_html_e( 'Back', 'prose-core' ); ?></a></p>
		<table class="widefat striped">
			<thead><tr><th>Time</th><th>Type</th><th>Actor</th><th>Payload</th></tr></thead>
			<tbody>
				<?php foreach ( $timeline as $event ) : ?>
					<tr>
						<td><?php echo esc_html( $event['created_at'] ); ?></td>
						<td><?php echo esc_html( $event['event_type'] ); ?></td>
						<td><?php echo esc_html( $event['actor'] ); ?></td>
						<td><pre><?php echo esc_html( wp_json_encode( $event['payload'], JSON_PRETTY_PRINT ) ); ?></pre></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php else : ?>
		<table class="widefat striped">
			<thead><tr><th>ID</th><th>User</th><th>Status</th><th>Workflow</th><th>Created</th></tr></thead>
			<tbody>
				<?php foreach ( $list as $session ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=courtflow-sessions&session_id=' . $session['id'] ) ); ?>"><?php echo esc_html( (string) $session['id'] ); ?></a></td>
						<td><?php echo esc_html( (string) $session['user_id'] ); ?></td>
						<td><?php echo esc_html( $session['status'] ); ?></td>
						<td><?php echo esc_html( (string) ( $session['workflow_id'] ?? '-' ) ); ?></td>
						<td><?php echo esc_html( $session['created_at'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
