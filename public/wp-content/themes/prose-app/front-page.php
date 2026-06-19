<?php
/**
 * Front page template (chat-first landing).
 *
 * Static UI built to match the Figma "Homepage" template
 * (file: xVPgq7caO1qyHwi7EbkPRw, node 30:2). No processing wired up yet —
 * markup and layout only.
 *
 * @package ProseApp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ProseApp\Courtflow;

$prose_home_url   = esc_url( home_url( '/' ) );
$prose_login_url  = esc_url( wp_login_url() );
$prose_reg_url    = esc_url( function_exists( 'wp_registration_url' ) ? wp_registration_url() : wp_login_url() );
$prose_nav_links  = array(
	array(
		'label'  => __( 'Home', 'prose-app' ),
		'url'    => $prose_home_url,
		'active' => true,
	),
	array(
		'label'  => __( 'FAQ', 'prose-app' ),
		'url'    => '#faq',
		'active' => false,
	),
	array(
		'label'  => __( 'Contact Us', 'prose-app' ),
		'url'    => '#contact',
		'active' => false,
	),
);

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

<div class="flex min-h-screen flex-col" data-prose-home>

	<?php // Header. ?>
	<header class="sticky top-0 z-30 flex h-[72px] items-center justify-between border-b border-slate-100 bg-white px-4 md:px-8">
		<a href="<?php echo $prose_home_url; ?>" class="flex items-center gap-2 no-underline" rel="home">
			<span class="flex size-7 items-center justify-center rounded-lg bg-indigo-600 text-[11px] font-bold text-white">P</span>
			<span class="text-[18px] font-bold tracking-tight text-slate-900"><?php bloginfo( 'name' ); ?></span>
		</a>

		<?php // Desktop nav. ?>
		<nav class="hidden items-center gap-6 md:flex" aria-label="<?php esc_attr_e( 'Primary', 'prose-app' ); ?>">
			<?php foreach ( $prose_nav_links as $link ) : ?>
				<a
					href="<?php echo esc_url( $link['url'] ); ?>"
					class="flex flex-col items-center gap-1 px-1 py-1.5 text-[14px] no-underline <?php echo $link['active'] ? 'font-semibold text-indigo-600' : 'font-medium text-slate-700 hover:text-slate-900'; ?>"
				>
					<?php echo esc_html( $link['label'] ); ?>
					<?php if ( $link['active'] ) : ?>
						<span class="h-0.5 w-7 rounded-sm bg-indigo-600"></span>
					<?php endif; ?>
				</a>
			<?php endforeach; ?>
		</nav>

		<?php // Desktop auth actions. ?>
		<div class="hidden items-center gap-2 md:flex">
			<a href="<?php echo $prose_login_url; ?>" class="rounded-lg border border-slate-200 px-4 py-2 text-[14px] font-medium text-slate-900 no-underline hover:bg-slate-50">
				<?php esc_html_e( 'Login', 'prose-app' ); ?>
			</a>
			<a href="<?php echo $prose_reg_url; ?>" class="rounded-lg bg-indigo-600 px-4 py-2 text-[14px] font-semibold text-white no-underline hover:bg-indigo-700">
				<?php esc_html_e( 'Register', 'prose-app' ); ?>
			</a>
		</div>

		<?php // Mobile hamburger. ?>
		<button
			type="button"
			class="flex size-9 items-center justify-center rounded-lg border border-slate-200 text-slate-700 md:hidden"
			aria-label="<?php esc_attr_e( 'Open menu', 'prose-app' ); ?>"
			data-prose-menu-open
		>
			<svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
				<line x1="3" y1="6" x2="21" y2="6" />
				<line x1="3" y1="12" x2="21" y2="12" />
				<line x1="3" y1="18" x2="21" y2="18" />
			</svg>
		</button>
	</header>

	<?php // Hero. ?>
	<main id="content" class="flex flex-1 justify-center px-4 pb-[180px] pt-12 md:px-8 md:pb-12 md:pt-24">
		<div class="flex w-full max-w-[900px] flex-col items-center gap-6">
			<h1 class="text-center text-[28px] font-bold leading-tight text-slate-900 md:text-[36px] md:leading-[44px]">
				<?php esc_html_e( 'How can I help you today?', 'prose-app' ); ?>
			</h1>
			<p class="max-w-[620px] text-center text-[16px] leading-[26px] text-slate-500">
				<?php esc_html_e( 'Guided intake for NYC divorce and Family Court matters — Supreme Court matrimonial filings, custody, support, and orders of protection.', 'prose-app' ); ?>
			</p>

			<div class="hidden h-4 w-px md:block" aria-hidden="true"></div>

			<?php // Intake chat widget (plugin-provided). Drives POST /prose/v1/intake. ?>
			<div class="w-full md:w-[720px] md:max-w-full">
				<?php
				if ( shortcode_exists( 'prose_intake_chat' ) ) {
					echo do_shortcode( '[prose_intake_chat]' );
				}
				?>

				<?php // Package preview (plugin-provided). Reacts to a resolved workflow, drives POST /prose/v1/package/preview. ?>
				<?php
				if ( shortcode_exists( 'prose_package_preview' ) ) {
					echo do_shortcode( '[prose_package_preview]' );
				}
				?>
				<div class="mt-4 w-full">
					<?php Courtflow\render_intake_disclaimer(); ?>
				</div>
			</div>

			<?php // Suggested prompts (shared with workspace). Prefill the widget input on click. ?>
			<?php Courtflow\render_prompt_chip_cards(); ?>

			<?php // Privacy line. ?>
			<div class="flex items-center justify-center gap-1.5">
				<svg class="size-[14px] text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<path d="M12 3l7 3v6c0 4.5-3 8-7 9-4-1-7-4.5-7-9V6z" />
				</svg>
				<span class="text-[13px] text-slate-500"><?php esc_html_e( 'Your conversations are private and secure.', 'prose-app' ); ?></span>
			</div>
		</div>
	</main>

	<?php // Mobile menu drawer (Homepage / Mobile menu open). ?>
	<div class="fixed inset-0 z-40 hidden md:hidden" data-prose-menu aria-hidden="true">
		<div class="absolute inset-0 bg-black/45" data-prose-menu-close></div>
		<div class="absolute inset-y-0 right-0 flex w-[300px] max-w-[85%] flex-col bg-white shadow-xl">
			<div class="flex h-[72px] items-center justify-between border-b border-slate-100 px-4">
				<span class="text-[18px] font-bold text-slate-900"><?php bloginfo( 'name' ); ?></span>
				<button type="button" class="flex size-9 items-center justify-center rounded-lg border border-slate-200 text-slate-700" aria-label="<?php esc_attr_e( 'Close menu', 'prose-app' ); ?>" data-prose-menu-close>
					<svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
						<line x1="6" y1="6" x2="18" y2="18" />
						<line x1="18" y1="6" x2="6" y2="18" />
					</svg>
				</button>
			</div>
			<nav class="flex flex-col p-2" aria-label="<?php esc_attr_e( 'Mobile', 'prose-app' ); ?>">
				<?php foreach ( $prose_nav_links as $link ) : ?>
					<a
						href="<?php echo esc_url( $link['url'] ); ?>"
						class="rounded-lg px-3 py-3 text-[15px] no-underline <?php echo $link['active'] ? 'font-semibold text-indigo-600' : 'font-medium text-slate-700 hover:bg-slate-50'; ?>"
					>
						<?php echo esc_html( $link['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
			<div class="mt-auto flex flex-col gap-2 border-t border-slate-100 p-4">
				<a href="<?php echo $prose_login_url; ?>" class="rounded-lg border border-slate-200 px-4 py-2.5 text-center text-[14px] font-medium text-slate-900 no-underline hover:bg-slate-50">
					<?php esc_html_e( 'Login', 'prose-app' ); ?>
				</a>
				<a href="<?php echo $prose_reg_url; ?>" class="rounded-lg bg-indigo-600 px-4 py-2.5 text-center text-[14px] font-semibold text-white no-underline hover:bg-indigo-700">
					<?php esc_html_e( 'Register', 'prose-app' ); ?>
				</a>
			</div>
		</div>
	</div>
</div>

<script>
( function () {
	var root  = document.querySelector( '[data-prose-home]' );
	if ( ! root ) {
		return;
	}
	var menu  = root.querySelector( '[data-prose-menu]' );
	var open  = root.querySelector( '[data-prose-menu-open]' );
	var close = root.querySelectorAll( '[data-prose-menu-close]' );

	function show() {
		menu.classList.remove( 'hidden' );
		menu.setAttribute( 'aria-hidden', 'false' );
		document.body.style.overflow = 'hidden';
	}
	function hide() {
		menu.classList.add( 'hidden' );
		menu.setAttribute( 'aria-hidden', 'true' );
		document.body.style.overflow = '';
	}

	if ( open ) {
		open.addEventListener( 'click', show );
	}
	close.forEach( function ( el ) {
		el.addEventListener( 'click', hide );
	} );
	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key ) {
			hide();
		}
	} );
}() );
</script>

<?php wp_footer(); ?>
</body>
</html>
