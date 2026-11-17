<?php
/**
 * Dobles mínimos de los repositorios de clases globales y variables de Elementor.
 *
 * Igual que {@see ElementorDesignFakes.php}: sólo para pruebas de proceso aparte.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace Elementor\Modules\GlobalClasses {
	final class Global_Classes_Repository {
		/** @var \Throwable|null */
		public static ?\Throwable $fail = null;

		public static function make( object $kit ): self {
			return new self();
		}

		/** @return array<string, string> */
		public function all_labels(): array {
			if ( null !== self::$fail ) {
				throw self::$fail;
			}

			return array( 'g-hero' => 'Hero' );
		}
	}
}

namespace Elementor\Modules\Variables\Storage {
	final class Fake_Variable {
		public function __construct( private string $id, private bool $broken = false, private bool $deleted = false ) {}

		public function id(): string {
			return $this->id;
		}

		public function type(): string {
			return 'global-color-variable';
		}

		public function label(): string {
			return 'Color ' . $this->id;
		}

		public function value() {
			if ( $this->broken ) {
				throw new \RuntimeException( 'valor ilegible' );
			}

			return '#ff0000';
		}

		public function is_deleted(): bool {
			return $this->deleted;
		}
	}

	final class Variables_Repository {
		/** @var \Throwable|null */
		public static ?\Throwable $fail = null;

		/** @var array<int, Fake_Variable> */
		public static array $variables = array();

		public function __construct( object $kit ) {}

		public function load(): object {
			if ( null !== self::$fail ) {
				throw self::$fail;
			}

			return new class( self::$variables ) {
				/** @param array<int, Fake_Variable> $all */
				public function __construct( private array $all ) {}

				/** @return array<int, Fake_Variable> */
				public function all(): array {
					return $this->all;
				}
			};
		}
	}
}
