<?php
/**
 * PDF renderer — render generated documents and package bundles to PDF.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents\Pdf;

use ProSe\Core\Forms\Documents\Document_Provider_Interface;
use ProSe\Core\Forms\Documents\Generated_Document;
use ProSe\Core\Forms\Documents\Package_Document_Bundle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Pdf_Renderer
 *
 * Renders Document Generation Engine outputs into PDF files. A single form
 * becomes a one-document PDF; a Package_Document_Bundle becomes a single
 * combined PDF (cover page + one section per form). The renderer resolves the
 * template via Pdf_Template_Registry, maps fields via Pdf_Field_Mapper, draws
 * with the dependency-free Pdf_Document_Writer, and persists through
 * Pdf_Storage_Service — returning a Pdf_Render_Result with the artifact and
 * audit metadata.
 *
 * Implements both the PDF-specific contract and the generic document provider
 * abstraction so it slots into the existing output boundary.
 */
final class Pdf_Renderer implements Pdf_Renderer_Interface, Document_Provider_Interface {

	/**
	 * Template registry.
	 *
	 * @var Pdf_Template_Registry
	 */
	private Pdf_Template_Registry $registry;

	/**
	 * Field mapper.
	 *
	 * @var Pdf_Field_Mapper
	 */
	private Pdf_Field_Mapper $mapper;

	/**
	 * Storage service.
	 *
	 * @var Pdf_Storage_Service
	 */
	private Pdf_Storage_Service $storage;

	/**
	 * Low-level PDF writer.
	 *
	 * @var Pdf_Document_Writer
	 */
	private Pdf_Document_Writer $writer;

	/**
	 * Constructor.
	 *
	 * @param Pdf_Template_Registry|null $registry Template registry.
	 * @param Pdf_Field_Mapper|null      $mapper   Field mapper.
	 * @param Pdf_Storage_Service|null   $storage  Storage service.
	 * @param Pdf_Document_Writer|null   $writer   PDF writer.
	 */
	public function __construct(
		?Pdf_Template_Registry $registry = null,
		?Pdf_Field_Mapper $mapper = null,
		?Pdf_Storage_Service $storage = null,
		?Pdf_Document_Writer $writer = null
	) {
		$this->registry = $registry ?? new Pdf_Template_Registry();
		$this->mapper   = $mapper ?? new Pdf_Field_Mapper();
		$this->storage  = $storage ?? new Pdf_Storage_Service();
		$this->writer   = $writer ?? new Pdf_Document_Writer();
	}

	/**
	 * Provider identifier.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'pdf';
	}

	/**
	 * Output format.
	 *
	 * @return string
	 */
	public function format(): string {
		return Document_Provider_Interface::FORMAT_PDF;
	}

	/**
	 * Whether the provider can run here (builtin renderer is always available).
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * Whether the provider can render a document.
	 *
	 * @param Generated_Document $document Document.
	 * @return bool
	 */
	public function supports( Generated_Document $document ): bool {
		return $this->supports_form( $document->form_code() );
	}

	/**
	 * Whether the renderer supports a form code (builtin covers all forms).
	 *
	 * @param string $form_code Form code.
	 * @return bool
	 */
	public function supports_form( string $form_code ): bool {
		return '' !== $form_code;
	}

	/**
	 * Render a single document (Document_Provider_Interface).
	 *
	 * @param Generated_Document   $document Document.
	 * @param array<string, mixed> $options  Options.
	 * @return array<string, mixed>
	 */
	public function render( Generated_Document $document, array $options = array() ): array {
		return $this->render_document( $document, $options )->to_array();
	}

	/**
	 * Render a bundle (Document_Provider_Interface).
	 *
	 * @param Package_Document_Bundle $bundle  Bundle.
	 * @param array<string, mixed>    $options Options.
	 * @return array<string, mixed>
	 */
	public function render_bundle( Package_Document_Bundle $bundle, array $options = array() ): array {
		return $this->render_package( $bundle, $options )->to_array();
	}

	/**
	 * Render a single generated document into a PDF.
	 *
	 * @param Generated_Document   $document Document.
	 * @param array<string, mixed> $options  Options (filename, store).
	 * @return Pdf_Render_Result
	 */
	public function render_document( Generated_Document $document, array $options = array() ): Pdf_Render_Result {
		$start    = microtime( true );
		$template = $this->registry->resolve( $document->form_code() );
		$entries  = $this->mapper->map_document( $document, true );
		$counts   = $this->count_entries( $entries );

		$bytes    = $this->writer->build( array( $this->document_lines( $document, $template, $entries ) ) );
		$filename = $this->filename( $options, $document->form_code() . '.pdf' );
		$stored   = $this->persist( $bytes, $filename, $options );

		return new Pdf_Render_Result(
			array(
				'success'          => true,
				'scope'            => Pdf_Render_Result::SCOPE_FORM,
				'format'           => $this->format(),
				'form_code'        => $document->form_code(),
				'package_key'      => $document->package_key(),
				'template'         => (string) $template['form_code'],
				'template_version' => (string) $template['template_version'],
				'renderer_type'    => (string) $template['renderer_type'],
				'file_path'        => (string) $stored['file_path'],
				'download_url'     => (string) $stored['download_url'],
				'checksum'         => (string) $stored['checksum'],
				'bytes'            => (int) $stored['bytes'],
				'field_count'      => $counts['total'],
				'resolved_count'   => $counts['resolved'],
				'missing_count'    => $counts['missing'],
				'duration_ms'      => $this->elapsed_ms( $start ),
				'audit'            => $this->document_audit( $document, $template ),
			)
		);
	}

	/**
	 * Render a package bundle into a single combined PDF.
	 *
	 * @param Package_Document_Bundle $bundle  Bundle.
	 * @param array<string, mixed>    $options Options (filename, store).
	 * @return Pdf_Render_Result
	 */
	public function render_package( Package_Document_Bundle $bundle, array $options = array() ): Pdf_Render_Result {
		$start = microtime( true );

		$order    = array_merge( $bundle->required_forms(), $bundle->optional_forms() );
		$sections = array( $this->bundle_cover_lines( $bundle, $order ) );
		$total    = 0;
		$resolved = 0;
		$missing  = 0;
		$rendered = array();

		foreach ( $order as $form_code ) {
			$document = $bundle->document( $form_code );

			if ( null === $document ) {
				continue;
			}

			$template   = $this->registry->resolve( $form_code );
			$entries    = $this->mapper->map_document( $document, true );
			$counts     = $this->count_entries( $entries );
			$total     += $counts['total'];
			$resolved  += $counts['resolved'];
			$missing   += $counts['missing'];
			$rendered[] = $form_code;

			$sections[] = $this->document_lines( $document, $template, $entries );
		}

		$bytes    = $this->writer->build( $sections );
		$filename = $this->filename( $options, 'package-' . $this->slug( $bundle->package_key() ) . '.pdf' );
		$stored   = $this->persist( $bytes, $filename, $options );

		return new Pdf_Render_Result(
			array(
				'success'          => true,
				'scope'            => Pdf_Render_Result::SCOPE_PACKAGE,
				'format'           => $this->format(),
				'package_key'      => $bundle->package_key(),
				'template'         => $bundle->package_key(),
				'template_version' => 'bundle-1.0',
				'renderer_type'    => Pdf_Template_Registry::RENDERER_BUILTIN,
				'file_path'        => (string) $stored['file_path'],
				'download_url'     => (string) $stored['download_url'],
				'checksum'         => (string) $stored['checksum'],
				'bytes'            => (int) $stored['bytes'],
				'field_count'      => $total,
				'resolved_count'   => $resolved,
				'missing_count'    => $missing,
				'duration_ms'      => $this->elapsed_ms( $start ),
				'audit'            => $this->bundle_audit( $bundle ),
			)
		);
	}

	/**
	 * Build the text lines for a single document section.
	 *
	 * @param Generated_Document               $document Document.
	 * @param array<string, string>            $template Template descriptor.
	 * @param array<int, array<string, mixed>> $entries  Mapped field entries.
	 * @return string[]
	 */
	private function document_lines( Generated_Document $document, array $template, array $entries ): array {
		$audit = $document->audit();

		$lines   = array();
		$lines[] = $document->form_code() . '  ' . $document->title();
		$lines[] = str_repeat( '-', 64 );
		$lines[] = sprintf(
			'Template: %s v%s (%s)',
			(string) $template['form_code'],
			(string) $template['template_version'],
			(string) $template['renderer_type']
		);
		$lines[] = sprintf( 'Status: %s    Requirement: %s', $document->status(), $document->requirement() );

		if ( '' !== $document->package_key() ) {
			$lines[] = 'Package: ' . $document->package_key();
		}

		$lines[] = 'Generated: ' . ( null !== $audit ? $audit->generated_at() : gmdate( 'Y-m-d H:i:s' ) ) . ' UTC';
		$lines[] = '';
		$lines[] = 'Fields:';

		foreach ( $entries as $entry ) {
			$display = '' === (string) $entry['display'] ? '<missing>' : (string) $entry['display'];

			$lines[] = sprintf(
				'  %-22s %-12s %s%s',
				(string) $entry['pdf_field'],
				'[' . (string) $entry['type'] . ']',
				$display,
				$entry['required'] ? '  *' : ''
			);
		}

		return $lines;
	}

	/**
	 * Build the cover-page lines for a package bundle.
	 *
	 * @param Package_Document_Bundle $bundle Bundle.
	 * @param string[]                $order  Form codes in render order.
	 * @return string[]
	 */
	private function bundle_cover_lines( Package_Document_Bundle $bundle, array $order ): array {
		$completeness = $bundle->completeness();
		$audit        = $bundle->audit();

		$lines   = array();
		$lines[] = 'Document Package';
		$lines[] = str_repeat( '=', 64 );
		$lines[] = 'Package: ' . $bundle->package_key();
		$lines[] = sprintf(
			'Completion: %d%%    Ready: %s',
			$completeness->completion_percentage(),
			$completeness->is_ready_to_generate() ? 'YES' : 'NO'
		);
		$lines[] = 'Generated: ' . ( null !== $audit ? $audit->generated_at() : gmdate( 'Y-m-d H:i:s' ) ) . ' UTC';

		if ( null !== $audit ) {
			$lines[] = sprintf( 'Source case: %d    Version: %d', $audit->source_case_id(), $audit->version() );
		}

		$lines[] = '';
		$lines[] = 'Forms in this package:';

		foreach ( $order as $form_code ) {
			$document = $bundle->document( $form_code );

			if ( null === $document ) {
				continue;
			}

			$lines[] = sprintf( '  %-6s %-40s %s', $form_code, $document->title(), $document->status() );
		}

		return $lines;
	}

	/**
	 * Aggregate field counts for a set of mapped entries.
	 *
	 * @param array<int, array<string, mixed>> $entries Entries.
	 * @return array{total:int, resolved:int, missing:int}
	 */
	private function count_entries( array $entries ): array {
		$total    = count( $entries );
		$resolved = 0;
		$missing  = 0;

		foreach ( $entries as $entry ) {
			if ( ! empty( $entry['resolved'] ) ) {
				++$resolved;
			}

			if ( ! empty( $entry['required'] ) && empty( $entry['resolved'] ) ) {
				++$missing;
			}
		}

		return array(
			'total'    => $total,
			'resolved' => $resolved,
			'missing'  => $missing,
		);
	}

	/**
	 * Persist (or skip persisting) the rendered bytes.
	 *
	 * @param string               $bytes    PDF bytes.
	 * @param string               $filename Target filename.
	 * @param array<string, mixed> $options  Options.
	 * @return array<string, mixed>
	 */
	private function persist( string $bytes, string $filename, array $options ): array {
		$store = ! array_key_exists( 'store', $options ) || false !== $options['store'];

		if ( ! $store ) {
			return array(
				'file_path'    => '',
				'download_url' => '',
				'checksum'     => $this->storage->checksum( $bytes ),
				'bytes'        => strlen( $bytes ),
			);
		}

		return $this->storage->store( $bytes, $filename );
	}

	/**
	 * Resolve the output filename.
	 *
	 * @param array<string, mixed> $options  Options.
	 * @param string               $fallback Fallback filename.
	 * @return string
	 */
	private function filename( array $options, string $fallback ): string {
		$filename = isset( $options['filename'] ) ? (string) $options['filename'] : '';

		return '' !== $filename ? $filename : $fallback;
	}

	/**
	 * Audit metadata for a single document render.
	 *
	 * @param Generated_Document    $document Document.
	 * @param array<string, string> $template Template descriptor.
	 * @return array<string, mixed>
	 */
	private function document_audit( Generated_Document $document, array $template ): array {
		$audit = $document->audit();

		$base = null !== $audit
			? $audit->to_array()
			: array(
				'generated_at'      => gmdate( 'Y-m-d H:i:s' ),
				'generated_by'      => 0,
				'version'           => 1,
				'source_case_id'    => 0,
				'source_package_id' => $document->package_key(),
			);

		$base['template_version'] = (string) $template['template_version'];

		return $base;
	}

	/**
	 * Audit metadata for a package bundle render.
	 *
	 * @param Package_Document_Bundle $bundle Bundle.
	 * @return array<string, mixed>
	 */
	private function bundle_audit( Package_Document_Bundle $bundle ): array {
		$audit = $bundle->audit();

		$base = null !== $audit
			? $audit->to_array()
			: array(
				'generated_at'      => gmdate( 'Y-m-d H:i:s' ),
				'generated_by'      => 0,
				'version'           => 1,
				'source_case_id'    => 0,
				'source_package_id' => $bundle->package_key(),
			);

		$base['template_version'] = 'bundle-1.0';

		return $base;
	}

	/**
	 * Milliseconds elapsed since a start microtime.
	 *
	 * @param float $start Start microtime.
	 * @return float
	 */
	private function elapsed_ms( float $start ): float {
		return round( ( microtime( true ) - $start ) * 1000, 3 );
	}

	/**
	 * Slugify a key for use in a filename.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function slug( string $value ): string {
		$value = strtolower( $value );
		$value = (string) preg_replace( '/[^a-z0-9]+/', '-', $value );

		return trim( $value, '-' );
	}
}
