<?php
/**
 * Validación única de archivos entrantes a la biblioteca de medios.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Media;

/**
 * Decide si un archivo puede entrar y con qué nombre.
 *
 * Es el único punto donde se toma esa decisión. Las dos vías de subida
 * —la importación por URL y la carga directa— lo llaman, porque una
 * comprobación escrita dos veces termina divergiendo y dejando un camino
 * más débil que el otro.
 */
final class UploadValidator {

	/**
	 * Tipos admitidos. Deliberadamente corta.
	 *
	 * SVG queda fuera a propósito: es XML, admite `<script>` y se sirve desde
	 * el propio dominio, así que aceptarlo es XSS almacenado. No se añade
	 * aunque un cliente lo pida.
	 */
	public const ALLOWED_MIME_TYPES = array(
		'image/jpeg',
		'image/png',
		'image/gif',
		'image/webp',
		'application/pdf',
	);

	/**
	 * Extensión que corresponde a cada tipo admitido.
	 */
	private const EXTENSIONES = array(
		'image/jpeg'      => 'jpg',
		'image/png'       => 'png',
		'image/gif'       => 'gif',
		'image/webp'      => 'webp',
		'application/pdf' => 'pdf',
	);

	/**
	 * Fragmentos que nunca pueden quedar en el nombre final.
	 *
	 * No basta con añadir la extensión correcta al final: un Apache con
	 * `AddHandler` mal configurado ejecuta `shell.php.png` como PHP, porque
	 * mira todas las extensiones y no sólo la última. Por eso estas se
	 * eliminan del nombre en lugar de confiar en que la última mande.
	 */
	private const EXTENSIONES_PELIGROSAS = array(
		'php',
		'php1',
		'php2',
		'php3',
		'php4',
		'php5',
		'php6',
		'php7',
		'php8',
		'php9',
		'phps',
		'phtml',
		'phtm',
		'pht',
		'phar',
		'shtml',
		'htaccess',
		'htpasswd',
		'cgi',
		'pl',
		'py',
		'rb',
		'sh',
		'bash',
		'exe',
		'com',
		'bat',
		'cmd',
		'jsp',
		'asp',
		'aspx',
		'svg',
		'svgz',
		'xml',
		'html',
		'htm',
		'js',
		'mjs',
	);

	/**
	 * Tope de peso por defecto: 10 MB.
	 */
	private const MAX_BYTES_DEFECTO = 10485760;

	/**
	 * Tope de superficie por defecto: 50 megapíxeles.
	 *
	 * El peso no basta. Un PNG de pocos kilobytes puede describir un lienzo de
	 * 50.000 × 50.000 y reventar la memoria de PHP al generar las miniaturas,
	 * porque la imagen se descomprime entera en RAM. Se mide la superficie
	 * antes de que WordPress la toque.
	 */
	private const MAX_PIXELES_DEFECTO = 50000000;

	/**
	 * Nombre de reserva cuando el propuesto se queda en nada.
	 */
	private const NOMBRE_RESERVA = 'nexo-media';

	/**
	 * Valida un archivo ya presente en disco.
	 *
	 * @param string $ruta             Ruta temporal del archivo.
	 * @param string $nombre_propuesto Nombre sugerido por el cliente.
	 * @return ValidatedUpload|\WP_Error
	 */
	public function validate( string $ruta, string $nombre_propuesto ) {
		if ( '' === $ruta || ! is_file( $ruta ) || ! is_readable( $ruta ) ) {
			return new \WP_Error(
				'nexo_mcp_media_unreadable',
				__( 'No se pudo leer el archivo recibido.', 'nexo-mcp' )
			);
		}

		$tamano = filesize( $ruta );

		if ( false === $tamano || $tamano <= 0 ) {
			return new \WP_Error(
				'nexo_mcp_media_empty',
				__( 'El archivo recibido está vacío.', 'nexo-mcp' )
			);
		}

		$max_bytes = $this->max_bytes();

		if ( $tamano > $max_bytes ) {
			return new \WP_Error(
				'nexo_mcp_media_too_large',
				sprintf(
					/* translators: %s: tamaño máximo en megabytes. */
					__( 'El archivo supera el máximo de %s MB.', 'nexo-mcp' ),
					(string) round( $max_bytes / 1048576, 1 )
				)
			);
		}

		$mime = $this->detectar_mime( $ruta );

		if ( ! in_array( $mime, self::ALLOWED_MIME_TYPES, true ) ) {
			return new \WP_Error(
				'nexo_mcp_media_type_denied',
				sprintf(
					/* translators: %s: tipo de archivo detectado. */
					__( 'El tipo de archivo no está permitido (%s). Se admiten JPEG, PNG, GIF, WebP y PDF.', 'nexo-mcp' ),
					'' !== $mime ? $mime : __( 'desconocido', 'nexo-mcp' )
				)
			);
		}

		$exceso = $this->comprobar_dimensiones( $ruta );

		if ( $exceso instanceof \WP_Error ) {
			return $exceso;
		}

		return new ValidatedUpload(
			$this->nombre_seguro( $nombre_propuesto, $mime ),
			$mime,
			(int) $tamano
		);
	}

	/**
	 * Tope de peso, ajustable por filtro.
	 */
	private function max_bytes(): int {
		/**
		 * Permite ajustar el peso máximo admitido en una subida.
		 *
		 * @param int $bytes Tope en bytes.
		 */
		$valor = (int) apply_filters( 'nexo_mcp_media_max_bytes', self::MAX_BYTES_DEFECTO );

		return $valor > 0 ? $valor : self::MAX_BYTES_DEFECTO;
	}

	/**
	 * Rechaza lienzos capaces de agotar la memoria al generar miniaturas.
	 *
	 * @param string $ruta Archivo validado.
	 * @return true|\WP_Error
	 */
	private function comprobar_dimensiones( string $ruta ) {
		$dimensiones = function_exists( 'wp_getimagesize' )
			? wp_getimagesize( $ruta )
			: getimagesize( $ruta ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Archivo local ya validado como imagen.

		// Un PDF no devuelve dimensiones: no se procesa como imagen y no
		// corre este riesgo, así que pasa.
		if ( ! is_array( $dimensiones ) || ! isset( $dimensiones[0], $dimensiones[1] ) ) {
			return true;
		}

		$ancho = (int) $dimensiones[0];
		$alto  = (int) $dimensiones[1];

		/**
		 * Permite ajustar la superficie máxima admitida, en píxeles.
		 *
		 * @param int $pixeles Tope de ancho por alto.
		 */
		$max = (int) apply_filters( 'nexo_mcp_media_max_pixels', self::MAX_PIXELES_DEFECTO );
		$max = $max > 0 ? $max : self::MAX_PIXELES_DEFECTO;

		if ( $ancho * $alto > $max ) {
			return new \WP_Error(
				'nexo_mcp_media_dimensions_denied',
				sprintf(
					/* translators: 1: ancho detectado, 2: alto detectado. */
					__( 'La imagen es demasiado grande (%1$d × %2$d píxeles) y agotaría la memoria al generar las miniaturas.', 'nexo-mcp' ),
					$ancho,
					$alto
				)
			);
		}

		return true;
	}

	/**
	 * Averigua el tipo real de un archivo por su contenido.
	 *
	 * No se mira la cabecera `Content-Type` ni la extensión: las controla
	 * quien envía y pueden mentir. Los bytes del archivo, no.
	 *
	 * @param string $ruta Archivo en disco.
	 */
	private function detectar_mime( string $ruta ): string {
		if ( function_exists( 'wp_get_image_mime' ) ) {
			$imagen = wp_get_image_mime( $ruta );

			if ( is_string( $imagen ) && '' !== $imagen ) {
				return $imagen;
			}
		}

		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );

			if ( false !== $finfo ) {
				$tipo = finfo_file( $finfo, $ruta );
				finfo_close( $finfo );

				if ( is_string( $tipo ) && '' !== $tipo ) {
					return $tipo;
				}
			}
		}

		// Último recurso para el PDF, que no es imagen y quedaría fuera si
		// finfo no estuviera disponible: su cabecera es una firma fija.
		$contenido = file_get_contents( $ruta, false, null, 0, 5 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Lectura local de cinco bytes, no una petición remota.

		return '%PDF-' === $contenido ? 'application/pdf' : '';
	}

	/**
	 * Construye un nombre que no pueda ejecutarse ni salirse del directorio.
	 *
	 * @param string $propuesto Nombre sugerido.
	 * @param string $mime      Tipo real ya validado.
	 */
	private function nombre_seguro( string $propuesto, string $mime ): string {
		// basename() corta cualquier intento de recorrer directorios antes de
		// que el nombre llegue a tocar el sistema de archivos.
		$nombre = basename( str_replace( '\\', '/', $propuesto ) );

		$esperada = self::EXTENSIONES[ $mime ] ?? '';
		$partes   = explode( '.', $nombre );
		$base     = (string) array_shift( $partes );

		// Se recorren TODAS las extensiones, no sólo la última: `shell.php.png`
		// es ejecutable en servidores con AddHandler mal configurado.
		$limpias = array_filter(
			$partes,
			static fn( string $parte ): bool => '' !== $parte
				&& ! in_array( strtolower( $parte ), self::EXTENSIONES_PELIGROSAS, true )
		);

		$nombre = '' !== $base ? $base : '';

		if ( array() !== $limpias ) {
			$nombre = ( '' !== $nombre ? $nombre : self::NOMBRE_RESERVA ) . '.' . implode( '.', $limpias );
		}

		// El saneado de WordPress va al final, no al principio. Aplicado antes,
		// convierte `shell.php.png` en `shell.php_.png` —su propia defensa— y
		// entonces `php_` ya no coincide con la lista de arriba, dejando un
		// nombre inofensivo pero feo. Limpiando primero sale `shell.png`.
		if ( function_exists( 'sanitize_file_name' ) ) {
			$nombre = sanitize_file_name( $nombre );
		}

		if ( '' === trim( $nombre, '.-_ ' ) ) {
			$nombre = self::NOMBRE_RESERVA;
		}

		if ( '' === $esperada ) {
			return $nombre;
		}

		$actual = strtolower( (string) pathinfo( $nombre, PATHINFO_EXTENSION ) );

		if ( $actual === $esperada || ( 'jpg' === $esperada && 'jpeg' === $actual ) ) {
			return $nombre;
		}

		// Si traía otra cosa se conserva como parte del nombre: la ruta de un
		// CDN puede terminar en algo que parece extensión sin serlo, y quitarlo
		// dejaría nombres irreconocibles en la biblioteca de medios.
		return $nombre . '.' . $esperada;
	}
}
