<?php
/**
 * Herramientas de plantillas del Theme Builder de Divi 5: listar, crear o reemplazar, borrar y restaurar.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Lógica de `list-divi-theme-builder`, `set-divi-template` y `delete-divi-template` (T6 de
 * `odd/tasks/divi-theme-builder.md`; decisiones S5, S6 y S7).
 *
 * Una plantilla decide qué ve TODO el sitio y Divi elige UNA sola por petición. El orden es siempre: permiso del Theme
 * Builder ({@see PermissionPolicy::can_write_divi_theme_builder()}, ANTES de mirar la entrada), Divi 5 activo, forma de la
 * entrada y, dentro del bloqueo único del Theme Builder ({@see DiviTemplateStore::locked()}), las reglas del esquema
 * cerrado ({@see DiviThemeBuilderSchema}), las de «ajena» y «sitio entero», el respaldo y la escritura.
 */
final class DiviTemplateEditor {

	public const CODE_DENIED = 'nexo_mcp_divi_template_denied';

	/**
	 * Esquema de plantillas.
	 *
	 * @var DiviThemeBuilderSchema
	 */
	private DiviThemeBuilderSchema $schema;

	/**
	 * Almacén de plantillas.
	 *
	 * @var DiviTemplateStore
	 */
	private DiviTemplateStore $store;

	/**
	 * Permiso del Theme Builder: fn(): bool.
	 *
	 * @var callable
	 */
	private $allowed;

	/**
	 * Indica si Divi 5 está activo: fn(): bool.
	 *
	 * @var callable
	 */
	private $divi5_active;

	/**
	 * El layout lleva `divi/post-content`: fn( int ): bool.
	 *
	 * @var callable
	 */
	private $has_post_content;

	/**
	 * Construye el editor.
	 *
	 * @param DiviThemeBuilderSchema $schema           Esquema.
	 * @param DiviTemplateStore      $store            Almacén.
	 * @param callable               $allowed          fn(): bool. `PermissionPolicy::can_write_divi_theme_builder()`.
	 * @param callable|null          $divi5_active     fn(): bool. Por omisión, `ET_BUILDER_5_DIR` definida.
	 * @param callable|null          $has_post_content fn( int ): bool. Por omisión, {@see DiviThemeBuilderSchema::layout_has_post_content()}.
	 */
	public function __construct( DiviThemeBuilderSchema $schema, DiviTemplateStore $store, callable $allowed, ?callable $divi5_active = null, ?callable $has_post_content = null ) {
		$this->schema           = $schema;
		$this->store            = $store;
		$this->allowed          = $allowed;
		$this->divi5_active     = $divi5_active ?? array( Divi5ContentWriter::class, 'divi5_installed' );
		$this->has_post_content = $has_post_content ?? array( DiviThemeBuilderSchema::class, 'layout_has_post_content' );
	}

	/**
	 * Lista las plantillas del Theme Builder en vivo con todo lo que hace falta para decidir un cambio.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function list_templates() {
		$gate = $this->gate();

		if ( null !== $gate ) {
			return $gate;
		}

		$live  = $this->store->live_id();
		$draft = $this->store->draft_id();
		$seen  = $this->inspect( $live );
		$items = $seen['items'];
		$list  = array_map(
			static fn( array $item ): array => array(
				'key'          => $item['id'],
				'enabled'      => true === $item['enabled'] && 'publish' === $item['status'],
				'default'      => $item['default'],
				'use_on'       => array_values( array_filter( array_column( $item['use_on'], 'id' ), 'is_string' ) ),
				'exclude_from' => array_values( array_filter( array_column( $item['exclude_from'], 'id' ), 'is_string' ) ),
			),
			$items
		);

		$default = $this->schema->winner( $list, array() );

		return array(
			'live'             => array(
				'id'     => $live > 0 ? $live : null,
				'exists' => $live > 0,
			),
			'draft'            => $this->draft( $draft ),
			'templates'        => $items,
			'default_template' => null !== $default && 'default' === $default['via'] ? (int) $default['key'] : null,
			'contexts'         => $this->contexts( $items, $list ),
			'shadows'          => $this->schema->shadows( $list ),
			'catalog'          => $this->schema->catalog(),
			'limits'           => array(
				'max_templates' => DiviTemplateStore::MAX_TEMPLATES,
				'max_contexts'  => DiviThemeBuilderSchema::MAX_CONTEXTS,
				'max_title'     => DiviThemeBuilderSchema::MAX_TITLE,
			),
			'warnings'         => $seen['warnings'],
		);
	}

	/**
	 * Permiso y Divi 5: lo primero que se comprueba en toda herramienta.
	 *
	 * @return \WP_Error|null
	 */
	private function gate(): ?\WP_Error {
		if ( ! ( $this->allowed )() ) {
			return self::denied();
		}

		return ( $this->divi5_active )() ? null : Divi5ContentWriter::required_error();
	}

	/**
	 * Error de las herramientas de plantillas sin el permiso del Theme Builder.
	 */
	public static function denied(): \WP_Error {
		return new \WP_Error(
			self::CODE_DENIED,
			__( 'Las plantillas del Theme Builder de Divi deciden lo que ve todo el sitio: sólo se leen y se cambian con el permiso del Theme Builder (scope mcp:divi_theme_builder con su interruptor encendido y un administrador con el permiso theme_builder de Divi), no con el permiso de una página.', 'nexo-mcp' )
		);
	}

	/**
	 * El borrador del Visual Builder, si lo hay.
	 *
	 * @param int $draft Id del borrador (0 si no hay).
	 *
	 * @return array<string, mixed>
	 */
	private function draft( int $draft ): array {
		if ( $draft <= 0 ) {
			return array(
				'exists' => false,
				'id'     => null,
			);
		}

		return array(
			'exists' => true,
			'id'     => $draft,
			'note'   => sprintf(
				/* translators: %d: id del borrador. */
				__( 'Hay un borrador del Theme Builder hecho en el Visual Builder (auto-draft, id %d). El conector lo ignora: sólo lee y escribe el Theme Builder en vivo. WordPress barre los auto-draft a los 7 días. Si alguien tiene el Visual Builder abierto y guarda en vivo con el estado viejo, Divi reescribe el mapeo entero y pisa lo que se hizo aquí.', 'nexo-mcp' ),
				$draft
			),
		);
	}

	/**
	 * Recorre el mapeo del Theme Builder en vivo: cada plantilla resumida, con sus layouts, y los avisos.
	 *
	 * @param int $tb Theme Builder en vivo (0 si no hay).
	 *
	 * @return array{items: array<int, array<string, mixed>>, warnings: array<int, array<string, mixed>>}
	 */
	private function inspect( int $tb ): array {
		$items    = array();
		$warnings = array();
		$defaults = array();

		foreach ( $this->store->mapping( $tb ) as $id ) {
			$post = $this->store->template( $id );

			if ( null === $post ) {
				$warnings[] = $this->warning( 'orphan_mapping', __( 'El mapeo del Theme Builder tiene una fila que no apunta a una plantilla: Divi la ignora.', 'nexo-mcp' ), array( 'template_id' => $id ) );
				continue;
			}

			$item = array_merge(
				array(
					'id'     => $id,
					'status' => (string) $post->post_status,
				),
				$this->schema->summary( $this->store->metas( $id ), $post->post_title )
			);

			if ( 'trash' === $item['status'] ) {
				$warnings[] = $this->warning( 'template_trashed', __( 'La plantilla está en la papelera pero sigue en el mapeo: Divi no la usa.', 'nexo-mcp' ), array( 'template_id' => $id ) );
			}

			if ( $this->store->is_marked_unused( $id ) ) {
				$warnings[] = $this->warning( 'scheduled_for_trash', __( 'Divi marcó esta plantilla como sin uso: pasados 7 días la manda a la papelera.', 'nexo-mcp' ), array( 'template_id' => $id ) );
			}

			if ( $item['default'] ) {
				$defaults[] = $id;
			}

			$replaces = $item['default'] || $this->content_context( $item['use_on'] );

			foreach ( array( 'header', 'body', 'footer' ) as $slot ) {
				$item[ $slot ] = $this->slot( $id, $slot, $item, $replaces, $warnings );
			}

			$items[] = $item;
		}

		if ( count( $defaults ) > 1 ) {
			$warnings[] = $this->warning( 'multiple_defaults', __( 'Hay más de una plantilla por defecto: sólo una vale (Divi elige la de menor id). Deja una.', 'nexo-mcp' ), array( 'template_ids' => $defaults ) );
		}

		return array(
			'items'    => $items,
			'warnings' => $warnings,
		);
	}

	/**
	 * Una ranura de la plantilla con el estado de su layout; añade a `$warnings` lo que no cuadra.
	 *
	 * @param int                              $id       Plantilla.
	 * @param string                           $slot     `header`, `body` o `footer`.
	 * @param array<string, mixed>             $item     Resumen de la plantilla.
	 * @param bool                             $replaces Si la plantilla ocupa el lugar del contenido (por defecto o contexto de contenido).
	 * @param array<int, array<string, mixed>> $warnings Avisos.
	 *
	 * @return array<string, mixed>
	 */
	private function slot( int $id, string $slot, array $item, bool $replaces, array &$warnings ): array {
		$out    = $item[ $slot ];
		$layout = $out['layout_id'];

		if ( 'body' === $slot ) {
			$out['has_post_content'] = false;
		}

		if ( null === $layout ) {
			return $out;
		}

		$post = get_post( $layout );

		if ( ! $post instanceof \WP_Post ) {
			$out['status'] = 'missing';
		} elseif ( DiviLayoutGuard::LAYOUT_TYPES[ $slot ] !== $post->post_type ) {
			$out['status'] = 'wrong_type';
		} else {
			$out['status'] = (string) $post->post_status;
		}

		$at = array(
			'template_id' => $id,
			'layout_id'   => $layout,
			'slot'        => $slot,
		);

		if ( in_array( $out['status'], array( 'missing', 'wrong_type' ), true ) ) {
			$warnings[] = $this->warning(
				'layout_invalid',
				sprintf(
					/* translators: 1: id, 2: ranura. */
					__( 'El layout %1$d no existe o no es un layout de tipo %2$s: Divi lo ignora y esa área sale como si no hubiera layout.', 'nexo-mcp' ),
					$layout,
					$slot
				),
				$at
			);

			return $out;
		}

		if ( 'publish' !== $out['status'] ) {
			$warnings[] = $this->warning(
				'layout_not_published',
				sprintf(
					/* translators: 1: id, 2: estado. */
					__( 'El layout %1$d está en estado %2$s: Divi muestra igualmente un layout que no es publish (salvo trash) y lo ve el público. Publícalo o quítalo de la plantilla.', 'nexo-mcp' ),
					$layout,
					$out['status']
				),
				$at
			);
		}

		if ( $this->store->is_marked_unused( $layout ) ) {
			$warnings[] = $this->warning( 'scheduled_for_trash', __( 'Divi marcó este layout como sin uso: pasados 7 días lo manda a la papelera.', 'nexo-mcp' ), $at );
		}

		if ( 'body' === $slot ) {
			$out['has_post_content'] = true === ( $this->has_post_content )( $layout );

			if ( ! $out['has_post_content'] && $replaces && true === $item['enabled'] && 'publish' === $item['status'] && $out['enabled'] ) {
				$warnings[] = $this->warning( 'body_replaces_content', __( 'El cuerpo no lleva divi/post-content: REEMPLAZA el contenido de las páginas, entradas y pantallas de WooCommerce a las que se asigna (el carrito, por ejemplo, dejaría de verse).', 'nexo-mcp' ), $at );
			}
		}

		return $out;
	}

	/**
	 * Si algún contexto de la plantilla ocupa el lugar del contenido (inicio, singulares y WooCommerce).
	 *
	 * @param array<int, array{id: string|null, supported: bool, priority: int|null}> $contexts Contextos resumidos.
	 */
	private function content_context( array $contexts ): bool {
		foreach ( $contexts as $context ) {
			$id = $context['id'];

			if ( is_string( $id ) && ( 'homepage' === $id || str_starts_with( $id, 'singular:' ) || str_starts_with( $id, 'woocommerce:' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Cada contexto del catálogo en uso, con la plantilla que gana si la petición sólo cumple ése.
	 *
	 * @param array<int, array<string, mixed>> $items Plantillas resumidas.
	 * @param array<int, array<string, mixed>> $templates Lista de {@see DiviThemeBuilderSchema::winner()}.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function contexts( array $items, array $templates ): array {
		$used = array();

		foreach ( $items as $item ) {
			foreach ( $item['use_on'] as $context ) {
				$id = $context['id'];

				if ( is_string( $id ) && null !== $context['priority'] ) {
					$used[ $id ]['priority']    = $context['priority'];
					$used[ $id ]['templates'][] = $item['id'];
				}
			}
		}

		$out = array();

		foreach ( $used as $context => $data ) {
			$winner = $this->schema->winner( $templates, array( $context ) );
			$out[]  = array(
				'context'   => $context,
				'priority'  => $data['priority'],
				'winner'    => null === $winner ? null : (int) $winner['key'],
				'via'       => null === $winner ? null : $winner['via'],
				'templates' => array_values( array_unique( $data['templates'] ) ),
			);
		}

		usort( $out, static fn( array $a, array $b ): int => array( $b['priority'], $a['context'] ) <=> array( $a['priority'], $b['context'] ) );

		return $out;
	}

	/**
	 * Un aviso de la salida.
	 *
	 * @param string               $code    Código corto.
	 * @param string               $message Texto.
	 * @param array<string, mixed> $at      Dónde (plantilla, layout, ranura).
	 *
	 * @return array<string, mixed>
	 */
	private function warning( string $code, string $message, array $at ): array {
		return array_merge(
			array(
				'code'    => $code,
				'message' => $message,
			),
			$at
		);
	}
}
