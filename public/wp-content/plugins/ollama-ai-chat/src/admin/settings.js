/**
 * Admin settings page enhancements.
 */
( function () {
	const colorInput = document.getElementById( 'ollama_primary_color' );
	if ( ! colorInput ) {
		return;
	}

	colorInput.addEventListener( 'input', function () {
		document.documentElement.style.setProperty(
			'--ollama-primary',
			colorInput.value
		);
	} );
} )();
