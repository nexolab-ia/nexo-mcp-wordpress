<?php
/**
 * Arranque de las pruebas: un WordPress mínimo en memoria.
 *
 * Las reglas de permisos de Nexo MCP son lógica pura sobre unas pocas
 * funciones de WordPress (capacidades, opciones, metadatos). Aquí se
 * sustituyen por dobles en memoria para probar esas reglas sin base de datos.
 * La prueba de punta a punta contra un WordPress real está en el README
 * (docker compose).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'NEXO_MCP_VERSION', '1.0.0-test' );
define( 'NEXO_MCP_FILE', dirname( __DIR__ ) . '/nexo-mcp.php' );
define( 'NEXO_MCP_PATH', dirname( __DIR__ ) . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );

require dirname( __DIR__ ) . '/vendor/autoload.php';

/**
 * Estado del WordPress simulado. Se reinicia en cada prueba.
 */
final class WP_Test_State {

	/** @var array<string, mixed> */
	public static array $options = array();

	/** @var array<string, bool> */
	public static array $caps = array();

	/** @var array<int, WP_Post> */
	public static array $posts = array();

	/** @var array<int, array<string, mixed>> */
	public static array $meta = array();

	/** @var array<string, array<string, mixed>> */
	public static array $abilities = array();

	/** @var array<int, array<string, mixed>> */
	public static array $audit = array();

	public static int $user_id = 1;

	public static function reset(): void {
		self::$options   = array();
		self::$caps      = array();
		self::$posts     = array();
		self::$meta      = array();
		self::$abilities = array();
		self::$audit     = array();
		self::$user_id   = 1;
	}
}

class WP_Error {
	/** @var array<string, array<int, string>> */
	public array $errors = array();
	/** @var array<string, mixed> */
	public array $error_data = array();

	public function __construct( $code = '', $message = '', $data = '' ) {
		if ( '' === $code ) {
			return;
		}
		$this->errors[ $code ][] = (string) $message;
		if ( '' !== $data ) {
			$this->error_data[ $code ] = $data;
		}
	}

	public function get_error_code() {
		$codes = array_keys( $this->errors );
		return $codes[0] ?? '';
	}

	public function get_error_message( $code = '' ) {
		$code = '' === $code ? $this->get_error_code() : $code;
		return $this->errors[ $code ][0] ?? '';
	}

	public function get_error_data( $code = '' ) {
		$code = '' === $code ? $this->get_error_code() : $code;
		return $this->error_data[ $code ] ?? null;
	}

	public function has_errors(): bool {
		return array() !== $this->errors;
	}
}

class WP_Post {
	public int $ID = 0;
	public string $post_type = 'post';
	public string $post_status = 'draft';
	public string $post_title = '';
	public string $post_content = '';
	public string $post_name = '';
	public string $post_modified_gmt = '2026-01-01 00:00:00';

	public function __construct( array $data = array() ) {
		foreach ( $data as $key => $value ) {
			$this->$key = $value;
		}
	}
}

/**
 * Doble de $wpdb: guarda las filas de auditoría.
 */
final class WPDB_Test_Double {
	public string $prefix = 'wp_';

	public function insert( string $table, array $row, $format = null ) {
		WP_Test_State::$audit[] = $row;
		return 1;
	}

	public function prepare( string $sql, ...$args ): string {
		return $sql;
	}

	public function get_results( $sql, $output = null ): array {
		return array();
	}

	public function query( $sql ) {
		return 0;
	}

	public function update( ...$args ) {
		return 0;
	}
}

$GLOBALS['wpdb'] = new WPDB_Test_Double();

// ----------------------------------------------------------- Traducción/escape
function __( $text, $domain = null ) { return $text; }
function _e( $text, $domain = null ): void { echo $text; }
function esc_html__( $text, $domain = null ) { return $text; }
function esc_html( $text ) { return (string) $text; }
function esc_attr( $text ) { return (string) $text; }
function esc_url( $text ) { return (string) $text; }
function esc_url_raw( $text ) { return (string) $text; }
function sanitize_text_field( $text ) { return trim( (string) $text ); }
function sanitize_textarea_field( $text ) { return trim( (string) $text ); }
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
function sanitize_title( $title ) { return sanitize_key( str_replace( ' ', '-', (string) $title ) ); }
function sanitize_email( $email ) { return (string) $email; }
function wp_kses_post( $text ) { return preg_replace( '#<script[^>]*>.*?</script>#is', '', (string) $text ); }
function wp_strip_all_tags( $text ) { return strip_tags( (string) $text ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_unslash( $value ) { return $value; }
function wp_slash( $value ) { return $value; }
function wp_json_encode( $data, $flags = 0 ) { return json_encode( $data, $flags ); }
function untrailingslashit( $value ) { return rtrim( (string) $value, '/\\' ); }
function trailingslashit( $value ) { return untrailingslashit( $value ) . '/'; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); }
function rest_sanitize_boolean( $value ) {
	if ( is_string( $value ) ) {
		$value = strtolower( $value );
		if ( in_array( $value, array( 'false', '0', '' ), true ) ) {
			return false;
		}
	}
	return (bool) $value;
}
function is_wp_error( $thing ): bool { return $thing instanceof WP_Error; }
function apply_filters( $hook, $value, ...$args ) { return $value; }
function do_action( $hook, ...$args ): void {}
function add_action( ...$args ): bool { return true; }
function add_filter( ...$args ): bool { return true; }
function did_action( $hook ): int { return 0; }
function current_time( $type, $gmt = false ) { return gmdate( 'Y-m-d H:i:s' ); }
function wp_salt( $scheme = 'auth' ) { return 'test-salt'; }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function site_url( $path = '' ) { return home_url( $path ); }
function rest_url( $path = '' ) { return home_url( '/wp-json/' . ltrim( (string) $path, '/' ) ); }
function admin_url( $path = '' ) { return home_url( '/wp-admin/' . ltrim( (string) $path, '/' ) ); }
function wp_get_environment_type() { return 'production'; }
function is_ssl(): bool { return true; }
function is_multisite(): bool { return false; }

// ----------------------------------------------------------------- Usuarios
function get_current_user_id(): int { return WP_Test_State::$user_id; }
function is_user_logged_in(): bool { return WP_Test_State::$user_id > 0; }
function current_user_can( $capability, ...$args ): bool {
	return ! empty( WP_Test_State::$caps[ $capability ] );
}
function user_can( $user, $capability, ...$args ): bool {
	return current_user_can( $capability, ...$args );
}

// ----------------------------------------------------------------- Opciones
function get_option( $name, $default = false ) {
	return array_key_exists( $name, WP_Test_State::$options ) ? WP_Test_State::$options[ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ): bool {
	WP_Test_State::$options[ $name ] = $value;
	return true;
}
function add_option( $name, $value = '', $deprecated = '', $autoload = null ): bool {
	if ( ! array_key_exists( $name, WP_Test_State::$options ) ) {
		WP_Test_State::$options[ $name ] = $value;
	}
	return true;
}
function delete_option( $name ): bool {
	unset( WP_Test_State::$options[ $name ] );
	return true;
}
function get_transient( $name ) { return get_option( '_transient_' . $name ); }
function set_transient( $name, $value, $ttl = 0 ): bool { return update_option( '_transient_' . $name, $value ); }
function delete_transient( $name ): bool { return delete_option( '_transient_' . $name ); }

// ------------------------------------------------------- Contenido y metadatos
function get_post( $post = null ) {
	if ( $post instanceof WP_Post ) {
		return $post;
	}
	return WP_Test_State::$posts[ (int) $post ] ?? null;
}
function get_post_meta( $post_id, $key = '', $single = false ) {
	$value = WP_Test_State::$meta[ (int) $post_id ][ $key ] ?? '';
	return $single ? $value : array( $value );
}
function update_post_meta( $post_id, $key, $value, $prev = '' ) {
	$old = WP_Test_State::$meta[ (int) $post_id ][ $key ] ?? null;
	if ( $old === $value ) {
		return false;
	}
	WP_Test_State::$meta[ (int) $post_id ][ $key ] = $value;
	return true;
}
function delete_post_meta( $post_id, $key, $value = '' ): bool {
	unset( WP_Test_State::$meta[ (int) $post_id ][ $key ] );
	return true;
}
function wp_update_post( $data, $wp_error = false ) {
	$id = (int) ( $data['ID'] ?? 0 );
	if ( ! isset( WP_Test_State::$posts[ $id ] ) ) {
		return new WP_Error( 'invalid_post', 'No existe.' );
	}
	foreach ( $data as $key => $value ) {
		if ( 'ID' !== $key ) {
			WP_Test_State::$posts[ $id ]->$key = $value;
		}
	}
	return $id;
}

// ---------------------------------------------------------------- Abilities
function wp_register_ability( string $name, array $args ) {
	WP_Test_State::$abilities[ $name ] = $args;
	return true;
}
function wp_register_ability_category( string $slug, array $args ) {
	return true;
}
