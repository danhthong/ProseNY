/**
 * Markdown rendering utilities.
 */

import { marked } from 'marked';
import hljs from 'highlight.js/lib/core';
import javascript from 'highlight.js/lib/languages/javascript';
import python from 'highlight.js/lib/languages/python';
import php from 'highlight.js/lib/languages/php';
import bash from 'highlight.js/lib/languages/bash';
import json from 'highlight.js/lib/languages/json';
import xml from 'highlight.js/lib/languages/xml';
import css from 'highlight.js/lib/languages/css';

hljs.registerLanguage( 'javascript', javascript );
hljs.registerLanguage( 'js', javascript );
hljs.registerLanguage( 'python', python );
hljs.registerLanguage( 'php', php );
hljs.registerLanguage( 'bash', bash );
hljs.registerLanguage( 'sh', bash );
hljs.registerLanguage( 'json', json );
hljs.registerLanguage( 'html', xml );
hljs.registerLanguage( 'xml', xml );
hljs.registerLanguage( 'css', css );

marked.setOptions( {
	highlight( code, lang ) {
		if ( lang && hljs.getLanguage( lang ) ) {
			try {
				return hljs.highlight( code, { language: lang } ).value;
			} catch {
				// Fall through.
			}
		}
		return hljs.highlightAuto( code ).value;
	},
	breaks: true,
	gfm: true,
} );

/**
 * Render markdown to HTML string.
 *
 * @param {string} content Markdown content.
 * @return {string}
 */
export function renderMarkdown( content ) {
	if ( ! content ) {
		return '';
	}
	return marked.parse( content );
}

/**
 * Copy text to clipboard.
 *
 * @param {string} text Text to copy.
 * @return {Promise<boolean>}
 */
export async function copyToClipboard( text ) {
	try {
		await navigator.clipboard.writeText( text );
		return true;
	} catch {
		return false;
	}
}
