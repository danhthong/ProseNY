<?php
/**
 * Document provider contract — output abstraction for generated documents.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface Document_Provider_Interface
 *
 * Output abstraction for rendering a Generated_Document (or a full
 * Package_Document_Bundle) into a concrete artifact. Future providers —
 * PDF, DOCX, XML, and court-specific exports — implement this contract.
 *
 * Rendering is intentionally NOT implemented yet; this interface only
 * defines the boundary the renderer integration will sit behind.
 */
interface Document_Provider_Interface {

	// Output format identifiers.
	public const FORMAT_PDF   = 'pdf';
	public const FORMAT_DOCX  = 'docx';
	public const FORMAT_XML   = 'xml';
	public const FORMAT_COURT = 'court_export';

	/**
	 * Provider identifier (e.g. pdf, docx, xml, court_export).
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * Output format produced by the provider.
	 *
	 * @return string
	 */
	public function format(): string;

	/**
	 * Whether the provider can run in the current environment.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Whether the provider can render the given document.
	 *
	 * @param Generated_Document $document Generated document.
	 * @return bool
	 */
	public function supports( Generated_Document $document ): bool;

	/**
	 * Render a single generated document to a concrete artifact.
	 *
	 * Implementations return a descriptor of the produced artifact, e.g.
	 * { format, mime_type, path|url|content, bytes }.
	 *
	 * @param Generated_Document   $document Generated document.
	 * @param array<string, mixed> $options  Provider-specific options.
	 * @return array<string, mixed>
	 */
	public function render( Generated_Document $document, array $options = array() ): array;

	/**
	 * Render an entire package bundle to a concrete artifact (or set).
	 *
	 * @param Package_Document_Bundle $bundle  Package bundle.
	 * @param array<string, mixed>    $options Provider-specific options.
	 * @return array<string, mixed>
	 */
	public function render_bundle( Package_Document_Bundle $bundle, array $options = array() ): array;
}
