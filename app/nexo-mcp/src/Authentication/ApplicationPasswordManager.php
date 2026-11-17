<?php
/**
 * Gestión segura de credenciales revocables.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Authentication;

use NexoDev\NexoMCP\Authorization\Capabilities;

/**
 * Encapsula la API oficial de Application Passwords.
 */
final class ApplicationPasswordManager {

	private const NAME_PREFIX = 'Nexo MCP · ';

	/**
	 * Crea una credencial y devuelve el secreto una sola vez.
	 *
	 * @param int    $user_id Usuario autorizado.
	 * @param string $label   Nombre reconocible del cliente.
	 * @return array{password:string,item:array<string,mixed>}|\WP_Error
	 */
	public function create( int $user_id, string $label ) {
		$user = get_userdata( $user_id );

		if ( false === $user || ! user_can( $user, Capabilities::CONNECT ) ) {
			return new \WP_Error(
				'nexo_mcp_invalid_credential_user',
				__( 'El usuario seleccionado no tiene acceso MCP.', 'nexo-mcp' )
			);
		}

		if ( ! wp_is_application_passwords_available_for_user( $user ) ) {
			return new \WP_Error(
				'nexo_mcp_application_passwords_unavailable',
				__( 'Application Passwords no está disponible para este usuario. Revisa HTTPS y los plugins de seguridad.', 'nexo-mcp' )
			);
		}

		$clean_label = sanitize_text_field( $label );

		if ( '' === $clean_label ) {
			return new \WP_Error(
				'nexo_mcp_empty_credential_label',
				__( 'Escribe un nombre para identificar la credencial.', 'nexo-mcp' )
			);
		}

		$app_id = wp_generate_uuid4();
		$name   = self::NAME_PREFIX
			. wp_html_excerpt( $clean_label, 80, '' )
			. ' · '
			. substr( $app_id, 0, 8 );

		$created = \WP_Application_Passwords::create_new_application_password(
			$user_id,
			array(
				'name'   => $name,
				'app_id' => $app_id,
			)
		);

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		return array(
			'password' => (string) $created[0],
			'item'     => (array) $created[1],
		);
	}

	/**
	 * Revoca una credencial Nexo concreta.
	 *
	 * @param int    $user_id Propietario.
	 * @param string $uuid    UUID de la credencial.
	 * @return true|\WP_Error
	 */
	public function revoke( int $user_id, string $uuid ) {
		$item = $this->find( $user_id, $uuid );

		if ( is_wp_error( $item ) ) {
			return $item;
		}

		$deleted = \WP_Application_Passwords::delete_application_password( $user_id, $uuid );

		if ( ! $deleted ) {
			return new \WP_Error(
				'nexo_mcp_credential_revoke_failed',
				__( 'No fue posible revocar la credencial.', 'nexo-mcp' )
			);
		}

		return true;
	}

	/**
	 * Rota una credencial sin dejar al usuario sin acceso si falla la creación.
	 *
	 * @param int    $user_id Propietario.
	 * @param string $uuid    UUID anterior.
	 * @return array{password:string,item:array<string,mixed>}|\WP_Error
	 */
	public function rotate( int $user_id, string $uuid ) {
		$old_item = $this->find( $user_id, $uuid );

		if ( is_wp_error( $old_item ) ) {
			return $old_item;
		}

		$old_name = str_replace( self::NAME_PREFIX, '', (string) $old_item['name'] );
		$created  = $this->create( $user_id, 'Rotada · ' . $old_name );

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$revoked = \WP_Application_Passwords::delete_application_password( $user_id, $uuid );

		if ( ! $revoked ) {
			$new_uuid = (string) ( $created['item']['uuid'] ?? '' );

			if ( wp_is_uuid( $new_uuid ) ) {
				\WP_Application_Passwords::delete_application_password( $user_id, $new_uuid );
			}

			return new \WP_Error(
				'nexo_mcp_credential_rotation_failed',
				__( 'No fue posible revocar la credencial anterior; la nueva fue descartada.', 'nexo-mcp' )
			);
		}

		return $created;
	}

	/**
	 * Lista sólo credenciales creadas por Nexo MCP.
	 *
	 * @param int $user_id Propietario.
	 * @return list<array<string, mixed>>
	 */
	public function list_for_user( int $user_id ): array {
		$items = \WP_Application_Passwords::get_user_application_passwords( $user_id );

		return array_values(
			array_filter(
				$items,
				static function ( array $item ): bool {
					return str_starts_with( (string) ( $item['name'] ?? '' ), self::NAME_PREFIX );
				}
			)
		);
	}

	/**
	 * Obtiene usuarios autorizados para recibir una credencial MCP.
	 *
	 * @return list<\WP_User>
	 */
	public function get_eligible_users(): array {
		$users = get_users(
			array(
				'capability' => Capabilities::CONNECT,
				'orderby'    => 'display_name',
				'order'      => 'ASC',
			)
		);

		return array_values(
			array_filter(
				$users,
				static function ( \WP_User $user ): bool {
					return wp_is_application_passwords_available_for_user( $user );
				}
			)
		);
	}

	/**
	 * Busca y valida que el UUID pertenezca a Nexo MCP.
	 *
	 * @param int    $user_id Propietario.
	 * @param string $uuid    UUID buscado.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function find( int $user_id, string $uuid ) {
		if ( ! wp_is_uuid( $uuid ) ) {
			return new \WP_Error(
				'nexo_mcp_invalid_credential_uuid',
				__( 'El identificador de credencial no es válido.', 'nexo-mcp' )
			);
		}

		$item = \WP_Application_Passwords::get_user_application_password( $user_id, $uuid );

		if ( null === $item || ! str_starts_with( (string) ( $item['name'] ?? '' ), self::NAME_PREFIX ) ) {
			return new \WP_Error(
				'nexo_mcp_credential_not_found',
				__( 'La credencial Nexo MCP no existe.', 'nexo-mcp' )
			);
		}

		return $item;
	}
}
