<?php
/**
 * WP-CLI command: PDF template resolution audit.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents\Pdf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Pdf_Audit_Command
 *
 * Registers `wp prose pdf audit` (the `pdf:audit` command), which traces the
 * official-template resolution pipeline for the core court forms and writes
 * pdf-template-audit.json, pdf-field-registry.json and pdf-template-audit.md.
 *
 * Read-only: no rendering logic and no form mappings are modified.
 */
final class Pdf_Audit_Command {

	/**
	 * Default form codes to audit.
	 *
	 * @var string[]
	 */
	private const DEFAULT_CODES = array( 'UD-1', 'UD-2', 'UD-3', 'UD-4', 'UD-6', 'UD-7', 'FC-1', 'FC-2', 'FC-3', 'FC-7' );

	/**
	 * Audit official PDF template resolution for the core court forms.
	 *
	 * ## OPTIONS
	 *
	 * [--forms=<codes>]
	 * : Comma-separated form codes to audit. Defaults to the core set.
	 *
	 * [--output-dir=<dir>]
	 * : Directory to write the audit artifacts into. Defaults to the plugin
	 *   tests/manual/pdf-template-audit-output directory.
	 *
	 * ## EXAMPLES
	 *
	 *     wp prose pdf audit
	 *     wp prose pdf audit --forms=UD-1,FC-7 --output-dir=/tmp/audit
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Flags.
	 * @return void
	 */
	public function audit( array $args, array $assoc_args ): void {
		unset( $args );

		$codes = isset( $assoc_args['forms'] )
			? array_values( array_filter( array_map( 'trim', explode( ',', (string) $assoc_args['forms'] ) ) ) )
			: self::DEFAULT_CODES;

		$output_dir = isset( $assoc_args['output-dir'] )
			? rtrim( (string) $assoc_args['output-dir'], '/\\' )
			: $this->default_output_dir();

		$rows    = $this->fetch_rows();
		$service = new Pdf_Template_Audit_Service();

		$records  = $service->build_records( $codes, $rows );
		$audits   = $service->audit( $records );
		$registry = $service->field_registry( $audits );
		$markdown = $service->to_markdown( $audits, $registry );

		$this->write( $output_dir, $audits, $registry, $markdown );

		$this->report_summary( $audits, $output_dir );
	}

	/**
	 * Fetch all prose_form catalog rows with PDF analysis meta.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function fetch_rows(): array {
		global $wpdb;

		$sql = "
			SELECT p.ID AS post_id, p.post_title AS title,
				MAX(CASE WHEN m.meta_key='prose_form_code'       THEN m.meta_value END) AS form_code,
				MAX(CASE WHEN m.meta_key='prose_file_url'        THEN m.meta_value END) AS file_url,
				MAX(CASE WHEN m.meta_key='prose_pdf_fillable'    THEN m.meta_value END) AS fillable,
				MAX(CASE WHEN m.meta_key='prose_pdf_field_count' THEN m.meta_value END) AS field_count,
				MAX(CASE WHEN m.meta_key='prose_pdf_fields_json' THEN m.meta_value END) AS fields_json
			FROM {$wpdb->posts} p
			JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
			WHERE p.post_type = 'prose_form'
			GROUP BY p.ID, p.post_title
		";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = (array) $wpdb->get_results( $sql, ARRAY_A );

		return array_map( array( $this, 'normalize_row' ), $results );
	}

	/**
	 * Normalize a raw DB row into the service's row shape.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private function normalize_row( array $row ): array {
		$fields  = array();
		$decoded = json_decode( (string) ( $row['fields_json'] ?? '' ), true );

		if ( is_array( $decoded ) && isset( $decoded['fields'] ) && is_array( $decoded['fields'] ) ) {
			$fields = array_values( $decoded['fields'] );
		}

		return array(
			'post_id'     => (int) ( $row['post_id'] ?? 0 ),
			'title'       => (string) ( $row['title'] ?? '' ),
			'form_code'   => (string) ( $row['form_code'] ?? '' ),
			'file_url'    => (string) ( $row['file_url'] ?? '' ),
			'fillable'    => '1' === (string) ( $row['fillable'] ?? '' ),
			'field_count' => (int) ( $row['field_count'] ?? 0 ),
			'fields'      => $fields,
		);
	}

	/**
	 * Write the three audit artifacts.
	 *
	 * @param string                           $dir      Output directory.
	 * @param array<int, array<string, mixed>> $audits   Audit results.
	 * @param array<int, array<string, mixed>> $registry Field registry.
	 * @param string                           $markdown Markdown report.
	 * @return void
	 */
	private function write( string $dir, array $audits, array $registry, string $markdown ): void {
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$json = wp_json_encode( $audits, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$reg  = wp_json_encode( $registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		file_put_contents( $dir . '/pdf-template-audit.json', (string) $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $dir . '/pdf-field-registry.json', (string) $reg ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $dir . '/pdf-template-audit.md', $markdown ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Emit a WP-CLI summary.
	 *
	 * @param array<int, array<string, mixed>> $audits Audit results.
	 * @param string                           $dir    Output directory.
	 * @return void
	 */
	private function report_summary( array $audits, string $dir ): void {
		if ( ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		$rows = array();

		foreach ( $audits as $a ) {
			$rows[] = array(
				'form_code'         => (string) $a['form_code'],
				'file_exists'       => $a['file_exists'] ? 'yes' : 'no',
				'pdf_type'          => null === $a['pdf_type'] ? '-' : (string) $a['pdf_type'],
				'field_count'       => (string) $a['field_count'],
				'renderer_selected' => (string) $a['renderer_selected'],
				'can_fill'          => $a['can_fill'] ? 'YES' : 'no',
				'fallback_reason'   => null === $a['fallback_reason'] ? '-' : (string) $a['fallback_reason'],
			);
		}

		\WP_CLI\Utils\format_items(
			'table',
			$rows,
			array( 'form_code', 'file_exists', 'pdf_type', 'field_count', 'renderer_selected', 'can_fill', 'fallback_reason' )
		);

		\WP_CLI::success( 'Audit written to ' . $dir );
	}

	/**
	 * Default output directory.
	 *
	 * @return string
	 */
	private function default_output_dir(): string {
		if ( defined( 'PROSE_CORE_PATH' ) ) {
			return rtrim( PROSE_CORE_PATH, '/\\' ) . '/tests/manual/pdf-template-audit-output';
		}

		return rtrim( sys_get_temp_dir(), '/\\' ) . '/pdf-template-audit-output';
	}
}
