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
			<section class="flex w-full flex-col overflow-hidden rounded-xl border border-slate-200 bg-white lg:flex-[0_0_64%]">
				<div class="flex items-center gap-3 border-b border-slate-100 px-4 py-3">
					<span class="truncate text-[14px] font-medium text-slate-900">
						<?php echo esc_html( '' !== $prose_file_name ? $prose_file_name : __( 'Form preview', 'prose-app' ) ); ?>
					</span>
					<div class="ml-auto flex items-center gap-2">
						<?php if ( '' !== $prose_file_url ) : ?>
							<a
								href="<?php echo esc_url( $prose_file_url ); ?>"
								target="_blank"
								rel="noopener noreferrer"
								class="flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-[13px] font-medium text-slate-600 no-underline hover:bg-slate-50"
							>
								<svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
									<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />
									<polyline points="15 3 21 3 21 9" />
									<line x1="10" y1="14" x2="21" y2="3" />
								</svg>
								<span class="hidden sm:inline"><?php esc_html_e( 'Open', 'prose-app' ); ?></span>
							</a>
							<a
								href="<?php echo esc_url( $prose_file_url ); ?>"
								download
								class="flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-[13px] font-medium text-slate-600 no-underline hover:bg-slate-50"
							>
								<svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
									<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
									<polyline points="7 10 12 15 17 10" />
									<line x1="12" y1="15" x2="12" y2="3" />
								</svg>
								<span class="hidden sm:inline"><?php esc_html_e( 'Download', 'prose-app' ); ?></span>
							</a>
						<?php endif; ?>
					</div>
				</div>

				<div class="bg-slate-100 p-3 md:p-4">
					<?php if ( '' !== $prose_file_url ) : ?>
						<iframe
							src="<?php echo esc_url( $prose_file_url ); ?>#toolbar=1&navpanes=0&view=FitH"
							title="<?php echo esc_attr( get_the_title() ); ?>"
							class="h-[60vh] w-full rounded-lg border border-slate-200 bg-white shadow-sm md:h-[78vh]"
							loading="lazy"
						></iframe>
					<?php else : ?>
						<div class="flex h-[60vh] flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-slate-300 bg-white text-center md:h-[78vh]">
							<svg class="size-10 text-slate-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
								<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
								<polyline points="14 2 14 8 20 8" />
							</svg>
							<p class="text-[14px] text-slate-500"><?php esc_html_e( 'No PDF available for this form yet.', 'prose-app' ); ?></p>
						</div>
					<?php endif; ?>
				</div>
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

				<?php // AI explanation placeholder. ?>
				<div class="flex flex-col gap-2 rounded-xl bg-slate-100 p-4">
					<div class="flex items-center gap-2">
						<svg class="size-4 text-indigo-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
							<path d="M12 3l1.9 4.8L18.7 9.7l-4.8 1.9L12 16.4l-1.9-4.8L5.3 9.7l4.8-1.9z" />
						</svg>
						<h2 class="text-[15px] font-semibold text-slate-900"><?php esc_html_e( 'AI explanation', 'prose-app' ); ?></h2>
					</div>
					<p class="text-[13px] leading-[20px] text-slate-500">
						<?php esc_html_e( 'Coming soon: a plain-language summary of this form, who needs it, and when to file it.', 'prose-app' ); ?>
					</p>
				</div>
			</aside>

		</div>
	</main>
</div>

<?php wp_footer(); ?>
</body>
</html>
