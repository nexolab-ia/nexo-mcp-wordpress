<?php
/**
 * Doble mínimo de `\Elementor\Plugin` para el sistema de diseño.
 *
 * Define una clase global: sólo se carga en pruebas de proceso aparte, para no
 * hacer creer a las demás que Elementor está instalado.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace Elementor {
	final class Plugin {
		public static ?Plugin $instance = null;
		public object $experiments;
		public object $kits_manager;
		public ?object $documents = null;

		public static function instance(): Plugin {
			if ( null === self::$instance ) {
				self::$instance               = new self();
				self::$instance->experiments  = new class() {
					public function is_feature_active( string $name ): bool {
						return true;
					}
				};
				self::$instance->kits_manager = new class() {
					public function get_active_kit(): object {
						return new class() {
							public function get_settings( string $key ) {
								return array();
							}
							public function get_main_id(): int {
								return 9;
							}
						};
					}
				};
			}

			return self::$instance;
		}
	}
}

namespace Elementor {
	/**
	 * Documentos de Elementor: `get()` entrega un documento cuyo `save()` se comporta como se pida.
	 */
	final class Fake_Documents {
		/** @var string ok | false | throw | drop (guarda menos nodos de los pedidos). */
		public string $mode = 'ok';

		/** @return object|false */
		public function get( int $id ) {
			$mode = $this->mode;

			return new class( $id, $mode ) {
				public function __construct( private int $id, private string $mode ) {}

				public function set_is_built_with_elementor( bool $on ): void {}

				public function get_edit_url(): string {
					return 'https://example.test/wp-admin/post.php?post=' . $this->id . '&action=elementor';
				}

				/** @param array<string, mixed> $data */
				public function save( array $data ): bool {
					if ( 'throw' === $this->mode ) {
						throw new \RuntimeException( 'Elementor se cayó' );
					}

					if ( 'false' === $this->mode ) {
						return false;
					}

					$tree = $data['elements'];

					if ( 'drop' === $this->mode ) {
						$tree = array( array_values( $tree )[0] );
						unset( $tree[0]['elements'] );
					}

					update_post_meta( $this->id, '_elementor_data', wp_slash( (string) wp_json_encode( $tree ) ) );

					return true;
				}
			};
		}
	}
}
