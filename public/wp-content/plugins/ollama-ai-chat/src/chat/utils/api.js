/**
 * REST API client for Ollama AI Chat.
 */

/**
 * Get config from localized script.
 *
 * @return {Object}
 */
export function getConfig() {
	return window.ollamaAiChat || {};
}

/**
 * Send a non-streaming chat request.
 *
 * @param {Array}  messages Messages.
 * @param {string} model    Model override.
 * @return {Promise<Object>}
 */
export async function sendChat( messages, model = '' ) {
	const config = getConfig();

	const response = await fetch( `${ config.restUrl }chat`, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': config.nonce,
		},
		credentials: 'same-origin',
		body: JSON.stringify( { messages, stream: false, model } ),
	} );

	const data = await response.json();

	if ( ! response.ok ) {
		const message =
			data?.message || data?.data?.message || config.i18n?.error;
		throw new Error( message );
	}

	return data;
}

/**
 * Send a streaming chat request.
 *
 * @param {Array}    messages  Messages.
 * @param {string}   model     Model override.
 * @param {Function} onChunk   Callback per chunk (content, done).
 * @return {Promise<string>}
 */
export async function sendChatStream( messages, model, onChunk ) {
	const config = getConfig();

	const response = await fetch( `${ config.restUrl }chat/stream`, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': config.nonce,
		},
		credentials: 'same-origin',
		body: JSON.stringify( { messages, model } ),
	} );

	if ( ! response.ok ) {
		const data = await response.json().catch( () => ( {} ) );
		throw new Error( data?.message || config.i18n?.error );
	}

	const reader = response.body?.getReader();
	if ( ! reader ) {
		throw new Error( config.i18n?.error );
	}

	const decoder = new TextDecoder();
	let fullContent = '';
	let buffer = '';

	while ( true ) {
		const { done, value } = await reader.read();
		if ( done ) {
			break;
		}

		buffer += decoder.decode( value, { stream: true } );
		const lines = buffer.split( '\n' );
		buffer = lines.pop() || '';

		for ( const line of lines ) {
			if ( ! line.startsWith( 'data: ' ) ) {
				continue;
			}
			try {
				const json = JSON.parse( line.slice( 6 ) );
				if ( json.error ) {
					throw new Error( json.error );
				}
				if ( json.chunk ) {
					fullContent += json.chunk;
					onChunk( json.chunk, false );
				}
				if ( json.done ) {
					onChunk( '', true );
				}
			} catch ( parseErr ) {
				if ( parseErr instanceof Error && parseErr.message !== 'Unexpected end of JSON input' ) {
					throw parseErr;
				}
			}
		}
	}

	return fullContent;
}

/**
 * Fetch history from server.
 *
 * @return {Promise<Array>}
 */
export async function fetchHistory() {
	const config = getConfig();

	const response = await fetch( `${ config.restUrl }history`, {
		headers: {
			'X-WP-Nonce': config.nonce,
		},
		credentials: 'same-origin',
	} );

	if ( ! response.ok ) {
		return [];
	}

	const data = await response.json();
	return data.messages || [];
}

/**
 * Save messages to server.
 *
 * @param {Array} messages Messages to save.
 * @return {Promise<void>}
 */
export async function saveHistory( messages ) {
	const config = getConfig();

	await fetch( `${ config.restUrl }history`, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': config.nonce,
		},
		credentials: 'same-origin',
		body: JSON.stringify( { messages } ),
	} );
}

/**
 * Clear server history.
 *
 * @return {Promise<void>}
 */
export async function clearHistory() {
	const config = getConfig();

	await fetch( `${ config.restUrl }history`, {
		method: 'DELETE',
		headers: {
			'X-WP-Nonce': config.nonce,
		},
		credentials: 'same-origin',
	} );
}
