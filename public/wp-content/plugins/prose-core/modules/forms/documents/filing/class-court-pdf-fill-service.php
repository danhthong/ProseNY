<?php
/**
 * Court PDF fill service — fill a single court form into a PDF.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents\Filing;

use ProSe\Core\Forms\Documents\Generated_Document;
use ProSe\Core\Forms\Documents\Pdf\Pdf_Document_Writer;
use ProSe\Core\Forms\Documents\Pdf\Pdf_Field_Mapper;
use ProSe\Core\Forms\Documents\Pdf\Pdf_Template_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Court_Pdf_Fill_Service
 *
 * Fills the official court PDF template for a single form with the document's
 * resolved field values, preserving the original court layout.
 *
 * Two strategies, selected per form by capability:
 *   - ACROFORM: when an official template PDF is registered and the pdftk
 *     toolchain is available, the AcroForm fields are populated in place so
 *     the original court layout is preserved exactly.
 *   - BUILTIN:  otherwise a self-contained text rendering of the populated
 *     fields is produced so the pipeline always yields a valid filled PDF.
 *
 * The fill result is a portable descriptor consumed by the bundler / merger.
 */
final class Court_Pdf_Fill_Service {

	public const STRATEGY_ACROFORM = 'acroform';
	public const STRATEGY_BUILTIN  = 'builtin';

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
	 * PDF writer (builtin strategy).
	 *
	 * @var Pdf_Document_Writer
	 */
	private Pdf_Document_Writer $writer;

	/**
	 * Cached pdftk availability.
	 *
	 * @var bool|null
	 */
	private ?bool $pdftk_available = null;

	/**
	 * Constructor.
	 *
	 * @param Pdf_Template_Registry|null $registry Template registry.
	 * @param Pdf_Field_Mapper|null      $mapper   Field mapper.
	 * @param Pdf_Document_Writer|null   $writer   PDF writer.
	 */
	public function __construct(
		?Pdf_Template_Registry $registry = null,
		?Pdf_Field_Mapper $mapper = null,
		?Pdf_Document_Writer $writer = null
	) {
		$this->registry = $registry ?? new Pdf_Template_Registry();
		$this->mapper   = $mapper ?? new Pdf_Field_Mapper();
		$this->writer   = $writer ?? new Pdf_Document_Writer();
	}

	/**
	 * Whether the AcroForm fill toolchain (template-fill via pdftk) is usable.
	 *
	 * @return bool
	 */
	public function is_acroform_available(): bool {
		if ( null !== $this->pdftk_available ) {
			return $this->pdftk_available;
		}

		$this->pdftk_available = class_exists( '\mikehaertl\pdftk\Pdf' ) && $this->pdftk_binary_present();

		return $this->pdftk_available;
	}

	/**
	 * Fill a single document into a PDF descriptor.
	 *
	 * @param Generated_Document   $document Document.
	 * @param array<string, mixed> $options  Options.
	 * @return array<string, mixed>
	 */
	public function fill( Generated_Document $document, array $options = array() ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$form_code = $document->form_code();
		$template  = $this->registry->resolve( $form_code );
		$entries   = $this->mapper->map_document( $document, true );
		$counts    = $this->count_entries( $entries );

		$use_acroform = Pdf_Template_Registry::RENDERER_ACROFORM === $template['renderer_type']
			&& '' !== (string) $template['template_path']
			&& is_readable( (string) $template['template_path'] )
			&& $this->is_acroform_available();

		$sections = $this->form_sections( $document, $template, $entries );

		if ( $use_acroform ) {
			$filled = $this->fill_acroform( (string) $template['template_path'], $entries );

			if ( null !== $filled ) {
				return $this->result( $document, $template, self::STRATEGY_ACROFORM, $filled, $sections, $counts );
			}
		}

		$bytes = $this->writer->build( array( $sections ) );

		return $this->result( $document, $template, self::STRATEGY_BUILTIN, $bytes, $sections, $counts );
	}

	/**
	 * Build a fill descriptor.
	 *
	 * @param Generated_Document                        $document Document.
	 * @param array<string, string>                     $template Template descriptor.
	 * @param string                                    $strategy Strategy used.
	 * @param string                                    $bytes    PDF bytes.
	 * @param array<int, string>                        $sections Text section lines.
	 * @param array{total:int,resolved:int,missing:int} $counts Field counts.
	 * @return array<string, mixed>
	 */
	private function result(
		Generated_Document $document,
		array $template,
		string $strategy,
		string $bytes,
		array $sections,
		array $counts
	): array {
		return array(
			'form_code'        => $document->form_code(),
			'title'            => $document->title(),
			'strategy'         => $strategy,
			'template_version' => (string) $template['template_version'],
			'template_path'    => (string) $template['template_path'],
			'renderer_type'    => (string) $template['renderer_type'],
			'bytes'            => $bytes,
			'sections'         => $sections,
			'page_count'       => Pdf_Document_Writer::count_pages( $bytes ),
			'field_count'      => $counts['total'],
			'resolved_count'   => $counts['resolved'],
			'missing_count'    => $counts['missing'],
		);
	}

	/**
	 * Fill an AcroForm template with pdftk.
	 *
	 * @param string                           $template_path Template PDF path.
	 * @param array<int, array<string, mixed>> $entries       Mapped entries.
	 * @return string|null Filled PDF bytes, or null on failure.
	 */
	private function fill_acroform( string $template_path, array $entries ): ?string {
		$data = array();

		foreach ( $entries as $entry ) {
			$data[ (string) $entry['pdf_field'] ] = $this->fill_value( $entry );
		}

		try {
			$class = '\mikehaertl\pdftk\Pdf';
			$pdf   = new $class( $template_path );
			$pdf->fillForm( $data )->needAppearances();

			$tmp = tempnam( sys_get_temp_dir(), 'prose-fill-' );

			if ( false === $tmp ) {
				return null;
			}

			if ( ! $pdf->saveAs( $tmp ) ) {
				return null;
			}

			$bytes = (string) file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

			return '' === $bytes ? null : $bytes;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Compute the AcroForm fill value for an entry.
	 *
	 * @param array<string, mixed> $entry Mapped entry.
	 * @return string
	 */
	private function fill_value( array $entry ): string {
		if ( Pdf_Field_Mapper::TYPE_CHECKBOX === $entry['type'] ) {
			return '[X]' === (string) $entry['display'] ? 'Yes' : 'Off';
		}

		if ( empty( $entry['resolved'] ) ) {
			return '';
		}

		return (string) $entry['display'];
	}

	/**
	 * Build the text lines for the builtin filled form.
	 *
	 * @param Generated_Document               $document Document.
	 * @param array<string, string>            $template Template descriptor.
	 * @param array<int, array<string, mixed>> $entries  Mapped entries.
	 * @return string[]
	 */
	private function form_sections( Generated_Document $document, array $template, array $entries ): array {
		$lines   = array();
		$lines[] = $document->form_code() . '  ' . $document->title();
		$lines[] = str_repeat( '=', 64 );
		$lines[] = sprintf(
			'Court template: %s v%s (%s)',
			(string) $template['form_code'],
			(string) $template['template_version'],
			(string) $template['renderer_type']
		);
		$lines[] = 'Status: ' . $document->status();

		if ( '' !== $document->package_key() ) {
			$lines[] = 'Package: ' . $document->package_key();
		}

		$lines[] = '';

		foreach ( $entries as $entry ) {
			$display = '' === (string) $entry['display'] ? '________________' : (string) $entry['display'];

			$lines[] = sprintf(
				'  %-22s %s%s',
				(string) $entry['pdf_field'] . ':',
				$display,
				$entry['required'] ? '  *' : ''
			);
		}

		return $lines;
	}

	/**
	 * Aggregate counts for mapped entries.
	 *
	 * @param array<int, array<string, mixed>> $entries Entries.
	 * @return array{total:int,resolved:int,missing:int}
	 */
	private function count_entries( array $entries ): array {
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
			'total'    => count( $entries ),
			'resolved' => $resolved,
			'missing'  => $missing,
		);
	}

	/**
	 * Detect a pdftk binary on the PATH.
	 *
	 * @return bool
	 */
	private function pdftk_binary_present(): bool {
		if ( ! function_exists( 'exec' ) ) {
			return false;
		}

		$output = array();
		$status = 1;

		@exec( 'pdftk --version 2>/dev/null', $output, $status ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec

		return 0 === $status;
	}
}
