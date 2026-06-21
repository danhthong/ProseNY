<?php
/**
 * PHPUnit bootstrap for prose-core unit tests.
 *
 * @package ProSeCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'PROSE_CORE_PATH' ) ) {
	define( 'PROSE_CORE_PATH', dirname( __DIR__ ) . '/' );
}

$composer_autoload = PROSE_CORE_PATH . 'vendor/autoload.php';

if ( is_readable( $composer_autoload ) ) {
	require_once $composer_autoload;
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * @param mixed $data Data.
	 * @return string|false
	 */
	function wp_json_encode( $data ) {
		return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * @param string $text   Text to translate.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function __( $text, $domain = 'default' ) {
		unset( $domain );
		return (string) $text;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * @param string $str String.
	 * @return string
	 */
	function sanitize_text_field( $str ) {
		return trim( (string) $str );
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	/**
	 * @param string $str String.
	 * @return string
	 */
	function sanitize_textarea_field( $str ) {
		return trim( (string) $str );
	}
}

$GLOBALS['prose_test_options'] = array();

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * @param string $option  Option name.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	function get_option( $option, $default = false ) {
		return $GLOBALS['prose_test_options'][ $option ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * @param string $option Option name.
	 * @param mixed  $value  Value.
	 * @return bool
	 */
	function update_option( $option, $value ) {
		$GLOBALS['prose_test_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * @param string $option Option name.
	 * @return bool
	 */
	function delete_option( $option ) {
		unset( $GLOBALS['prose_test_options'][ $option ] );
		return true;
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	/**
	 * @param string $filename Filename.
	 * @return string
	 */
	function sanitize_file_name( $filename ) {
		return preg_replace( '/[^A-Za-z0-9._-]/', '-', (string) $filename );
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	/**
	 * @param string $title Title.
	 * @return string
	 */
	function sanitize_title( $title ) {
		$title = strtolower( trim( (string) $title ) );
		return preg_replace( '/[^a-z0-9-]/', '-', $title );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * @param string $key String key.
	 * @return string
	 */
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

if ( ! function_exists( 'wp_normalize_path' ) ) {
	/**
	 * @param string $path Path.
	 * @return string
	 */
	function wp_normalize_path( $path ) {
		$path = str_replace( '\\', '/', (string) $path );
		$path = preg_replace( '|(?<=.)/+|', '/', $path );

		if ( ':' === substr( $path, 1, 1 ) ) {
			$path = ucfirst( $path );
		}

		return $path;
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * @param string $url URL.
	 * @return string
	 */
	function esc_url_raw( $url ) {
		return trim( (string) $url );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	/**
	 * @param string $string URL or path.
	 * @return string
	 */
	function trailingslashit( $string ) {
		return rtrim( (string) $string, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'current_time' ) ) {
	/**
	 * @param string $type Type.
	 * @return string
	 */
	function current_time( $type ) {
		unset( $type );
		return gmdate( 'Y-m-d H:i:s' );
	}
}

if ( ! function_exists( 'wp_rand' ) ) {
	/**
	 * @param int $min Minimum.
	 * @param int $max Maximum.
	 * @return int
	 */
	function wp_rand( $min = 0, $max = 0 ) {
		return random_int( (int) $min, (int) $max );
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	/**
	 * @return string
	 */
	function wp_generate_uuid4() {
		$data = random_bytes( 16 );
		$data[6] = chr( ( ord( $data[6] ) & 0x0f ) | 0x40 );
		$data[8] = chr( ( ord( $data[8] ) & 0x3f ) | 0x80 );

		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * @param string $capability Capability.
	 * @return bool
	 */
	function current_user_can( $capability ) {
		unset( $capability );
		return false;
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	/**
	 * @return array<string, string|bool>
	 */
	function wp_upload_dir() {
		$base = sys_get_temp_dir() . '/prose-uploads-test';

		if ( ! is_dir( $base ) ) {
			mkdir( $base, 0777, true );
		}

		$subdir = gmdate( 'Y/m' );
		$path   = trailingslashit( $base ) . $subdir;

		if ( ! is_dir( $path ) ) {
			mkdir( $path, 0777, true );
		}

		return array(
			'basedir' => $base,
			'baseurl' => 'http://example.test/uploads',
			'path'    => trailingslashit( $path ),
			'url'     => 'http://example.test/uploads/' . $subdir . '/',
			'error'   => false,
		);
	}
}

if ( ! function_exists( 'wp_generate_password' ) ) {
	/**
	 * @param int  $length              Password length.
	 * @param bool $special_chars       Include special characters.
	 * @param bool $extra_special_chars Extra special characters.
	 * @return string
	 */
	function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
		unset( $special_chars, $extra_special_chars );

		return substr( bin2hex( random_bytes( (int) max( 1, ceil( $length / 2 ) ) ) ), 0, (int) $length );
	}
}

$GLOBALS['prose_test_transients'] = array();

if ( ! function_exists( 'get_transient' ) ) {
	/**
	 * @param string $transient Transient name.
	 * @return mixed
	 */
	function get_transient( $transient ) {
		return $GLOBALS['prose_test_transients'][ $transient ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	/**
	 * @param string $transient  Transient name.
	 * @param mixed  $value      Value.
	 * @param int    $expiration Expiration seconds.
	 * @return bool
	 */
	function set_transient( $transient, $value, $expiration = 0 ) {
		unset( $expiration );
		$GLOBALS['prose_test_transients'][ $transient ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	/**
	 * @param string $transient Transient name.
	 * @return bool
	 */
	function delete_transient( $transient ) {
		unset( $GLOBALS['prose_test_transients'][ $transient ] );

		return true;
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	/**
	 * @param string $target_dir Target directory.
	 * @return bool
	 */
	function wp_mkdir_p( $target_dir ) {
		return is_dir( $target_dir ) || mkdir( $target_dir, 0777, true );
	}
}

if ( ! function_exists( 'wp_delete_file' ) ) {
	/**
	 * @param string $file File path.
	 * @return void
	 */
	function wp_delete_file( $file ) {
		if ( is_string( $file ) && file_exists( $file ) ) {
			unlink( $file );
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * @param mixed $thing Thing to check.
	 * @return bool
	 */
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

$GLOBALS['prose_test_filters'] = array();

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * @param string   $tag      Filter tag.
	 * @param callable $callback Callback.
	 * @return true
	 */
	function add_filter( $tag, $callback ) {
		if ( ! isset( $GLOBALS['prose_test_filters'][ $tag ] ) ) {
			$GLOBALS['prose_test_filters'][ $tag ] = array();
		}

		$GLOBALS['prose_test_filters'][ $tag ][] = $callback;

		return true;
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	/**
	 * @param string   $tag      Filter tag.
	 * @param callable $callback Callback.
	 * @return bool
	 */
	function remove_filter( $tag, $callback ) {
		if ( ! isset( $GLOBALS['prose_test_filters'][ $tag ] ) ) {
			return false;
		}

		$key = array_search( $callback, $GLOBALS['prose_test_filters'][ $tag ], true );

		if ( false === $key ) {
			return false;
		}

		unset( $GLOBALS['prose_test_filters'][ $tag ][ $key ] );

		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * @param string $tag  Filter tag.
	 * @param mixed  $value Value.
	 * @return mixed
	 */
	function apply_filters( $tag, $value ) {
		$args = func_get_args();
		array_shift( $args );

		if ( empty( $GLOBALS['prose_test_filters'][ $tag ] ) ) {
			return $value;
		}

		foreach ( $GLOBALS['prose_test_filters'][ $tag ] as $callback ) {
			$args[0] = $value;
			$value   = call_user_func_array( $callback, $args );
		}

		return $value;
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * Minimal REST response stub.
	 */
	class WP_REST_Response {
		/**
		 * @var mixed
		 */
		private $data;

		/**
		 * @var int
		 */
		private int $status;

		/**
		 * @param mixed $data   Response data.
		 * @param int   $status HTTP status.
		 */
		public function __construct( $data = null, $status = 200 ) {
			$this->data   = $data;
			$this->status = (int) $status;
		}

		/**
		 * @return mixed
		 */
		public function get_data() {
			return $this->data;
		}

		/**
		 * @return int
		 */
		public function get_status() {
			return $this->status;
		}
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error stub for unit tests.
	 */
	class WP_Error {
		/**
		 * @var string
		 */
		private string $code;

		/**
		 * @var string
		 */
		private string $message;

		/**
		 * @var mixed
		 */
		private $data;

		/**
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 * @param mixed  $data    Error data.
		 */
		public function __construct( $code = '', $message = '', $data = null ) {
			$this->code    = (string) $code;
			$this->message = (string) $message;
			$this->data    = $data;
		}

		/**
		 * @return string
		 */
		public function get_error_code() {
			return $this->code;
		}

		/**
		 * @return string
		 */
		public function get_error_message() {
			return $this->message;
		}

		/**
		 * @return mixed
		 */
		public function get_error_data() {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	/**
	 * Minimal WP_Post stub for unit tests.
	 */
	class WP_Post {
		/**
		 * @var int
		 */
		public $ID = 0;

		/**
		 * @var string
		 */
		public $post_title = '';

		/**
		 * @var string
		 */
		public $post_type = '';

		/**
		 * @var string
		 */
		public $post_status = 'publish';
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	/**
	 * Minimal WP_Query stub for unit tests (returns no posts).
	 */
	class WP_Query {
		/**
		 * @var array<int, WP_Post|int>
		 */
		public $posts = array();

		/**
		 * @var int
		 */
		public $post_count = 0;

		/**
		 * @var int
		 */
		public $found_posts = 0;

		/**
		 * @param array<string, mixed> $query Query args.
		 */
		public function __construct( $query = array() ) {
			unset( $query );
			$this->post_count  = count( $this->posts );
			$this->found_posts = $this->post_count;
		}

		/**
		 * @return bool
		 */
		public function have_posts() {
			return $this->post_count > 0;
		}
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	/**
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param bool   $single  Return single value.
	 * @return mixed
	 */
	function get_post_meta( $post_id, $key = '', $single = false ) {
		unset( $post_id, $key, $single );
		return $single ? '' : array();
	}
}

if ( ! function_exists( 'wp_get_post_terms' ) ) {
	/**
	 * @param int                  $post_id  Post ID.
	 * @param string               $taxonomy Taxonomy.
	 * @param array<string, mixed> $args     Args.
	 * @return array<int, string>|WP_Error
	 */
	function wp_get_post_terms( $post_id, $taxonomy = '', $args = array() ) {
		unset( $post_id, $taxonomy, $args );
		return array();
	}
}

if ( ! function_exists( 'get_term_by' ) ) {
	/**
	 * @param string $field    Field.
	 * @param string $value    Value.
	 * @param string $taxonomy Taxonomy.
	 * @return null
	 */
	function get_term_by( $field, $value, $taxonomy ) {
		unset( $field, $value, $taxonomy );
		return null;
	}
}

if ( ! function_exists( 'prose_test_temp_dir' ) ) {
	/**
	 * Create a writable temp directory for unit tests (avoids Windows TEMP ACL issues).
	 *
	 * @param string $prefix Directory name prefix.
	 * @return string Absolute path without trailing separator.
	 */
	function prose_test_temp_dir( string $prefix ): string {
		$base = PROSE_CORE_PATH . 'tests' . DIRECTORY_SEPARATOR . 'tmp';

		if ( ! is_dir( $base ) ) {
			mkdir( $base, 0777, true );
		}

		$dir = $base . DIRECTORY_SEPARATOR . sanitize_file_name( $prefix ) . '-' . uniqid( '', true );
		mkdir( $dir, 0777, true );

		return $dir;
	}
}

if ( ! function_exists( 'prose_test_remove_tree' ) ) {
	/**
	 * Recursively remove a directory tree. Never throws — teardown must not fail tests.
	 *
	 * @param string $dir Directory path.
	 * @return void
	 */
	function prose_test_remove_tree( string $dir ): void {
		if ( '' === $dir ) {
			return;
		}

		$resolved = realpath( $dir );
		$dir      = false !== $resolved ? $resolved : $dir;

		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $items ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$path = $dir . DIRECTORY_SEPARATOR . $item;

			if ( is_dir( $path ) ) {
				prose_test_remove_tree( $path );
				continue;
			}

			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
}

require_once PROSE_CORE_PATH . 'includes/class-autoloader.php';
ProSe\Core\Autoloader::register();
