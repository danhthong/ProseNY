<?php
/**
 * Intent Detector — extracts intent signals from user text.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Routing\Resolver;

use ProSe\Core\Routing\Signal_Lexicon;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Intent_Detector
 */
final class Intent_Detector {

	/**
	 * Signal lexicon.
	 *
	 * @var Signal_Lexicon
	 */
	private Signal_Lexicon $lexicon;

	/**
	 * Constructor.
	 *
	 * @param Signal_Lexicon|null $lexicon Signal lexicon.
	 */
	public function __construct( ?Signal_Lexicon $lexicon = null ) {
		$this->lexicon = $lexicon ?? new Signal_Lexicon();
	}

	/**
	 * Detect intent signals from text.
	 *
	 * @param string $text User text.
	 * @return array{signals: string[]}
	 */
	public function detect( string $text ): array {
		return array(
			'signals' => $this->lexicon->extract_signals( $text ),
		);
	}

	/**
	 * Extract facts from text.
	 *
	 * @param string $text User text.
	 * @return array<string, mixed>
	 */
	public function extract_facts( string $text ): array {
		return $this->lexicon->extract_facts( $text );
	}
}
