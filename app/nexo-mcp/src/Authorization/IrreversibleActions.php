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
 * - delete-global-class y delete-design-variable: `DELETE` (quitan la clase o variable de todo el sitio).
 * - update-kit-globals: `DELETE`, sólo si `delete` trae algo (borra colores o tipografías personalizadas).
 * - undo-design-system-change: `UNDO` (sobrescribe la colección completa con el respaldo).
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
		'delete-product'            => 'DELETE',
		'restore-revision'          => 'RESTORE',
		'delete-global-class'       => 'DELETE',
		'delete-design-variable'    => 'DELETE',
		'undo-design-system-change' => 'UNDO',
	);

	/**
	 * Herramientas que piden palabra sólo si un parámetro trae algo: herramienta => [parámetro, palabra].
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const CONDITIONAL = array(
		'update-kit-globals' => array( 'delete', 'DELETE' ),
	);

	/**
	 * Impide instanciar el catálogo.
	 */
	private function __construct() {
	}

	/**
	 * Palabra que exige una herramienta, o null si no exige ninguna.
	 *
	 * Una herramienta condicional (`update-kit-globals`) devuelve su palabra sin `$input`
	 * (para el esquema) y, con `$input`, sólo si el parámetro que la dispara trae algo.
	 *
	 * @param string                    $tool  Nombre corto de la herramienta.
	 * @param array<string, mixed>|null $input Parámetros recibidos, si se evalúa una llamada.
	 */
	public static function word_for( string $tool, ?array $input = null ): ?string {
		if ( isset( self::WORDS[ $tool ] ) ) {
			return self::WORDS[ $tool ];
		}

		if ( ! isset( self::CONDITIONAL[ $tool ] ) ) {
			return null;
		}

		[ $param, $word ] = self::CONDITIONAL[ $tool ];

		if ( null === $input ) {
			return $word;
		}

		return isset( $input[ $param ] ) && array() !== $input[ $param ] && '' !== $input[ $param ] ? $word : null;
	}

	/**
	 * Herramientas que exigen confirmación.
	 *
	 * @return array<int, string>
	 */
	public static function tools(): array {
		return array_merge( array_keys( self::WORDS ), array_keys( self::CONDITIONAL ) );
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
		$word = self::word_for( $tool, $input );

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
