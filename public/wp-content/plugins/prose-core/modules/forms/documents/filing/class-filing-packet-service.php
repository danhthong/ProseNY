<?php
/**
 * Filing packet service — orchestrate case + package into one filing packet.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents\Filing;

use ProSe\Core\Forms\Documents\Document_Generation_Service;
use ProSe\Core\Forms\Documents\Package_Document_Bundle;
use ProSe\Core\Forms\Documents\Pdf\Pdf_Storage_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Filing_Packet_Service
 *
 * Top-level orchestrator of the Court Filing Packet Engine:
 *
 *   Case -> Package -> Filled Court Forms -> PDF Bundle -> Single Filing Packet
 *
 * It generates the package document bundle, fills every form in filing order,
 * merges them into one packet PDF, stores the packet and its manifest, and
 * returns a Filing_Packet descriptor carrying the manifest and audit metadata.
 */
final class Filing_Packet_Service {

	/**
	 * Document generation service.
	 *
	 * @var Document_Generation_Service
	 */
	private Document_Generation_Service $generation;

	/**
	 * Package bundler.
	 *
	 * @var Package_Pdf_Bundler
	 */
	private Package_Pdf_Bundler $bundler;

	/**
	 * PDF merge service.
	 *
	 * @var Pdf_Merge_Service
	 */
	private Pdf_Merge_Service $merge;

	/**
	 * Storage service.
	 *
	 * @var Pdf_Storage_Service
	 */
	private Pdf_Storage_Service $storage;

	/**
	 * Constructor.
	 *
	 * @param Document_Generation_Service|null $generation Generation service.
	 * @param Package_Pdf_Bundler|null         $bundler    Package bundler.
	 * @param Pdf_Merge_Service|null           $merge      Merge service.
	 * @param Pdf_Storage_Service|null         $storage    Storage service.
	 */
	public function __construct(
		?Document_Generation_Service $generation = null,
		?Package_Pdf_Bundler $bundler = null,
		?Pdf_Merge_Service $merge = null,
		?Pdf_Storage_Service $storage = null
	) {
		$this->generation = $generation ?? new Document_Generation_Service();
		$this->bundler    = $bundler ?? new Package_Pdf_Bundler();
		$this->merge      = $merge ?? new Pdf_Merge_Service();
		$this->storage    = $storage ?? new Pdf_Storage_Service();
	}

	/**
	 * Generate a filing packet for a case + package.
	 *
	 * @param \ProSe\Core\Forms\Engine\Case_State|int $case_or_id  Case state or case ID.
	 * @param string                                  $package_key Package key.
	 * @param array<string, mixed>                    $options     Options:
	 *                                                             - context (array) resolver context
	 *                                                             - generated_by (int)
	 *                                                             - version (int)
	 *                                                             - filename (string) packet file name
	 *                                                             - store (bool) write artifacts (default true).
	 * @return Filing_Packet|null
	 */
	public function generate( $case_or_id, string $package_key, array $options = array() ): ?Filing_Packet {
		$start = microtime( true );

		$context      = (array) ( $options['context'] ?? array() );
		$generated_by = (int) ( $options['generated_by'] ?? 0 );
		$version      = (int) ( $options['version'] ?? 1 );

		$bundle = $this->generation->generate_package( $case_or_id, $package_key, $context, $generated_by, $version );

		if ( null === $bundle ) {
			return null;
		}

		return $this->from_bundle( $bundle, $options, $start );
	}

	/**
	 * Build a filing packet from an already-generated bundle.
	 *
	 * @param Package_Document_Bundle $bundle  Bundle.
	 * @param array<string, mixed>    $options Options (see generate()).
	 * @param float|null              $start   Start timestamp.
	 * @return Filing_Packet
	 */
	public function from_bundle( Package_Document_Bundle $bundle, array $options = array(), ?float $start = null ): Filing_Packet {
		$start = null === $start ? microtime( true ) : $start;
		$store = (bool) ( $options['store'] ?? true );

		$bundled = $this->bundler->bundle( $bundle, $options );
		$merged  = $this->merge->merge( $bundled['forms'], $options );

		$counts   = $this->aggregate_counts( $bundled['forms'] );
		$filename = $this->filename( $options, $bundle->package_key() );
		$bytes    = (string) $merged['bytes'];

		$stored = array(
			'file_path'    => '',
			'download_url' => '',
			'checksum'     => $this->storage->checksum( $bytes ),
			'bytes'        => strlen( $bytes ),
		);

		$manifest_path = '';

		if ( $store ) {
			$stored = $this->storage->store( $bytes, $filename );
		}

		$audit = null !== $bundle->audit() ? $bundle->audit()->to_array() : array();

		$manifest = $this->build_manifest(
			$bundle->package_key(),
			$bundled['order'],
			(int) $merged['page_count'],
			$bundled['forms'],
			(string) $merged['strategy'],
			(string) $stored['checksum'],
			$audit
		);

		if ( $store ) {
			$manifest_file = $this->manifest_filename( $options, $bundle->package_key() );
			$manifest_meta = $this->storage->store( (string) wp_json_encode( $manifest ), $manifest_file );
			$manifest_path = (string) $manifest_meta['file_path'];
		}

		return new Filing_Packet(
			array(
				'success'        => true,
				'package_key'    => $bundle->package_key(),
				'forms'          => $bundled['order'],
				'page_count'     => (int) $merged['page_count'],
				'strategy'       => (string) $merged['strategy'],
				'file_path'      => (string) $stored['file_path'],
				'download_url'   => (string) $stored['download_url'],
				'manifest_path'  => $manifest_path,
				'checksum'       => (string) $stored['checksum'],
				'bytes'          => (int) $stored['bytes'],
				'field_count'    => $counts['total'],
				'resolved_count' => $counts['resolved'],
				'missing_count'  => $counts['missing'],
				'duration_ms'    => round( ( microtime( true ) - $start ) * 1000, 2 ),
				'manifest'       => $manifest,
				'audit'          => $audit,
				'errors'         => array(),
			)
		);
	}

	/**
	 * Build the packet manifest.
	 *
	 * @param string                           $package_key Package key.
	 * @param string[]                         $order       Filing order.
	 * @param int                              $page_count  Total page count.
	 * @param array<int, array<string, mixed>> $forms       Fill descriptors.
	 * @param string                           $strategy    Merge strategy.
	 * @param string                           $checksum    Packet checksum.
	 * @param array<string, mixed>             $audit       Audit metadata.
	 * @return array<string, mixed>
	 */
	private function build_manifest(
		string $package_key,
		array $order,
		int $page_count,
		array $forms,
		string $strategy,
		string $checksum,
		array $audit
	): array {
		$detail = array();

		foreach ( $forms as $form ) {
			$detail[] = array(
				'form_code'        => (string) $form['form_code'],
				'title'            => (string) $form['title'],
				'template_version' => (string) $form['template_version'],
				'pages'            => (int) $form['page_count'],
				'field_count'      => (int) $form['field_count'],
				'resolved_count'   => (int) $form['resolved_count'],
				'missing_count'    => (int) $form['missing_count'],
				'strategy'         => (string) $form['strategy'],
			);
		}

		return array(
			'package_key'    => $package_key,
			'forms'          => array_values( $order ),
			'page_count'     => $page_count,
			'form_count'     => count( $order ),
			'merge_strategy' => $strategy,
			'checksum'       => $checksum,
			'generated_at'   => isset( $audit['generated_at'] ) ? (string) $audit['generated_at'] : gmdate( 'c' ),
			'source_case_id' => isset( $audit['source_case_id'] ) ? (int) $audit['source_case_id'] : 0,
			'forms_detail'   => $detail,
		);
	}

	/**
	 * Aggregate field counts across forms.
	 *
	 * @param array<int, array<string, mixed>> $forms Fill descriptors.
	 * @return array{total:int,resolved:int,missing:int}
	 */
	private function aggregate_counts( array $forms ): array {
		$total    = 0;
		$resolved = 0;
		$missing  = 0;

		foreach ( $forms as $form ) {
			$total    += (int) $form['field_count'];
			$resolved += (int) $form['resolved_count'];
			$missing  += (int) $form['missing_count'];
		}

		return array(
			'total'    => $total,
			'resolved' => $resolved,
			'missing'  => $missing,
		);
	}

	/**
	 * Resolve the packet PDF file name.
	 *
	 * @param array<string, mixed> $options     Options.
	 * @param string               $package_key Package key.
	 * @return string
	 */
	private function filename( array $options, string $package_key ): string {
		$filename = (string) ( $options['filename'] ?? '' );

		if ( '' === $filename ) {
			$filename = ( '' !== $package_key ? $package_key : 'filing-packet' ) . '.pdf';
		}

		return $filename;
	}

	/**
	 * Resolve the manifest file name.
	 *
	 * @param array<string, mixed> $options     Options.
	 * @param string               $package_key Package key.
	 * @return string
	 */
	private function manifest_filename( array $options, string $package_key ): string {
		$filename = (string) ( $options['manifest_filename'] ?? '' );

		if ( '' === $filename ) {
			$base     = preg_replace( '/\.pdf$/i', '', $this->filename( $options, $package_key ) );
			$filename = $base . '.manifest.json';
		}

		return $filename;
	}
}
