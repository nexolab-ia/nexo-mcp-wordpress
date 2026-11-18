<?php
/**
 * Dobles de Elementor para probar `ElementorDesignStore`: plugin, Kit y repositorio de clases con estado.
 *
 * Define clases globales: sólo se carga en pruebas de proceso aparte, y no junto a
 * {@see ElementorDesignFakes.php} ni {@see ElementorDesignRepositoryFakes.php}.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace Elementor {
	final class Fake_Kit {
		/** @var array<string, mixed> */
		public array $json = array();

		public function get_settings( string $key = '' ) {
			return array();
		}

		public function get_main_id(): int {
			return 9;
		}

		/** @var bool Quita los títulos al guardar, como el filtrado de HTML sin unfiltered_html. */
		public bool $strip_titles = false;

		/** @param array<string, mixed> $data */
		public function save( array $data ): bool {
			$settings = $data['settings'];

			if ( $this->strip_titles ) {
				foreach ( $settings as $key => $list ) {
					foreach ( $list as $i => $item ) {
						unset( $settings[ $key ][ $i ]['title'] );
					}
				}
			}

			update_post_meta( 9, '_elementor_page_settings', $settings );

			return true;
		}

		public function get_json_meta( string $key ) {
			return $this->json[ $key ] ?? array();
		}

		public function update_json_meta( string $key, $value ): bool {
			$this->json[ $key ] = $value;

			return true;
		}

		public function delete_meta( string $key ): bool {
			unset( $this->json[ $key ] );

			return true;
		}
	}

	final class Plugin {
		public static ?Plugin $instance = null;
		public object $experiments;
		public object $kits_manager;
		public object $files_manager;
		public Fake_Kit $kit;
		public int $cache_cleared = 0;

		public static function instance(): Plugin {
			if ( null === self::$instance ) {
				$plugin                = new self();
				$plugin->kit           = new Fake_Kit();
				$plugin->experiments   = new class() {
					public function is_feature_active( string $name ): bool {
						return true;
					}
				};
				$plugin->kits_manager  = new class( $plugin ) {
					public function __construct( private Plugin $plugin ) {}

					public function get_active_kit(): object {
						return $this->plugin->kit;
					}
				};
				$plugin->files_manager = new class( $plugin ) {
					public function __construct( private Plugin $plugin ) {}

					public function clear_cache(): void {
						++$this->plugin->cache_cleared;
					}
				};
				self::$instance        = $plugin;
			}

			return self::$instance;
		}
	}
}

namespace Elementor\Modules\GlobalClasses {
	final class Global_Classes_Repository {
		/** @var array<string, array<string, mixed>> */
		public static array $items = array();

		/** @var array<int, string> */
		public static array $order = array();

		/** @var array<int, array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<int, string>}> */
		public static array $applied = array();

		/** @var string ok | ignore (no guarda nada) | wrong_order | altered (guarda otro contenido) */
		public static string $mode = 'ok';

		public static function make( object $kit ): self {
			return new self();
		}

		public function all( bool $with_preview = false ): object {
			return new class( self::$items, self::$order ) {
				/**
				 * @param array<string, array<string, mixed>> $items Items.
				 * @param array<int, string>                  $order Orden.
				 */
				public function __construct( private array $items, private array $order ) {}

				/** @return array{items: array<string, array<string, mixed>>, order: array<int, string>} */
				public function get(): array {
					return array(
						'items' => $this->items,
						'order' => $this->order,
					);
				}
			};
		}

		/**
		 * @param array<string, array<string, mixed>> $touched Items tocados.
		 * @param array<string, mixed>                $changes Cambios.
		 * @param array<int, string>                  $order   Orden final.
		 */
		public function apply_changes( array $touched, array $changes, array $order ): void {
			self::$applied[] = array( $touched, $changes, $order );

			if ( 'ignore' === self::$mode ) {
				return;
			}

			foreach ( (array) $changes['deleted'] as $id ) {
				unset( self::$items[ $id ] );
			}

			foreach ( $touched as $id => $item ) {
				self::$items[ $id ] = 'altered' === self::$mode ? array_merge( $item, array( 'label' => 'otra' ) ) : $item;
			}

			self::$order = 'wrong_order' === self::$mode ? array_reverse( $order ) : $order;
		}
	}
}
