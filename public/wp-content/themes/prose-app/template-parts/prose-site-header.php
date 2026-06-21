<?php
/**
 * Shared Proseny site header (matches Figma "Header / Desktop" + "Header / Mobile").
 *
 * Used by the standalone Forms templates. Includes a mobile drawer with a
 * small self-contained toggle script scoped to [data-prose-shell].
 *
 * @package ProseApp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ProseApp\Users;

$prose_home_url  = esc_url( home_url( '/' ) );
$prose_login_url = esc_url( Users\login_url() );
$prose_reg_url   = esc_url( Users\register_url() );

if ( function_exists( 'get_post_type_archive_link' ) ) {
	$prose_forms_url = get_post_type_archive_link( 'prose_form' );
	$prose_forms_url = $prose_forms_url ? esc_url( $prose_forms_url ) : $prose_home_url;
} else {
	$prose_forms_url = $prose_home_url;
}

$prose_nav_links = array(
	array(
		'label'  => __( 'Home', 'prose-app' ),
		'url'    => $prose_home_url,
		'active' => is_front_page(),
	),
	array(
		'label'  => __( 'Forms', 'prose-app' ),
		'url'    => $prose_forms_url,
		'active' => is_post_type_archive( 'prose_form' ) || is_singular( 'prose_form' ),
	),
	array(
		'label'  => __( 'FAQ', 'prose-app' ),
		'url'    => $prose_home_url . '#faq',
		'active' => false,
	),
	array(
		'label'  => __( 'Contact Us', 'prose-app' ),
		'url'    => $prose_home_url . '#contact',
		'active' => false,
	),
);

$prose_dash_url   = esc_url( Users\dashboard_url() );
$prose_logout_url = esc_url( wp_logout_url( is_user_logged_in() ? Users\dashboard_url() : home_url( '/' ) ) );
?>
<header class="sticky top-0 z-30 flex h-[72px] items-center justify-between border-b border-slate-100 bg-white px-4 md:px-8">
	<a href="<?php echo $prose_home_url; ?>" class="flex items-center gap-2 no-underline" rel="home">
		<span class="flex size-7 items-center justify-center rounded-lg bg-indigo-600 text-[11px] font-bold text-white">P</span>
		<span class="text-[18px] font-bold tracking-tight text-slate-900"><?php bloginfo( 'name' ); ?></span>
	</a>

	<nav class="hidden items-center gap-6 md:flex" aria-label="<?php esc_attr_e( 'Primary', 'prose-app' ); ?>">
		<?php foreach ( $prose_nav_links as $prose_link ) : ?>
			<a
				href="<?php echo esc_url( $prose_link['url'] ); ?>"
				class="flex flex-col items-center gap-1 px-1 py-1.5 text-[14px] no-underline <?php echo $prose_link['active'] ? 'font-semibold text-indigo-600' : 'font-medium text-slate-700 hover:text-slate-900'; ?>"
			>
				<?php echo esc_html( $prose_link['label'] ); ?>
				<?php if ( $prose_link['active'] ) : ?>
					<span class="h-0.5 w-7 rounded-sm bg-indigo-600"></span>
				<?php endif; ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<div class="hidden items-center gap-2 md:flex">
		<?php if ( is_user_logged_in() ) : ?>
			<a href="<?php echo $prose_dash_url; ?>" class="prose-btn prose-btn--primary">
				<?php esc_html_e( 'Dashboard', 'prose-app' ); ?>
			</a>
			<a href="<?php echo $prose_logout_url; ?>" class="prose-btn prose-btn--secondary">
				<?php esc_html_e( 'Logout', 'prose-app' ); ?>
			</a>
		<?php else : ?>
			<a href="<?php echo $prose_login_url; ?>" class="prose-btn prose-btn--secondary">
				<?php esc_html_e( 'Login', 'prose-app' ); ?>
			</a>
			<a href="<?php echo $prose_reg_url; ?>" class="prose-btn prose-btn--primary">
				<?php esc_html_e( 'Register', 'prose-app' ); ?>
			</a>
		<?php endif; ?>
	</div>

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

<?php // Mobile menu drawer. ?>
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
			<?php foreach ( $prose_nav_links as $prose_link ) : ?>
				<a
					href="<?php echo esc_url( $prose_link['url'] ); ?>"
					class="rounded-lg px-3 py-3 text-[15px] no-underline <?php echo $prose_link['active'] ? 'font-semibold text-indigo-600' : 'font-medium text-slate-700 hover:bg-slate-50'; ?>"
				>
					<?php echo esc_html( $prose_link['label'] ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<div class="mt-auto flex flex-col gap-2 border-t border-slate-100 p-4">
			<?php if ( is_user_logged_in() ) : ?>
				<a href="<?php echo $prose_dash_url; ?>" class="prose-btn prose-btn--primary prose-btn--block">
					<?php esc_html_e( 'Dashboard', 'prose-app' ); ?>
				</a>
				<a href="<?php echo $prose_logout_url; ?>" class="prose-btn prose-btn--secondary prose-btn--block">
					<?php esc_html_e( 'Logout', 'prose-app' ); ?>
				</a>
			<?php else : ?>
				<a href="<?php echo $prose_login_url; ?>" class="prose-btn prose-btn--secondary prose-btn--block">
					<?php esc_html_e( 'Login', 'prose-app' ); ?>
				</a>
				<a href="<?php echo $prose_reg_url; ?>" class="prose-btn prose-btn--primary prose-btn--block">
					<?php esc_html_e( 'Register', 'prose-app' ); ?>
				</a>
			<?php endif; ?>
		</div>
	</div>
</div>

<script>
( function () {
	var root = document.querySelector( '[data-prose-shell]' );
	if ( ! root ) {
		return;
	}
	var menu  = root.querySelector( '[data-prose-menu]' );
	var open  = root.querySelector( '[data-prose-menu-open]' );
	var close = root.querySelectorAll( '[data-prose-menu-close]' );
	if ( ! menu ) {
		return;
	}

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
