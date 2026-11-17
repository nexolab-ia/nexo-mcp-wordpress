<?php
/**
 * Productos WooCommerce mediante CRUD oficial.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\WooCommerce;

use NexoDev\NexoMCP\Support\Settings;

/**
 * Gestiona productos simples, variables y variaciones sin consultas SQL.
 */
final class ProductGateway {

	/**
	 * Configuración.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Construye el gateway.
	 *
	 * @param Settings $settings Configuración.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Lista productos paginados.
	 *
	 * @param array<string, mixed> $input Filtros.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function list_products( array $input ) {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return $this->woocommerce_required();
		}

		$query_args = array(
			'limit'    => (int) ( $input['per_page'] ?? 20 ),
			'page'     => (int) ( $input['page'] ?? 1 ),
			'type'     => ! empty( $input['type'] ) ? (string) $input['type'] : array( 'simple', 'variable' ),
			'sku'      => sanitize_text_field( (string) ( $input['sku'] ?? '' ) ),
			'paginate' => true,
			'return'   => 'objects',
		);

		if ( ! empty( $input['status'] ) && 'any' !== $input['status'] ) {
			$query_args['status'] = (string) $input['status'];
		}

		$result = wc_get_products( $query_args );

		if ( ! is_object( $result ) || ! isset( $result->products ) ) {
			return new \WP_Error( 'nexo_mcp_products_failed', __( 'WooCommerce no devolvió productos.', 'nexo-mcp' ) );
		}

		return array(
			'products'    => array_map( array( $this, 'product_summary' ), $result->products ),
			'total'       => (int) $result->total,
			'total_pages' => (int) $result->max_num_pages,
		);
	}

	/**
	 * Obtiene un producto con variaciones.
	 *
	 * @param int $product_id Producto.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function get_product( int $product_id ) {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;

		if ( ! $product instanceof \WC_Product ) {
			return new \WP_Error( 'nexo_mcp_product_not_found', __( 'No se encontró el producto.', 'nexo-mcp' ) );
		}

		return $this->product_detail( $product );
	}

	/**
	 * Crea o actualiza un producto y sus variaciones.
	 *
	 * @param array<string, mixed> $input Datos validados.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function upsert_product( array $input ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return $this->woocommerce_required();
		}

		$product_id = (int) ( $input['id'] ?? 0 );
		$type       = (string) ( $input['type'] ?? 'simple' );
		$product    = $product_id > 0 ? wc_get_product( $product_id ) : false;

		if ( $product_id > 0 && ! $product instanceof \WC_Product ) {
			return new \WP_Error( 'nexo_mcp_product_not_found', __( 'No se encontró el producto.', 'nexo-mcp' ) );
		}

		if ( $product instanceof \WC_Product && $product->get_type() !== $type ) {
			return new \WP_Error( 'nexo_mcp_product_type_change_denied', __( 'No se permite cambiar el tipo de un producto existente.', 'nexo-mcp' ) );
		}

		if ( ! $product instanceof \WC_Product ) {
			$product = 'variable' === $type ? new \WC_Product_Variable() : new \WC_Product_Simple();
		}

		$this->apply_product_fields( $product, $input );

		$status = (string) ( $input['status'] ?? 'draft' );

		if ( 'publish' === $status && ! (bool) $this->settings->get( 'publishing_enabled' ) ) {
			$status = 'draft';
		}

		$product->set_status( $status );

		if ( array_key_exists( 'categories', $input ) ) {
			$product->set_category_ids( array_map( 'absint', (array) $input['categories'] ) );
		}

		if ( array_key_exists( 'images', $input ) ) {
			$images = array_values( array_filter( array_map( 'absint', (array) $input['images'] ) ) );
			$product->set_image_id( (int) ( $images[0] ?? 0 ) );
			$product->set_gallery_image_ids( array_slice( $images, 1, 20 ) );
		}

		// Los atributos valen para cualquier tipo de producto: en uno simple
		// alimentan la tabla de información de la ficha. Restringirlos a los
		// variables los descartaba en silencio —la herramienta devolvía éxito
		// con `attributes: []`— y quien creara un catálogo entero se enteraba
		// mucho después. Lo que sí es exclusivo de los variables es marcar un
		// atributo como base de variación, y eso se resuelve en build_attributes().
		if ( array_key_exists( 'attributes', $input ) ) {
			$product->set_attributes(
				$this->build_attributes( (array) $input['attributes'], 'variable' === $type )
			);
		}

		$product_id = $product->save();

		if ( $product_id < 1 ) {
			return new \WP_Error( 'nexo_mcp_product_save_failed', __( 'No fue posible guardar el producto.', 'nexo-mcp' ) );
		}

		if ( 'variable' === $type && array_key_exists( 'variations', $input ) ) {
			$variation_result = $this->save_variations( $product_id, (array) $input['variations'] );

			if ( is_wp_error( $variation_result ) ) {
				return $variation_result;
			}

			\WC_Product_Variable::sync( $product_id );
		}

		return $this->get_product( $product_id );
	}

	/**
	 * Envía un producto a papelera o lo elimina.
	 *
	 * @param int  $product_id Producto.
	 * @param bool $force      Eliminación definitiva.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function delete_product( int $product_id, bool $force ) {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;

		if ( ! $product instanceof \WC_Product ) {
			return new \WP_Error( 'nexo_mcp_product_not_found', __( 'No se encontró el producto.', 'nexo-mcp' ) );
		}

		$result = $product->delete( $force );

		if ( ! $result ) {
			return new \WP_Error( 'nexo_mcp_product_delete_failed', __( 'No fue posible eliminar el producto.', 'nexo-mcp' ) );
		}

		return array(
			'id'      => $product_id,
			'deleted' => true,
			'force'   => $force,
		);
	}

	/**
	 * Aplica campos escalares mediante setters CRUD.
	 *
	 * @param \WC_Product          $product Producto.
	 * @param array<string, mixed> $input   Datos.
	 */
	private function apply_product_fields( \WC_Product $product, array $input ): void {
		$setters = array(
			'name'              => 'set_name',
			'slug'              => 'set_slug',
			'description'       => 'set_description',
			'short_description' => 'set_short_description',
			'sku'               => 'set_sku',
			'regular_price'     => 'set_regular_price',
			'sale_price'        => 'set_sale_price',
			'stock_status'      => 'set_stock_status',
			'manage_stock'      => 'set_manage_stock',
			'stock_quantity'    => 'set_stock_quantity',
			// Sin estos dos, un producto digital se guardaba como físico y
			// WooCommerce le pedía dirección de envío al comprador.
			'virtual'           => 'set_virtual',
			'downloadable'      => 'set_downloadable',
		);

		foreach ( $setters as $field => $setter ) {
			if ( array_key_exists( $field, $input ) ) {
				$value = $input[ $field ];

				if ( in_array( $field, array( 'name', 'slug', 'sku', 'stock_status' ), true ) ) {
					$value = sanitize_text_field( (string) $value );
				} elseif ( in_array( $field, array( 'description', 'short_description' ), true ) ) {
					$value = wp_kses_post( (string) $value );
				} elseif ( in_array( $field, array( 'virtual', 'downloadable', 'manage_stock' ), true ) ) {
					$value = (bool) $value;
				}

				$product->{$setter}( $value );
			}
		}

		// Un descargable que no es virtual sigue pidiendo envío: WooCommerce
		// trata las dos marcas por separado y quien pide un producto digital
		// espera las dos. Se asume la intención en vez de guardar algo a medias.
		if ( ! empty( $input['downloadable'] ) && ! array_key_exists( 'virtual', $input ) ) {
			$product->set_virtual( true );
		}
	}

	/**
	 * Construye los atributos de un producto.
	 *
	 * @param array<int, array<string, mixed>> $attributes Atributos.
	 * @param bool                             $es_variable Si el producto admite variaciones.
	 * @return list<\WC_Product_Attribute>
	 */
	private function build_attributes( array $attributes, bool $es_variable = false ): array {
		$result = array();

		foreach ( array_slice( $attributes, 0, 20 ) as $position => $item ) {
			$name    = sanitize_text_field( (string) ( $item['name'] ?? '' ) );
			$options = array_values(
				array_filter(
					array_map( 'sanitize_text_field', (array) ( $item['options'] ?? array() ) )
				)
			);

			if ( '' === $name || array() === $options ) {
				continue;
			}

			$attribute = new \WC_Product_Attribute();
			$attribute->set_id( 0 );
			$attribute->set_name( $name );
			$attribute->set_options( array_slice( $options, 0, 100 ) );
			$attribute->set_position( (int) $position );
			$attribute->set_visible( ! isset( $item['visible'] ) || ! empty( $item['visible'] ) );
			// Marcar un atributo como base de variación en un producto simple
			// deja el dato inconsistente: WooCommerce lo guarda pero no hay
			// variaciones que lo usen, y la ficha se comporta de forma rara.
			$attribute->set_variation( $es_variable && ! empty( $item['variation'] ) );
			$result[] = $attribute;
		}

		return $result;
	}

	/**
	 * Guarda variaciones acotadas.
	 *
	 * @param int                              $product_id Producto padre.
	 * @param array<int, array<string, mixed>> $variations Variaciones.
	 * @return true|\WP_Error
	 */
	private function save_variations( int $product_id, array $variations ) {
		foreach ( array_slice( $variations, 0, 100 ) as $item ) {
			$variation_id = (int) ( $item['id'] ?? 0 );
			$variation    = $variation_id > 0 ? wc_get_product( $variation_id ) : false;

			if ( $variation_id > 0 && ( ! $variation instanceof \WC_Product_Variation || $variation->get_parent_id() !== $product_id ) ) {
				return new \WP_Error( 'nexo_mcp_variation_invalid', __( 'Una variación no pertenece al producto.', 'nexo-mcp' ) );
			}

			if ( ! $variation instanceof \WC_Product_Variation ) {
				$variation = new \WC_Product_Variation();
				$variation->set_parent_id( $product_id );
			}

			foreach (
				array(
					'sku'            => 'set_sku',
					'regular_price'  => 'set_regular_price',
					'sale_price'     => 'set_sale_price',
					'stock_status'   => 'set_stock_status',
					'manage_stock'   => 'set_manage_stock',
					'stock_quantity' => 'set_stock_quantity',
				) as $field => $setter
			) {
				if ( array_key_exists( $field, $item ) ) {
					$variation->{$setter}( $item[ $field ] );
				}
			}

			if ( array_key_exists( 'attributes', $item ) ) {
				$attributes = array();

				foreach ( (array) $item['attributes'] as $name => $value ) {
					$attributes[ sanitize_title( (string) $name ) ] = sanitize_text_field( (string) $value );
				}

				$variation->set_attributes( $attributes );
			}

			$variation->set_status( 'publish' );
			$variation->save();
		}

		return true;
	}

	/**
	 * Resumen de producto.
	 *
	 * @param \WC_Product $product Producto.
	 * @return array<string, mixed>
	 */
	private function product_summary( \WC_Product $product ): array {
		return array(
			'id'            => $product->get_id(),
			'name'          => $product->get_name(),
			'type'          => $product->get_type(),
			'status'        => $product->get_status(),
			'sku'           => $product->get_sku(),
			'price'         => $product->get_price(),
			'regular_price' => $product->get_regular_price(),
			'sale_price'    => $product->get_sale_price(),
			'stock_status'  => $product->get_stock_status(),
			'permalink'     => $product->get_permalink(),
		);
	}

	/**
	 * Detalle completo normalizado.
	 *
	 * @param \WC_Product $product Producto.
	 * @return array<string, mixed>
	 */
	private function product_detail( \WC_Product $product ): array {
		$detail                      = $this->product_summary( $product );
		$detail['slug']              = $product->get_slug();
		$detail['description']       = $product->get_description();
		$detail['short_description'] = $product->get_short_description();
		$detail['manage_stock']      = $product->get_manage_stock();
		$detail['stock_quantity']    = $product->get_stock_quantity();
		$detail['virtual']           = $product->is_virtual();
		$detail['downloadable']      = $product->is_downloadable();
		$detail['categories']        = $product->get_category_ids();
		$detail['images']            = array_values(
			array_filter( array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) )
		);
		$detail['attributes']        = array_map(
			static fn( \WC_Product_Attribute $attribute ): array => array(
				'name'      => $attribute->get_name(),
				'options'   => $attribute->get_options(),
				'visible'   => $attribute->get_visible(),
				'variation' => $attribute->get_variation(),
			),
			$product->get_attributes()
		);
		$detail['variations']        = array();

		if ( $product instanceof \WC_Product_Variable ) {
			$detail['variations'] = array_map(
				static function ( int $variation_id ): array {
					$variation = wc_get_product( $variation_id );

					return $variation instanceof \WC_Product_Variation
						? array(
							'id'             => $variation->get_id(),
							'sku'            => $variation->get_sku(),
							'regular_price'  => $variation->get_regular_price(),
							'sale_price'     => $variation->get_sale_price(),
							'stock_status'   => $variation->get_stock_status(),
							'stock_quantity' => $variation->get_stock_quantity(),
							'attributes'     => $variation->get_attributes(),
						)
						: array();
				},
				$product->get_children()
			);
		}

		return $detail;
	}

	/**
	 * Error estable cuando WooCommerce no está activo.
	 */
	private function woocommerce_required(): \WP_Error {
		return new \WP_Error( 'nexo_mcp_woocommerce_required', __( 'WooCommerce no está instalado o activo.', 'nexo-mcp' ) );
	}
}
