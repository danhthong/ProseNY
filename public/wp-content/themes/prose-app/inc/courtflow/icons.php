<?php
/**
 * CourtFlow inline SVG icons.
 *
 * @package ProseApp
 */

namespace ProseApp\Courtflow;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @return array<string, string>
 */
function icon_paths(): array {
	return array(
		'clipboard'    => '<path stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round" d="M9 2h6v2H9V2zm-2 4h10v12H7V6zm2 2v8h6V8H9z"/>',
		'scale'        => '<path stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" d="M6 16h12M8 16l4-10 4 10M10 12h4"/>',
		'document'     => '<path stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round" d="M8 4h8v14H8V4zm2 4h4M10 12h4M10 15h4"/>',
		'users'        => '<path stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" d="M10 10a3 3 0 100-6 3 3 0 000 6zm-6 8c0-3 2.7-5 6-5s6 2 6 5"/>',
		'dollar'       => '<path stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" d="M12 6v12M9 9c0-1.5 1.3-2 3-2s3 .5 3 2-1.3 2-3 2.5-3 2.5 1.3.5 3 2 3 3.5"/>',
		'shield-check' => '<path stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round" d="M12 4l6 3v5c0 4-2.5 6.5-6 8-3.5-1.5-6-4-6-8V7l6-3zm-2 6l2 2 4-4"/>',
		'eye'          => '<path stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" d="M4 12s2.5-5 8-5 8 5 8 5-2.5 5-8 5-8-5-8-5zm8 0a3 3 0 11-6 0 3 3 0 016 0z"/>',
		'package'      => '<path stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round" d="M4 8l8-4 8 4-8 4-8-4zm0 0v8l8 4 8-4V8"/>',
		'check-circle' => '<path stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" d="M12 4a8 8 0 100 16 8 8 0 000-16zm-3 8l2 2 5-5"/>',
		'info'         => '<circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.5" fill="none"/><path stroke="currentColor" stroke-width="1.5" stroke-linecap="round" d="M12 10v5M12 8h.01"/>',
		'chevron'      => '<path stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" d="M9 6l3 3 3-3"/>',
		'menu'         => '<path stroke="currentColor" stroke-width="1.5" stroke-linecap="round" d="M5 7h14M5 12h14M5 17h14"/>',
		'x'            => '<path stroke="currentColor" stroke-width="1.5" stroke-linecap="round" d="M7 7l10 10M17 7L7 17"/>',
	);
}

/**
 * Render an icon SVG.
 */
function render_icon( string $name, string $class = 'cf-icon' ): string {
	$paths = icon_paths();
	$inner = $paths[ $name ] ?? $paths['clipboard'];

	return sprintf(
		'<svg class="%s" width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false">%s</svg>',
		esc_attr( $class ),
		$inner
	);
}
