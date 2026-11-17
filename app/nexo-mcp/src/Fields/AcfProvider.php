<?php
/**
 * Proveedor de campos de Advanced Custom Fields.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Fields;

/**
 * Lee los campos que ACF declara para una entrada.
 *
 * Se consulta siempre a ACF por la definición del campo antes de devolver nada.
 * Recorrer `post_meta` en crudo mezclaría claves internas de WordPress con las
 * del sitio, y no habría forma de saber qué es un campo del cliente y qué es
 * fontanería que no debe tocarse.
 */
final class AcfProvider implements FieldProvider {

	/**
	 * Identificador estable del proveedor.
	 */
	public function slug(): string {
		return 'acf';
	}

	/**
	 * Nombre legible del plugin.
	 */
	public function label(): string {
		return 'Advanced Custom Fields';
	}

	/**
	 * Indica si ACF está activo.
	 */
	public function is_active(): bool {
		return function_exists( 'get_field_objects' ) && function_exists( 'get_field_object' );
	}

	/**
	 * Lee los campos definidos para una entrada.
	 *
	 * @param int $post_id Entrada consultada.
	 * @return array<int, FieldValue>
	 */
	public function read( int $post_id ): array {
		if ( ! $this->is_active() ) {
			return array();
		}

		// Se recorren los grupos de campos, no los valores guardados.
		// `get_field_objects()` sólo devuelve los campos que ya tienen valor, así
		// que una ficha recién creada aparecía vacía: el cliente no veía los
		// campos que faltaban por rellenar y no tenía forma de saber que existían,
		// que es justo lo que se le pide cuando completa una ficha.
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return $this->desde_valores( $post_id );
		}

		$grupos = acf_get_field_groups( array( 'post_id' => $post_id ) );

		if ( ! is_array( $grupos ) || array() === $grupos ) {
			return array();
		}

		$campos = array();
		$vistos = array();

		foreach ( $grupos as $grupo ) {
			$definiciones = acf_get_fields( $grupo );

			if ( ! is_array( $definiciones ) ) {
				continue;
			}

			foreach ( $definiciones as $definicion ) {
				$nombre = (string) ( $definicion['name'] ?? '' );

				// Un mismo campo puede venir en dos grupos aplicables al contenido.
				if ( '' === $nombre || isset( $vistos[ $nombre ] ) ) {
					continue;
				}

				$vistos[ $nombre ]   = true;
				$definicion['value'] = get_field( $nombre, $post_id );
				$campos[]            = $this->normalizar( $nombre, $definicion );
			}
		}

		return $campos;
	}

	/**
	 * Respaldo cuando las funciones de grupos no están disponibles.
	 *
	 * @param int $post_id Entrada consultada.
	 * @return array<int, FieldValue>
	 */
	private function desde_valores( int $post_id ): array {
		$objetos = get_field_objects( $post_id );

		if ( ! is_array( $objetos ) ) {
			return array();
		}

		$campos = array();

		foreach ( $objetos as $nombre => $definicion ) {
			$campos[] = $this->normalizar( (string) $nombre, is_array( $definicion ) ? $definicion : array() );
		}

		return $campos;
	}

	/**
	 * Consulta la definición de un solo campo.
	 *
	 * Devuelve null cuando ACF no lo reconoce, que es la comprobación que impide
	 * escribir una clave arbitraria de `post_meta`.
	 *
	 * @param string $name    Nombre del campo.
	 * @param int    $post_id Entrada consultada.
	 * @return array<string, mixed>|null
	 */
	public function definition( string $name, int $post_id ): ?array {
		if ( ! $this->is_active() ) {
			return null;
		}

		$definicion = get_field_object( $name, $post_id );

		if ( is_array( $definicion ) && ! empty( $definicion['type'] ) ) {
			return $definicion;
		}

		// `get_field_object()` resuelve por el valor guardado, así que un campo
		// que todavía está vacío no aparece. Rellenar una ficha en blanco es el
		// caso normal, no la excepción: se busca la definición entre los grupos
		// que aplican al contenido, que es donde vive de verdad.
		foreach ( $this->definiciones_de_grupos( $post_id ) as $campo ) {
			if ( (string) ( $campo['name'] ?? '' ) === $name ) {
				return $campo;
			}
		}

		return null;
	}

	/**
	 * Reúne las definiciones de todos los grupos que aplican al contenido.
	 *
	 * @param int $post_id Entrada consultada.
	 * @return array<int, array<string, mixed>>
	 */
	private function definiciones_de_grupos( int $post_id ): array {
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return array();
		}

		$grupos = acf_get_field_groups( array( 'post_id' => $post_id ) );

		if ( ! is_array( $grupos ) ) {
			return array();
		}

		$definiciones = array();

		foreach ( $grupos as $grupo ) {
			$campos = acf_get_fields( $grupo );

			if ( is_array( $campos ) ) {
				$definiciones = array_merge( $definiciones, $campos );
			}
		}

		return $definiciones;
	}

	/**
	 * Convierte la definición de ACF en un campo normalizado.
	 *
	 * @param string               $nombre     Nombre del campo.
	 * @param array<string, mixed> $definicion Definición entregada por ACF.
	 */
	private function normalizar( string $nombre, array $definicion ): FieldValue {
		$tipo       = (string) ( $definicion['type'] ?? '' );
		$etiqueta   = (string) ( $definicion['label'] ?? $nombre );
		$anidado    = FieldTypes::nested( $tipo );
		$escribible = FieldTypes::writable( $tipo );

		if ( ! $escribible ) {
			// Los anidados sí muestran su valor, porque leerlos es útil aunque no
			// se escriban; los tipos desconocidos no, porque no sabemos su forma.
			$campo = FieldValue::blocked( $nombre, $tipo, $etiqueta, FieldTypes::reason( $tipo ) );

			if ( $anidado ) {
				$campo->value = $definicion['value'] ?? null;
			}

			return $campo;
		}

		return new FieldValue( $nombre, $tipo, $etiqueta, $definicion['value'] ?? null );
	}
}
