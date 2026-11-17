<?php
/**
 * Campo personalizado normalizado.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Fields;

/**
 * Representa un campo personalizado tal como lo ve el cliente MCP.
 *
 * Cada plugin de campos guarda lo suyo a su manera, y el árbol completo de un
 * grupo con repetidores puede ser enorme. El cliente recibe esta lista plana y
 * dirige cada edición por el nombre del campo, igual que con los constructores.
 */
final class FieldValue {

	/**
	 * Nombre del campo, el que se usa para escribirlo.
	 *
	 * @var string
	 */
	public string $name;

	/**
	 * Tipo declarado por el plugin de campos.
	 *
	 * @var string
	 */
	public string $type;

	/**
	 * Etiqueta legible para el propietario del sitio.
	 *
	 * @var string
	 */
	public string $label;

	/**
	 * Valor actual, o null cuando el campo no se puede leer plano.
	 *
	 * @var mixed
	 */
	public $value;

	/**
	 * Indica si el campo admite escritura desde el conector.
	 *
	 * @var bool
	 */
	public bool $editable;

	/**
	 * Motivo por el que un campo no admite escritura.
	 *
	 * @var string
	 */
	public string $reason;

	/**
	 * Construye un campo normalizado.
	 *
	 * @param string $name     Nombre del campo.
	 * @param string $type     Tipo declarado.
	 * @param string $label    Etiqueta legible.
	 * @param mixed  $value    Valor actual.
	 * @param bool   $editable Si admite escritura.
	 * @param string $reason   Motivo del bloqueo.
	 */
	public function __construct(
		string $name,
		string $type,
		string $label,
		$value = null,
		bool $editable = true,
		string $reason = ''
	) {
		$this->name     = $name;
		$this->type     = $type;
		$this->label    = $label;
		$this->value    = $value;
		$this->editable = $editable;
		$this->reason   = $reason;
	}

	/**
	 * Construye un campo que se muestra pero no se escribe.
	 *
	 * Se listan igual que los editables para que el cliente sepa que existen y
	 * no insista: callarlos llevaría a reintentos y a suponer que el dato falta.
	 *
	 * @param string $name   Nombre del campo.
	 * @param string $type   Tipo declarado.
	 * @param string $label  Etiqueta legible.
	 * @param string $reason Motivo del bloqueo.
	 */
	public static function blocked( string $name, string $type, string $label, string $reason ): self {
		return new self( $name, $type, $label, null, false, $reason );
	}

	/**
	 * Serializa el campo para la respuesta MCP.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$data = array(
			'name'     => $this->name,
			'type'     => $this->type,
			'label'    => $this->label,
			'value'    => $this->value,
			'editable' => $this->editable,
		);

		if ( '' !== $this->reason ) {
			$data['reason'] = $this->reason;
		}

		return $data;
	}
}
