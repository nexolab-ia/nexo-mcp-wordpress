<?php
/**
 * Funciones y clases mínimas de WordPress que usan las fuentes de producción de DiviConditionsSchema y
 * DiviConditionsCatalog (roles, tipos de contenido, términos, productos). Sólo se incluye en pruebas de proceso aparte:
 * lo que cada prueba quiera que «exista» está en WP_Conditions_Stub_State.
 *
 * Las firmas siguen a las de WordPress: si el código de producción llama a un método o pasa argumentos que no existen,
 * la prueba falla.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_Conditions_Stub_State' ) ) {
	final class WP_Conditions_Stub_State {
		/** @var array<string, string> */
		public static array $roles = array();
		/** @var array<string, array{public: bool, label: string}> */
		public static array $post_types = array();
		/** @var array<string, array<int, string>> taxonomía => id => nombre. */
		public static array $terms = array();
		/** @var array<int, string> id => nombre. */
		public static array $products = array();
	}
}

if ( ! class_exists( 'WP_Roles' ) ) {
	class WP_Roles {
		/**
		 * @return array<string, string>
		 */
		public function get_names() {
			return WP_Conditions_Stub_State::$roles;
		}
	}
}

if ( ! class_exists( 'WP_Post_Type' ) ) {
	class WP_Post_Type {
		/** @var bool */
		public $public = false;
		/** @var string */
		public $label = '';
		/** @var object */
		public $labels;

		public function __construct( bool $public, string $label ) {
			$this->public = $public;
			$this->label  = $label;
			$this->labels = (object) array( 'name' => $label );
		}
	}
}

if ( ! class_exists( 'WP_Term' ) ) {
	class WP_Term {
		/** @var int */
		public $term_id = 0;
		/** @var string */
		public $name = '';

		public function __construct( int $id, string $name ) {
			$this->term_id = $id;
			$this->name    = $name;
		}
	}
}

if ( ! function_exists( 'wp_roles' ) ) {
	function wp_roles() {
		return new WP_Roles();
	}
}

if ( ! function_exists( 'translate_user_role' ) ) {
	function translate_user_role( $name, $domain = 'default' ) {
		return (string) $name;
	}
}

if ( ! function_exists( 'get_post_type_object' ) ) {
	function get_post_type_object( $post_type ) {
		$info = WP_Conditions_Stub_State::$post_types[ $post_type ] ?? null;

		return null === $info ? null : new WP_Post_Type( $info['public'], $info['label'] );
	}
}

if ( ! function_exists( 'get_post_types' ) ) {
	function get_post_types( $args = array(), $output = 'names', $operator = 'and' ) {
		$out = array();

		foreach ( WP_Conditions_Stub_State::$post_types as $name => $info ) {
			if ( isset( $args['public'] ) && (bool) $args['public'] !== $info['public'] ) {
				continue;
			}

			$out[ $name ] = 'objects' === $output ? new WP_Post_Type( $info['public'], $info['label'] ) : $name;
		}

		return $out;
	}
}

if ( ! function_exists( 'get_term' ) ) {
	function get_term( $term, $taxonomy = '', $output = 'OBJECT', $filter = 'raw' ) {
		$name = WP_Conditions_Stub_State::$terms[ $taxonomy ][ (int) $term ] ?? null;

		return null === $name ? null : new WP_Term( (int) $term, $name );
	}
}

if ( ! function_exists( 'wc_get_product' ) ) {
	function wc_get_product( $the_product = false ) {
		$name = WP_Conditions_Stub_State::$products[ (int) $the_product ] ?? null;

		return null === $name ? false : new class( $name ) {
			/** @var string */
			private $name;

			public function __construct( string $name ) {
				$this->name = $name;
			}

			public function get_name() {
				return $this->name;
			}
		};
	}
}
