<?php
/**
 * Nodo normalizado de un constructor visual.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Representa un fragmento de texto editable dentro de una página.
 *
 * El cliente MCP nunca recibe el árbol propietario completo: una página de
 * Elementor supera con facilidad el megabyte de JSON y saturaría su contexto.
 * Recibe esta lista plana y dirige cada edición por identificador de nodo.
 */
final class BuilderNode {

	/**
	 * Identificador del nodo dentro del contenido.
	 *
	 * @var string
	 */
	public string $id;

	/**
	 * Tipo declarado por el constructor.
	 *
	 * @var string
	 */
	public string $type;

	/**
	 * Texto actual, o null cuando el nodo no es editable.
	 *
	 * @var string|null
	 */
	public ?string $text;

	/**
	 * Indica si el nodo admite edición.
	 *
	 * @var bool
	 */
	public bool $editable;

	/**
	 * Motivo por el que un nodo no es editable.
	 *
	 * @var string
	 */
	public string $reason;

	/**
	 * Atributos editables y su valor actual.
	 *
	 * Sólo los que el tipo admite, así que un nodo sin enlace ni imagen llega
	 * con el array vacío y el cliente sabe de antemano qué puede pedir en vez
	 * de descubrirlo a base de errores.
	 *
	 * @var array<string, mixed>
	 */
	public array $attributes;

	/**
	 * Construye un nodo normalizado.
	 *
	 * @param string               $id         Identificador.
	 * @param string               $type       Tipo del constructor.
	 * @param string|null          $text       Texto actual.
	 * @param bool                 $editable   Si admite edición.
	 * @param string               $reason     Motivo del bloqueo.
	 * @param array<string, mixed> $attributes Atributos editables.
	 */
	public function __construct(
		string $id,
		string $type,
		?string $text,
		bool $editable = true,
		string $reason = '',
		array $attributes = array()
	) {
		$this->id         = $id;
		$this->type       = $type;
		$this->text       = $text;
		$this->editable   = $editable;
		$this->reason     = $reason;
		$this->attributes = $attributes;
	}

	/**
	 * Construye un nodo bloqueado por política de seguridad.
	 *
	 * @param string $id   Identificador.
	 * @param string $type Tipo del constructor.
	 */
	public static function blocked( string $id, string $type ): self {
		return new self(
			$id,
			$type,
			null,
			false,
			__( 'Tipo no editable: admite código ejecutable.', 'nexo-mcp' )
		);
	}

	/**
	 * Serializa el nodo para la respuesta MCP.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$data = array(
			'id'   => $this->id,
			'type' => $this->type,
		);

		// Lo normal es que un nodo tenga texto y se pueda editar, así que sólo
		// viaja lo que se sale de eso. En una landing de cincuenta nodos, repetir
		// `"editable": true` en cada uno eran cientos de tokens de ruido.
		if ( null !== $this->text ) {
			$data['text'] = $this->text;
		}

		if ( ! $this->editable ) {
			$data['editable'] = false;
		}

		if ( '' !== $this->reason ) {
			$data['reason'] = $this->reason;
		}

		// Sólo viaja cuando hay algo que ofrecer: un catálogo de cuarenta nodos
		// con un `attributes: {}` en cada uno es ruido en el contexto del cliente.
		if ( array() !== $this->attributes ) {
			$data['attributes'] = $this->attributes;
		}

		return $data;
	}
}
