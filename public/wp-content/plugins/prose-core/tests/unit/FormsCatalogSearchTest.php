<?php
/**
 * Forms catalog search tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Forms\Forms_Catalog;
use ProSe\Core\Routing\Workflow_Catalog;

/**
 * Class FormsCatalogSearchTest
 */
class FormsCatalogSearchTest extends TestCase {

	/**
	 * @var Forms_Catalog
	 */
	private Forms_Catalog $catalog;

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		Forms_Catalog::reset_cache();
		Workflow_Catalog::reset_cache();
		$this->catalog = new Forms_Catalog();
	}

	/**
	 * Exact form code search returns UD-1 with workflow references.
	 */
	public function test_search_by_form_code(): void {
		$results = $this->catalog->search( array( 'q' => 'UD-1' ), 5 );

		$this->assertNotEmpty( $results );
		$this->assertSame( 'UD-1', $results[0]['code'] );
		$this->assertSame( 'supreme_court', $results[0]['court'] );
		$this->assertNotEmpty( $results[0]['workflows'] );
	}

	/**
	 * Order of protection workflow maps Family Offense Petition (8-2).
	 */
	public function test_order_of_protection_form_mapping(): void {
		$results = $this->catalog->search(
			array(
				'workflow' => 'order_of_protection_nyc',
			),
			50
		);

		$codes = array_column( $results, 'code' );
		$this->assertContains( '8-2', $codes );
	}

	/**
	 * Every workflow-required form exists in the catalog.
	 */
	public function test_workflow_coverage_has_no_gaps(): void {
		$missing = $this->catalog->validate_workflow_coverage();

		$this->assertSame( array(), $missing, 'Missing forms: ' . wp_json_encode( $missing ) );
	}

	/**
	 * Uncontested divorce no children lists the expected UD packet forms.
	 */
	public function test_uncontested_divorce_no_children_forms(): void {
		$records = $this->catalog->get_form_records_for_workflow( 'uncontested_divorce_no_children_nyc' );
		$codes   = array_keys( $records );

		foreach ( array( 'UD-1', 'UD-2', 'UD-3', 'UD-4', 'UD-6', 'UD-7' ) as $expected ) {
			$this->assertContains( $expected, $codes, "Expected form $expected in uncontested packet" );
		}
	}
}
