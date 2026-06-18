<?php
/**
 * Document Request Detector — recognizes when the user wants blank forms/PDFs.
 *
 * Shared by the deterministic intake agent and the AI interpreter so both
 * paths honor the same "give me the paperwork" intent.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Intake;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Document_Request_Detector
 */
final class Document_Request_Detector {

	/**
	 * Whether the user is asking for blank forms, a PDF download, or paperwork.
	 *
	 * @param string $message User message.
	 * @return bool
	 */
	public function wants_documents( string $message ): bool {
		$text = strtolower( trim( $message ) );

		if ( '' === $text ) {
			return false;
		}

		$phrases = array(
			'blank pdf',
			'blank pdfs',
			'blank forms',
			'blank form',
			'blank packet',
			'blank paperwork',
			'just the blank',
			'just forms',
			'just the form',
			'just want the form',
			'just need the form',
			'just give me the form',
			'give me the form',
			'give me forms',
			'give me the forms',
			'i want the form',
			'i need the form',
			'i want forms',
			'i need forms',
			'i want pdf',
			'i need pdf',
			'i want a pdf',
			'i need a pdf',
			'i want blank',
			'i need blank',
			'download pdf',
			'download the pdf',
			'download forms',
			'download form',
			'download the form',
			'download the forms',
			'get pdf',
			'get the pdf',
			'get forms',
			'get the forms',
			'show me the form',
			'show me forms',
			'skip the question',
			'skip question',
			"don't want to answer",
			'do not want to answer',
			'dont want to answer',
			'without answering',
			'no questions',
			'document',
			'documents',
			'paperwork',
			'package',
			'print',
			'give me',
			'send me',
			'get me',
			'i need the',
			'i want the',
		);

		foreach ( $phrases as $phrase ) {
			if ( str_contains( $text, $phrase ) ) {
				return true;
			}
		}

		// Standalone strong tokens (e.g. "pdf" or "download" alone).
		if ( preg_match( '/\b(pdf|download)\b/', $text ) ) {
			return true;
		}

		return false;
	}
}
