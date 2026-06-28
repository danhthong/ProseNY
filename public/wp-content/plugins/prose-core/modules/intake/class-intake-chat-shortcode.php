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
use ProSe\Core\Documents\Rest\Documents_Rest_Controller;
use ProSe\Core\Loader;
use ProSe\Core\PackageBuilder\Package_Preview_Shortcode;
use ProSe\Core\PackageBuilder\Rest\Package_Builder_Rest_Controller;

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
	 * Whether the chat widget should use the OpenAI-backed interpreter endpoint.
	 *
	 * Defaults to true (100% OpenAI-driven intake). The AI module's helper
	 * function may not be loaded at front-end render time, so we fall back to
	 * the same filter with a true default — the REST route is registered
	 * independently on rest_api_init and is always available for the POST.
	 *
	 * @return bool
	 */
	private static function use_ai_interpreter(): bool {
		if ( function_exists( 'prose_intake_use_ai_interpreter' ) ) {
			return (bool) prose_intake_use_ai_interpreter();
		}

		/** This filter is documented in modules/ai-intake/class-ai-intake-module.php */
		return (bool) apply_filters( 'prose_intake_use_ai_interpreter', true );
	}

	/**
	 * Register hooks.
	 *
	 * @param Loader $loader Hook loader.
	 * @return void
	 */
	public function register( Loader $loader ): void {
		$loader->add_action( 'init', $this, 'register_shortcode' );
		$loader->add_action( 'wp_enqueue_scripts', $this, 'register_assets' );
		$loader->add_action( 'wp_logout', $this, 'flag_intake_session_reset' );
	}

	/**
	 * Mark the browser intake session for clearing after logout.
	 *
	 * localStorage survives WordPress logout redirects; a short-lived cookie tells
	 * the widget to wipe the stored conversation on the next page load.
	 *
	 * @return void
	 */
	public function flag_intake_session_reset(): void {
		if ( headers_sent() ) {
			return;
		}

		setcookie(
			'prose_clear_intake',
			'1',
			time() + 600,
			COOKIEPATH ? COOKIEPATH : '/',
			COOKIE_DOMAIN,
			is_ssl(),
			false
		);
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
				prose_core_asset_version( 'modules/intake/assets/chat.css' )
			);
		}

		if ( ! wp_script_is( self::HANDLE, 'registered' ) ) {
			wp_register_script(
				self::HANDLE,
				PROSE_CORE_URL . 'modules/intake/assets/chat.js',
				array(),
				prose_core_asset_version( 'modules/intake/assets/chat.js' ),
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
				'layout'      => '',
			),
			is_array( $atts ) ? $atts : array(),
			self::TAG
		);

		self::ensure_registered();
		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::HANDLE );

		if ( ! self::$localized ) {
			$use_ai = self::use_ai_interpreter();
			$route  = $use_ai
				? AI_Intake_Rest_Controller::NAMESPACE . AI_Intake_Rest_Controller::ROUTE_INTERPRET
				: Intake_Rest_Controller::NAMESPACE . Intake_Rest_Controller::ROUTE;

			wp_localize_script(
				self::HANDLE,
				'ProseIntake',
				array(
					'restUrl'      => esc_url_raw( rest_url( $route ) ),
					'actionsUrl'   => esc_url_raw( rest_url( Intake_Rest_Controller::NAMESPACE . Intake_Rest_Controller::ROUTE_ACTIONS ) ),
					'completeStageUrl' => esc_url_raw( rest_url( Intake_Rest_Controller::NAMESPACE . Intake_Rest_Controller::ROUTE_COMPLETE_STAGE ) ),
					'mergedPdfUrl' => esc_url_raw( rest_url( Package_Builder_Rest_Controller::NAMESPACE . Package_Builder_Rest_Controller::ROUTE_MERGED_PDF ) ),
					'documentsUploadUrl' => esc_url_raw( rest_url( Documents_Rest_Controller::NAMESPACE . Documents_Rest_Controller::ROUTE_UPLOAD ) ),
					'maxUploadBytes' => Documents_Rest_Controller::MAX_UPLOAD_BYTES,
					'useAi'          => $use_ai,
					'loggedIn'       => is_user_logged_in(),
					'userId'         => get_current_user_id(),
					'cookiePath'     => COOKIEPATH ? COOKIEPATH : '/',
					'conversationRestUrl' => esc_url_raw( rest_url( 'prose/v1/me/conversations/session/' ) ),
					'nonce'          => wp_create_nonce( 'wp_rest' ),
					'storageKey'     => 'prose_intake_session',
					'strings'        => array(
						'sending'         => __( 'Thinking…', 'prose-core' ),
						'error'           => __( 'Something went wrong. Please try again.', 'prose-core' ),
						'review'          => __( 'We need a little more help with your intake. A team member may follow up.', 'prose-core' ),
						'complete'        => __( 'Based on the information you\'ve provided, I identified the appropriate filing package for your case. You can review the next steps below or download the required court forms.', 'prose-core' ),
						'greeting'        => __( 'How can I help with your legal matter today?', 'prose-core' ),
						'reset'           => __( 'Start over', 'prose-core' ),
						'send'            => __( 'Send', 'prose-core' ),
						'completion'      => __( 'Intake completion', 'prose-core' ),
						'caseActions'     => __( 'Case Actions', 'prose-core' ),
						'caseSummary'     => __( 'Case Summary', 'prose-core' ),
						'getDocuments'    => __( 'Get Documents', 'prose-core' ),
						'viewSummary'     => __( 'View Case Summary', 'prose-core' ),
						'hideSummary'     => __( 'Hide Case Summary', 'prose-core' ),
						'downloading'     => __( 'Preparing download…', 'prose-core' ),
						'downloadError'   => __( 'Documents are not available for download yet.', 'prose-core' ),
						'finishIntake'    => __( 'Answer a few routing questions in English to enable blank form download.', 'prose-core' ),
						'uploadDocument'  => __( 'Upload court document', 'prose-core' ),
						'uploadingDocument' => __( 'Reviewing your document…', 'prose-core' ),
						'uploadError'     => __( 'Could not process that document. Please try a PDF under 10 MB.', 'prose-core' ),
						'uploadTypeError' => __( 'Only PDF court documents are supported right now.', 'prose-core' ),
						'uploadSizeError' => __( 'PDF must be 10 MB or smaller.', 'prose-core' ),
						'uploadedFile'    => __( 'Uploaded document:', 'prose-core' ),
						'documentIdentifiedPrefix' => __( 'This looks like a', 'prose-core' ),
						'documentUnknown' => __( 'I could not automatically identify this document from the PDF. I will ask a few questions to figure out what kind of papers you received.', 'prose-core' ),
						'exportDebug'     => __( 'Export debug', 'prose-core' ),
						'exportCopied'    => __( 'Copied to clipboard', 'prose-core' ),
						'exportDownloaded' => __( 'Debug export downloaded', 'prose-core' ),
						'exportError'     => __( 'Could not export conversation.', 'prose-core' ),
					),
				)
			);

			self::$localized = true;
		}

		$placeholder     = (string) $atts['placeholder'];
		$title           = (string) $atts['title'];
		$is_homepage     = 'homepage' === (string) $atts['layout'];
		$input_id        = $is_homepage ? 'prose-intake-input-home' : 'prose-intake-input';
		$section_classes = 'prose-intake' . ( $is_homepage ? ' prose-intake--homepage' : '' );

		ob_start();

		if ( $is_homepage ) {
			self::render_homepage_open( $section_classes );
			self::render_sidebar( false );
			self::render_homepage_chat_open();
		} else {
			echo '<section class="' . esc_attr( $section_classes ) . '" data-prose-intake aria-live="polite">';
		}

		self::render_chat_header( $title, $is_homepage );
		?>
			<div class="prose-intake__transcript" data-prose-intake-transcript></div>
		<?php

		if ( ! $is_homepage ) {
			self::render_inline_actions( true );
		}

		self::render_chat_form( $placeholder, $input_id );

		if ( $is_homepage ) {
			$footer = apply_filters( 'prose_intake_homepage_chat_footer', '' );
			if ( is_string( $footer ) && '' !== $footer ) {
				echo $footer; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			self::render_homepage_close();
		} else {
			echo '</section>';
		}

		return (string) ob_get_clean();
	}

	/**
	 * Open homepage layout wrapper and left sidebar column.
	 *
	 * @param string $section_classes Root section class list.
	 * @return void
	 */
	private static function render_homepage_open( string $section_classes ): void {
		?>
		<section class="<?php echo esc_attr( $section_classes ); ?>" data-prose-intake data-prose-intake-layout="homepage" aria-live="polite">
			<div class="prose-homepage-layout">
		<?php
	}

	/**
	 * Render the left sidebar (Case Summary, Case Actions, package preview).
	 *
	 * @param bool $summary_hidden Whether the summary panel starts hidden.
	 * @return void
	 */
	private static function render_sidebar( bool $summary_hidden ): void {
		?>
				<aside class="prose-homepage-layout__sidebar">
					<div class="prose-intake__summary prose-intake__summary--sidebar" data-prose-intake-summary<?php echo $summary_hidden ? ' hidden' : ''; ?>>
						<h4 class="prose-intake__summary-title"><?php esc_html_e( 'Case Summary', 'prose-core' ); ?></h4>
						<ul class="prose-intake__summary-list" data-prose-intake-summary-list></ul>
					</div>
					<div class="prose-intake__actions" data-prose-intake-actions hidden>
						<h3 class="prose-intake__actions-title"><?php esc_html_e( 'Case Actions', 'prose-core' ); ?></h3>
						<div class="prose-intake__action-buttons" data-prose-intake-download-buttons hidden></div>
					</div>
					<div class="prose-homepage-layout__package">
						<?php
						if ( class_exists( Package_Preview_Shortcode::class ) ) {
							echo Package_Preview_Shortcode::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						}
						?>
					</div>
				</aside>
		<?php
	}

	/**
	 * Open the homepage chat column and hero copy.
	 *
	 * @return void
	 */
	private static function render_homepage_chat_open(): void {
		?>
				<div class="prose-homepage-layout__chat">
					<h1 class="prose-homepage-layout__hero">
						<?php esc_html_e( 'How can I help you today?', 'prose-core' ); ?>
					</h1>
					<p class="prose-homepage-layout__subtitle">
						<?php esc_html_e( 'Guided intake for NYC divorce and Family Court matters — Supreme Court matrimonial filings, custody, support, and orders of protection.', 'prose-core' ); ?>
					</p>
					<div class="prose-intake__chat-card">
		<?php
	}

	/**
	 * Close homepage layout wrappers.
	 *
	 * @return void
	 */
	private static function render_homepage_close(): void {
		?>
					</div>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * Render the widget header row.
	 *
	 * @param string $title       Widget title.
	 * @param bool   $is_homepage Whether the homepage layout is active.
	 * @return void
	 */
	private static function render_chat_header( string $title, bool $is_homepage ): void {
		?>
			<header class="prose-intake__header">
				<h2 class="prose-intake__title"><?php echo esc_html( $title ); ?></h2>
				<div class="prose-intake__header-actions">
					<button type="button" class="prose-intake__export" data-prose-intake-export hidden>
						<?php esc_html_e( 'Export debug', 'prose-core' ); ?>
					</button>
					<button type="button" class="prose-intake__reset" data-prose-intake-reset>
						<?php esc_html_e( 'Start over', 'prose-core' ); ?>
					</button>
				</div>
			</header>
		<?php
	}

	/**
	 * Render inline Case Actions block used by the default single-column layout.
	 *
	 * @param bool $summary_hidden Whether the summary panel starts hidden.
	 * @return void
	 */
	private static function render_inline_actions( bool $summary_hidden ): void {
		?>
			<aside class="prose-intake__actions" data-prose-intake-actions hidden>
				<h3 class="prose-intake__actions-title"><?php esc_html_e( 'Case Actions', 'prose-core' ); ?></h3>
				<div class="prose-intake__summary" data-prose-intake-summary<?php echo $summary_hidden ? ' hidden' : ''; ?>>
					<h4 class="prose-intake__summary-title"><?php esc_html_e( 'Case Summary', 'prose-core' ); ?></h4>
					<ul class="prose-intake__summary-list" data-prose-intake-summary-list></ul>
				</div>
				<div class="prose-intake__action-buttons" data-prose-intake-download-buttons hidden></div>
				<button type="button" class="prose-intake__action prose-intake__action--secondary" data-prose-intake-toggle-summary hidden>
					<?php esc_html_e( 'View Case Summary', 'prose-core' ); ?>
				</button>
			</aside>
		<?php
	}

	/**
	 * Render the message input form.
	 *
	 * @param string $placeholder Input placeholder.
	 * @param string $input_id    Textarea id attribute.
	 * @return void
	 */
	private static function render_chat_form( string $placeholder, string $input_id ): void {
		?>
			<form class="prose-intake__form" data-prose-intake-form>
				<input
					type="file"
					class="screen-reader-text"
					accept="application/pdf,.pdf"
					data-prose-intake-file
				/>
				<button
					type="button"
					class="prose-intake__upload"
					data-prose-intake-upload
					title="<?php esc_attr_e( 'Upload a court PDF', 'prose-core' ); ?>"
					aria-label="<?php esc_attr_e( 'Upload court document', 'prose-core' ); ?>"
				>
					<?php esc_html_e( 'Upload', 'prose-core' ); ?>
				</button>
				<label class="screen-reader-text" for="<?php echo esc_attr( $input_id ); ?>"><?php esc_html_e( 'Your message', 'prose-core' ); ?></label>
				<textarea
					id="<?php echo esc_attr( $input_id ); ?>"
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
		<?php
	}
}
