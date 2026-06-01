<?php
/**
 * [courtflow_workspace] shortcode — renders the workspace UI anywhere.
 *
 * @package ProseCore
 */

namespace Prose\Core\Shortcodes;

use Prose\Core\Security\Disclaimer;

final class Workspace {

	public static function register(): void {
		add_shortcode( 'courtflow_workspace', array( self::class, 'render' ) );
	}

	/**
	 * @param array<string, mixed>|string $atts
	 */
	public static function render( $atts = array() ): string {
		if ( wp_style_is( 'courtflow-workspace', 'registered' ) ) {
			wp_enqueue_style( 'courtflow-workspace' );
		}

		if ( wp_script_is( 'courtflow-workspace', 'registered' ) ) {
			wp_enqueue_script( 'courtflow-workspace' );
		}

		if ( function_exists( 'ProseApp\\Enqueue\\localize' ) ) {
			\ProseApp\Enqueue\localize();
		}

		if ( function_exists( 'ProseApp\\Enqueue\\enqueue_inter_font' ) ) {
			\ProseApp\Enqueue\enqueue_inter_font();
		}

		$disclaimer = Disclaimer::render_html();
		$disclaimer = str_replace( 'courtflow-disclaimer', 'courtflow-disclaimer cf-legal-notice', $disclaimer );

		ob_start();
		?>
		<div class="courtflow-workspace cf-shell" data-wp-interactive="courtflow" id="cf-workspace-root">
			<a class="cf-skip-link" href="#courtflow-chat-input"><?php esc_html_e( 'Skip to chat input', 'prose-core' ); ?></a>
			<?php echo $disclaimer; ?>
			<?php
			if ( function_exists( 'get_template_part' ) ) {
				get_template_part( 'template-parts/courtflow', 'header' );
			}
			?>
			<div class="cf-workspace-body courtflow-grid">
				<div class="courtflow-col courtflow-col-left" id="cf-col-left">
					<?php
					if ( function_exists( 'get_template_part' ) ) {
						get_template_part( 'template-parts/courtflow', 'progress-rail' );
					}
					?>
				</div>
				<div class="courtflow-col courtflow-col-center" id="cf-col-center">
					<?php
					if ( function_exists( 'get_template_part' ) ) {
						get_template_part( 'template-parts/courtflow', 'intake-chat', array( 'session_id' => 0 ) );
					}
					?>
				</div>
				<div class="courtflow-col courtflow-col-right" id="cf-col-right">
					<?php
					if ( function_exists( 'get_template_part' ) ) {
						get_template_part( 'template-parts/courtflow', 'context-panel' );
					}
					?>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
