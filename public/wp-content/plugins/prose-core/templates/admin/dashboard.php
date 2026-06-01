<?php
/**
 * CourtFlow admin dashboard template.
 *
 * @package ProseCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap courtflow-admin">
	<h1><?php esc_html_e( 'CourtFlow Dashboard', 'prose-core' ); ?></h1>
	<div class="courtflow-stats">
		<div class="courtflow-stat-card">
			<span class="courtflow-stat-value"><?php echo esc_html( (string) $total_sessions ); ?></span>
			<span class="courtflow-stat-label"><?php esc_html_e( 'Intake Sessions', 'prose-core' ); ?></span>
		</div>
		<div class="courtflow-stat-card">
			<span class="courtflow-stat-value"><?php echo esc_html( (string) $sessions_metric ); ?></span>
			<span class="courtflow-stat-label"><?php esc_html_e( 'Sessions Created (metric)', 'prose-core' ); ?></span>
		</div>
	</div>
	<h2><?php esc_html_e( 'Recent Sessions', 'prose-core' ); ?></h2>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'ID', 'prose-core' ); ?></th>
				<th><?php esc_html_e( 'User', 'prose-core' ); ?></th>
				<th><?php esc_html_e( 'Status', 'prose-core' ); ?></th>
				<th><?php esc_html_e( 'Created', 'prose-core' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $recent as $session ) : ?>
				<tr>
					<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=courtflow-sessions&session_id=' . $session['id'] ) ); ?>"><?php echo esc_html( (string) $session['id'] ); ?></a></td>
					<td><?php echo esc_html( (string) $session['user_id'] ); ?></td>
					<td><?php echo esc_html( $session['status'] ); ?></td>
					<td><?php echo esc_html( $session['created_at'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
