<?php
/**
 * Document classifier tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Documents\Document_Classifier;

/**
 * Class DocumentClassifierTest
 */
class DocumentClassifierTest extends TestCase {

	/**
	 * Classifier under test.
	 *
	 * @var Document_Classifier
	 */
	private Document_Classifier $classifier;

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		$this->classifier = new Document_Classifier();
	}

	/**
	 * OSC filename is classified correctly.
	 */
	public function test_osc_filename_classification(): void {
		$result = $this->classifier->classify( 'motion_osc_return_date.pdf', 'Order to Show Cause returnable February 1' );

		$this->assertSame( 'order_to_show_cause', $result['type'] );
		$this->assertSame( 'temporary_relief', $result['stage'] );
		$this->assertGreaterThan( 0, $result['confidence'] );
	}

	/**
	 * Answer document is classified from text.
	 */
	public function test_answer_text_classification(): void {
		$result = $this->classifier->classify( 'scan001.pdf', 'Verified Answer to the complaint with affirmative defenses' );

		$this->assertSame( 'answer', $result['type'] );
		$this->assertSame( 'response', $result['stage'] );
	}

	/**
	 * Order keyword in filename.
	 */
	public function test_order_classification(): void {
		$result = $this->classifier->classify( 'court_order_signed.pdf', 'It is ordered that the parties appear' );

		$this->assertSame( 'order', $result['type'] );
	}

	/**
	 * Unknown document falls back gracefully.
	 */
	public function test_unknown_document_fallback(): void {
		$result = $this->classifier->classify( 'notes.txt', 'random meeting notes' );

		$this->assertSame( 'unknown', $result['type'] );
		$this->assertSame( 0.0, $result['confidence'] );
		$this->assertNull( $result['stage'] );
	}
}
