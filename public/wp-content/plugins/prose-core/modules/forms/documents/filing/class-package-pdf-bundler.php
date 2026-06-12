<?php
/**
 * Package PDF bundler — fill every form of a package in filing order.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents\Filing;

use ProSe\Core\Forms\Documents\Package_Document_Bundle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Package_Pdf_Bundler
 *
 * Turns a Package_Document_Bundle into an ordered list of filled court forms,
 * ready to be merged into a single packet. Filing order follows the package
 * catalog: required forms first (in catalog order), then the optional forms
 * selected for the package. Only forms actually present in the bundle are
 * included.
 */
final class Package_Pdf_Bundler {

	/**
	 * Court PDF fill service.
	 *
	 * @var Court_Pdf_Fill_Service
	 */
	private Court_Pdf_Fill_Service $filler;

	/**
	 * Constructor.
	 *
	 * @param Court_Pdf_Fill_Service|null $filler Fill service.
	 */
	public function __construct( ?Court_Pdf_Fill_Service $filler = null ) {
		$this->filler = $filler ?? new Court_Pdf_Fill_Service();
	}

	/**
	 * Filing order for a bundle: required forms then optional forms, limited
	 * to those present in the bundle.
	 *
	 * @param Package_Document_Bundle $bundle Bundle.
	 * @return string[]
	 */
	public function filing_order( Package_Document_Bundle $bundle ): array {
		$order = array();

		foreach ( array_merge( $bundle->required_forms(), $bundle->optional_forms() ) as $form_code ) {
			if ( null !== $bundle->document( $form_code ) && ! in_array( $form_code, $order, true ) ) {
				$order[] = $form_code;
			}
		}

		return $order;
	}

	/**
	 * Fill every form of a bundle into ordered fill descriptors.
	 *
	 * @param Package_Document_Bundle $bundle  Bundle.
	 * @param array<string, mixed>    $options Options.
	 * @return array<string, mixed> { package_key, order, forms }.
	 */
	public function bundle( Package_Document_Bundle $bundle, array $options = array() ): array {
		$order = $this->filing_order( $bundle );
		$forms = array();

		foreach ( $order as $form_code ) {
			$document = $bundle->document( $form_code );

			if ( null === $document ) {
				continue;
			}

			$forms[] = $this->filler->fill( $document, $options );
		}

		return array(
			'package_key' => $bundle->package_key(),
			'order'       => $order,
			'forms'       => $forms,
		);
	}
}
