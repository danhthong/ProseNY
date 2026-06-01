/**
 * Chat history storage utilities.
 */

const MAX_MESSAGES = 100;

/**
 * Get localStorage key for site.
 *
 * @param {number} siteId Site ID.
 * @return {string}
 */
export function getStorageKey( siteId ) {
	return `ollama_ai_chat_${ siteId || 1 }`;
}

/**
 * Load messages from localStorage.
 *
 * @param {number} siteId Site ID.
 * @return {Array}
 */
export function loadLocalHistory( siteId ) {
	try {
		const raw = localStorage.getItem( getStorageKey( siteId ) );
		if ( ! raw ) {
			return [];
		}
		const parsed = JSON.parse( raw );
		return Array.isArray( parsed ) ? parsed : [];
	} catch {
		return [];
	}
}

/**
 * Save messages to localStorage.
 *
 * @param {number} siteId   Site ID.
 * @param {Array}  messages Messages.
 */
export function saveLocalHistory( siteId, messages ) {
	try {
		const trimmed = messages.slice( -MAX_MESSAGES );
		localStorage.setItem(
			getStorageKey( siteId ),
			JSON.stringify( trimmed )
		);
	} catch {
		// Storage full or unavailable.
	}
}

/**
 * Clear localStorage history.
 *
 * @param {number} siteId Site ID.
 */
export function clearLocalHistory( siteId ) {
	try {
		localStorage.removeItem( getStorageKey( siteId ) );
	} catch {
		// Ignore.
	}
}

/**
 * Whether localStorage should be used.
 *
 * @param {string} historyMode History mode setting.
 * @param {boolean} isLoggedIn User logged in.
 * @return {boolean}
 */
export function shouldUseLocal( historyMode, isLoggedIn ) {
	if ( historyMode === 'local' ) {
		return true;
	}
	if ( historyMode === 'db' ) {
		return false;
	}
	// both: guests use local, logged-in also syncs local as backup
	return ! isLoggedIn || historyMode === 'both';
}

/**
 * Whether database history should be used.
 *
 * @param {string} historyMode History mode setting.
 * @param {boolean} isLoggedIn User logged in.
 * @return {boolean}
 */
export function shouldUseDb( historyMode, isLoggedIn ) {
	if ( ! isLoggedIn ) {
		return false;
	}
	return historyMode === 'db' || historyMode === 'both';
}
