/**
 * Ollama AI Chat frontend entry point (vanilla JS).
 */

import { ChatWidget } from './chat-widget';
import { getConfig } from './utils/api';

/**
 * Mount all chat instances on the page.
 */
function mountChats() {
	const config = getConfig();
	if ( ! config ) {
		return;
	}

	const instances = config.instances?.length ? config.instances : [];
	const roots = document.querySelectorAll( '[data-ollama-chat]' );

	roots.forEach( ( el, index ) => {
		const instance = instances[ index ] || {
			id: el.id || `ollama-chat-${ index }`,
			title: el.dataset.title || config.title,
			height: el.dataset.height || '520px',
			theme: el.dataset.theme || 'auto',
			model: el.dataset.model || '',
			layout: el.dataset.layout || 'widget',
		};

		new ChatWidget( el, instance );
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mountChats );
} else {
	mountChats();
}
