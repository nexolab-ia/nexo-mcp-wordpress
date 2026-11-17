<?php
/**
 * Lectura de campos personalizados.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Fields;

/**
 * Entrega los campos personalizados de un contenido al cliente MCP.
 *
 * De momento sólo lee. La escritura llegará cuando cada tipo tenga su
 * saneamiento propio verificado contra una instalación real; escribir con un
 * saneamiento genérico rompería los campos de texto enriquecido y dejaría
 * referencias colgando en los que guardan identificadores.
 */
final class FieldGateway {

	/**
	 * Proveedores reconocidos.
	 *
	 * @var array<int, FieldProvider>
	 */
	private array $providers;

	/**
	 * Saneamiento por tipo de campo.
	 *
	 * @var FieldSanitizer
	 */
	private FieldSanitizer $sanitizer;

	/**
	 * Construye el gateway.
	 *
	 * @param array<int, FieldProvider>|null $providers Proveedores propios.
	 * @param FieldSanitizer|null            $sanitizer Saneador propio.
	 */
	public function __construct( ?array $providers = null, ?FieldSanitizer $sanitizer = null ) {
		$this->providers = $providers ?? array( new AcfProvider() );
		$this->sanitizer = $sanitizer ?? new FieldSanitizer();
	}

	/**
	 * Devuelve el proveedor activo, si hay alguno.
	 */
	public function active(): ?FieldProvider {
		foreach ( $this->providers as $provider ) {
			if ( $provider->is_active() ) {
				return $provider;
			}
		}

		return null;
	}

	/**
	 * Lista los campos personalizados de un contenido.
	 *
	 * @param int $post_id Contenido consultado.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function list_fields( int $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, array( 'page', 'post' ), true ) ) {
			return new \WP_Error(
				'nexo_mcp_content_not_found',
				__( 'No se encontró el contenido solicitado.', 'nexo-mcp' )
			);
		}

		$provider = $this->active();

		if ( null === $provider ) {
			return array(
				'id'       => $post_id,
				'provider' => null,
				'fields'   => array(),
				'message'  => __( 'No hay ningún plugin de campos personalizados reconocido en este sitio.', 'nexo-mcp' ),
			);
		}

		$campos = $provider->read( $post_id );

		return array(
			'id'       => $post_id,
			'provider' => $provider->slug(),
			'label'    => $provider->label(),
			'editable' => count(
				array_filter(
					$campos,
					static fn( FieldValue $campo ): bool => $campo->editable
				)
			),
			'fields'   => array_map(
				static fn( FieldValue $campo ): array => $campo->to_array(),
				$campos
			),
		);
	}

	/**
	 * Escribe un campo personalizado, uno por llamada.
	 *
	 * @param int    $post_id Contenido modificado.
	 * @param string $name    Nombre del campo.
	 * @param mixed  $value   Valor recibido del cliente.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function update_field( int $post_id, string $name, $value ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, array( 'page', 'post' ), true ) ) {
			return new \WP_Error(
				'nexo_mcp_content_not_found',
				__( 'No se encontró el contenido solicitado.', 'nexo-mcp' )
			);
		}

		$provider = $this->active();

		if ( ! $provider instanceof AcfProvider ) {
			return new \WP_Error(
				'nexo_mcp_fields_provider_missing',
				__( 'No hay ningún plugin de campos personalizados reconocido en este sitio.', 'nexo-mcp' )
			);
		}

		// Sin definición no hay escritura. Es lo que impide que el nombre del
		// campo se convierta en una clave arbitraria de `post_meta` y se acabe
		// pisando fontanería de WordPress como `_wp_page_template`.
		$definicion = $provider->definition( $name, $post_id );

		if ( null === $definicion ) {
			return new \WP_Error(
				'nexo_mcp_field_unknown',
				__( 'El campo no existe para este contenido según el plugin de campos.', 'nexo-mcp' )
			);
		}

		$tipo = (string) $definicion['type'];

		if ( ! FieldTypes::writable( $tipo ) ) {
			return new \WP_Error(
				'nexo_mcp_field_type_denied',
				FieldTypes::reason( $tipo ),
				array( 'type' => $tipo )
			);
		}

		$limpio = $this->sanitizer->clean( $tipo, $value );

		if ( is_wp_error( $limpio ) ) {
			return $limpio;
		}

		update_field( $name, $limpio, $post_id );

		return array(
			'id'    => $post_id,
			'name'  => $name,
			'type'  => $tipo,
			'value' => get_field( $name, $post_id ),
			'cache' => ( new \NexoDev\NexoMCP\Cache\CachePurger( new \NexoDev\NexoMCP\Support\Settings() ) )
				->after_post_change( $post_id ),
		);
	}
}
