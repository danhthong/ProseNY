<?php
/**
 * Package Preview Shortcode — [prose_package_preview] + render callback.
 *
 * Renders the package summary card the front-page chat shows once intake
 * resolves a workflow. Preview-only (no ZIP); driven by POST /prose/v1/package/preview.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\PackageBuilder;

use ProSe\Core\Loader;
use ProSe\Core\PackageBuilder\Rest\Package_Builder_Rest_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Package_Preview_Shortcode
 */
final class Package_Preview_Shortcode {

	/**
	 * Shortcode tag.
	 */
	public const TAG = 'prose_package_preview';

	/**
	 * Script/style handle.
	 */
	private const HANDLE = 'prose-package-preview';

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
	 * Ensure assets are registered.
	 *
	 * @return void
	 */
	private static function ensure_registered(): void {
		if ( ! wp_style_is( self::HANDLE, 'registered' ) ) {
			wp_register_style(
				self::HANDLE,
				PROSE_CORE_URL . 'modules/packagebuilder/assets/package-preview.css',
				array(),
				PROSE_CORE_VERSION
			);
		}

		if ( ! wp_script_is( self::HANDLE, 'registered' ) ) {
			wp_register_script(
				self::HANDLE,
				PROSE_CORE_URL . 'modules/packagebuilder/assets/package-preview.js',
				array(),
				PROSE_CORE_VERSION,
				true
			);
		}
	}

	/**
	 * Shortcode + template render callback.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function render( $atts = array() ): string {
		unset( $atts );

		self::ensure_registered();
		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::HANDLE );

		if ( ! self::$localized ) {
			wp_localize_script(
				self::HANDLE,
				'ProsePackagePreview',
				array(
					'restUrl'    => esc_url_raw( rest_url( Package_Builder_Rest_Controller::NAMESPACE . Package_Builder_Rest_Controller::ROUTE_PREVIEW ) ),
					'buildUrl'   => esc_url_raw( rest_url( Package_Builder_Rest_Controller::NAMESPACE . Package_Builder_Rest_Controller::ROUTE_BUILD ) ),
					'mergedUrl'  => esc_url_raw( rest_url( Package_Builder_Rest_Controller::NAMESPACE . Package_Builder_Rest_Controller::ROUTE_MERGED_PDF ) ),
					'nonce'      => wp_create_nonce( 'wp_rest' ),
					'storageKey' => 'prose_intake_session',
					'strings'    => array(
						'heading'    => __( 'Your document package', 'prose-core' ),
						'required'   => __( 'Required', 'prose-core' ),
						'optional'   => __( 'Optional', 'prose-core' ),
						'ready'      => __( 'Ready', 'prose-core' ),
						'pending'    => __( 'Preparing', 'prose-core' ),
						'incomplete' => __( 'Some required forms are not ready yet.', 'prose-core' ),
						'download'   => __( 'Download current step forms (PDF)', 'prose-core' ),
						'building'   => __( 'Preparing your PDF…', 'prose-core' ),
						'error'      => __( 'Could not load your package. Please try again.', 'prose-core' ),
						'noPdf'      => __( 'Blank forms for this matter are not available to download yet.', 'prose-core' ),
						'viewForm'   => __( 'View form details', 'prose-core' ),
					),
				)
			);

			self::$localized = true;
		}

		ob_start();
		?>
		<section class="prose-package" data-prose-package hidden aria-live="polite">
			<header class="prose-package__header">
				<h2 class="prose-package__title" data-prose-package-title>
					<?php esc_html_e( 'Your document package', 'prose-core' ); ?>
				</h2>
				<span class="prose-package__status" data-prose-package-status></span>
			</header>

			<div class="prose-package__summary" data-prose-package-summary></div>
			<div class="prose-package__stages" data-prose-package-stages></div>

			<footer class="prose-package__footer">
				<button type="button" class="prose-package__download" data-prose-package-download hidden>
					<?php esc_html_e( 'Download current step forms (PDF)', 'prose-core' ); ?>
				</button>
			</footer>
		</section>
		<?php

		return (string) ob_get_clean();
	}
}
