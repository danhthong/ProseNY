<?php
/**
 * Standalone runner for the PDF Template Resolution Audit.
 *
 * Mirrors `wp prose pdf audit` but connects to the database directly (via the
 * Local MySQL socket) so the audit artifacts can be produced without WP-CLI.
 * Writes pdf-template-audit.json, pdf-field-registry.json and
 * pdf-template-audit.md.
 *
 * Usage:
 *   php tests/manual/run_pdf_template_audit.php
 *
 * @package ProSeCore
 */

use ProSe\Core\Forms\Documents\Pdf\Pdf_Template_Audit_Service;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}

if ( ! defined( 'PROSE_CORE_PATH' ) ) {
	define( 'PROSE_CORE_PATH', dirname( __DIR__, 2 ) . '/' );
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		unset( $domain );
		return (string) $text;
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( (string) $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $string ) {
		return rtrim( (string) $string, '/\\' ) . '/';
	}
}

require_once PROSE_CORE_PATH . 'includes/class-autoloader.php';
\ProSe\Core\Autoloader::register();

/**
 * Read DB credentials from wp-config.php.
 *
 * @param string $config_path wp-config.php path.
 * @return array<string, string>
 */
function read_db_config( string $config_path ): array {
	$source = (string) file_get_contents( $config_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$out    = array(
		'name' => 'local',
		'user' => 'root',
		'pass' => 'root',
		'host' => 'localhost',
	);

	foreach ( array(
		'DB_NAME'     => 'name',
		'DB_USER'     => 'user',
		'DB_PASSWORD' => 'pass',
		'DB_HOST'     => 'host',
	) as $const => $key ) {
		if ( preg_match( "/define\(\s*'" . $const . "'\s*,\s*'([^']*)'/", $source, $m ) ) {
			$out[ $key ] = $m[1];
		}
	}

	return $out;
}

/**
 * Connect to MySQL, trying the configured host then any Local socket.
 *
 * @param array<string, string> $cfg DB config.
 * @return \mysqli
 */
function connect_db( array $cfg ): \mysqli {
	mysqli_report( MYSQLI_REPORT_OFF );

	$conn = @mysqli_connect( $cfg['host'], $cfg['user'], $cfg['pass'], $cfg['name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

	if ( $conn instanceof \mysqli ) {
		return $conn;
	}

	$sockets = (array) glob( ( getenv( 'HOME' ) ?: '' ) . '/Library/Application Support/Local/run/*/mysql/mysqld.sock' );
	usort(
		$sockets,
		static function ( $a, $b ) {
			return filemtime( $b ) <=> filemtime( $a );
		}
	);

	foreach ( $sockets as $socket ) {
		$conn = @mysqli_connect( 'localhost', $cfg['user'], $cfg['pass'], $cfg['name'], 0, $socket ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( $conn instanceof \mysqli ) {
			fwrite( STDERR, "Connected via socket: {$socket}\n" );
			return $conn;
		}
	}

	fwrite( STDERR, "ERROR: could not connect to the database.\n" );
	exit( 1 );
}

$cfg  = read_db_config( PROSE_CORE_PATH . '../../../wp-config.php' );
$conn = connect_db( $cfg );

$sql = "
	SELECT p.ID AS post_id, p.post_title AS title,
		MAX(CASE WHEN m.meta_key='prose_form_code'       THEN m.meta_value END) AS form_code,
		MAX(CASE WHEN m.meta_key='prose_file_url'        THEN m.meta_value END) AS file_url,
		MAX(CASE WHEN m.meta_key='prose_pdf_fillable'    THEN m.meta_value END) AS fillable,
		MAX(CASE WHEN m.meta_key='prose_pdf_field_count' THEN m.meta_value END) AS field_count,
		MAX(CASE WHEN m.meta_key='prose_pdf_fields_json' THEN m.meta_value END) AS fields_json
	FROM wp_posts p
	JOIN wp_postmeta m ON m.post_id = p.ID
	WHERE p.post_type = 'prose_form'
	GROUP BY p.ID, p.post_title
";

$result = mysqli_query( $conn, $sql );
$rows   = array();

while ( $row = mysqli_fetch_assoc( $result ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
	$fields  = array();
	$decoded = json_decode( (string) ( $row['fields_json'] ?? '' ), true );

	if ( is_array( $decoded ) && isset( $decoded['fields'] ) && is_array( $decoded['fields'] ) ) {
		$fields = array_values( $decoded['fields'] );
	}

	$rows[] = array(
		'post_id'     => (int) $row['post_id'],
		'title'       => (string) $row['title'],
		'form_code'   => (string) ( $row['form_code'] ?? '' ),
		'file_url'    => (string) ( $row['file_url'] ?? '' ),
		'fillable'    => '1' === (string) ( $row['fillable'] ?? '' ),
		'field_count' => (int) ( $row['field_count'] ?? 0 ),
		'fields'      => $fields,
	);
}

mysqli_close( $conn );

$codes       = array( 'UD-1', 'UD-2', 'UD-3', 'UD-4', 'UD-6', 'UD-7', 'FC-1', 'FC-2', 'FC-3', 'FC-7' );
$content_dir = PROSE_CORE_PATH . '../../../wp-content';
$content_dir = realpath( $content_dir ) ?: $content_dir;

$service  = new Pdf_Template_Audit_Service( null, null, $content_dir, '' );
$records  = $service->build_records( $codes, $rows );
$audits   = $service->audit( $records );
$registry = $service->field_registry( $audits );
$markdown = $service->to_markdown( $audits, $registry );

$output_dir = PROSE_CORE_PATH . 'tests/manual/pdf-template-audit-output';

if ( ! is_dir( $output_dir ) ) {
	mkdir( $output_dir, 0775, true );
}

file_put_contents( $output_dir . '/pdf-template-audit.json', (string) wp_json_encode( $audits, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
file_put_contents( $output_dir . '/pdf-field-registry.json', (string) wp_json_encode( $registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
file_put_contents( $output_dir . '/pdf-template-audit.md', $markdown ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents

echo $markdown;
echo "\nArtifacts written to: {$output_dir}\n";
