<?php
/**
 * Resultado inmutable de validar un archivo entrante.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Media;

/**
 * Transporta el nombre ya saneado y el tipo real detectado.
 */
final class ValidatedUpload {

	/**
	 * Nombre seguro para la biblioteca de medios.
	 *
	 * @var string
	 */
	public string $filename;

	/**
	 * Tipo detectado leyendo los bytes del archivo.
	 *
	 * @var string
	 */
	public string $mime_type;

	/**
	 * Tamaño en bytes.
	 *
	 * @var int
	 */
	public int $size;

	/**
	 * Construye el resultado.
	 *
	 * @param string $filename  Nombre saneado.
	 * @param string $mime_type Tipo real.
	 * @param int    $size      Tamaño en bytes.
	 */
	public function __construct( string $filename, string $mime_type, int $size ) {
		$this->filename  = $filename;
		$this->mime_type = $mime_type;
		$this->size      = $size;
	}
}
