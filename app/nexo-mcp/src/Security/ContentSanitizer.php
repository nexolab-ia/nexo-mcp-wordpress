<?php
/**
 * Saneamiento del contenido escrito por el conector.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Security;

/**
 * Amplía `wp_kses_post()` con lo que una landing necesita, sin abrir la puerta.
 *
 * `wp_kses_post()` es la lista de un autor escribiendo un artículo, y elimina
 * `<iframe>`, `<svg>` y `<style>`. Para una página de negocio eso significa
 * quedarse sin mapa, sin iconos y sin diseño: el conector informaba éxito y la
 * página salía rota. Aquí se permiten esos tres, pero cerrados:
 *
 * - Los `<iframe>` sólo pueden apuntar a un puñado de dominios conocidos. El
 *   contenido llega desde un cliente MCP, o sea desde un modelo que puede ser
 *   manipulado por texto ajeno; un `src` libre convertiría eso en un marco
 *   arbitrario dentro del sitio del cliente.
 * - En `<svg>` se admite sólo el vocabulario de dibujo. Ni `<script>`, ni
 *   `<foreignObject>`, ni `<use>` —cuyo `href` puede traer un documento
 *   externo—, ni ningún atributo `on*`.
 * - El CSS de un `<style>` no lo revisa `wp_kses()`, que sólo mira etiquetas y
 *   atributos. Se filtra aparte para que no cargue recursos externos ni tape la
 *   pantalla completa.
 */
final class ContentSanitizer {

	/**
	 * Dominios cuyos iframes se aceptan.
	 *
	 * Se comparan contra el host completo, no con `str_contains`, porque
	 * `google.com.atacante.cl` contiene la cadena y no es Google.
	 */
	private const IFRAME_HOSTS = array(
		'google.com',
		'www.google.com',
		'maps.google.com',
		'youtube.com',
		'www.youtube.com',
		'youtube-nocookie.com',
		'www.youtube-nocookie.com',
		'player.vimeo.com',
		'open.spotify.com',
		'w.soundcloud.com',
	);

	/**
	 * Patrones de CSS que se eliminan de un bloque `<style>`.
	 *
	 * `@import` y `url()` traen recursos de fuera; `expression()` y
	 * `-moz-binding` ejecutaban código en navegadores antiguos; `position:fixed`
	 * permite cubrir la pantalla completa y suplantar la interfaz del sitio.
	 */
	private const CSS_DENIED = array(
		'/@import[^;{]*;?/i',
		'/expression\s*\(/i',
		'/behavior\s*:/i',
		'/-moz-binding\s*:/i',
		'/javascript\s*:/i',
		'/position\s*:\s*fixed/i',
		'/url\s*\(\s*[\'"]?\s*(?!https:\/\/|data:image\/)[^)]*\)/i',
	);

	/**
	 * Limpia el contenido antes de guardarlo.
	 *
	 * @param string $html Contenido recibido del cliente.
	 */
	public function sanitize( string $html ): string {
		$clean = wp_kses( $html, $this->allowed_html() );
		$clean = $this->drop_foreign_iframes( $clean );

		return $this->clean_style_blocks( $clean );
	}

	/**
	 * Lista permitida: la de un artículo más los tres añadidos.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public function allowed_html(): array {
		$allowed = wp_kses_allowed_html( 'post' );

		$allowed['iframe'] = array(
			'src'             => true,
			'width'           => true,
			'height'          => true,
			'title'           => true,
			'loading'         => true,
			'allow'           => true,
			'allowfullscreen' => true,
			'referrerpolicy'  => true,
			'frameborder'     => true,
			'style'           => true,
			'class'           => true,
		);

		$allowed['style'] = array(
			'type'  => true,
			'media' => true,
		);

		$svg_common = array(
			'class'           => true,
			'style'           => true,
			'fill'            => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
			'opacity'         => true,
			'transform'       => true,
			'clip-rule'       => true,
			'fill-rule'       => true,
			'aria-hidden'     => true,
			'aria-label'      => true,
			'role'            => true,
			'id'              => true,
		);

		$allowed['svg'] = array_merge(
			$svg_common,
			array(
				'xmlns'     => true,
				'viewbox'   => true,
				'viewBox'   => true,
				'width'     => true,
				'height'    => true,
				'focusable' => true,
			)
		);

		$allowed['path']     = array_merge( $svg_common, array( 'd' => true ) );
		$allowed['circle']   = array_merge(
			$svg_common,
			array(
				'cx' => true,
				'cy' => true,
				'r'  => true,
			)
		);
		$allowed['ellipse']  = array_merge(
			$svg_common,
			array(
				'cx' => true,
				'cy' => true,
				'rx' => true,
				'ry' => true,
			)
		);
		$allowed['rect']     = array_merge(
			$svg_common,
			array(
				'x'      => true,
				'y'      => true,
				'width'  => true,
				'height' => true,
				'rx'     => true,
				'ry'     => true,
			)
		);
		$allowed['line']     = array_merge(
			$svg_common,
			array(
				'x1' => true,
				'y1' => true,
				'x2' => true,
				'y2' => true,
			)
		);
		$allowed['polyline'] = array_merge( $svg_common, array( 'points' => true ) );
		$allowed['polygon']  = array_merge( $svg_common, array( 'points' => true ) );
		$allowed['g']        = $svg_common;
		$allowed['title']    = array();

		return $allowed;
	}

	/**
	 * Decide si el `src` de un iframe apunta a un destino admitido.
	 *
	 * @param string $src Valor del atributo.
	 */
	public function iframe_src_allowed( string $src ): bool {
		$src = trim( html_entity_decode( $src, ENT_QUOTES ) );

		if ( '' === $src ) {
			return false;
		}

		$scheme = wp_parse_url( $src, PHP_URL_SCHEME );

		// Sólo https: un http embebido rompería la página en un sitio con TLS y
		// permitiría interceptar lo que se muestra dentro del marco.
		if ( 'https' !== $scheme ) {
			return false;
		}

		$host = wp_parse_url( $src, PHP_URL_HOST );

		if ( ! is_string( $host ) || '' === $host ) {
			return false;
		}

		return in_array( strtolower( $host ), self::IFRAME_HOSTS, true );
	}

	/**
	 * Depura el CSS de un bloque `<style>`.
	 *
	 * @param string $css Hoja recibida.
	 */
	public function filter_css( string $css ): string {
		foreach ( self::CSS_DENIED as $pattern ) {
			$css = (string) preg_replace( $pattern, '', $css );
		}

		// `</style>` dentro del CSS cerraría el bloque antes de tiempo y dejaría
		// el resto interpretándose como marcado.
		return str_ireplace( array( '</style', '<script' ), '', $css );
	}

	/**
	 * Elimina los iframes cuyo destino no está en la lista.
	 *
	 * Se ejecuta después de `wp_kses()`, que ya normalizó comillas y atributos,
	 * de modo que el marcado a recorrer es predecible.
	 *
	 * @param string $html Contenido ya filtrado por kses.
	 */
	private function drop_foreign_iframes( string $html ): string {
		if ( false === stripos( $html, '<iframe' ) ) {
			return $html;
		}

		return (string) preg_replace_callback(
			'#<iframe\b[^>]*>.*?</iframe>|<iframe\b[^>]*/?>#is',
			function ( array $hallazgo ): string {
				$tag = $hallazgo[0];

				// `srcdoc` inyecta un documento completo sin pasar por la lista de
				// dominios, así que su sola presencia descarta el marco.
				if ( preg_match( '/\bsrcdoc\s*=/i', $tag ) ) {
					return '';
				}

				if ( ! preg_match( '/\bsrc\s*=\s*["\']([^"\']*)["\']/i', $tag, $src ) ) {
					return '';
				}

				return $this->iframe_src_allowed( $src[1] ) ? $tag : '';
			},
			$html
		);
	}

	/**
	 * Aplica el filtro de CSS al interior de cada bloque `<style>`.
	 *
	 * @param string $html Contenido ya filtrado por kses.
	 */
	private function clean_style_blocks( string $html ): string {
		if ( false === stripos( $html, '<style' ) ) {
			return $html;
		}

		return (string) preg_replace_callback(
			'#(<style\b[^>]*>)(.*?)(</style>)#is',
			fn( array $hallazgo ): string => $hallazgo[1] . $this->filter_css( $hallazgo[2] ) . $hallazgo[3],
			$html
		);
	}
}
