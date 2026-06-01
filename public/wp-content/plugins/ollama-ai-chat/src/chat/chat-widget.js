/**
 * Vanilla JS chat widget.
 */

import {
	getConfig,
	sendChat,
	sendChatStream,
	fetchHistory,
	saveHistory,
	clearHistory,
} from './utils/api';
import {
	loadLocalHistory,
	saveLocalHistory,
	clearLocalHistory,
	shouldUseLocal,
	shouldUseDb,
} from './utils/storage';
import { renderMarkdown, copyToClipboard } from './utils/markdown';

/**
 * Apply theme class to element.
 *
 * @param {HTMLElement} el    Element.
 * @param {string}        theme Theme mode.
 */
function applyTheme( el, theme ) {
	if ( ! el ) {
		return;
	}
	el.classList.remove( 'dark', 'oac-light' );
	if ( theme === 'dark' ) {
		el.classList.add( 'dark' );
	} else if ( theme === 'light' ) {
		el.classList.add( 'oac-light' );
	} else if ( window.matchMedia( '(prefers-color-scheme: dark)' ).matches ) {
		el.classList.add( 'dark' );
	}
}

/**
 * Chat widget controller.
 */
export class ChatWidget {
	constructor( mountEl, instance ) {
		this.mountEl = mountEl;
		this.config = getConfig();
		this.title = instance.title || this.config.title || 'AI Assistant';
		this.height = instance.height || '520px';
		this.theme = instance.theme || 'auto';
		this.model = instance.model || '';
		this.layout = instance.layout || 'widget';
		this.isWidget = this.layout === 'widget';
		this.isOpen = ! this.isWidget;
		this.messages = [];
		this.isLoading = false;
		this.error = null;
		this.el = {};
		this.init();
	}

	async init() {
		applyTheme( this.mountEl, this.theme );
		if ( this.theme === 'auto' ) {
			window.matchMedia( '(prefers-color-scheme: dark)' ).addEventListener( 'change', () => {
				applyTheme( this.mountEl, 'auto' );
			} );
		}
		await this.loadHistory();
		this.render();
		this.bindEvents();
	}

	async loadHistory() {
		let loaded = [];
		if ( shouldUseDb( this.config.historyMode, this.config.isLoggedIn ) ) {
			try {
				loaded = await fetchHistory();
			} catch {
				// Fall through.
			}
		}
		if ( loaded.length === 0 && shouldUseLocal( this.config.historyMode, this.config.isLoggedIn ) ) {
			loaded = loadLocalHistory( this.config.siteId );
		}
		this.messages = loaded;
	}

	render() {
		const i18n = this.config.i18n || {};
		const wrapper = document.createElement( 'div' );
		wrapper.className = 'ollama-ai-chat-widget';
		wrapper.innerHTML = this.buildTemplate( i18n );
		this.mountEl.innerHTML = '';
		this.mountEl.appendChild( wrapper );

		this.el = {
			wrapper,
			toggle: wrapper.querySelector( '[data-oac-toggle]' ),
			panel: wrapper.querySelector( '[data-oac-panel]' ),
			messages: wrapper.querySelector( '[data-oac-messages]' ),
			error: wrapper.querySelector( '[data-oac-error]' ),
			loginNotice: wrapper.querySelector( '[data-oac-login-notice]' ),
			form: wrapper.querySelector( '[data-oac-form]' ),
			input: wrapper.querySelector( '[data-oac-input]' ),
			send: wrapper.querySelector( '[data-oac-send]' ),
			clear: wrapper.querySelector( '[data-oac-clear]' ),
			close: wrapper.querySelector( '[data-oac-close]' ),
			toggleOpen: wrapper.querySelector( '.oac-toggle-open' ),
			toggleClose: wrapper.querySelector( '.oac-toggle-close' ),
		};

		this.updatePanelState();
		this.renderMessages();
	}

	buildTemplate( i18n ) {
		const panelClasses = [
			'oac-flex', 'oac-flex-col', 'oac-bg-white', 'dark:oac-bg-gray-900',
			'oac-shadow-2xl', 'oac-border', 'oac-border-gray-200', 'dark:oac-border-gray-700',
			'oac-overflow-hidden', 'oac-transition-all', 'oac-duration-300', 'oac-ease-in-out',
		];
		if ( this.isWidget ) {
			panelClasses.push(
				'oac-fixed', 'oac-z-[99999]', 'oac-bottom-24', 'oac-right-4',
				'oac-w-[380px]', 'oac-max-w-[calc(100vw-2rem)]', 'oac-rounded-2xl'
			);
		} else {
			panelClasses.push( 'oac-w-full', 'oac-rounded-2xl' );
		}

		const parts = [];

		if ( this.isWidget ) {
			parts.push(
				`<button type="button" data-oac-toggle class="oac-fixed oac-z-[99999] oac-bottom-4 oac-right-4 oac-w-14 oac-h-14 oac-rounded-full oac-bg-ollama-primary oac-text-white oac-shadow-lg oac-flex oac-items-center oac-justify-center hover:oac-scale-105 oac-transition-transform" aria-label="${ this.escapeAttr( i18n.open || 'Open chat' ) }">`,
				'<svg class="oac-w-6 oac-h-6 oac-toggle-open" fill="currentColor" viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>',
				'<svg class="oac-w-6 oac-h-6 oac-toggle-close oac-hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>',
				'</button>'
			);
		}

		const closeBtn = this.isWidget
			? `<button type="button" data-oac-close class="oac-p-1.5 oac-rounded-lg hover:oac-bg-white/20" title="${ this.escapeAttr( i18n.close || 'Close' ) }"><svg class="oac-w-4 oac-h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>`
			: '';

		parts.push(
			`<div data-oac-panel class="${ panelClasses.join( ' ' ) }" style="height:${ this.escapeAttr( this.height ) }" role="dialog" aria-label="${ this.escapeAttr( this.title ) }">`,
			'<div class="oac-flex oac-items-center oac-justify-between oac-px-4 oac-py-3 oac-bg-ollama-primary oac-text-white oac-rounded-t-2xl">',
			'<div class="oac-flex oac-items-center oac-gap-2">',
			'<div class="oac-w-8 oac-h-8 oac-rounded-full oac-bg-white/20 oac-flex oac-items-center oac-justify-center">',
			'<svg class="oac-w-4 oac-h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2a2 2 0 012 2c0 .74-.4 1.39-1 1.73V7h1a7 7 0 017 7v2a2 2 0 012 2v3a2 2 0 01-2 2h-1v1a2 2 0 01-2 2H6a2 2 0 01-2-2v-1H3a2 2 0 01-2-2v-3a2 2 0 012-2v-2a7 7 0 017-7h1V5.73c-.6-.34-1-.99-1-1.73a2 2 0 012-2zm0 4h-1a5 5 0 00-5 5v2h12v-2a5 5 0 00-5-5z"/></svg>',
			'</div>',
			`<h3 class="oac-font-semibold oac-text-sm">${ this.escapeHtml( this.title ) }</h3>`,
			'</div>',
			'<div class="oac-flex oac-gap-1">',
			`<button type="button" data-oac-clear class="oac-p-1.5 oac-rounded-lg hover:oac-bg-white/20" title="${ this.escapeAttr( i18n.clear || 'Clear' ) }">`,
			'<svg class="oac-w-4 oac-h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>',
			'</button>',
			closeBtn,
			'</div>',
			'</div>',
			'<div data-oac-error class="oac-hidden oac-px-4 oac-py-2 oac-bg-red-50 dark:oac-bg-red-900/30 oac-text-red-600 dark:oac-text-red-400 oac-text-xs"></div>',
			`<div data-oac-login-notice class="${ this.config.isLoggedIn ? 'oac-hidden' : '' } oac-px-4 oac-py-3 oac-text-center oac-text-sm oac-text-gray-500">${ this.escapeHtml( i18n.loginRequired || '' ) }</div>`,
			'<div data-oac-messages class="oac-flex-1 oac-overflow-y-auto oac-p-4 oac-space-y-1"></div>',
			'<form data-oac-form class="oac-border-t oac-border-gray-200 dark:oac-border-gray-700 oac-p-3 oac-bg-white dark:oac-bg-gray-900">',
			'<div class="oac-flex oac-gap-2 oac-items-end">',
			`<textarea data-oac-input rows="1" placeholder="${ this.escapeAttr( i18n.placeholder || 'Type your message...' ) }" class="oac-flex-1 oac-resize-none oac-rounded-xl oac-border oac-border-gray-200 dark:oac-border-gray-700 oac-bg-gray-50 dark:oac-bg-gray-800 oac-px-4 oac-py-2.5 oac-text-sm oac-text-gray-900 dark:oac-text-gray-100 oac-outline-none focus:oac-ring-2 focus:oac-ring-ollama-primary oac-max-h-32"></textarea>`,
			`<button type="submit" data-oac-send class="oac-flex-shrink-0 oac-bg-ollama-primary oac-text-white oac-rounded-xl oac-px-4 oac-py-2.5 oac-text-sm oac-font-medium hover:oac-opacity-90 disabled:oac-opacity-50">${ this.escapeHtml( i18n.send || 'Send' ) }</button>`,
			'</div>',
			'</form>',
			'</div>'
		);

		return parts.join( '' );
	}

	bindEvents() {
		if ( this.el.toggle ) {
			this.el.toggle.addEventListener( 'click', () => {
				this.isOpen = ! this.isOpen;
				this.updatePanelState();
			} );
		}
		if ( this.el.close ) {
			this.el.close.addEventListener( 'click', () => {
				this.isOpen = false;
				this.updatePanelState();
			} );
		}
		if ( this.el.clear ) {
			this.el.clear.addEventListener( 'click', () => this.handleClear() );
		}
		if ( this.el.form ) {
			this.el.form.addEventListener( 'submit', ( e ) => {
				e.preventDefault();
				this.handleSend();
			} );
		}
		if ( this.el.input ) {
			this.el.input.addEventListener( 'keydown', ( e ) => {
				if ( e.key === 'Enter' && ! e.shiftKey ) {
					e.preventDefault();
					this.handleSend();
				}
			} );
		}
	}

	updatePanelState() {
		if ( ! this.el.panel ) {
			return;
		}
		const hidden = this.isWidget && ! this.isOpen;
		this.el.panel.classList.toggle( 'oac-opacity-0', hidden );
		this.el.panel.classList.toggle( 'oac-pointer-events-none', hidden );
		this.el.panel.classList.toggle( 'oac-scale-95', hidden );
		this.el.panel.classList.toggle( 'oac-translate-y-4', hidden );
		this.el.panel.classList.toggle( 'oac-opacity-100', ! hidden );
		this.el.panel.classList.toggle( 'oac-scale-100', ! hidden );
		this.el.panel.classList.toggle( 'oac-translate-y-0', ! hidden );

		if ( this.el.toggleOpen ) {
			this.el.toggleOpen.classList.toggle( 'oac-hidden', this.isOpen );
		}
		if ( this.el.toggleClose ) {
			this.el.toggleClose.classList.toggle( 'oac-hidden', ! this.isOpen );
		}
		if ( this.el.toggle ) {
			const i18n = this.config.i18n || {};
			this.el.toggle.setAttribute(
				'aria-label',
				this.isOpen ? ( i18n.close || 'Close' ) : ( i18n.open || 'Open chat' )
			);
		}
	}

	setError( message ) {
		this.error = message;
		if ( ! this.el.error ) {
			return;
		}
		if ( message ) {
			this.el.error.textContent = message;
			this.el.error.classList.remove( 'oac-hidden' );
		} else {
			this.el.error.classList.add( 'oac-hidden' );
			this.el.error.textContent = '';
		}
	}

	setLoading( loading ) {
		this.isLoading = loading;
		if ( this.el.input ) {
			this.el.input.disabled = loading || ! this.config.isLoggedIn;
		}
		if ( this.el.send ) {
			this.el.send.disabled = loading || ! this.config.isLoggedIn;
		}
		this.renderMessages();
	}

	renderMessages() {
		if ( ! this.el.messages ) {
			return;
		}

		const i18n = this.config.i18n || {};
		this.el.messages.innerHTML = '';

		if ( this.messages.length === 0 && ! this.isLoading ) {
			const empty = document.createElement( 'div' );
			empty.className = 'oac-flex oac-items-center oac-justify-center oac-h-full oac-text-gray-400 oac-text-sm';
			empty.textContent = 'Start a conversation...';
			this.el.messages.appendChild( empty );
		}

		this.messages.forEach( ( msg, index ) => {
			this.el.messages.appendChild( this.createMessageEl( msg, index ) );
		} );

		if ( this.isLoading ) {
			this.el.messages.appendChild( this.createLoadingEl() );
		}

		this.el.messages.scrollTop = this.el.messages.scrollHeight;
	}

	createMessageEl( msg, index ) {
		const isUser = msg.role === 'user';
		const wrap = document.createElement( 'div' );
		wrap.className = `oac-flex oac-mb-3 ${ isUser ? 'oac-justify-end' : 'oac-justify-start' }`;

		const bubble = document.createElement( 'div' );
		bubble.className = isUser
			? 'oac-max-w-[85%] oac-rounded-2xl oac-px-4 oac-py-2.5 oac-text-sm oac-leading-relaxed oac-shadow-sm oac-bg-ollama-primary oac-text-white oac-rounded-br-md'
			: 'oac-max-w-[85%] oac-rounded-2xl oac-px-4 oac-py-2.5 oac-text-sm oac-leading-relaxed oac-shadow-sm oac-bg-gray-100 dark:oac-bg-gray-800 oac-text-gray-900 dark:oac-text-gray-100 oac-rounded-bl-md';

		if ( isUser ) {
			const p = document.createElement( 'p' );
			p.className = 'oac-whitespace-pre-wrap';
			p.textContent = msg.content;
			bubble.appendChild( p );
		} else {
			const content = document.createElement( 'div' );
			content.className = 'oac-chat-markdown oac-prose oac-prose-sm dark:oac-prose-invert oac-max-w-none';
			content.innerHTML = renderMarkdown( msg.content );
			bubble.appendChild( content );
			this.attachCopyButtons( content );
		}

		if ( msg.timestamp ) {
			const time = document.createElement( 'p' );
			time.className = 'oac-text-[10px] oac-opacity-50 oac-mt-1 oac-text-right';
			time.textContent = new Date( msg.timestamp ).toLocaleTimeString();
			bubble.appendChild( time );
		}

		wrap.appendChild( bubble );
		return wrap;
	}

	createLoadingEl() {
		const wrap = document.createElement( 'div' );
		wrap.className = 'oac-flex oac-justify-start oac-mb-3';
		const inner = document.createElement( 'div' );
		inner.className = 'oac-bg-gray-100 dark:oac-bg-gray-800 oac-rounded-2xl oac-px-4 oac-py-3 oac-flex oac-gap-1.5';
		for ( const delay of [ '0ms', '150ms', '300ms' ] ) {
			const dot = document.createElement( 'span' );
			dot.className = 'oac-w-2 oac-h-2 oac-bg-ollama-primary oac-rounded-full oac-animate-bounce';
			dot.style.animationDelay = delay;
			inner.appendChild( dot );
		}
		wrap.appendChild( inner );
		return wrap;
	}

	attachCopyButtons( container ) {
		const i18n = this.config.i18n || {};
		container.querySelectorAll( 'pre code' ).forEach( ( block ) => {
			const pre = block.parentElement;
			if ( ! pre || pre.querySelector( '.oac-copy-btn' ) ) {
				return;
			}
			pre.style.position = 'relative';
			const btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'oac-copy-btn oac-absolute oac-top-2 oac-right-2 oac-text-xs oac-bg-gray-700 oac-text-white oac-px-2 oac-py-1 oac-rounded oac-opacity-70 hover:oac-opacity-100';
			btn.textContent = i18n.copy || 'Copy';
			btn.addEventListener( 'click', async () => {
				const ok = await copyToClipboard( block.textContent || '' );
				btn.textContent = ok ? ( i18n.copied || 'Copied!' ) : ( i18n.copy || 'Copy' );
				setTimeout( () => {
					btn.textContent = i18n.copy || 'Copy';
				}, 2000 );
			} );
			pre.appendChild( btn );
		} );
	}

	async persistHistory( updatedMessages ) {
		if ( shouldUseLocal( this.config.historyMode, this.config.isLoggedIn ) ) {
			saveLocalHistory( this.config.siteId, updatedMessages );
		}
		if ( shouldUseDb( this.config.historyMode, this.config.isLoggedIn ) ) {
			const lastTwo = updatedMessages.slice( -2 );
			if ( lastTwo.length > 0 ) {
				try {
					await saveHistory( lastTwo );
				} catch {
					// Non-fatal.
				}
			}
		}
	}

	async handleSend() {
		const content = this.el.input?.value?.trim();
		if ( ! content || this.isLoading ) {
			return;
		}

		if ( ! this.config.isLoggedIn ) {
			this.setError( this.config.i18n?.loginRequired || 'Please log in to use the chat.' );
			return;
		}

		this.setError( null );
		const userMessage = {
			role: 'user',
			content,
			timestamp: new Date().toISOString(),
		};
		const previousMessages = [ ...this.messages ];
		this.messages = [ ...this.messages, userMessage ];
		this.el.input.value = '';
		this.setLoading( true );

		const apiMessages = this.messages.map( ( { role, content: c } ) => ( { role, content: c } ) );

		try {
			let assistantContent = '';

			if ( this.config.enableStreaming ) {
				const assistantMessage = {
					role: 'assistant',
					content: '',
					timestamp: new Date().toISOString(),
				};
				this.messages.push( assistantMessage );
				const assistantIndex = this.messages.length - 1;

				assistantContent = await sendChatStream(
					apiMessages,
					this.model,
					( chunk ) => {
						if ( chunk ) {
							assistantContent += chunk;
							this.messages[ assistantIndex ].content = assistantContent;
							this.renderMessages();
						}
					}
				);
				this.messages[ assistantIndex ].content = assistantContent;
			} else {
				const response = await sendChat( apiMessages, this.model );
				assistantContent = response?.message?.content || response?.content || '';
				this.messages.push( {
					role: 'assistant',
					content: assistantContent,
					timestamp: new Date().toISOString(),
				} );
			}

			await this.persistHistory( this.messages );
		} catch ( err ) {
			this.setError( err.message || this.config.i18n?.error );
			this.messages = previousMessages;
		} finally {
			this.setLoading( false );
			this.renderMessages();
		}
	}

	async handleClear() {
		this.messages = [];
		this.setError( null );
		if ( shouldUseLocal( this.config.historyMode, this.config.isLoggedIn ) ) {
			clearLocalHistory( this.config.siteId );
		}
		if ( shouldUseDb( this.config.historyMode, this.config.isLoggedIn ) ) {
			try {
				await clearHistory();
			} catch {
				// Non-fatal.
			}
		}
		this.renderMessages();
	}

	escapeHtml( text ) {
		const div = document.createElement( 'div' );
		div.textContent = text;
		return div.innerHTML;
	}

	escapeAttr( text ) {
		return String( text )
			.replace( /&/g, '&amp;' )
			.replace( /"/g, '&quot;' )
			.replace( /</g, '&lt;' );
	}
}
