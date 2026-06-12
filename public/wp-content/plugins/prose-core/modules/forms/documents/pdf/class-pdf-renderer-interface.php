<?php
/**
 * PDF renderer contract.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents\Pdf;

use ProSe\Core\Forms\Documents\Generated_Document;
use ProSe\Core\Forms\Documents\Package_Document_Bundle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface Pdf_Renderer_Interface
 *
 * Contract for rendering Document Generation Engine outputs into PDF files.
 * Implementations consume a Generated_Document (single form) or a
 * Package_Document_Bundle (combined package PDF) and return a
 * Pdf_Render_Result describing the produced artifact.
 */
interface Pdf_Renderer_Interface {

	/**
	 * Output format identifier.
	 *
	 * @return string
	 */
	public function format(): string;

	/**
	 * Whether the renderer can produce a PDF for the given form code.
	 *
	 * @param string $form_code Form code.
	 * @return bool
	 */
	public function supports_form( string $form_code ): bool;

	/**
	 * Render a single generated document to a PDF.
	 *
	 * @param Generated_Document   $document Generated document.
	 * @param array<string, mixed> $options  Render options (filename, store, ...).
	 * @return Pdf_Render_Result
	 */
	public function render_document( Generated_Document $document, array $options = array() ): Pdf_Render_Result;

	/**
	 * Render a full package bundle into a single combined PDF.
	 *
	 * @param Package_Document_Bundle $bundle  Package bundle.
	 * @param array<string, mixed>    $options Render options (filename, store, ...).
	 * @return Pdf_Render_Result
	 */
	public function render_package( Package_Document_Bundle $bundle, array $options = array() ): Pdf_Render_Result;
}
