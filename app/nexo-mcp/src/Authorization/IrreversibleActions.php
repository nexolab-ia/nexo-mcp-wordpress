<?php
/**
 * Confirmación explícita de las acciones que no se pueden deshacer.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Authorization;

/**
 * Exige una palabra de confirmación antes de ejecutar acciones irreversibles.
 *
 * El esquema JSON de cada herramienta ya declara el campo `confirmation`,
 * pero el esquema lo valida quien llama y un cliente descuidado puede saltarlo.
 * Aquí se repite en el servidor, en el mismo punto para todas las
 * herramientas, y la negativa queda auditada.
 *
 * - delete-product: `DELETE` (con `force` el producto se borra para siempre).
 * - restore-revision: `RESTORE` (reemplaza el contenido actual por otro).
 * - execute-plugin-action: no usa palabra; su confirmación es el token de
 *   aprobación de un solo uso que entrega prepare-plugin-action.
 */
final class IrreversibleActions {

	/**
	 * Código de error común.
	 */
	public const CODE = 'nexo_mcp_confirmation_required';

	/**
	 * Palabra exigida por herramienta.
	 *
	 * @var array<string, string>
	 */
	private const WORDS = array(
		'delete-product'   => 'DELETE',
		'restore-revision' => 'RESTORE',
	);

	/**
	 * Impide instanciar el catálogo.
	 */
	private function __construct() {
	}

	/**
	 * Palabra que exige una herramienta, o null si no exige ninguna.
	 *
	 * @param string $tool Nombre corto de la herramienta.
	 */
	public static function word_for( string $tool ): ?string {
		return self::WORDS[ $tool ] ?? null;
	}

	/**
	 * Herramientas que exigen confirmación.
	 *
	 * @return array<int, string>
	 */
	public static function tools(): array {
		return array_keys( self::WORDS );
	}

	/**
	 * Comprueba la confirmación recibida.
	 *
	 * @param string               $tool  Nombre corto de la herramienta.
	 * @param array<string, mixed> $input Parámetros recibidos.
	 *
	 * @return true|\WP_Error
	 */
	public static function check( string $tool, array $input ) {
		$word = self::word_for( $tool );

		if ( null === $word ) {
			return true;
		}

		$given = isset( $input['confirmation'] ) && is_string( $input['confirmation'] )
			? $input['confirmation']
			: '';

		if ( hash_equals( $word, $given ) ) {
			return true;
		}

		return new \WP_Error(
			self::CODE,
			sprintf(
				/* translators: 1: herramienta, 2: palabra de confirmación. */
				__( '«%1$s» no se puede deshacer. Pide confirmación explícita a la persona y repite la llamada con "confirmation": "%2$s".', 'nexo-mcp' ),
				$tool,
				$word
			),
			array( 'status' => 400 )
		);
	}

	/**
	 * Fragmento de esquema JSON para el campo de confirmación.
	 *
	 * @param string $tool Nombre corto de la herramienta.
	 *
	 * @return array<string, mixed>
	 */
	public static function schema_for( string $tool ): array {
		$word = self::word_for( $tool );

		return array(
			'type'        => 'string',
			'enum'        => array( (string) $word ),
			'description' => sprintf( 'Escribe %s sólo después de que la persona lo apruebe: la acción no se puede deshacer.', (string) $word ),
		);
	}
}
