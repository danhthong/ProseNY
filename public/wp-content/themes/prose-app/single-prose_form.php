<?php
/**
 * Single Form Details template.
 *
 * Matches the Figma "Form Details / Desktop" layout: PDF viewer on the LEFT
 * (~65%) and an actions/metadata sidebar on the RIGHT (~35%). On mobile the
 * PDF viewer stacks on top. Real PDF rendering via an embedded iframe.
 *
 * @package ProseApp
 */

use ProseApp\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

the_post();

$prose_post_id   = get_the_ID();
$prose_form_id   = Forms\get_form_id( $prose_post_id );
$prose_file_name = Forms\get_file_name( $prose_post_id );
$prose_file_url  = Forms\get_file_url( $prose_post_id );
$prose_case_type = Forms\get_case_type_label( $prose_post_id );
$prose_desc      = Forms\get_description( $prose_post_id );
$prose_explain   = Forms\get_ai_explanation( $prose_post_id );
$prose_forms_url = get_post_type_archive_link( Forms\POST_TYPE );
$prose_forms_url = $prose_forms_url ? esc_url( $prose_forms_url ) : esc_url( home_url( '/' ) );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'min-h-screen bg-slate-50 font-sans text-slate-900 antialiased' ); ?>>
<?php wp_body_open(); ?>

<div class="flex min-h-screen flex-col" data-prose-shell>

	<?php get_template_part( 'template-parts/prose-site-header' ); ?>

	<main id="content" class="mx-auto w-full max-w-[1280px] flex-1 px-4 py-6 md:px-8 md:py-8">

		<?php // Breadcrumb. ?>
		<nav class="mb-6 flex flex-wrap items-center gap-2 text-[13px] text-slate-500" aria-label="<?php esc_attr_e( 'Breadcrumb', 'prose-app' ); ?>">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="no-underline hover:text-slate-700"><?php esc_html_e( 'Home', 'prose-app' ); ?></a>
			<span aria-hidden="true">/</span>
			<a href="<?php echo $prose_forms_url; ?>" class="no-underline hover:text-slate-700"><?php esc_html_e( 'Court Forms Library', 'prose-app' ); ?></a>
			<span aria-hidden="true">/</span>
			<span class="font-medium text-slate-700"><?php echo esc_html( '' !== $prose_form_id ? $prose_form_id : get_the_title() ); ?></span>
		</nav>

		<div class="flex flex-col gap-6 lg:flex-row lg:items-start">

			<?php // PDF viewer (left, ~65%). ?>
			<section
				class="flex w-full flex-col overflow-hidden rounded-xl border border-slate-200 bg-white lg:flex-[0_0_64%]"
				data-pdf-viewer
				data-pdf-url="<?php echo esc_url( $prose_file_url ); ?>"
			>
				<?php // Toolbar. ?>
				<div class="flex items-center gap-2 border-b border-slate-100 px-3 py-2.5 md:px-4">
					<span class="truncate text-[14px] font-medium text-slate-900">
						<?php echo esc_html( '' !== $prose_file_name ? $prose_file_name : __( 'Form preview', 'prose-app' ) ); ?>
					</span>

					<div class="ml-auto flex items-center gap-1.5">
						<?php if ( '' !== $prose_file_url ) : ?>
							<span class="mr-1 hidden text-[12px] tabular-nums text-slate-500 sm:inline" data-pdf-counter>
								<span data-pdf-page>1</span> / <span data-pdf-total>–</span>
							</span>
							<button type="button" class="flex size-8 items-center justify-center rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 disabled:opacity-40" aria-label="<?php esc_attr_e( 'Zoom out', 'prose-app' ); ?>" data-pdf-zoom-out>
								<svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12" /></svg>
							</button>
							<button type="button" class="flex size-8 items-center justify-center rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 disabled:opacity-40" aria-label="<?php esc_attr_e( 'Zoom in', 'prose-app' ); ?>" data-pdf-zoom-in>
								<svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" /></svg>
							</button>
							<a href="<?php echo esc_url( $prose_file_url ); ?>" download class="flex size-8 items-center justify-center rounded-lg border border-slate-200 text-slate-600 no-underline hover:bg-slate-50" aria-label="<?php esc_attr_e( 'Download', 'prose-app' ); ?>">
								<svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
									<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
									<polyline points="7 10 12 15 17 10" />
									<line x1="12" y1="15" x2="12" y2="3" />
								</svg>
							</a>
						<?php endif; ?>
					</div>
				</div>

				<?php // Canvas / preview area. ?>
				<div class="flex h-[60vh] items-start justify-center overflow-auto bg-slate-100 p-3 md:h-[78vh] md:p-4">
					<?php if ( '' !== $prose_file_url ) : ?>
						<canvas class="rounded-lg bg-white shadow-sm" data-pdf-canvas></canvas>
						<div class="hidden flex-col items-center gap-3 self-center text-center" data-pdf-fallback>
							<p class="text-[14px] text-slate-500"><?php esc_html_e( 'Unable to render the preview.', 'prose-app' ); ?></p>
							<a href="<?php echo esc_url( $prose_file_url ); ?>" target="_blank" rel="noopener noreferrer" class="rounded-lg bg-indigo-600 px-4 py-2 text-[13px] font-semibold text-white no-underline hover:bg-indigo-700"><?php esc_html_e( 'Open PDF', 'prose-app' ); ?></a>
						</div>
					<?php else : ?>
						<div class="flex flex-col items-center justify-center gap-2 self-center text-center">
							<svg class="size-10 text-slate-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
								<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
								<polyline points="14 2 14 8 20 8" />
							</svg>
							<p class="text-[14px] text-slate-500"><?php esc_html_e( 'No PDF available for this form yet.', 'prose-app' ); ?></p>
						</div>
					<?php endif; ?>
				</div>

				<?php // Footer page navigation. ?>
				<?php if ( '' !== $prose_file_url ) : ?>
					<div class="flex items-center justify-center gap-3 border-t border-slate-100 px-4 py-2.5">
						<button type="button" class="flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-[13px] font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40" data-pdf-prev>
							<svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6" /></svg>
							<?php esc_html_e( 'Prev', 'prose-app' ); ?>
						</button>
						<span class="text-[13px] tabular-nums text-slate-500">
							<?php esc_html_e( 'Page', 'prose-app' ); ?> <span data-pdf-page>1</span> <?php esc_html_e( 'of', 'prose-app' ); ?> <span data-pdf-total>–</span>
						</span>
						<button type="button" class="flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-[13px] font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40" data-pdf-next>
							<?php esc_html_e( 'Next', 'prose-app' ); ?>
							<svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6" /></svg>
						</button>
					</div>
				<?php endif; ?>
			</section>

			<?php // Sidebar (right, ~35%). ?>
			<aside class="flex w-full flex-col gap-4 lg:flex-1">
				<h1 class="text-[22px] font-bold leading-tight text-slate-900 md:text-[26px]"><?php the_title(); ?></h1>

				<div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[13px] font-medium text-slate-600">
					<?php if ( '' !== $prose_form_id ) : ?>
						<span><?php esc_html_e( 'Form ID:', 'prose-app' ); ?> <span class="text-slate-900"><?php echo esc_html( $prose_form_id ); ?></span></span>
					<?php endif; ?>
					<?php if ( '' !== $prose_case_type ) : ?>
						<span><?php esc_html_e( 'Case Type:', 'prose-app' ); ?> <span class="text-slate-900"><?php echo esc_html( $prose_case_type ); ?></span></span>
					<?php endif; ?>
				</div>

				<p class="text-[14px] leading-[22px] text-slate-600"><?php echo esc_html( $prose_desc ); ?></p>

				<?php // Download Form card. ?>
				<div class="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4">
					<h2 class="text-[16px] font-semibold text-slate-900"><?php esc_html_e( 'Download Form', 'prose-app' ); ?></h2>
					<?php if ( '' !== $prose_file_name ) : ?>
						<div class="flex items-center gap-2 text-[14px] text-slate-700">
							<svg class="size-4 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
								<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
								<polyline points="14 2 14 8 20 8" />
							</svg>
							<span class="truncate"><?php echo esc_html( $prose_file_name ); ?></span>
						</div>
					<?php endif; ?>
					<p class="text-[12px] text-slate-400"><?php esc_html_e( 'PDF · NY Courts', 'prose-app' ); ?></p>

					<?php if ( '' !== $prose_file_url ) : ?>
						<a
							href="<?php echo esc_url( $prose_file_url ); ?>"
							download
							class="flex items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-[14px] font-semibold text-white no-underline hover:bg-indigo-700"
						>
							<svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
								<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
								<polyline points="7 10 12 15 17 10" />
								<line x1="12" y1="15" x2="12" y2="3" />
							</svg>
							<?php esc_html_e( 'Download PDF', 'prose-app' ); ?>
						</a>
					<?php else : ?>
						<span class="flex items-center justify-center rounded-lg bg-slate-100 px-4 py-2.5 text-[14px] font-semibold text-slate-400">
							<?php esc_html_e( 'PDF unavailable', 'prose-app' ); ?>
						</span>
					<?php endif; ?>

					<button
						type="button"
						class="flex cursor-not-allowed items-center justify-center rounded-lg border border-slate-200 px-4 py-2.5 text-[14px] font-medium text-slate-400"
						disabled
						aria-disabled="true"
						title="<?php esc_attr_e( 'Coming soon', 'prose-app' ); ?>"
					>
						<?php esc_html_e( 'Start Guided Interview', 'prose-app' ); ?>
					</button>
				</div>

				<?php // AI explanation. ?>
				<div class="flex max-h-[70vh] flex-col gap-3 overflow-y-auto rounded-xl bg-slate-100 p-4">
					<div class="flex items-center gap-2">
						<svg class="size-4 shrink-0 text-indigo-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
							<path d="M12 3l1.9 4.8L18.7 9.7l-4.8 1.9L12 16.4l-1.9-4.8L5.3 9.7l4.8-1.9z" />
						</svg>
						<h2 class="text-[15px] font-semibold text-slate-900"><?php esc_html_e( 'AI explanation', 'prose-app' ); ?></h2>
					</div>

					<?php if ( ! empty( $prose_explain['has_content'] ) ) : ?>
						<?php
						$prose_summary      = trim( (string) ( $prose_explain['summary'] ?? '' ) );
						$prose_body         = trim( (string) ( $prose_explain['body'] ?? '' ) );
						$prose_show_summary = '' !== $prose_summary && ( '' === $prose_body || $prose_summary !== $prose_body );
						$prose_sections     = is_array( $prose_explain['sections'] ?? null ) ? $prose_explain['sections'] : array();
						$prose_prepare      = is_array( $prose_explain['prepare'] ?? null ) ? $prose_explain['prepare'] : array();
						$prose_labels       = array(
							'what'  => __( 'What it is', 'prose-app' ),
							'why'   => __( 'Who needs it', 'prose-app' ),
							'when'  => __( 'When to file', 'prose-app' ),
							'next'  => __( "What's next", 'prose-app' ),
						);
						?>

						<?php if ( $prose_show_summary ) : ?>
							<p class="text-[13px] leading-[20px] text-slate-700"><?php echo esc_html( $prose_summary ); ?></p>
						<?php endif; ?>

						<?php foreach ( $prose_labels as $prose_key => $prose_label ) : ?>
							<?php if ( '' !== trim( (string) ( $prose_sections[ $prose_key ] ?? '' ) ) ) : ?>
								<div>
									<p class="text-[12px] font-semibold uppercase tracking-wide text-slate-500"><?php echo esc_html( $prose_label ); ?></p>
									<p class="text-[13px] leading-[20px] text-slate-600"><?php echo esc_html( (string) $prose_sections[ $prose_key ] ); ?></p>
								</div>
							<?php endif; ?>
						<?php endforeach; ?>

						<?php if ( '' !== $prose_body ) : ?>
							<div class="flex flex-col gap-1 border-t border-slate-200/80 pt-2">
								<?php
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper.
								echo Forms\format_explanation_body( $prose_body );
								?>
							</div>
						<?php endif; ?>

						<?php if ( ! empty( $prose_prepare['required'] ) || ! empty( $prose_prepare['optional'] ) || ! empty( $prose_prepare['conditional'] ) ) : ?>
							<div class="border-t border-slate-200/80 pt-2">
								<p class="text-[12px] font-semibold uppercase tracking-wide text-slate-500"><?php esc_html_e( 'What to prepare', 'prose-app' ); ?></p>
								<p class="mb-2 text-[12px] leading-[18px] text-slate-500"><?php esc_html_e( 'Gather this information before you fill out the form.', 'prose-app' ); ?></p>

								<?php if ( ! empty( $prose_prepare['required'] ) ) : ?>
									<p class="text-[12px] font-medium text-slate-700"><?php esc_html_e( 'Required', 'prose-app' ); ?></p>
									<ul class="mt-1 list-disc space-y-1 pl-4 text-[13px] leading-[20px] text-slate-600">
										<?php foreach ( $prose_prepare['required'] as $prose_item ) : ?>
											<li><?php echo esc_html( (string) ( $prose_item['label'] ?? '' ) ); ?></li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>

								<?php if ( ! empty( $prose_prepare['optional'] ) ) : ?>
									<p class="mt-2 text-[12px] font-medium text-slate-700"><?php esc_html_e( 'Helpful to have', 'prose-app' ); ?></p>
									<ul class="mt-1 list-disc space-y-1 pl-4 text-[13px] leading-[20px] text-slate-600">
										<?php foreach ( $prose_prepare['optional'] as $prose_item ) : ?>
											<li><?php echo esc_html( (string) ( $prose_item['label'] ?? '' ) ); ?></li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>

								<?php if ( ! empty( $prose_prepare['conditional'] ) ) : ?>
									<p class="mt-2 text-[12px] font-medium text-slate-700"><?php esc_html_e( 'If applicable', 'prose-app' ); ?></p>
									<ul class="mt-1 list-disc space-y-1 pl-4 text-[13px] leading-[20px] text-slate-600">
										<?php foreach ( $prose_prepare['conditional'] as $prose_item ) : ?>
											<li><?php echo esc_html( (string) ( $prose_item['label'] ?? '' ) ); ?></li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							</div>
						<?php endif; ?>

						<?php if ( '' !== trim( (string) ( $prose_sections['stage'] ?? '' ) ) || '' !== trim( (string) ( $prose_sections['court'] ?? '' ) ) ) : ?>
							<div class="flex flex-wrap gap-2 pt-1">
								<?php if ( '' !== trim( (string) ( $prose_sections['stage'] ?? '' ) ) ) : ?>
									<span class="rounded-full bg-white px-2.5 py-1 text-[11px] font-medium text-slate-600"><?php echo esc_html( (string) $prose_sections['stage'] ); ?></span>
								<?php endif; ?>
								<?php if ( '' !== trim( (string) ( $prose_sections['court'] ?? '' ) ) ) : ?>
									<span class="rounded-full bg-white px-2.5 py-1 text-[11px] font-medium text-slate-600"><?php echo esc_html( (string) $prose_sections['court'] ); ?></span>
								<?php endif; ?>
							</div>
						<?php endif; ?>

						<?php if ( ! empty( $prose_explain['common_mistakes'] ) ) : ?>
							<div>
								<p class="text-[12px] font-semibold uppercase tracking-wide text-slate-500"><?php esc_html_e( 'Common mistakes', 'prose-app' ); ?></p>
								<ul class="mt-1 list-disc space-y-1 pl-4 text-[13px] leading-[20px] text-slate-600">
									<?php foreach ( $prose_explain['common_mistakes'] as $prose_mistake ) : ?>
										<li><?php echo esc_html( (string) $prose_mistake ); ?></li>
									<?php endforeach; ?>
								</ul>
							</div>
						<?php endif; ?>

						<?php if ( '' !== (string) ( $prose_explain['official_url'] ?? '' ) ) : ?>
							<a
								href="<?php echo esc_url( (string) $prose_explain['official_url'] ); ?>"
								target="_blank"
								rel="noopener noreferrer"
								class="text-[12px] font-medium text-indigo-600 no-underline hover:text-indigo-700"
							>
								<?php esc_html_e( 'View on NY Courts', 'prose-app' ); ?>
							</a>
						<?php endif; ?>

						<p class="text-[11px] leading-[16px] text-slate-400">
							<?php esc_html_e( 'Informational guidance only — not legal advice.', 'prose-app' ); ?>
						</p>
					<?php else : ?>
						<p class="text-[13px] leading-[20px] text-slate-500">
							<?php esc_html_e( 'Coming soon: a plain-language summary of this form, who needs it, and when to file it.', 'prose-app' ); ?>
						</p>
					<?php endif; ?>
				</div>
			</aside>

		</div>
	</main>
</div>

<?php if ( '' !== $prose_file_url ) : ?>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
	<script>
	( function () {
		var el = document.querySelector( '[data-pdf-viewer]' );
		if ( ! el || ! window.pdfjsLib ) {
			return;
		}
		var url = el.getAttribute( 'data-pdf-url' );
		var canvas = el.querySelector( '[data-pdf-canvas]' );
		if ( ! url || ! canvas ) {
			return;
		}

		window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

		var ctx       = canvas.getContext( '2d' );
		var fallback  = el.querySelector( '[data-pdf-fallback]' );
		var prevBtn   = el.querySelector( '[data-pdf-prev]' );
		var nextBtn   = el.querySelector( '[data-pdf-next]' );
		var zoomIn    = el.querySelector( '[data-pdf-zoom-in]' );
		var zoomOut   = el.querySelector( '[data-pdf-zoom-out]' );
		var pageEls   = el.querySelectorAll( '[data-pdf-page]' );
		var totalEls  = el.querySelectorAll( '[data-pdf-total]' );

		var pdfDoc = null, pageNum = 1, scale = 1.2, rendering = false, pending = null;

		function setText( list, value ) {
			Array.prototype.forEach.call( list, function ( node ) {
				node.textContent = value;
			} );
		}

		function updateButtons() {
			if ( prevBtn ) {
				prevBtn.disabled = pageNum <= 1;
			}
			if ( nextBtn ) {
				nextBtn.disabled = ! pdfDoc || pageNum >= pdfDoc.numPages;
			}
		}

		function renderPage( num ) {
			rendering = true;
			pdfDoc.getPage( num ).then( function ( page ) {
				var dpr      = window.devicePixelRatio || 1;
				var viewport = page.getViewport( { scale: scale } );
				canvas.width        = Math.floor( viewport.width * dpr );
				canvas.height       = Math.floor( viewport.height * dpr );
				canvas.style.width  = Math.floor( viewport.width ) + 'px';
				canvas.style.height = Math.floor( viewport.height ) + 'px';

				var task = page.render( {
					canvasContext: ctx,
					viewport: viewport,
					transform: 1 !== dpr ? [ dpr, 0, 0, dpr, 0, 0 ] : null
				} );

				task.promise.then( function () {
					rendering = false;
					if ( null !== pending ) {
						var next = pending;
						pending = null;
						renderPage( next );
					}
				} );
			} );

			setText( pageEls, num );
			updateButtons();
		}

		function queueRender( num ) {
			if ( rendering ) {
				pending = num;
			} else {
				renderPage( num );
			}
		}

		window.pdfjsLib.getDocument( url ).promise.then( function ( doc ) {
			pdfDoc = doc;
			setText( totalEls, doc.numPages );
			renderPage( pageNum );
		} ).catch( function () {
			if ( canvas ) {
				canvas.classList.add( 'hidden' );
			}
			if ( fallback ) {
				fallback.classList.remove( 'hidden' );
				fallback.classList.add( 'flex' );
			}
		} );

		if ( prevBtn ) {
			prevBtn.addEventListener( 'click', function () {
				if ( pageNum <= 1 ) {
					return;
				}
				pageNum--;
				queueRender( pageNum );
			} );
		}
		if ( nextBtn ) {
			nextBtn.addEventListener( 'click', function () {
				if ( ! pdfDoc || pageNum >= pdfDoc.numPages ) {
					return;
				}
				pageNum++;
				queueRender( pageNum );
			} );
		}
		if ( zoomIn ) {
			zoomIn.addEventListener( 'click', function () {
				scale = Math.min( scale + 0.2, 3 );
				queueRender( pageNum );
			} );
		}
		if ( zoomOut ) {
			zoomOut.addEventListener( 'click', function () {
				scale = Math.max( scale - 0.2, 0.5 );
				queueRender( pageNum );
			} );
		}
	}() );
	</script>
<?php endif; ?>

<?php wp_footer(); ?>
</body>
</html>
