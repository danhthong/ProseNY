<?php
/**
 * Legal disclaimer helper.
 *
 * @package ProseCore
 */

namespace Prose\Core\Security;

use Prose\Core\Support\Config;

final class Disclaimer {

	public static function text(): string {
		return (string) Config::get( 'disclaimer_text', Config::default_disclaimer() );
	}

	public static function render_html(): string {
		return '<div class="courtflow-disclaimer" role="note">' .
			wp_kses_post( self::text() ) .
			'</div>';
	}
}
