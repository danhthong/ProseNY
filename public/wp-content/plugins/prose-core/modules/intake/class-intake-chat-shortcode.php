<?php
/**
 * Intake Chat Shortcode — [prose_intake_chat] + render callback.
 *
 * Frontend MVP widget: account-free, localStorage-only, driven entirely by the
 * public POST /prose/v1/intake endpoint.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Intake;

use ProSe\Core\Ai_Intake\Rest\AI_Intake_Rest_Controller;
use ProSe\Core\Loader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Intake_Chat_Shortcode
 */
final class Intake_Chat_Shortcode {

	/**
	 * Shortcode tag.
	 */
	public const TAG = 'prose_intake_chat';

	/**
	 * Script/style handle.
	 */
	private const HANDLE = 'prose-intake-chat';

	/**
	 * Whether localization data has been attached.
	 *
	 * @var bool
	 */
	private static bool $localized = false;

	/**
	 * Register hooks.
	 *
	 * @param Loader $loader Hook loader.
	 * @return void
	 */
	public function register( Loader $loader ): void {
		$loader->add_action( 'init', $this, 'register_shortcode' );
		$loader->add_action( 'wp_enqueue_scripts', $this, 'register_assets' );
	}

	/**
	 * Register the shortcode.
	 *
	 * @return void
	 */
	public function register_shortcode(): void {
		add_shortcode( self::TAG, array( __CLASS__, 'render' ) );
	}

	/**
	 * Register (not enqueue) the widget assets.
	 *
	 * @return void
	 */
	public function register_assets(): void {
		self::ensure_registered();
	}

	/**
	 * Ensure the widget assets are registered with WordPress.
	 *
	 * @return void
	 */
	private static function ensure_registered(): void {
		if ( ! wp_style_is( self::HANDLE, 'registered' ) ) {
			wp_register_style(
				self::HANDLE,
				PROSE_CORE_URL . 'modules/intake/assets/chat.css',
				array(),
				PROSE_CORE_VERSION
			);
		}

		if ( ! wp_script_is( self::HANDLE, 'registered' ) ) {
			wp_register_script(
				self::HANDLE,
				PROSE_CORE_URL . 'modules/intake/assets/chat.js',
				array(),
				PROSE_CORE_VERSION,
				true
			);
		}
	}

	/**
	 * Shortcode + template render callback.
	 *
	 * Usable both as the [prose_intake_chat] shortcode and called directly from
	 * a theme template (e.g. front-page.php):
	 *
	 *     echo \ProSe\Core\Intake\Intake_Chat_Shortcode::render();
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function render( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'title'       => __( 'Tell me about your situation', 'prose-core' ),
				'placeholder' => __( 'Type your message here…', 'prose-core' ),
			),
			is_array( $atts ) ? $atts : array(),
			self::TAG
		);

		self::ensure_registered();
		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::HANDLE );

		if ( ! self::$localized ) {
			$use_ai = function_exists( 'prose_intake_use_ai_interpreter' ) && prose_intake_use_ai_interpreter();
			$route  = $use_ai
				? AI_Intake_Rest_Controller::NAMESPACE . AI_Intake_Rest_Controller::ROUTE_INTERPRET
				: Intake_Rest_Controller::NAMESPACE . Intake_Rest_Controller::ROUTE;

			wp_localize_script(
				self::HANDLE,
				'ProseIntake',
				array(
					'restUrl'    => esc_url_raw( rest_url( $route ) ),
					'useAi'      => $use_ai,
					'nonce'      => wp_create_nonce( 'wp_rest' ),
					'storageKey' => 'prose_intake_session',
					'strings'    => array(
						'sending'   => __( 'Thinking…', 'prose-core' ),
						'error'     => __( 'Something went wrong. Please try again.', 'prose-core' ),
						'complete'  => __( 'Thanks — I have everything I need for now.', 'prose-core' ),
						'greeting'  => __( 'How can I help with your legal matter today?', 'prose-core' ),
						'reset'     => __( 'Start over', 'prose-core' ),
						'send'      => __( 'Send', 'prose-core' ),
						'completion' => __( 'Intake completion', 'prose-core' ),
					),
				)
			);

			self::$localized = true;
		}

		$placeholder = (string) $atts['placeholder'];
		$title       = (string) $atts['title'];

		ob_start();
		?>
		<section class="prose-intake" data-prose-intake aria-live="polite">
			<header class="prose-intake__header">
				<h2 class="prose-intake__title"><?php echo esc_html( $title ); ?></h2>
				<button type="button" class="prose-intake__reset" data-prose-intake-reset>
					<?php esc_html_e( 'Start over', 'prose-core' ); ?>
				</button>
			</header>

			<div class="prose-intake__progress" data-prose-intake-progress hidden>
				<div class="prose-intake__progress-label">
					<span><?php esc_html_e( 'Intake completion', 'prose-core' ); ?></span>
					<span data-prose-intake-completion-text>0%</span>
				</div>
				<div class="prose-intake__progress-track">
					<div class="prose-intake__progress-bar" data-prose-intake-completion-bar style="width:0%"></div>
				</div>
			</div>

			<div class="prose-intake__transcript" data-prose-intake-transcript></div>

			<form class="prose-intake__form" data-prose-intake-form>
				<label class="screen-reader-text" for="prose-intake-input"><?php esc_html_e( 'Your message', 'prose-core' ); ?></label>
				<textarea
					id="prose-intake-input"
					class="prose-intake__input"
					name="message"
					rows="1"
					placeholder="<?php echo esc_attr( $placeholder ); ?>"
					data-prose-intake-input
					required
				></textarea>
				<button type="submit" class="prose-intake__send" data-prose-intake-send>
					<?php esc_html_e( 'Send', 'prose-core' ); ?>
				</button>
			</form>
		</section>
		<?php

		return (string) ob_get_clean();
	}
}
