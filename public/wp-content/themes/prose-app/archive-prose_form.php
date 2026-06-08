<?php
/**
 * Forms Library (archive) template.
 *
 * Matches the Figma "Forms Library / Desktop" layout: page header, search,
 * case-type filter pills, responsive forms grid (3 / 2 / 1 columns), and
 * pagination.
 *
 * @package ProseApp
 */

use ProseApp\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$prose_forms_url   = get_post_type_archive_link( Forms\POST_TYPE );
$prose_forms_url   = $prose_forms_url ? $prose_forms_url : home_url( '/' );
$prose_active_type = sanitize_title( (string) get_query_var( Forms\FILTER_VAR ) );

$prose_case_terms = get_terms(
	array(
		'taxonomy'   => Forms\TAXONOMY,
		'hide_empty' => true,
		'orderby'    => 'name',
	)
);
if ( is_wp_error( $prose_case_terms ) ) {
	$prose_case_terms = array();
}
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

	<main id="content" class="mx-auto w-full max-w-[1280px] flex-1 px-4 py-8 md:px-8 md:py-10">

		<?php // Page header. ?>
		<header class="mb-6 flex flex-col gap-2">
			<h1 class="text-[28px] font-bold leading-tight text-slate-900 md:text-[36px]"><?php esc_html_e( 'Court Forms Library', 'prose-app' ); ?></h1>
			<p class="text-[15px] text-slate-500"><?php esc_html_e( 'Browse Divorce and Family Court forms.', 'prose-app' ); ?></p>
		</header>

		<?php // Search bar. ?>
		<form class="mb-5 flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 focus-within:border-indigo-400" role="search" method="get" action="<?php echo esc_url( $prose_forms_url ); ?>">
			<svg class="size-5 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
				<circle cx="11" cy="11" r="7" />
				<line x1="21" y1="21" x2="16.65" y2="16.65" />
			</svg>
			<label for="prose-forms-search" class="sr-only"><?php esc_html_e( 'Search forms', 'prose-app' ); ?></label>
			<input
				id="prose-forms-search"
				type="search"
				name="s"
				value="<?php echo esc_attr( get_search_query() ); ?>"
				placeholder="<?php esc_attr_e( 'Search by Form ID or title…', 'prose-app' ); ?>"
				class="w-full border-0 bg-transparent p-0 text-[14px] text-slate-900 placeholder:text-slate-400 focus:outline-none focus:ring-0"
			>
			<input type="hidden" name="post_type" value="<?php echo esc_attr( Forms\POST_TYPE ); ?>">
		</form>

		<?php // Case-type filter pills. ?>
		<?php if ( ! empty( $prose_case_terms ) ) : ?>
			<div class="mb-7 flex flex-wrap gap-2">
				<a
					href="<?php echo esc_url( $prose_forms_url ); ?>"
					class="rounded-full border px-3.5 py-1.5 text-[13px] font-medium no-underline <?php echo '' === $prose_active_type ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'; ?>"
				>
					<?php esc_html_e( 'All Forms', 'prose-app' ); ?>
				</a>
				<?php foreach ( $prose_case_terms as $prose_term ) : ?>
					<?php $prose_is_active = ( $prose_active_type === $prose_term->slug ); ?>
					<a
						href="<?php echo esc_url( add_query_arg( Forms\FILTER_VAR, $prose_term->slug, $prose_forms_url ) ); ?>"
						class="rounded-full border px-3.5 py-1.5 text-[13px] font-medium no-underline <?php echo $prose_is_active ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'; ?>"
					>
						<?php echo esc_html( $prose_term->name ); ?>
					</a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php if ( have_posts() ) : ?>
			<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
				<?php
				while ( have_posts() ) :
					the_post();
					$prose_id        = get_the_ID();
					$prose_form_no   = Forms\get_form_id( $prose_id );
					$prose_file_name = Forms\get_file_name( $prose_id );
					$prose_type      = Forms\get_case_type_label( $prose_id );
					?>
					<article class="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-5 transition hover:border-slate-300 hover:shadow-sm">
						<div class="flex items-center justify-between gap-2">
							<?php if ( '' !== $prose_form_no ) : ?>
								<span class="text-[12px] font-semibold uppercase tracking-wide text-indigo-600"><?php echo esc_html( $prose_form_no ); ?></span>
							<?php endif; ?>
							<?php if ( '' !== $prose_type ) : ?>
								<span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-medium text-slate-600"><?php echo esc_html( $prose_type ); ?></span>
							<?php endif; ?>
						</div>

						<h2 class="text-[16px] font-semibold leading-snug text-slate-900">
							<a href="<?php the_permalink(); ?>" class="no-underline hover:text-indigo-600"><?php the_title(); ?></a>
						</h2>

						<?php if ( '' !== $prose_file_name ) : ?>
							<p class="flex items-center gap-1.5 text-[13px] text-slate-500">
								<svg class="size-3.5 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
									<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
									<polyline points="14 2 14 8 20 8" />
								</svg>
								<span class="truncate"><?php echo esc_html( $prose_file_name ); ?></span>
							</p>
						<?php endif; ?>

						<div class="mt-auto flex items-center gap-2 pt-2">
							<a
								href="<?php the_permalink(); ?>"
								class="rounded-lg border border-slate-200 px-3 py-1.5 text-[13px] font-medium text-slate-900 no-underline hover:bg-slate-50"
							>
								<?php esc_html_e( 'View Form', 'prose-app' ); ?>
							</a>
							<span class="cursor-not-allowed text-[13px] font-medium text-slate-300" title="<?php esc_attr_e( 'Coming soon', 'prose-app' ); ?>">
								<?php esc_html_e( 'Start Guided Interview', 'prose-app' ); ?>
							</span>
						</div>
					</article>
					<?php
				endwhile;
				?>
			</div>

			<?php
			$prose_pagination = paginate_links(
				array(
					'type'      => 'array',
					'mid_size'  => 1,
					'prev_text' => __( '&larr; Prev', 'prose-app' ),
					'next_text' => __( 'Next &rarr;', 'prose-app' ),
				)
			);
			?>
			<?php if ( ! empty( $prose_pagination ) ) : ?>
				<nav class="mt-10 flex flex-wrap items-center justify-center gap-2" aria-label="<?php esc_attr_e( 'Forms pagination', 'prose-app' ); ?>">
					<?php foreach ( $prose_pagination as $prose_link ) : ?>
						<?php
						$prose_is_current = false !== strpos( $prose_link, 'current' );
						$prose_classes    = $prose_is_current
							? 'rounded-lg border border-indigo-600 bg-indigo-600 px-3.5 py-1.5 text-[13px] font-semibold text-white'
							: 'rounded-lg border border-slate-200 bg-white px-3.5 py-1.5 text-[13px] font-medium text-slate-700 hover:bg-slate-50';
						$prose_link       = str_replace( 'page-numbers', 'page-numbers no-underline ' . $prose_classes, $prose_link );
						echo wp_kses_post( $prose_link );
						?>
					<?php endforeach; ?>
				</nav>
			<?php endif; ?>

		<?php else : ?>
			<?php // Empty state. ?>
			<div class="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-slate-300 bg-white px-6 py-16 text-center">
				<svg class="size-10 text-slate-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
					<circle cx="11" cy="11" r="7" />
					<line x1="21" y1="21" x2="16.65" y2="16.65" />
				</svg>
				<h2 class="text-[16px] font-semibold text-slate-900"><?php esc_html_e( 'No forms found', 'prose-app' ); ?></h2>
				<p class="max-w-sm text-[14px] text-slate-500"><?php esc_html_e( 'Try a different search term or clear the case-type filter.', 'prose-app' ); ?></p>
				<a href="<?php echo esc_url( $prose_forms_url ); ?>" class="rounded-lg bg-indigo-600 px-4 py-2 text-[14px] font-semibold text-white no-underline hover:bg-indigo-700">
					<?php esc_html_e( 'View all forms', 'prose-app' ); ?>
				</a>
			</div>
		<?php endif; ?>

	</main>
</div>

<?php wp_footer(); ?>
</body>
</html>
