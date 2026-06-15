<?php
/**
 * Merged Blank PDF Service — produces a single merged PDF of the blank court
 * forms required by a resolved workflow.
 *
 * The AI never selects forms here: required form codes come from the
 * deterministic Workflow Repository. This service only resolves each form's
 * blank source PDF and concatenates them into one downloadable file.
 *
 * Resolution order per workflow:
 *  1. A pre-composed official packet (e.g. the NYS Uncontested Divorce
 *     Composite) when one is mapped for the workflow.
 *  2. Per-form blank PDFs resolved from the Forms Repository / uploads.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\PackageBuilder;

use ProSe\Core\Packet\Packet_Store;
use ProSe\Core\Packet\Pdf_Merger;
use ProSe\Core\Packet\Pdf_Resolver;
use ProSe\Core\Routing\Workflow_Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Merged_Blank_Pdf_Service
 */
final class Merged_Blank_Pdf_Service {

	/**
	 * Stored package id prefix.
	 */
	private const ID_PREFIX = 'blank-';

	/**
	 * Workflow catalog.
	 *
	 * @var Workflow_Catalog
	 */
	private Workflow_Catalog $workflows;

	/**
	 * Blank PDF resolver.
	 *
	 * @var Pdf_Resolver
	 */
	private Pdf_Resolver $resolver;

	/**
	 * Packet store (reused for merged-PDF storage + URLs).
	 *
	 * @var Packet_Store
	 */
	private Packet_Store $store;

	/**
	 * Constructor.
	 *
	 * @param Workflow_Catalog|null $workflows Workflow catalog.
	 * @param Pdf_Resolver|null     $resolver  Blank PDF resolver.
	 * @param Packet_Store|null     $store     Packet store.
	 */
	public function __construct(
		?Workflow_Catalog $workflows = null,
		?Pdf_Resolver $resolver = null,
		?Packet_Store $store = null
	) {
		$this->workflows = $workflows ?? new Workflow_Catalog();
		$this->resolver  = $resolver ?? new Pdf_Resolver();
		$this->store     = $store ?? new Packet_Store();
	}

	/**
	 * Read-only availability for a workflow (never generates).
	 *
	 * @param string $workflow Workflow key.
	 * @return array<string, mixed>
	 */
	public function status( string $workflow ): array {
		$workflow = sanitize_key( $workflow );

		if ( '' === $workflow ) {
			return array(
				'available'    => false,
				'download_url' => '',
			);
		}

		$package_id   = $this->package_id( $workflow );
		$sources      = $this->resolve_sources( $workflow );
		$has_sources  = ! empty( $sources['paths'] );
		$already_made = $this->store->pdf_exists( $package_id );

		return array(
			'available'    => $has_sources || $already_made,
			'download_url' => $already_made ? $this->store->pdf_url( $package_id ) : '',
			'form_count'   => count( $sources['codes'] ),
			'merged_count' => count( $sources['paths'] ),
			'missing'      => $sources['missing'],
		);
	}

	/**
	 * Build (or reuse) the merged blank PDF for a workflow.
	 *
	 * @param string $workflow Workflow key.
	 * @param bool   $force    Force a rebuild even when cached.
	 * @return array<string, mixed>
	 */
	public function build( string $workflow, bool $force = false ): array {
		$workflow = sanitize_key( $workflow );

		if ( '' === $workflow ) {
			return $this->failure( __( 'A resolved workflow is required.', 'prose-core' ) );
		}

		if ( null === $this->workflows->by_key( $workflow ) ) {
			return $this->failure(
				sprintf(
					/* translators: %s: workflow key */
					__( 'Workflow "%s" was not found.', 'prose-core' ),
					$workflow
				)
			);
		}

		$package_id = $this->package_id( $workflow );

		if ( ! $force && $this->store->pdf_exists( $package_id ) ) {
			$sources = $this->resolve_sources( $workflow );

			return $this->success( $workflow, $package_id, $sources );
		}

		$sources = $this->resolve_sources( $workflow );

		if ( empty( $sources['paths'] ) ) {
			return $this->failure(
				__( 'No blank PDF forms are available for this workflow yet.', 'prose-core' ),
				$sources['missing']
			);
		}

		$bytes = $this->merge_paths( $sources['paths'] );

		if ( null === $bytes || '' === $bytes ) {
			return $this->failure( __( 'Could not merge the blank forms into a single PDF.', 'prose-core' ) );
		}

		if ( ! $this->store->write_pdf( $package_id, $bytes ) ) {
			return $this->failure( __( 'Could not save the merged PDF.', 'prose-core' ) );
		}

		return $this->success( $workflow, $package_id, $sources );
	}

	/**
	 * Build (or reuse) a merged PDF from an explicit list of form codes.
	 *
	 * Used by the "direct" intake path where a user asks for specific forms by
	 * code rather than answering intake questions. The codes still come from the
	 * user, not the AI — this service only resolves and concatenates the blank
	 * source PDFs.
	 *
	 * @param array<int, string> $codes Requested form codes.
	 * @return array<string, mixed>
	 */
	public function build_for_codes( array $codes ): array {
		$codes = $this->normalize_codes( $codes );

		if ( empty( $codes ) ) {
			return $this->failure( __( 'No form codes were provided.', 'prose-core' ) );
		}

		$paths    = array();
		$resolved = array();
		$missing  = array();

		foreach ( $codes as $code ) {
			$path = $this->resolve_form_path( $code );

			if ( '' !== $path ) {
				$paths[]    = $path;
				$resolved[] = $code;
			} else {
				$missing[] = $code;
			}
		}

		if ( empty( $paths ) ) {
			return $this->failure(
				__( 'None of the requested forms have a blank PDF available yet.', 'prose-core' ),
				$missing
			);
		}

		$package_id = 'forms-' . substr( md5( implode( ',', $codes ) ), 0, 12 );

		if ( ! $this->store->pdf_exists( $package_id ) ) {
			$bytes = $this->merge_paths( $paths );

			if ( null === $bytes || '' === $bytes ) {
				return $this->failure( __( 'Could not merge the requested forms into a single PDF.', 'prose-core' ) );
			}

			if ( ! $this->store->write_pdf( $package_id, $bytes ) ) {
				return $this->failure( __( 'Could not save the merged PDF.', 'prose-core' ) );
			}
		}

		return array(
			'success'      => true,
			'download_url' => $this->store->pdf_url( $package_id ),
			'requested'    => $codes,
			'merged'       => $resolved,
			'missing'      => $missing,
		);
	}

	/**
	 * Normalize and de-duplicate a list of form codes.
	 *
	 * @param array<int, string> $codes Raw codes.
	 * @return array<int, string>
	 */
	private function normalize_codes( array $codes ): array {
		$clean = array();

		foreach ( $codes as $code ) {
			$code = strtoupper( trim( (string) $code ) );

			if ( '' !== $code && ! in_array( $code, $clean, true ) ) {
				$clean[] = $code;
			}
		}

		return $clean;
	}

	/**
	 * Map of workflow key => pre-composed packet filename under uploads/prose/forms.
	 *
	 * @return array<string, string>
	 */
	private function workflow_composites(): array {
		/**
		 * Filter the workflow => composite-packet filename map.
		 *
		 * Each filename is resolved under uploads/prose/forms. A composite is a
		 * single official PDF that already bundles every blank form for the
		 * workflow (e.g. the NYS Uncontested Divorce Composite).
		 *
		 * @param array<string, string> $map Workflow => filename.
		 */
		return (array) apply_filters(
			'prose_core_workflow_composite_packets',
			array(
				'uncontested_divorce_children_nyc'    => 'composite-uncontested-divorce.pdf',
				'uncontested_divorce_no_children_nyc' => 'composite-uncontested-divorce.pdf',
			)
		);
	}

	/**
	 * Resolve the ordered source PDF paths for a workflow.
	 *
	 * @param string $workflow Workflow key.
	 * @return array{paths: array<int, string>, missing: array<int, string>, codes: array<int, string>}
	 */
	private function resolve_sources( string $workflow ): array {
		$composite = $this->composite_path( $workflow );

		if ( '' !== $composite ) {
			return array(
				'paths'   => array( $composite ),
				'missing' => array(),
				'codes'   => array(),
			);
		}

		$definition = $this->workflows->by_key( $workflow );
		$codes      = is_array( $definition ) ? $this->workflows->required_form_codes( $definition ) : array();

		$paths   = array();
		$missing = array();

		foreach ( $codes as $code ) {
			$path = $this->resolve_form_path( $code );

			if ( '' !== $path ) {
				$paths[] = $path;
			} else {
				$missing[] = $code;
			}
		}

		return array(
			'paths'   => $paths,
			'missing' => $missing,
			'codes'   => $codes,
		);
	}

	/**
	 * Resolve a single form code to a readable blank PDF path.
	 *
	 * Tries the Forms Repository resolver first, then a set of conventional
	 * flat filenames under uploads/prose/forms (resilient to punctuation in
	 * form codes such as UD-8(1)).
	 *
	 * @param string $code Form code.
	 * @return string Readable path or empty string.
	 */
	private function resolve_form_path( string $code ): string {
		$resolved = $this->resolver->resolve( $code );
		$path     = (string) ( $resolved['pdf_path'] ?? '' );

		if ( '' !== $path && is_readable( $path ) ) {
			return $path;
		}

		$dir = $this->forms_dir();

		if ( '' === $dir ) {
			return '';
		}

		foreach ( $this->candidate_filenames( $code ) as $filename ) {
			$candidate = $dir . $filename;

			if ( is_readable( $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Candidate flat filenames for a form code.
	 *
	 * @param string $code Form code.
	 * @return array<int, string>
	 */
	private function candidate_filenames( string $code ): array {
		$lower = strtolower( trim( $code ) );

		$names = array(
			$lower . '.pdf',
			preg_replace( '/[^a-z0-9]+/', '-', $lower ) . '.pdf',
			preg_replace( '/[^a-z0-9]/', '', $lower ) . '.pdf',
		);

		if ( function_exists( 'sanitize_title' ) ) {
			$names[] = sanitize_title( $code ) . '.pdf';
		}

		$names = array_values( array_unique( array_filter( $names, static fn( $name ) => '.pdf' !== $name ) ) );

		return $names;
	}

	/**
	 * Absolute path to a workflow's pre-composed packet, if present.
	 *
	 * @param string $workflow Workflow key.
	 * @return string
	 */
	private function composite_path( string $workflow ): string {
		$map = $this->workflow_composites();

		if ( empty( $map[ $workflow ] ) ) {
			return '';
		}

		$dir = $this->forms_dir();

		if ( '' === $dir ) {
			return '';
		}

		$path = $dir . (string) $map[ $workflow ];

		return is_readable( $path ) ? $path : '';
	}

	/**
	 * Absolute path (trailing slash) to the blank forms directory.
	 *
	 * @return string
	 */
	private function forms_dir(): string {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return '';
		}

		$uploads = wp_upload_dir();

		if ( ! is_array( $uploads ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		return trailingslashit( $uploads['basedir'] ) . 'prose/forms/';
	}

	/**
	 * Merge readable PDF paths into one PDF byte string.
	 *
	 * A single pre-composed source is returned verbatim; multiple sources are
	 * concatenated (pdftk when available, else the zero-dependency merger).
	 *
	 * @param array<int, string> $paths Source paths.
	 * @return string|null
	 */
	private function merge_paths( array $paths ): ?string {
		$paths = array_values(
			array_filter(
				$paths,
				static fn( $path ) => is_string( $path ) && '' !== $path && is_readable( $path )
			)
		);

		if ( empty( $paths ) ) {
			return null;
		}

		if ( 1 === count( $paths ) ) {
			$bytes = file_get_contents( $paths[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			return ( false === $bytes || '' === $bytes ) ? null : $bytes;
		}

		if ( class_exists( '\mikehaertl\pdftk\Pdf' ) ) {
			$merged = $this->merge_with_pdftk( $paths );

			if ( null !== $merged ) {
				return $merged;
			}
		}

		return ( new Pdf_Merger() )->merge( $paths );
	}

	/**
	 * Merge PDF files with pdftk when available.
	 *
	 * @param array<int, string> $paths Absolute PDF paths.
	 * @return string|null
	 */
	private function merge_with_pdftk( array $paths ): ?string {
		$sources = array();

		foreach ( $paths as $index => $path ) {
			if ( ! is_readable( $path ) ) {
				return null;
			}

			$sources[ 'F' . $index ] = $path;
		}

		$out = tempnam( sys_get_temp_dir(), 'prose-blank-pdf-' );

		if ( false === $out ) {
			return null;
		}

		try {
			$class = '\mikehaertl\pdftk\Pdf';
			$pdf   = new $class( $sources );

			foreach ( array_keys( $sources ) as $handle ) {
				$pdf->cat( null, null, $handle );
			}

			if ( ! $pdf->saveAs( $out ) ) {
				return null;
			}

			$bytes = file_get_contents( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			return ( false === $bytes || '' === $bytes ) ? null : $bytes;
		} catch ( \Throwable $e ) {
			return null;
		} finally {
			if ( file_exists( $out ) ) {
				unlink( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
	}

	/**
	 * Stored package id for a workflow.
	 *
	 * @param string $workflow Workflow key.
	 * @return string
	 */
	private function package_id( string $workflow ): string {
		return self::ID_PREFIX . $workflow;
	}

	/**
	 * Build a success payload.
	 *
	 * @param string                                                                    $workflow   Workflow key.
	 * @param string                                                                    $package_id Stored package id.
	 * @param array{paths: array<int, string>, missing: array<int, string>, codes: array<int, string>} $sources Resolved sources.
	 * @return array<string, mixed>
	 */
	private function success( string $workflow, string $package_id, array $sources ): array {
		return array(
			'success'      => true,
			'workflow'     => $workflow,
			'download_url' => $this->store->pdf_url( $package_id ),
			'form_count'   => count( $sources['codes'] ),
			'merged_count' => count( $sources['paths'] ),
			'missing'      => $sources['missing'],
		);
	}

	/**
	 * Build a failure payload.
	 *
	 * @param string             $message Error message.
	 * @param array<int, string> $missing Missing form codes.
	 * @return array<string, mixed>
	 */
	private function failure( string $message, array $missing = array() ): array {
		return array(
			'success' => false,
			'error'   => array( 'message' => $message ),
			'missing' => $missing,
		);
	}
}
