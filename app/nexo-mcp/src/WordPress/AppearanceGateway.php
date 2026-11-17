<?php
/**
 * Apariencia global segura.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\WordPress;

/**
 * Administra identidad, CSS global y menús sin editar archivos del tema.
 */
final class AppearanceGateway {

	/**
	 * Obtiene identidad y tema activo.
	 *
	 * @return array<string, mixed>
	 */
	public function get_site_appearance(): array {
		$theme = wp_get_theme();

		return array(
			'name'          => (string) get_option( 'blogname', '' ),
			'description'   => (string) get_option( 'blogdescription', '' ),
			'theme'         => $theme->get( 'Name' ),
			'theme_version' => $theme->get( 'Version' ),
			'stylesheet'    => $theme->get_stylesheet(),
			'template'      => $theme->get_template(),
			'custom_css'    => wp_get_custom_css(),
		);
	}

	/**
	 * Actualiza nombre, descripción, zona horaria y visibilidad del sitio.
	 *
	 * Valida todo antes de escribir: si un campo no sirve, no se aplica
	 * ninguno. Devuelve sólo lo que se aplicó y no la apariencia completa,
	 * que arrastra el CSS adicional y puede pesar miles de tokens (ADR-030).
	 *
	 * @param array<string, mixed> $input Datos.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function update_site_identity( array $input ) {
		if ( array_key_exists( 'timezone', $input )
			&& ! in_array( (string) $input['timezone'], timezone_identifiers_list(), true )
		) {
			return new \WP_Error( 'nexo_mcp_timezone_invalid', __( 'Zona horaria no válida. Usa un identificador como America/Santiago.', 'nexo-mcp' ) );
		}

		$changes = array();

		if ( array_key_exists( 'name', $input ) ) {
			$changes['name'] = sanitize_text_field( (string) $input['name'] );
			update_option( 'blogname', $changes['name'] );
		}

		if ( array_key_exists( 'description', $input ) ) {
			$changes['description'] = sanitize_text_field( (string) $input['description'] );
			update_option( 'blogdescription', $changes['description'] );
		}

		if ( array_key_exists( 'timezone', $input ) ) {
			$changes['timezone'] = (string) $input['timezone'];

			// Igual que WordPress al elegir una ciudad: manda la zona y el
			// desfase fijo queda vacío.
			update_option( 'timezone_string', $changes['timezone'] );
			update_option( 'gmt_offset', '' );
		}

		if ( array_key_exists( 'search_visible', $input ) ) {
			$changes['search_visible'] = rest_sanitize_boolean( $input['search_visible'] );
			update_option( 'blog_public', $changes['search_visible'] ? '1' : '0' );
		}

		if ( array() === $changes ) {
			return new \WP_Error( 'nexo_mcp_identity_empty', __( 'Indica al menos un campo para cambiar.', 'nexo-mcp' ) );
		}

		return $changes;
	}

	/**
	 * Actualiza CSS adicional bloqueando capacidades activas y red remota.
	 *
	 * @param string $css CSS.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function update_custom_css( string $css ) {
		$css = wp_strip_all_tags( $css );

		if ( strlen( $css ) > 100000 ) {
			return new \WP_Error( 'nexo_mcp_css_too_large', __( 'El CSS supera el máximo permitido.', 'nexo-mcp' ) );
		}

		if ( preg_match( '/@import|expression\s*\(|javascript\s*:|behavior\s*:|-moz-binding|url\s*\(\s*[\'"]?\s*(?:https?:)?\/\//i', $css ) ) {
			return new \WP_Error( 'nexo_mcp_css_denied', __( 'El CSS contiene una construcción no permitida.', 'nexo-mcp' ) );
		}

		$result = wp_update_custom_css_post( $css );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'updated' => true,
			'length'  => strlen( $css ),
			'css'     => wp_get_custom_css(),
			// El CSS adicional afecta a todas las páginas, así que aquí sí
			// corresponde la purga total: dejar una sola URL al día serviría el
			// resto con la hoja anterior.
			'cache'   => $this->cache()->after_global_change(),
		);
	}

	/**
	 * Purgador de caché, resuelto en el momento de usarlo.
	 *
	 * Se construye aquí y no en el constructor para no cambiar la firma del
	 * gateway, que se instancia desde varios sitios.
	 */
	private function cache(): \NexoDev\NexoMCP\Cache\CachePurger {
		return new \NexoDev\NexoMCP\Cache\CachePurger( new \NexoDev\NexoMCP\Support\Settings() );
	}

	/**
	 * Lista menús clásicos y sus elementos.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function list_menus(): array {
		return array_map(
			static function ( \WP_Term $menu ): array {
				$items = wp_get_nav_menu_items( $menu->term_id );

				if ( ! is_array( $items ) ) {
					$items = array();
				}

				return array(
					'id'    => $menu->term_id,
					'name'  => $menu->name,
					'slug'  => $menu->slug,
					'count' => $menu->count,
					'items' => array_map(
						static fn( \WP_Post $item ): array => array(
							'id'        => $item->ID,
							'title'     => $item->title,
							'url'       => $item->url,
							'parent'    => (int) $item->menu_item_parent,
							'position'  => (int) $item->menu_order,
							'object_id' => (int) $item->object_id,
							'type'      => $item->type,
						),
						$items
					),
				);
			},
			wp_get_nav_menus()
		);
	}

	/**
	 * Crea o actualiza un elemento de menú clásico.
	 *
	 * @param array<string, mixed> $input Datos.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function upsert_menu_item( array $input ) {
		$menu = wp_get_nav_menu_object( (int) $input['menu_id'] );

		if ( ! $menu instanceof \WP_Term ) {
			return new \WP_Error( 'nexo_mcp_menu_not_found', __( 'No se encontró el menú.', 'nexo-mcp' ) );
		}

		$item_id = (int) ( $input['item_id'] ?? 0 );

		if ( $item_id > 0 ) {
			$item_menus = wp_get_post_terms( $item_id, 'nav_menu', array( 'fields' => 'ids' ) );

			if ( is_wp_error( $item_menus ) || ! in_array( $menu->term_id, $item_menus, true ) ) {
				return new \WP_Error( 'nexo_mcp_menu_item_invalid', __( 'El elemento no pertenece al menú indicado.', 'nexo-mcp' ) );
			}
		}

		if ( ! empty( $input['delete'] ) ) {
			return $this->delete_menu_item( $menu, $item_id );
		}

		if ( '' === trim( (string) ( $input['title'] ?? '' ) ) ) {
			return new \WP_Error( 'nexo_mcp_menu_title_required', __( 'Debes indicar el título del elemento.', 'nexo-mcp' ) );
		}

		$args = array(
			'menu-item-title'     => sanitize_text_field( (string) $input['title'] ),
			'menu-item-status'    => 'publish',
			'menu-item-parent-id' => (int) ( $input['parent'] ?? 0 ),
			'menu-item-position'  => (int) ( $input['position'] ?? 0 ),
		);

		if ( ! empty( $input['object_id'] ) ) {
			$post = get_post( (int) $input['object_id'] );

			if ( ! $post instanceof \WP_Post || ! is_post_type_viewable( $post->post_type ) ) {
				return new \WP_Error( 'nexo_mcp_menu_object_invalid', __( 'El contenido del menú no es válido.', 'nexo-mcp' ) );
			}

			$args['menu-item-object-id'] = $post->ID;
			$args['menu-item-object']    = $post->post_type;
			$args['menu-item-type']      = 'post_type';
		} else {
			$url = esc_url_raw( (string) ( $input['url'] ?? '' ), array( 'https', 'http' ) );

			if ( '' === $url ) {
				return new \WP_Error( 'nexo_mcp_menu_url_invalid', __( 'Debes indicar una URL válida.', 'nexo-mcp' ) );
			}

			$args['menu-item-url']  = $url;
			$args['menu-item-type'] = 'custom';
		}

		$result = wp_update_nav_menu_item( $menu->term_id, $item_id, $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'menu_id' => $menu->term_id,
			'item_id' => (int) $result,
			'updated' => $item_id > 0,
		);
	}

	/**
	 * Quita un elemento del menú.
	 *
	 * Los elementos de menú no pasan por la papelera y se borran con la misma
	 * función que borra páginas, así que se exige que el id sea de verdad un
	 * elemento de este menú. Los hijos suben un nivel, como en wp-admin: si no,
	 * quedan colgando de un padre que no existe y desaparecen del menú. La
	 * respuesta trae lo borrado para poder volver a crearlo.
	 *
	 * @param \WP_Term $menu    Menú ya validado.
	 * @param int      $item_id Elemento, ya comprobado que pertenece al menú.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function delete_menu_item( \WP_Term $menu, int $item_id ) {
		$items = wp_get_nav_menu_items( $menu->term_id );
		$item  = null;

		foreach ( is_array( $items ) ? $items : array() as $candidate ) {
			if ( (int) $candidate->ID === $item_id && 'nav_menu_item' === $candidate->post_type ) {
				$item = $candidate;
				break;
			}
		}

		if ( null === $item ) {
			return new \WP_Error( 'nexo_mcp_menu_item_invalid', __( 'El elemento no pertenece al menú indicado.', 'nexo-mcp' ) );
		}

		$parent = (int) $item->menu_item_parent;
		$moved  = array();

		foreach ( $items as $child ) {
			if ( (int) $child->menu_item_parent === $item_id ) {
				update_post_meta( (int) $child->ID, '_menu_item_menu_item_parent', (string) $parent );
				$moved[] = (int) $child->ID;
			}
		}

		if ( ! wp_delete_post( $item_id, true ) ) {
			return new \WP_Error( 'nexo_mcp_menu_item_delete_failed', __( 'No se pudo quitar el elemento del menú.', 'nexo-mcp' ) );
		}

		return array(
			'menu_id' => $menu->term_id,
			'deleted' => array(
				'title'     => $item->title,
				'url'       => $item->url,
				'object_id' => (int) $item->object_id,
				'parent'    => $parent,
				'position'  => (int) $item->menu_order,
			),
			'moved'   => $moved,
		);
	}
}
