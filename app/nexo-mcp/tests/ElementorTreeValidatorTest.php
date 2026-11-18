<?php
/**
 * Validación previa del árbol atómico de Elementor 4 (clase pura, sin Elementor).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\ElementorNesting;
use NexoDev\NexoMCP\Builders\ElementorTreeValidator;
use PHPUnit\Framework\TestCase;

/**
 * Cada rechazo se comprueba con el código, la ruta y el mensaje exactos que
 * recibe el cliente; la validación es pura, así que no hay nada que crear.
 */
final class ElementorTreeValidatorTest extends TestCase {

	private const TYPES = 'e-flexbox, e-div-block, e-heading, e-paragraph, e-button, e-image, e-divider';

	/**
	 * Claves de `settings` por tipo, como las daría `get_props_schema()`.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function allowed(): array {
		$box = array( 'classes', 'tag', 'link', 'attributes', '_cssid' );

		return array(
			'e-flexbox'   => $box,
			'e-div-block' => $box,
			'e-heading'   => array( 'classes', 'tag', 'title', 'link', 'attributes', '_cssid' ),
			'e-paragraph' => array( 'classes', 'paragraph', 'tag', 'link', 'attributes', '_cssid' ),
			'e-button'    => array( 'classes', 'text', 'link', 'tag', 'attributes', '_cssid' ),
			'e-image'     => array( 'classes', 'image', 'link', 'attributes', '_cssid' ),
			'e-divider'   => array( 'classes', 'attributes', '_cssid' ),
		);
	}

	/**
	 * Validador real, con la regla de raíz por omisión.
	 *
	 * @param array<int, string> $global_classes Ids de clases globales existentes.
	 * @param array<int, int>    $images         Ids de adjuntos que existen.
	 */
	private function real_validator( array $global_classes = array( 'g-hero' ), array $images = array( 7 ) ): ElementorTreeValidator {
		return new ElementorTreeValidator(
			$this->allowed(),
			$global_classes,
			static function ( int $id ) use ( $images ) {
				return in_array( $id, $images, true )
					? true
					: new \WP_Error( 'nexo_mcp_attr_image_missing', 'No existe ningún medio con ese identificador.' );
			},
			array( 'gap', 'color', 'content', 'clip-path', 'width', 'padding', 'background', 'font-family', 'aspect-ratio', 'flex-direction', 'border-color' )
		);
	}

	/**
	 * Validador para las reglas de nodo, estilo y valor.
	 *
	 * Valida como hijos de un contenedor (salvo que la prueba indique otro padre)
	 * para que la regla de raíz, que se prueba aparte, no tape las reglas que cada
	 * prueba quiere comprobar con un widget suelto.
	 *
	 * @param array<int, string> $global_classes Ids de clases globales existentes.
	 * @param array<int, int>    $images         Ids de adjuntos que existen.
	 */
	private function validator( array $global_classes = array( 'g-hero' ), array $images = array( 7 ) ): object {
		return new class( $this->real_validator( $global_classes, $images ) ) {

			private ElementorTreeValidator $inner;

			public function __construct( ElementorTreeValidator $inner ) {
				$this->inner = $inner;
			}

			/**
			 * @param mixed $elements Lista de nodos.
			 * @return true|\WP_Error
			 */
			public function validate( $elements, string $parent_kind = ElementorNesting::CONTAINER, int $base_depth = 0, int $base_nodes = 0 ) {
				return $this->inner->validate( $elements, $parent_kind, $base_depth, $base_nodes );
			}
		};
	}

	/**
	 * @return array<string, mixed>
	 */
	private function prop( string $type, $value ): array {
		return array(
			'$$type' => $type,
			'value'  => $value,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function heading( string $text = 'Hola' ): array {
		return array(
			'type'     => 'e-heading',
			'settings' => array( 'title' => $this->prop( 'escaped-html', $text ) ),
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $children Hijos.
	 * @return array<string, mixed>
	 */
	private function box( array $children ): array {
		return array(
			'type'     => 'e-flexbox',
			'elements' => $children,
		);
	}

	/**
	 * @param mixed $result Resultado de validate().
	 */
	private function assertRejected( $result, string $code, string $path, string $message ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $message, $result->get_error_message() );
		$this->assertSame( array( 'path' => $path ), $result->get_error_data() );
	}

	private function link( string $url ): array {
		return $this->prop(
			'link',
			array( 'destination' => $this->prop( 'url', $url ) )
		);
	}

	public function test_un_arbol_completo_valido_pasa(): void {
		$tree = array(
			array(
				'type'     => 'e-flexbox',
				'settings' => array( 'classes' => $this->prop( 'classes', array( 'g-hero', 'caja-local' ) ) ),
				'styles'   => array(
					'caja-local' => array(
						'id'       => 'caja-local',
						'type'     => 'class',
						'label'    => 'caja-local',
						'variants' => array(
							array(
								'meta'  => array(
									'breakpoint' => 'desktop',
									'state'      => null,
								),
								'props' => array( 'gap' => $this->prop( 'size', array( 'size' => 8, 'unit' => 'px' ) ) ),
							),
						),
					),
				),
				'elements' => array(
					$this->heading(),
					array(
						'type'     => 'e-paragraph',
						'settings' => array( 'paragraph' => $this->prop( 'escaped-html', 'Texto' ) ),
					),
					array(
						'type'     => 'e-button',
						'settings' => array(
							'text' => $this->prop( 'escaped-html', 'Ir' ),
							'link' => $this->link( 'https://example.com/' ),
						),
					),
					array(
						'type'     => 'e-image',
						'settings' => array(
							'image' => $this->prop(
								'image',
								array(
									'src'  => $this->prop(
										'image-src',
										array(
											'id'  => $this->prop( 'image-attachment-id', 7 ),
											'url' => null,
										)
									),
									'size' => $this->prop( 'string', 'full' ),
								)
							),
						),
					),
					array( 'type' => 'e-divider' ),
				),
			),
		);

		$this->assertTrue( $this->validator()->validate( $tree ) );
	}

	public function test_los_ids_que_manda_el_cliente_se_ignoran_sin_error(): void {
		$node       = $this->heading();
		$node['id'] = 'cualquier cosa!';

		$this->assertTrue( $this->validator()->validate( array( $node ) ) );
	}

	public function test_enlaces_mailto_y_tel_pasan(): void {
		foreach ( array( 'mailto:hola@example.com', 'tel:+5491100000000' ) as $url ) {
			$node                      = $this->heading();
			$node['settings']['link'] = $this->link( $url );

			$this->assertTrue( $this->validator()->validate( array( $node ) ), $url );
		}
	}

	public function test_la_raiz_debe_ser_una_lista_no_vacia(): void {
		foreach ( array( array(), 'texto', array( 'type' => 'e-heading' ) ) as $root ) {
			$this->assertRejected(
				$this->validator()->validate( $root ),
				'nexo_mcp_elementor_tree_invalid',
				'elements',
				'elements: debe ser una lista no vacía de elementos.'
			);
		}
	}

	public function test_cada_elemento_debe_ser_un_objeto(): void {
		$this->assertRejected(
			$this->validator()->validate( array( $this->heading(), 'texto' ) ),
			'nexo_mcp_elementor_tree_invalid',
			'elements[1]',
			'elements[1]: cada elemento debe ser un objeto con «type».'
		);
	}

	public function test_falta_el_tipo(): void {
		$this->assertRejected(
			$this->validator()->validate( array( $this->box( array( array( 'settings' => array() ) ) ) ) ),
			'nexo_mcp_elementor_tree_invalid',
			'elements[0].elements[0].type',
			'elements[0].elements[0].type: indica el tipo del elemento.'
		);
	}

	public function test_e_svg_se_rechaza_por_ser_vector_de_codigo(): void {
		$this->assertRejected(
			$this->validator()->validate( array( $this->box( array( array( 'type' => 'e-svg' ) ) ) ) ),
			'nexo_mcp_elementor_type_denied',
			'elements[0].elements[0].type',
			'elements[0].elements[0].type: el tipo «e-svg» no está permitido: un SVG puede llevar código ejecutable. Tipos admitidos: ' . self::TYPES . '.'
		);
	}

	public function test_un_widget_v3_se_rechaza(): void {
		foreach ( array( 'heading', 'container', 'text-editor' ) as $type ) {
			$this->assertRejected(
				$this->validator()->validate( array( array( 'type' => $type ) ) ),
				'nexo_mcp_elementor_type_denied',
				'elements[0].type',
				"elements[0].type: «{$type}» es un elemento de Elementor 3; sólo se admiten elementos atómicos. Tipos admitidos: " . self::TYPES . '.'
			);
		}
	}

	public function test_los_tipos_que_admiten_codigo_se_rechazan_siempre(): void {
		foreach ( array( 'html', 'shortcode', 'code-highlight' ) as $type ) {
			$this->assertRejected(
				$this->validator()->validate( array( array( 'type' => $type ) ) ),
				'nexo_mcp_elementor_type_denied',
				'elements[0].type',
				"elements[0].type: «{$type}» admite código arbitrario y nunca se crea. Tipos admitidos: " . self::TYPES . '.'
			);
		}
	}

	public function test_un_tipo_atomico_fuera_de_la_lista_se_rechaza(): void {
		$this->assertRejected(
			$this->validator()->validate( array( array( 'type' => 'e-tabs' ) ) ),
			'nexo_mcp_elementor_type_denied',
			'elements[0].type',
			'elements[0].type: el tipo «e-tabs» no está permitido. Tipos admitidos: ' . self::TYPES . '.'
		);
	}

	public function test_solo_los_contenedores_admiten_hijos(): void {
		$node             = $this->heading();
		$node['elements'] = array( $this->heading() );

		$this->assertRejected(
			$this->validator()->validate( array( $this->box( array( $this->heading(), $node ) ) ) ),
			'nexo_mcp_elementor_children_denied',
			'elements[0].elements[1].elements',
			'elements[0].elements[1].elements: «e-heading» no admite elementos hijos; sólo los contenedores (e-flexbox, e-div-block).'
		);
	}

	public function test_la_profundidad_maxima_es_diez(): void {
		$tree = $this->heading();
		for ( $level = 2; $level <= 10; $level++ ) {
			$tree = $this->box( array( $tree ) );
		}
		$this->assertTrue( $this->validator()->validate( array( $tree ) ), 'diez niveles pasan' );

		$path = 'elements[0]' . str_repeat( '.elements[0]', 10 );
		$this->assertRejected(
			$this->validator()->validate( array( $this->box( array( $tree ) ) ) ),
			'nexo_mcp_elementor_too_deep',
			$path,
			$path . ': se supera la profundidad máxima de 10 niveles.'
		);
	}

	public function test_el_maximo_es_doscientos_elementos(): void {
		$children = array_fill( 0, 199, $this->heading() );
		$this->assertTrue( $this->validator()->validate( array( $this->box( $children ) ) ), '200 pasan' );

		$children[] = $this->heading();
		$this->assertRejected(
			$this->validator()->validate( array( $this->box( $children ) ) ),
			'nexo_mcp_elementor_too_many_nodes',
			'elements[0].elements[199]',
			'elements[0].elements[199]: se supera el máximo de 200 elementos.'
		);
	}

	public function test_el_arbol_no_puede_pesar_mas_de_tres_megas(): void {
		$this->assertRejected(
			$this->validator()->validate( array( $this->heading( str_repeat( 'a', 3 * 1024 * 1024 + 1 ) ) ) ),
			'nexo_mcp_elementor_too_large',
			'elements',
			'elements: el árbol pesa más de 3 MB.'
		);
	}

	public function test_una_clave_de_settings_desconocida_se_rechaza_con_la_lista_admitida(): void {
		$node                         = $this->heading();
		$node['settings']['titel']    = $this->prop( 'escaped-html', 'x' );

		$this->assertRejected(
			$this->validator()->validate( array( $this->box( array( $this->heading(), $node ) ) ) ),
			'nexo_mcp_elementor_setting_unknown',
			'elements[0].elements[1].settings.titel',
			'elements[0].elements[1].settings.titel: la propiedad «titel» no existe en «e-heading». Admitidas: classes, tag, title, link, _cssid.'
		);
	}

	public function test_attributes_se_rechaza_aunque_el_esquema_la_tenga(): void {
		$node                           = $this->heading();
		$node['settings']['attributes'] = $this->prop( 'attributes', array() );

		$this->assertRejected(
			$this->validator()->validate( array( $node ) ),
			'nexo_mcp_elementor_setting_denied',
			'elements[0].settings.attributes',
			'elements[0].settings.attributes: «attributes» no se admite: permite atributos HTML arbitrarios, como los de eventos.'
		);
	}

	public function test_settings_y_cada_propiedad_deben_tener_la_forma_esperada(): void {
		$node             = $this->heading();
		$node['settings'] = 'texto';
		$this->assertRejected(
			$this->validator()->validate( array( $node ) ),
			'nexo_mcp_elementor_setting_invalid',
			'elements[0].settings',
			'elements[0].settings: debe ser un objeto de propiedades.'
		);

		$node             = $this->heading();
		$node['settings'] = array( 'title' => 'texto plano' );
		$this->assertRejected(
			$this->validator()->validate( array( $node ) ),
			'nexo_mcp_elementor_setting_invalid',
			'elements[0].settings.title',
			'elements[0].settings.title: debe tener la forma {"$$type": «tipo», "value": …}.'
		);
	}

	public function test_un_tipo_de_propiedad_fuera_de_la_lista_se_rechaza(): void {
		foreach ( array( 'html-v3', 'svg-src', 'query', 'attributes' ) as $type ) {
			$node                      = $this->heading();
			$node['settings']['title'] = $this->prop( $type, 'x' );

			$this->assertRejected(
				$this->validator()->validate( array( $node ) ),
				'nexo_mcp_elementor_prop_type_denied',
				'elements[0].settings.title.$$type',
				"elements[0].settings.title.\$\$type: el tipo de propiedad «{$type}» no está permitido."
			);
		}
	}

	public function test_un_enlace_javascript_o_data_se_rechaza(): void {
		foreach ( array( 'javascript:alert(1)', 'java script:alert(1)', 'data:text/html;base64,AAAA' ) as $url ) {
			$node                     = $this->heading();
			$node['settings']['link'] = $this->link( $url );
			$result                   = $this->validator()->validate( array( $node ) );
			$path                     = 'elements[0].settings.link.value.destination.value';

			$this->assertInstanceOf( \WP_Error::class, $result, $url );
			$this->assertSame( 'nexo_mcp_attr_link_denied', $result->get_error_code(), $url );
			$this->assertStringStartsWith( $path . ': El esquema «', $result->get_error_message(), $url );
			$this->assertSame( array( 'path' => $path ), $result->get_error_data(), $url );
		}
	}

	public function test_un_enlace_http_o_relativo_se_rechaza_con_el_motivo_de_attributevalue(): void {
		$node                     = $this->heading();
		$node['settings']['link'] = $this->link( 'http://example.com' );
		$this->assertRejected(
			$this->validator()->validate( array( $node ) ),
			'nexo_mcp_attr_link_scheme',
			'elements[0].settings.link.value.destination.value',
			'elements[0].settings.link.value.destination.value: El esquema «http» no está permitido. Se admiten: https, mailto, tel.'
		);

		$node['settings']['link'] = $this->link( 'pagina' );
		$this->assertRejected(
			$this->validator()->validate( array( $node ) ),
			'nexo_mcp_attr_link_relative',
			'elements[0].settings.link.value.destination.value',
			'elements[0].settings.link.value.destination.value: Usa una dirección completa con https:// o una ruta que empiece por «/».'
		);
	}

	public function test_el_destino_del_enlace_solo_puede_ser_una_url(): void {
		$node                     = $this->heading();
		$node['settings']['link'] = $this->prop( 'link', array( 'destination' => $this->prop( 'string', 'https://example.com' ) ) );

		$this->assertRejected(
			$this->validator()->validate( array( $node ) ),
			'nexo_mcp_elementor_link_invalid',
			'elements[0].settings.link.value.destination',
			'elements[0].settings.link.value.destination: el destino debe ser {"$$type": "url", "value": «https://…»}.'
		);
	}

	public function test_una_url_fuera_de_un_enlace_se_rechaza(): void {
		$node                      = $this->heading();
		$node['settings']['title'] = $this->prop( 'url', 'https://example.com' );

		$this->assertRejected(
			$this->validator()->validate( array( $node ) ),
			'nexo_mcp_elementor_prop_type_denied',
			'elements[0].settings.title.$$type',
			'elements[0].settings.title.$$type: el tipo de propiedad «url» no está permitido.'
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function image_node( $src_value ): array {
		return array(
			'type'     => 'e-image',
			'settings' => array(
				'image' => $this->prop( 'image', array( 'src' => $this->prop( 'image-src', $src_value ) ) ),
			),
		);
	}

	public function test_una_imagen_por_url_se_rechaza(): void {
		$result = $this->validator()->validate(
			array( $this->box( array( $this->image_node( array( 'url' => $this->prop( 'url', 'https://evil.example/x.png' ) ) ) ) ) )
		);

		$this->assertRejected(
			$result,
			'nexo_mcp_elementor_image_url_denied',
			'elements[0].elements[0].settings.image.value.src.value.url',
			'elements[0].elements[0].settings.image.value.src.value.url: las imágenes se indican por el identificador de la biblioteca de medios (src.id), nunca por URL.'
		);
	}

	public function test_una_imagen_sin_id_se_rechaza(): void {
		$this->assertRejected(
			$this->validator()->validate( array( $this->image_node( array( 'id' => null ) ) ) ),
			'nexo_mcp_elementor_image_id_required',
			'elements[0].settings.image.value.src.value.id',
			'elements[0].settings.image.value.src.value.id: indica el identificador de una imagen de la biblioteca de medios.'
		);
	}

	public function test_una_imagen_con_id_inexistente_se_rechaza_con_el_motivo_de_attributevalue(): void {
		$this->assertRejected(
			$this->validator()->validate(
				array( $this->image_node( array( 'id' => $this->prop( 'image-attachment-id', 999 ) ) ) )
			),
			'nexo_mcp_attr_image_missing',
			'elements[0].settings.image.value.src.value.id.value',
			'elements[0].settings.image.value.src.value.id.value: No existe ningún medio con ese identificador.'
		);
	}

	public function test_un_id_de_estilo_o_clase_con_formato_invalido_se_rechaza(): void {
		$style = array(
			'type'     => 'class',
			'label'    => 'x1',
			'variants' => array(),
		);
		$node           = $this->heading();
		$node['styles'] = array( '1mal' => $style );

		$this->assertRejected(
			$this->validator()->validate( array( $node ) ),
			'nexo_mcp_elementor_style_invalid',
			'elements[0].styles.1mal',
			'elements[0].styles.1mal: el id de estilo debe cumplir /^[a-z][a-z-_0-9]*$/i.'
		);

		$node             = $this->heading();
		$node['settings'] = array( 'classes' => $this->prop( 'classes', array( 'g-hero', 'mala clase' ) ) );
		$this->assertRejected(
			$this->validator()->validate( array( $node ) ),
			'nexo_mcp_elementor_class_invalid',
			'elements[0].settings.classes.value[1]',
			'elements[0].settings.classes.value[1]: el nombre de clase «mala clase» debe cumplir /^[a-z][a-z-_0-9]*$/i.'
		);

		$node['settings'] = array( 'classes' => $this->prop( 'classes', 'g-hero' ) );
		$this->assertRejected(
			$this->validator()->validate( array( $node ) ),
			'nexo_mcp_elementor_class_invalid',
			'elements[0].settings.classes.value',
			'elements[0].settings.classes.value: debe ser una lista de nombres de clase.'
		);
	}

	public function test_la_forma_del_estilo_se_comprueba(): void {
		$cases = array(
			'type'     => array(
				array(
					'type'     => 'inline',
					'label'    => 'ab',
					'variants' => array(),
				),
				'elements[0].styles.caja.type',
				'debe ser «class».',
			),
			'label'    => array(
				array(
					'type'     => 'class',
					'variants' => array(),
				),
				'elements[0].styles.caja.label',
				'indica la etiqueta del estilo (texto).',
			),
			'variants' => array(
				array(
					'type'  => 'class',
					'label' => 'ab',
				),
				'elements[0].styles.caja.variants',
				'debe ser una lista de variantes.',
			),
			'id'       => array(
				array(
					'id'       => 'otra',
					'type'     => 'class',
					'label'    => 'ab',
					'variants' => array(),
				),
				'elements[0].styles.caja.id',
				'debe coincidir con la clave del estilo («caja»).',
			),
		);

		foreach ( $cases as $name => $case ) {
			$node           = $this->heading();
			$node['styles'] = array( 'caja' => $case[0] );

			$this->assertRejected(
				$this->validator()->validate( array( $node ) ),
				'nexo_mcp_elementor_style_invalid',
				$case[1],
				$case[1] . ': ' . $case[2]
			);
		}
	}

	public function test_el_css_personalizado_de_un_estilo_se_rechaza(): void {
		$node           = $this->heading();
		$node['styles'] = array(
			'caja' => array(
				'type'     => 'class',
				'label'    => 'ab',
				'variants' => array(
					array(
						'meta'       => array(
							'breakpoint' => 'desktop',
							'state'      => null,
						),
						'props'      => array(),
						'custom_css' => array( 'raw' => 'Ym9keXtkaXNwbGF5Om5vbmV9' ),
					),
				),
			),
		);

		$this->assertRejected(
			$this->validator()->validate( array( $node ) ),
			'nexo_mcp_elementor_style_css_denied',
			'elements[0].styles.caja.variants[0].custom_css',
			'elements[0].styles.caja.variants[0].custom_css: el CSS personalizado no se admite.'
		);
	}

	public function test_los_tipos_de_propiedad_de_los_estilos_siguen_la_misma_lista(): void {
		$node           = $this->heading();
		$node['styles'] = array(
			'caja' => array(
				'type'     => 'class',
				'label'    => 'ab',
				'variants' => array(
					array(
						'meta'  => array(
							'breakpoint' => 'desktop',
							'state'      => null,
						),
						'props' => array( 'color' => $this->prop( 'html-v3', 'x' ) ),
					),
				),
			),
		);

		$this->assertRejected(
			$this->validator()->validate( array( $node ) ),
			'nexo_mcp_elementor_prop_type_denied',
			'elements[0].styles.caja.variants[0].props.color.$$type',
			'elements[0].styles.caja.variants[0].props.color.$$type: el tipo de propiedad «html-v3» no está permitido.'
		);
	}

	public function test_una_clase_debe_ser_un_estilo_local_del_mismo_nodo_o_una_global_existente(): void {
		$style = array(
			'type'     => 'class',
			'label'    => 'ab',
			'variants' => array(),
		);

		// Local de otro nodo: no vale.
		$owner           = $this->heading();
		$owner['styles'] = array( 'ajena' => $style );
		$user            = $this->heading();
		$user['settings']['classes'] = $this->prop( 'classes', array( 'ajena' ) );

		$this->assertRejected(
			$this->validator()->validate( array( $this->box( array( $owner, $user ) ) ) ),
			'nexo_mcp_elementor_class_unknown',
			'elements[0].elements[1].settings.classes.value[0]',
			'elements[0].elements[1].settings.classes.value[0]: la clase «ajena» no existe: usa un estilo local de este mismo elemento o una clase global existente (get-design-system).'
		);

		// Global inexistente.
		$user['settings']['classes'] = $this->prop( 'classes', array( 'g-no-existe' ) );
		$this->assertRejected(
			$this->validator()->validate( array( $user ) ),
			'nexo_mcp_elementor_class_unknown',
			'elements[0].settings.classes.value[0]',
			'elements[0].settings.classes.value[0]: la clase «g-no-existe» no existe: usa un estilo local de este mismo elemento o una clase global existente (get-design-system).'
		);

		// Local propia y global existente: pasan.
		$owner['settings']['classes'] = $this->prop( 'classes', array( 'ajena', 'g-hero' ) );
		$this->assertTrue( $this->validator()->validate( array( $owner ) ) );
	}

	/**
	 * Nodo con un estilo «caja» cuyos props son los dados.
	 *
	 * @param array<string, mixed> $props   Props de la variante.
	 * @param array<string, mixed> $extra   Claves extra del estilo.
	 * @param array<string, mixed> $variant Claves extra de la variante.
	 * @return array<string, mixed>
	 */
	private function styled( array $props, array $extra = array(), array $variant = array() ): array {
		$node           = $this->heading();
		$node['styles'] = array(
			'caja' => array_merge(
				array(
					'id'       => 'caja',
					'type'     => 'class',
					'label'    => 'caja',
					'variants' => array(
						array_merge(
							array(
								'meta'  => array(
									'breakpoint' => 'desktop',
									'state'      => null,
								),
								'props' => $props,
							),
							$variant
						),
					),
				),
				$extra
			),
		);

		return $node;
	}

	public function test_un_estilo_con_claves_desconocidas_como_cssname_se_rechaza(): void {
		$this->assertRejected(
			$this->validator()->validate( array( $this->styled( array(), array( 'cssName' => 'x{}body{background:url(https://evil.example/c.png)}.y' ) ) ) ),
			'nexo_mcp_elementor_style_invalid',
			'elements[0].styles.caja.cssName',
			'elements[0].styles.caja.cssName: la clave «cssName» no se admite en un estilo. Admitidas: id, type, label, variants.'
		);
	}

	public function test_una_variante_o_un_meta_con_claves_desconocidas_se_rechaza(): void {
		$this->assertRejected(
			$this->validator()->validate( array( $this->styled( array(), array(), array( 'selector' => 'body' ) ) ) ),
			'nexo_mcp_elementor_style_invalid',
			'elements[0].styles.caja.variants[0].selector',
			'elements[0].styles.caja.variants[0].selector: la clave «selector» no se admite en una variante. Admitidas: meta, props, custom_css.'
		);

		$node = $this->styled( array() );
		$node['styles']['caja']['variants'][0]['meta']['media'] = 'all';
		$this->assertRejected(
			$this->validator()->validate( array( $node ) ),
			'nexo_mcp_elementor_style_invalid',
			'elements[0].styles.caja.variants[0].meta.media',
			'elements[0].styles.caja.variants[0].meta.media: la clave «media» no se admite en meta. Admitidas: breakpoint, state.'
		);
	}

	/**
	 * @return array<string, array<int, mixed>>
	 */
	public static function css_breakouts(): array {
		return array(
			'color cierra la regla'    => array( 'color', 'color', 'red;}body{display:none}' ),
			'color con url externa'    => array( 'color', 'color', 'red;background-image:url(https://evil.example/t.png)' ),
			'content cierra la regla'  => array( 'content', 'string', '"x"}body{display:none}' ),
			'clip-path con url'        => array( 'clip-path', 'string', 'url(https://evil.example/c.svg#a)' ),
			'salto de linea'           => array( 'color', 'color', "red\nbody" ),
			'tabulador'                => array( 'color', 'color', "red\tblue" ),
			'escape con barra'         => array( 'color', 'color', 'u\\72l(https://evil.example/x)' ),
			'comentario'               => array( 'color', 'color', 'red/*x*/' ),
			'arroba'                   => array( 'color', 'color', '@import' ),
			'menor que'                => array( 'color', 'color', 'red</style>' ),
			'expression'               => array( 'width', 'string', 'EXPRESSION(alert(1))' ),
			'image-set'                => array( 'content', 'string', 'Image-Set("a.png" 1x)' ),
			'webkit image-set'         => array( 'content', 'string', '-webkit-image-set("a.png" 1x)' ),
			'image()'                  => array( 'content', 'string', 'image("a.png")' ),
			'cross-fade'               => array( 'content', 'string', 'cross-fade(a, b)' ),
			'element()'                => array( 'content', 'string', 'element(#x)' ),
			'var()'                    => array( 'color', 'color', 'VAR(--x)' ),
			'important'                => array( 'color', 'color', 'red ! important' ),
			'src()'                    => array( 'content', 'string', 'SRC("a.png")' ),
		);
	}

	/**
	 * @dataProvider css_breakouts
	 */
	public function test_un_valor_de_estilo_que_se_sale_de_la_declaracion_se_rechaza( string $prop, string $type, string $value ): void {
		$path = 'elements[0].styles.caja.variants[0].props.' . $prop . '.value';

		$this->assertRejected(
			$this->validator()->validate( array( $this->styled( array( $prop => $this->prop( $type, $value ) ) ) ) ),
			'nexo_mcp_elementor_style_value_denied',
			$path,
			$path . ': el valor contiene caracteres o funciones que no se admiten en un valor de estilo (; { } < > \\ @, saltos de línea, /*, url(), image-set(), var(), !important…).'
		);
	}

	public function test_la_regla_aplica_a_hojas_anidadas_y_da_la_ruta_de_la_hoja(): void {
		$props = array( 'padding' => $this->prop( 'dimensions', array( 'block-start' => $this->prop( 'size', array( 'size' => 'x;}', 'unit' => 'px' ) ) ) ) );
		$path  = 'elements[0].styles.caja.variants[0].props.padding.value.block-start.value.size';

		$this->assertRejected(
			$this->validator()->validate( array( $this->styled( $props ) ) ),
			'nexo_mcp_elementor_style_value_denied',
			$path,
			$path . ': el valor contiene caracteres o funciones que no se admiten en un valor de estilo (; { } < > \\ @, saltos de línea, /*, url(), image-set(), var(), !important…).'
		);
	}

	public function test_un_estilo_normal_sigue_aceptandose(): void {
		$props = array(
			'color'       => $this->prop( 'color', '#ff0000' ),
			'background'  => $this->prop( 'color', 'rgba(0, 0, 0, 0.5)' ),
			'font-family' => $this->prop( 'font-family', '"Open Sans", sans-serif' ),
			'width'       => $this->prop( 'size', array( 'size' => 'calc(100% - 2.5rem)', 'unit' => 'custom' ) ),
			'gap'         => $this->prop( 'size', array( 'size' => 16, 'unit' => 'px' ) ),
			'aspect-ratio' => $this->prop( 'string', '16 / 9' ),
			'border-color' => $this->prop( 'color', 'hsl(210deg 50% 40%)' ),
		);

		$this->assertTrue( $this->validator()->validate( array( $this->styled( $props ) ) ) );
	}

	public function test_las_comillas_sin_cerrar_se_rechazan_con_su_propio_mensaje(): void {
		foreach ( array(
			array( 'font-family', 'font-family', '"Open Sans' ),
			array( 'content', 'string', "x'y" ),
			array( 'content', 'string', '"a" "b' ),
		) as $case ) {
			$path = 'elements[0].styles.caja.variants[0].props.' . $case[0] . '.value';

			$this->assertRejected(
				$this->validator()->validate( array( $this->styled( array( $case[0] => $this->prop( $case[1], $case[2] ) ) ) ) ),
				'nexo_mcp_elementor_style_value_denied',
				$path,
				$path . ': el valor tiene comillas sin cerrar.'
			);
		}
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public static function unbalanced_parentheses(): array {
		return array(
			'abre y no cierra'            => array( 'width', 'size', 'calc(100% - 2rem' ),
			'cierra de más'               => array( 'color', 'color', 'rgb(0, 0, 0))' ),
			'cierra antes de abrir'       => array( 'color', 'color', ')rgb(0, 0, 0(' ),
			'anidado sin cerrar el hijo'  => array( 'width', 'size', 'calc(100% - calc(2rem + 1px)' ),
			'sólo un cierre'              => array( 'content', 'string', 'a)' ),
		);
	}

	/**
	 * @dataProvider unbalanced_parentheses
	 */
	public function test_los_parentesis_sin_cerrar_se_rechazan_con_su_propio_mensaje( string $prop, string $type, string $value ): void {
		$path = 'elements[0].styles.caja.variants[0].props.' . $prop . '.value';

		$this->assertRejected(
			$this->validator()->validate( array( $this->styled( array( $prop => $this->prop( $type, $value ) ) ) ) ),
			'nexo_mcp_elementor_style_value_denied',
			$path,
			$path . ': el valor tiene paréntesis sin cerrar.'
		);
	}

	public function test_los_parentesis_balanceados_y_anidados_se_aceptan(): void {
		foreach ( array(
			'rgba(0, 0, 0, 0.5)',
			'hsl(210deg 50% 40%)',
			'calc(100% - 2.5rem)',
			'calc(100% - calc(2rem + (1px * 2)))',
			'"a ) b"',
			'min(10px, calc(1vw + 2px))',
		) as $value ) {
			$this->assertTrue(
				$this->validator()->validate( array( $this->styled( array( 'content' => $this->prop( 'string', $value ) ) ) ) ),
				$value
			);
		}
	}

	/**
	 * @return array<string, array<int, mixed>>
	 */
	public static function bad_meta(): array {
		$bp    = 'no es un breakpoint válido de Elementor 4.4.1. Admitidos: desktop, widescreen, laptop, tablet_extra, tablet, mobile_extra, mobile.';
		$state = 'no es un estado válido de Elementor 4.4.1. Admitidos: hover, active, focus, focus-visible, checked, e--selected, e--disabled, e--playing, e--paused, null.';

		return array(
			'breakpoint desconocido'    => array( 'breakpoint', 'phone', $bp ),
			'breakpoint en mayúsculas'  => array( 'breakpoint', 'Desktop', $bp ),
			'breakpoint vacío'          => array( 'breakpoint', '', $bp ),
			'breakpoint no es texto'    => array( 'breakpoint', 768, $bp ),
			'breakpoint nulo'           => array( 'breakpoint', null, $bp ),
			'estado desconocido'        => array( 'state', 'visited', $state ),
			'estado en mayúsculas'      => array( 'state', 'HOVER', $state ),
			'estado vacío'              => array( 'state', '', $state ),
			'estado no es texto'        => array( 'state', 5, $state ),
			'estado con inyección'      => array( 'state', 'hover{color:red}', $state ),
		);
	}

	/**
	 * @dataProvider bad_meta
	 * @param mixed $value Valor de la clave de `meta`.
	 */
	public function test_meta_breakpoint_y_state_fuera_de_la_lista_cerrada_se_rechazan_con_su_ruta( string $key, $value, string $reason ): void {
		$node = $this->styled( array() );
		$node['styles']['caja']['variants'][0]['meta'][ $key ] = $value;
		$path = 'elements[0].styles.caja.variants[0].meta.' . $key;

		$this->assertRejected(
			$this->validator()->validate( array( $node ) ),
			'nexo_mcp_elementor_style_invalid',
			$path,
			$path . ': ' . $reason
		);
	}

	public function test_todos_los_breakpoints_y_estados_de_la_lista_se_aceptan(): void {
		foreach ( array( 'desktop', 'widescreen', 'laptop', 'tablet_extra', 'tablet', 'mobile_extra', 'mobile' ) as $breakpoint ) {
			$node = $this->styled( array() );
			$node['styles']['caja']['variants'][0]['meta']['breakpoint'] = $breakpoint;
			$this->assertTrue( $this->validator()->validate( array( $node ) ), $breakpoint );
		}

		foreach ( array( null, 'hover', 'active', 'focus', 'focus-visible', 'checked', 'e--selected', 'e--disabled', 'e--playing', 'e--paused' ) as $state ) {
			$node = $this->styled( array() );
			$node['styles']['caja']['variants'][0]['meta']['state'] = $state;
			$this->assertTrue( $this->validator()->validate( array( $node ) ), (string) $state );
		}
	}

	public function test_una_sola_familia_con_o_sin_comillas_se_acepta(): void {
		foreach ( array( '"Open Sans"', 'Open Sans', "'Open Sans'" ) as $family ) {
			$this->assertTrue(
				$this->validator()->validate( array( $this->styled( array( 'font-family' => $this->prop( 'font-family', $family ) ) ) ) ),
				$family
			);
		}
	}

	public function test_una_propiedad_de_estilo_desconocida_se_rechaza_con_su_ruta(): void {
		$props = array(
			'colour'    => $this->prop( 'color', 'red' ),
			'font-size' => $this->prop( 'size', array( 'size' => 20, 'unit' => 'px' ) ),
		);
		$validator = new ElementorTreeValidator( $this->allowed(), array(), null, array( 'color', 'font-size' ) );

		$this->assertRejected(
			$validator->validate( array( $this->styled( $props ) ), ElementorNesting::CONTAINER ),
			'nexo_mcp_elementor_style_prop_unknown',
			'elements[0].styles.caja.variants[0].props.colour',
			'elements[0].styles.caja.variants[0].props.colour: la propiedad de estilo «colour» no existe en el esquema de estilos de Elementor.'
		);

		unset( $props['colour'] );
		$this->assertTrue( $validator->validate( array( $this->styled( $props ) ), ElementorNesting::CONTAINER ) );
	}

	public function test_sin_lista_de_propiedades_de_estilo_no_se_admite_ninguna(): void {
		$validator = new ElementorTreeValidator( $this->allowed() );
		$result    = $validator->validate( array( $this->styled( array( 'color' => $this->prop( 'color', 'red' ) ) ) ), ElementorNesting::CONTAINER );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_elementor_style_prop_unknown', $result->get_error_code() );
	}

	public function test_la_etiqueta_de_un_boton_se_limita_a_una_lista_corta(): void {
		$node             = array(
			'type'     => 'e-button',
			'settings' => array( 'tag' => $this->prop( 'string', 'script' ) ),
		);
		$this->assertRejected(
			$this->validator()->validate( array( $node ) ),
			'nexo_mcp_elementor_setting_invalid',
			'elements[0].settings.tag',
			'elements[0].settings.tag: la etiqueta de «e-button» debe ser una de: button, a, span, div.'
		);

		foreach ( array( 'button', 'a', 'span', 'div' ) as $tag ) {
			$node['settings']['tag'] = $this->prop( 'string', $tag );
			$this->assertTrue( $this->validator()->validate( array( $node ) ), $tag );
		}
	}

	public function test_un_widget_en_la_raiz_se_rechaza(): void {
		$result = $this->real_validator()->validate( array( $this->heading() ) );

		$this->assertRejected(
			$result,
			'nexo_mcp_elementor_nesting_denied',
			'elements[0].type',
			'elements[0].type: en la raíz de la página sólo se admiten contenedores atómicos (e-flexbox, e-div-block); «e-heading» va dentro de uno.'
		);
	}

	public function test_la_regla_de_raiz_mira_cada_nodo_de_la_raiz(): void {
		$result = $this->real_validator()->validate( array( $this->box( array( $this->heading() ) ), $this->heading() ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_elementor_nesting_denied', $result->get_error_code() );
		$this->assertSame( array( 'path' => 'elements[1].type' ), $result->get_error_data() );
	}

	public function test_el_padre_indicado_cambia_la_regla_de_la_primera_lista(): void {
		$validator = $this->real_validator();

		$this->assertTrue( $validator->validate( array( $this->heading() ), ElementorNesting::COLUMN ) );
		$this->assertTrue( $validator->validate( array( $this->heading(), $this->box( array() ) ), ElementorNesting::CONTAINER ) );

		$denied = $validator->validate( array( $this->box( array() ) ), ElementorNesting::COLUMN );
		$this->assertInstanceOf( \WP_Error::class, $denied );
		$this->assertSame( 'nexo_mcp_elementor_nesting_denied', $denied->get_error_code() );

		$this->assertInstanceOf( \WP_Error::class, $validator->validate( array( $this->heading() ), ElementorNesting::SECTION ) );
		$this->assertInstanceOf( \WP_Error::class, $validator->validate( array( $this->heading() ), ElementorNesting::WIDGET ) );
	}

	public function test_los_hijos_del_subarbol_nuevo_no_vuelven_a_pasar_por_la_regla_del_padre(): void {
		// Un contenedor atómico dentro de un contenedor atómico es válido aunque la lista cuelgue de una columna v3.
		$this->assertTrue( $this->real_validator()->validate( array( $this->heading() ), ElementorNesting::COLUMN ) );
		$this->assertTrue( $this->real_validator()->validate( array( $this->box( array( $this->box( array( $this->heading() ) ) ) ) ) ) );
	}

	public function test_la_profundidad_base_cuenta_desde_donde_cuelga_la_lista(): void {
		$nest = $this->heading();

		for ( $i = 0; $i < 4; $i++ ) {
			$nest = $this->box( array( $nest ) );
		}
		// Cinco niveles propios: con base 5 llegan a 10 (válido); con base 6, a 11.
		$this->assertTrue( $this->real_validator()->validate( array( $nest ), ElementorNesting::ROOT, 5 ) );

		$result = $this->real_validator()->validate( array( $nest ), ElementorNesting::ROOT, 6 );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_elementor_too_deep', $result->get_error_code() );
	}

	public function test_el_tope_de_nodos_cuenta_los_que_ya_tiene_el_documento(): void {
		$items = array_fill( 0, 5, $this->heading() );
		$tree  = array( $this->box( $items ) );

		$this->assertTrue( $this->real_validator()->validate( $tree, ElementorNesting::ROOT, 0, 194 ) );

		$result = $this->real_validator()->validate( $tree, ElementorNesting::ROOT, 0, 195 );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_elementor_too_many_nodes', $result->get_error_code() );
	}
}
