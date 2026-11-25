<?php
/**
 * set-divi-conditions (T2 de D7): esquema propio de `module.decoration.conditions` con el catálogo de S5.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\DiviConditionsSchema;

final class DiviConditionsSchemaTest extends NexoTestCase {

	private int $counter = 0;

	private function schema( bool $woo = true ): DiviConditionsSchema {
		$this->counter = 0;

		return new DiviConditionsSchema(
			static fn( string $type ): ?array => array(
				'post'       => array( 'public' => true, 'label' => 'Entradas' ),
				'page'       => array( 'public' => true, 'label' => 'Páginas' ),
				'product'    => array( 'public' => true, 'label' => 'Productos' ),
				'cookie'     => array( 'public' => true, 'label' => 'Cookies' ),
				'secreto'    => array( 'public' => false, 'label' => 'Secreto' ),
			)[ $type ] ?? null,
			static fn( string $role ): ?string => array( 'administrator' => 'Administrador', 'subscriber' => 'Suscriptor', 'editor' => 'Editor' )[ $role ] ?? null,
			static fn( int $id, string $taxonomy ): ?string => array( 'category' => array( 3 => 'Noticias', 4 => 'Avisos' ), 'post_tag' => array( 9 => 'oferta' ) )[ $taxonomy ][ $id ] ?? null,
			static fn( int $id ): ?array => array( 10 => array( 'type' => 'page', 'label' => 'Inicio' ), 11 => array( 'type' => 'page', 'label' => 'Tienda' ), 12 => array( 'type' => 'post', 'label' => 'Hola' ) )[ $id ] ?? null,
			static fn( int $id ): ?string => array( 50 => 'Camiseta', 51 => 'Gorra' )[ $id ] ?? null,
			static fn(): bool => $woo,
			function (): string {
				++$this->counter;

				return sprintf( '00000000-0000-4000-8000-%012d', $this->counter );
			}
		);
	}

	/**
	 * @param array<string, mixed> $c Condición.
	 * @return array<string, mixed>
	 */
	private function one( array $c, string $match = 'all' ): array {
		return array( 'match' => $match, 'conditions' => array( $c ) );
	}

	/**
	 * @param array<string, mixed> $c Condición.
	 * @return array<string, mixed>
	 */
	private function stored_one( array $c, string $match = 'all' ): array {
		$params = $this->schema()->validate( $this->one( $c, $match ) );
		$this->assertIsArray( $params, $params instanceof \WP_Error ? $params->get_error_message() : '' );

		return $this->schema()->stored( $params )[0];
	}

	private function assertInvalid( $result, string $path, string $needle = '' ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_conditions_invalid', $result->get_error_code(), $result->get_error_message() );
		$this->assertSame( array( 'path' => $path ), $result->get_error_data() );
		$this->assertStringStartsWith( $path . ': ', $result->get_error_message() );

		if ( '' !== $needle ) {
			$this->assertStringContainsString( $needle, $result->get_error_message() );
		}
	}

	/**
	 * @param array<string, mixed> $c Condición.
	 */
	private function assertCondInvalid( array $c, string $path, string $needle = '' ): void {
		$this->assertInvalid( $this->schema()->validate( $this->one( $c ) ), $path, $needle );
	}

	// ---- Forma guardada y todos los tipos válidos ----

	public function test_logged_in_is_stored_in_divis_exact_shape(): void {
		$this->assertSame(
			array(
				'id'                => '00000000-0000-4000-8000-000000000001',
				'conditionName'     => 'loggedInStatus',
				'conditionSettings' => array( 'displayRule' => 'loggedIn', 'enableCondition' => 'on' ),
				'operator'          => 'AND',
			),
			$this->stored_one( array( 'type' => 'logged_in', 'rule' => 'logged_in' ) )
		);
		$this->assertSame( 'loggedOut', $this->stored_one( array( 'type' => 'logged_in', 'rule' => 'logged_out' ) )['conditionSettings']['displayRule'] );
	}

	public function test_user_role_stores_roles_with_labels_and_empty_user_ids(): void {
		$stored = $this->stored_one( array( 'type' => 'user_role', 'rule' => 'is_not', 'roles' => array( 'administrator', 'editor' ) ) );

		$this->assertSame( 'userRole', $stored['conditionName'] );
		$this->assertSame(
			array(
				'displayRule'     => 'isNot',
				'userRoles'       => array( array( 'value' => 'administrator', 'label' => 'Administrador' ), array( 'value' => 'editor', 'label' => 'Editor' ) ),
				'userIds'         => '',
				'enableCondition' => 'on',
			),
			$stored['conditionSettings']
		);
	}

	public function test_date_time_before_after_store_date_and_time_with_the_full_builder_shape(): void {
		$stored = $this->stored_one( array( 'type' => 'date_time', 'rule' => 'after', 'date' => '2026-12-24', 'time' => '09:30' ) );

		$this->assertSame( 'dateTime', $stored['conditionName'] );
		$this->assertSame(
			array(
				'displayRule'                 => 'isAfter',
				'enableCondition'             => 'on',
				'date'                        => '2026-12-24',
				'time'                        => '09:30',
				'allDay'                      => 'on',
				'fromTime'                    => '00:00',
				'untilTime'                   => '00:00',
				'repeat'                      => 'off',
				'repeatFrequency'             => 'monthly',
				'repeatEnd'                   => 'never',
				'repeatUntilDate'             => '2026-12-24',
				'repeatTimes'                 => '3',
				'weekdays'                    => array(),
				'repeatFrequencySpecificDays' => 'weekly',
			),
			$stored['conditionSettings']
		);
		$this->assertSame( '00:00', $this->stored_one( array( 'type' => 'date_time', 'rule' => 'before', 'date' => '2026-01-31' ) )['conditionSettings']['time'], 'sin hora = medianoche' );
		$this->assertSame( 'isBefore', $this->stored_one( array( 'type' => 'date_time', 'rule' => 'before', 'date' => '2026-01-31' ) )['conditionSettings']['displayRule'] );
	}

	public function test_date_time_on_date_all_day_and_time_window(): void {
		$all = $this->stored_one( array( 'type' => 'date_time', 'rule' => 'on_date', 'date' => '2028-02-29' ) )['conditionSettings'];
		$this->assertSame( 'isOnSpecificDate', $all['displayRule'] );
		$this->assertSame( 'on', $all['allDay'] );

		$win = $this->stored_one( array( 'type' => 'date_time', 'rule' => 'on_date', 'date' => '2026-05-01', 'all_day' => false, 'from_time' => '09:00', 'until_time' => '17:30' ) )['conditionSettings'];
		$this->assertSame( 'off', $win['allDay'] );
		$this->assertSame( '09:00', $win['fromTime'] );
		$this->assertSame( '17:30', $win['untilTime'] );
		$this->assertSame( 'off', $win['repeat'] );
	}

	public function test_date_time_on_days_is_weekly_with_days_in_canonical_order(): void {
		$s = $this->stored_one( array( 'type' => 'date_time', 'rule' => 'on_days', 'weekdays' => array( 'saturday', 'monday', 'monday' ) ) )['conditionSettings'];

		$this->assertSame( 'isOnSpecificDays', $s['displayRule'] );
		$this->assertSame( array( 'monday', 'saturday' ), $s['weekdays'] );
		$this->assertSame( 'weekly', $s['repeatFrequencySpecificDays'] );
		$this->assertSame( 'on', $s['allDay'] );
		$this->assertSame( '', $s['date'] );
	}

	public function test_post_type_categories_tags_and_posts(): void {
		$pt = $this->stored_one( array( 'type' => 'post_type', 'rule' => 'is', 'post_types' => array( 'page', 'product' ) ) );
		$this->assertSame( 'postType', $pt['conditionName'] );
		$this->assertSame( array( array( 'value' => 'page', 'label' => 'Páginas' ), array( 'value' => 'product', 'label' => 'Productos' ) ), $pt['conditionSettings']['postTypes'] );

		$cat = $this->stored_one( array( 'type' => 'categories', 'rule' => 'is', 'terms' => array( 3, 4 ) ) );
		$this->assertSame( 'categories', $cat['conditionName'] );
		$this->assertSame( array( array( 'value' => 3, 'label' => 'Noticias', 'groupSlug' => 'category' ), array( 'value' => 4, 'label' => 'Avisos', 'groupSlug' => 'category' ) ), $cat['conditionSettings']['categories'] );

		$tag = $this->stored_one( array( 'type' => 'tags', 'rule' => 'is_not', 'terms' => array( 9 ) ) );
		$this->assertSame( 'tags', $tag['conditionName'] );
		$this->assertSame( array( array( 'value' => 9, 'label' => 'oferta', 'groupSlug' => 'post_tag' ) ), $tag['conditionSettings']['tags'] );
		$this->assertSame( 'isNot', $tag['conditionSettings']['displayRule'] );

		$posts = $this->stored_one( array( 'type' => 'posts', 'rule' => 'is', 'post_type' => 'page', 'posts' => array( 10, 11 ) ) );
		$this->assertSame( 'page', $posts['conditionName'], 'Divi guarda los posts concretos con el tipo de contenido como nombre' );
		$this->assertSame(
			array(
				'displayRule'   => 'is',
				'dynamicPosts'  => array( array( 'value' => 10, 'label' => 'Inicio' ), array( 'value' => 11, 'label' => 'Tienda' ) ),
				'postTypeLabel' => 'Páginas',
				'enableCondition' => 'on',
			),
			$posts['conditionSettings']
		);
	}

	public function test_browser_and_operating_system_use_divis_values(): void {
		$b = $this->stored_one( array( 'type' => 'browser', 'rule' => 'is_not', 'browsers' => array( 'safari', 'chrome' ) ) );
		$this->assertSame( 'browser', $b['conditionName'] );
		$this->assertSame( array( 'displayRule' => 'isNot', 'browsers' => array( 'safari', 'chrome' ), 'enableCondition' => 'on' ), $b['conditionSettings'] );

		$o = $this->stored_one( array( 'type' => 'operating_system', 'rule' => 'is', 'systems' => array( 'macos', 'iphone' ) ) );
		$this->assertSame( 'operatingSystem', $o['conditionName'] );
		$this->assertSame( array( 'displayRule' => 'is', 'operatingSystems' => array( 'macos', 'iphone' ), 'enableCondition' => 'on' ), $o['conditionSettings'] );
	}

	public function test_url_parameter_with_and_without_value(): void {
		$eq = $this->stored_one( array( 'type' => 'url_parameter', 'rule' => 'equals', 'name' => 'promo', 'value' => 'black friday' ) );
		$this->assertSame( 'urlParameter', $eq['conditionName'] );
		$this->assertSame(
			array( 'selectUrlParameter' => 'specificUrlParameter', 'displayRule' => 'equals', 'urlParameterName' => 'promo', 'urlParameterValue' => 'black friday', 'enableCondition' => 'on' ),
			$eq['conditionSettings']
		);

		$ex = $this->stored_one( array( 'type' => 'url_parameter', 'rule' => 'not_exists', 'name' => 'utm_source' ) );
		$this->assertSame( 'doesNotExist', $ex['conditionSettings']['displayRule'] );
		$this->assertSame( '', $ex['conditionSettings']['urlParameterValue'] );

		foreach ( array( 'exists' => 'exist', 'not_equals' => 'doesNotEqual', 'contains' => 'contains', 'not_contains' => 'doesNotContain' ) as $rule => $divi ) {
			$c = array( 'type' => 'url_parameter', 'rule' => $rule, 'name' => 'a' );

			if ( 'exists' !== $rule ) {
				$c['value'] = 'x';
			}

			$this->assertSame( $divi, $this->stored_one( $c )['conditionSettings']['displayRule'] );
		}
	}

	public function test_woocommerce_types(): void {
		$cart = $this->stored_one( array( 'type' => 'cart_contents', 'rule' => 'is_empty' ) );
		$this->assertSame( 'cartContents', $cart['conditionName'] );
		$this->assertSame( array( 'displayRule' => 'isEmpty', 'enableCondition' => 'on' ), $cart['conditionSettings'] );

		$spec = $this->stored_one( array( 'type' => 'cart_contents', 'rule' => 'not_has_product', 'products' => array( 50 ) ) );
		$this->assertSame( 'doesNotHaveSpecificProduct', $spec['conditionSettings']['displayRule'] );
		$this->assertSame( array( array( 'value' => 50, 'label' => 'Camiseta' ) ), $spec['conditionSettings']['products'] );

		$stock = $this->stored_one( array( 'type' => 'product_stock', 'rule' => 'out_of_stock', 'products' => array( 50, 51 ) ) );
		$this->assertSame( 'productStock', $stock['conditionName'] );
		$this->assertSame( 'isOutOfStock', $stock['conditionSettings']['displayRule'] );

		$buy = $this->stored_one( array( 'type' => 'product_purchase', 'rule' => 'has_bought' ) );
		$this->assertSame( 'productPurchase', $buy['conditionName'] );
		$this->assertSame( 'hasBoughtProduct', $buy['conditionSettings']['displayRule'] );
		$this->assertSame( 'hasNotBoughtSpecificProduct', $this->stored_one( array( 'type' => 'product_purchase', 'rule' => 'has_not_bought_product', 'products' => array( 51 ) ) )['conditionSettings']['displayRule'] );
	}

	// ---- match, tope, vacío, ids ----

	public function test_match_maps_to_one_operator_on_every_condition(): void {
		$conds = array(
			array( 'type' => 'logged_in', 'rule' => 'logged_in' ),
			array( 'type' => 'user_role', 'rule' => 'is', 'roles' => array( 'editor' ) ),
		);

		foreach ( array( 'all' => 'AND', 'any' => 'OR' ) as $match => $operator ) {
			$params = $this->schema()->validate( array( 'match' => $match, 'conditions' => $conds ) );
			$this->assertIsArray( $params );
			$this->assertSame( array( $operator, $operator ), array_column( $this->schema()->stored( $params ), 'operator' ) );
			$this->assertSame( array( 'on', 'on' ), array_column( array_column( $this->schema()->stored( $params ), 'conditionSettings' ), 'enableCondition' ) );
		}
	}

	public function test_match_is_required_and_closed(): void {
		$c = array( 'type' => 'logged_in', 'rule' => 'logged_in' );
		$this->assertInvalid( $this->schema()->validate( array( 'conditions' => array( $c ) ) ), 'match' );
		$this->assertInvalid( $this->schema()->validate( array( 'match' => 'ALL', 'conditions' => array( $c ) ) ), 'match' );
		$this->assertInvalid( $this->schema()->validate( array( 'match' => 'AND', 'conditions' => array( $c ) ) ), 'match' );
	}

	public function test_empty_list_means_remove_all(): void {
		$params = $this->schema()->validate( array( 'match' => 'all', 'conditions' => array() ) );
		$this->assertSame( array( 'match' => 'all', 'conditions' => array() ), $params );
		$this->assertSame( array(), $this->schema()->stored( $params ) );
		$this->assertIsArray( $this->schema()->validate( array( 'conditions' => array() ) ), 'sin match también quita' );
		$this->assertNull( $this->schema()->valid_stored( array() ), 'una lista vacía no es una lista del conector' );
	}

	public function test_at_most_five_conditions(): void {
		$c = array( 'type' => 'logged_in', 'rule' => 'logged_in' );
		$this->assertIsArray( $this->schema()->validate( array( 'match' => 'all', 'conditions' => array_fill( 0, 5, $c ) ) ) );
		$this->assertInvalid( $this->schema()->validate( array( 'match' => 'all', 'conditions' => array_fill( 0, 6, $c ) ) ), 'conditions', '5' );
	}

	public function test_top_level_shape_errors(): void {
		$this->assertInvalid( $this->schema()->validate( null ), 'conditions' );
		$this->assertInvalid( $this->schema()->validate( array() ), 'conditions' );
		$this->assertInvalid( $this->schema()->validate( 'x' ), 'conditions' );
		$this->assertInvalid( $this->schema()->validate( array( array( 'type' => 'logged_in' ) ) ), 'conditions' );
		$this->assertInvalid( $this->schema()->validate( array( 'match' => 'all' ) ), 'conditions' );
		$this->assertInvalid( $this->schema()->validate( array( 'match' => 'all', 'conditions' => array( 'a' => array() ) ) ), 'conditions' );
		$this->assertInvalid( $this->schema()->validate( array( 'match' => 'all', 'conditions' => array(), 'extra' => 1 ) ), 'extra' );
		$this->assertInvalid( $this->schema()->validate( array( 'match' => 'all', 'conditions' => array( 'logged_in' ) ) ), 'conditions.0' );
	}

	public function test_ids_are_unique_uuids_and_kept_for_unchanged_positions(): void {
		$schema = $this->schema();
		$a      = array( 'type' => 'logged_in', 'rule' => 'logged_in' );
		$b      = array( 'type' => 'user_role', 'rule' => 'is', 'roles' => array( 'editor' ) );
		$first  = $schema->stored( $schema->validate( array( 'match' => 'all', 'conditions' => array( $a, $b ) ) ) );

		$this->assertSame( array( '00000000-0000-4000-8000-000000000001', '00000000-0000-4000-8000-000000000002' ), array_column( $first, 'id' ) );

		$changed = $schema->stored( $schema->validate( array( 'match' => 'all', 'conditions' => array( $a, array( 'type' => 'user_role', 'rule' => 'is', 'roles' => array( 'subscriber' ) ) ) ) ), $first );
		$this->assertSame( $first[0]['id'], $changed[0]['id'], 'la condición que no cambia conserva su id' );
		$this->assertNotSame( $first[1]['id'], $changed[1]['id'], 'la que cambia recibe otro' );
		$this->assertNotContains( $changed[1]['id'], array( $first[0]['id'], $first[1]['id'] ) );

		$swapped = $schema->stored( $schema->validate( array( 'match' => 'all', 'conditions' => array( $b, $a ) ) ), $first );
		$this->assertNotContains( $swapped[0]['id'], array( $first[0]['id'] ), 'no se reutiliza el id de otra posición' );

		$mode = $schema->stored( $schema->validate( array( 'match' => 'any', 'conditions' => array( $a ) ) ), $first );
		$this->assertNotSame( $first[0]['id'], $mode[0]['id'], 'cambiar match cambia el operador: no es la misma condición' );

		foreach ( array_merge( $first, $changed, $swapped ) as $entry ) {
			$this->assertMatchesRegularExpression( DiviConditionsSchema::ID_PATTERN, $entry['id'] );
		}
	}

	public function test_foreign_ids_in_the_existing_list_are_never_reused(): void {
		$schema = $this->schema();
		$params = $schema->validate( $this->one( array( 'type' => 'logged_in', 'rule' => 'logged_in' ) ) );
		$old    = array( array( 'id' => 'no-es-uuid', 'conditionName' => 'loggedInStatus', 'conditionSettings' => array( 'displayRule' => 'loggedIn', 'enableCondition' => 'on' ), 'operator' => 'AND' ) );

		$this->assertMatchesRegularExpression( DiviConditionsSchema::ID_PATTERN, $schema->stored( $params, $old )[0]['id'] );
		$this->assertMatchesRegularExpression( DiviConditionsSchema::ID_PATTERN, $schema->stored( $params, 'basura' )[0]['id'] );
	}

	public function test_a_repeating_id_generator_still_yields_unique_ids(): void {
		$schema = new DiviConditionsSchema( null, null, null, null, null, static fn(): bool => true, static fn(): string => '00000000-0000-4000-8000-000000000009' );
		$c      = array( 'type' => 'logged_in', 'rule' => 'logged_in' );
		$stored = $schema->stored( $schema->validate( array( 'match' => 'all', 'conditions' => array( $c, $c ) ) ) );

		$this->assertCount( 2, array_unique( array_column( $stored, 'id' ) ) );
	}

	public function test_the_default_id_generator_makes_uuid_v4(): void {
		$this->assertMatchesRegularExpression( DiviConditionsSchema::ID_PATTERN, DiviConditionsSchema::random_id() );
		$this->assertNotSame( DiviConditionsSchema::random_id(), DiviConditionsSchema::random_id() );
	}

	// ---- Exclusiones ----

	/**
	 * @return array<string, array<int, string>>
	 */
	public static function excluded_types(): array {
		$out = array();

		foreach ( array( 'customField', 'pageVisit', 'postVisit', 'numberOfViews', 'cookie', 'categoryPage', 'tagPage', 'dateArchive', 'searchResults', 'author', 'dynamicPosts', 'device', 'disabledOn', 'bogusCondition', 'loggedInStatus', 'custom_field', '', 'LOGGED_IN' ) as $type ) {
			$out[ '' === $type ? 'empty' : $type ] = array( $type );
		}

		return $out;
	}

	/**
	 * @dataProvider excluded_types
	 */
	public function test_types_outside_the_catalog_are_rejected_with_the_path( string $type ): void {
		$this->assertCondInvalid( array( 'type' => $type, 'rule' => 'is' ), 'conditions.0.type', 'logged_in' );
	}

	public function test_a_missing_or_non_string_type_is_rejected(): void {
		$this->assertCondInvalid( array( 'rule' => 'is' ), 'conditions.0.type' );
		$this->assertCondInvalid( array( 'type' => array( 'logged_in' ) ), 'conditions.0.type' );
		$this->assertCondInvalid( array( 'type' => 5 ), 'conditions.0.type' );
	}

	public function test_excluded_divi_names_get_their_own_reason(): void {
		$result = $this->schema()->validate( $this->one( array( 'type' => 'customField', 'rule' => 'is' ) ) );
		$this->assertInvalid( $result, 'conditions.0.type', 'no se admite' );
		$this->assertStringContainsString( 'Admitidos', $result->get_error_message() );
	}

	public function test_the_path_carries_the_condition_index(): void {
		$ok = array( 'type' => 'logged_in', 'rule' => 'logged_in' );
		$this->assertInvalid(
			$this->schema()->validate( array( 'match' => 'all', 'conditions' => array( $ok, $ok, array( 'type' => 'user_role', 'rule' => 'is', 'roles' => array( 'fantasma' ) ) ) ) ),
			'conditions.2.roles'
		);
	}

	public function test_woocommerce_types_are_rejected_without_woocommerce(): void {
		foreach ( array( 'cart_contents', 'product_stock', 'product_purchase' ) as $type ) {
			$result = $this->schema( false )->validate( $this->one( array( 'type' => $type, 'rule' => 'is_empty' ) ) );
			$this->assertInvalid( $result, 'conditions.0.type', 'WooCommerce' );
		}
	}

	// ---- Valores ----

	public function test_unknown_keys_are_rejected_for_every_type(): void {
		$base = array(
			array( 'type' => 'logged_in', 'rule' => 'logged_in' ),
			array( 'type' => 'user_role', 'rule' => 'is', 'roles' => array( 'editor' ) ),
			array( 'type' => 'date_time', 'rule' => 'after', 'date' => '2026-01-01' ),
			array( 'type' => 'post_type', 'rule' => 'is', 'post_types' => array( 'page' ) ),
			array( 'type' => 'categories', 'rule' => 'is', 'terms' => array( 3 ) ),
			array( 'type' => 'tags', 'rule' => 'is', 'terms' => array( 9 ) ),
			array( 'type' => 'posts', 'rule' => 'is', 'post_type' => 'page', 'posts' => array( 10 ) ),
			array( 'type' => 'browser', 'rule' => 'is', 'browsers' => array( 'chrome' ) ),
			array( 'type' => 'operating_system', 'rule' => 'is', 'systems' => array( 'linux' ) ),
			array( 'type' => 'url_parameter', 'rule' => 'exists', 'name' => 'a' ),
			array( 'type' => 'cart_contents', 'rule' => 'is_empty' ),
			array( 'type' => 'product_stock', 'rule' => 'in_stock', 'products' => array( 50 ) ),
			array( 'type' => 'product_purchase', 'rule' => 'has_bought' ),
		);

		foreach ( $base as $c ) {
			foreach ( array( 'enableCondition', 'operator', 'id', 'conditionName', 'conditionSettings', 'displayRule', 'enable_condition' ) as $extra ) {
				$this->assertCondInvalid( $c + array( $extra => 'off' ), 'conditions.0.' . $extra );
			}
		}
	}

	public function test_a_rule_outside_the_closed_list_is_rejected(): void {
		$this->assertCondInvalid( array( 'type' => 'logged_in', 'rule' => 'loggedIn' ), 'conditions.0.rule' );
		$this->assertCondInvalid( array( 'type' => 'logged_in' ), 'conditions.0.rule' );
		$this->assertCondInvalid( array( 'type' => 'user_role', 'rule' => 'isNot', 'roles' => array( 'editor' ) ), 'conditions.0.rule' );
		$this->assertCondInvalid( array( 'type' => 'date_time', 'rule' => 'isFirstDayOfMonth' ), 'conditions.0.rule' );
		$this->assertCondInvalid( array( 'type' => 'date_time', 'rule' => 'not_on_date', 'date' => '2026-01-01' ), 'conditions.0.rule' );
		$this->assertCondInvalid( array( 'type' => 'url_parameter', 'rule' => 'exist', 'name' => 'a' ), 'conditions.0.rule' );
		$this->assertCondInvalid( array( 'type' => 'cart_contents', 'rule' => array( 'is_empty' ) ), 'conditions.0.rule' );
	}

	public function test_lists_must_be_non_empty_known_and_capped(): void {
		$this->assertCondInvalid( array( 'type' => 'user_role', 'rule' => 'is', 'roles' => array() ), 'conditions.0.roles' );
		$this->assertCondInvalid( array( 'type' => 'user_role', 'rule' => 'is' ), 'conditions.0.roles' );
		$this->assertCondInvalid( array( 'type' => 'user_role', 'rule' => 'is', 'roles' => 'editor' ), 'conditions.0.roles' );
		$this->assertCondInvalid( array( 'type' => 'user_role', 'rule' => 'is', 'roles' => array( 'a' => 'editor' ) ), 'conditions.0.roles' );
		$this->assertCondInvalid( array( 'type' => 'user_role', 'rule' => 'is', 'roles' => array( 'fantasma' ) ), 'conditions.0.roles' );
		$this->assertCondInvalid( array( 'type' => 'user_role', 'rule' => 'is', 'roles' => array( array( 'editor' ) ) ), 'conditions.0.roles' );
		$this->assertCondInvalid( array( 'type' => 'post_type', 'rule' => 'is', 'post_types' => array( 'secreto' ) ), 'conditions.0.post_types' );
		$this->assertCondInvalid( array( 'type' => 'post_type', 'rule' => 'is', 'post_types' => array( 'nada' ) ), 'conditions.0.post_types' );
		$this->assertCondInvalid( array( 'type' => 'post_type', 'rule' => 'is', 'post_types' => array( 'PAGE' ) ), 'conditions.0.post_types' );
		$this->assertCondInvalid( array( 'type' => 'categories', 'rule' => 'is', 'terms' => array( 99 ) ), 'conditions.0.terms' );
		$this->assertCondInvalid( array( 'type' => 'categories', 'rule' => 'is', 'terms' => array( 9 ) ), 'conditions.0.terms', 'category' );
		$this->assertCondInvalid( array( 'type' => 'tags', 'rule' => 'is', 'terms' => array( 3 ) ), 'conditions.0.terms' );
		$this->assertCondInvalid( array( 'type' => 'categories', 'rule' => 'is', 'terms' => array( '3' ) ), 'conditions.0.terms' );
		$this->assertCondInvalid( array( 'type' => 'categories', 'rule' => 'is', 'terms' => array( 0 ) ), 'conditions.0.terms' );
		$this->assertCondInvalid( array( 'type' => 'categories', 'rule' => 'is', 'terms' => array( 3.0 ) ), 'conditions.0.terms' );
		$this->assertCondInvalid( array( 'type' => 'browser', 'rule' => 'is', 'browsers' => array( 'netscape' ) ), 'conditions.0.browsers' );
		$this->assertCondInvalid( array( 'type' => 'browser', 'rule' => 'is', 'browsers' => array( 'Chrome' ) ), 'conditions.0.browsers' );
		$this->assertCondInvalid( array( 'type' => 'operating_system', 'rule' => 'is', 'systems' => array( 'beos' ) ), 'conditions.0.systems' );
		$this->assertCondInvalid( array( 'type' => 'operating_system', 'rule' => 'is' ), 'conditions.0.systems' );
	}

	public function test_caps_on_list_sizes(): void {
		$ok = $this->schema()->validate( $this->one( array( 'type' => 'user_role', 'rule' => 'is', 'roles' => array( 'administrator', 'editor', 'subscriber' ) ) ) );
		$this->assertIsArray( $ok );

		$many = new DiviConditionsSchema(
			static fn(): array => array( 'public' => true, 'label' => 'x' ),
			static fn(): string => 'Rol',
			static fn(): string => 'T',
			static fn( int $id ): array => array( 'type' => 'page', 'label' => 'P' ),
			static fn(): string => 'Prod',
			static fn(): bool => true
		);

		$this->assertIsArray( $many->validate( $this->one( array( 'type' => 'categories', 'rule' => 'is', 'terms' => range( 1, 20 ) ) ) ) );
		$this->assertInvalid( $many->validate( $this->one( array( 'type' => 'categories', 'rule' => 'is', 'terms' => range( 1, 21 ) ) ) ), 'conditions.0.terms', '20' );
		$this->assertInvalid( $many->validate( $this->one( array( 'type' => 'posts', 'rule' => 'is', 'post_type' => 'page', 'posts' => range( 1, 21 ) ) ) ), 'conditions.0.posts', '20' );
		$this->assertInvalid( $many->validate( $this->one( array( 'type' => 'product_stock', 'rule' => 'in_stock', 'products' => range( 1, 21 ) ) ) ), 'conditions.0.products', '20' );
		$this->assertInvalid( $many->validate( $this->one( array( 'type' => 'user_role', 'rule' => 'is', 'roles' => array_map( static fn( $i ) => 'r' . $i, range( 1, 11 ) ) ) ) ), 'conditions.0.roles', '10' );
	}

	public function test_duplicates_in_a_list_collapse(): void {
		$params = $this->schema()->validate( $this->one( array( 'type' => 'categories', 'rule' => 'is', 'terms' => array( 3, 3, 4 ) ) ) );
		$this->assertSame( array( 3, 4 ), $params['conditions'][0]['terms'] );
	}

	public function test_posts_need_a_published_post_of_that_public_type(): void {
		$this->assertCondInvalid( array( 'type' => 'posts', 'rule' => 'is', 'post_type' => 'page', 'posts' => array( 12 ) ), 'conditions.0.posts', 'page' );
		$this->assertCondInvalid( array( 'type' => 'posts', 'rule' => 'is', 'post_type' => 'page', 'posts' => array( 999 ) ), 'conditions.0.posts' );
		$this->assertCondInvalid( array( 'type' => 'posts', 'rule' => 'is', 'post_type' => 'page', 'posts' => array( '10' ) ), 'conditions.0.posts' );
		$this->assertCondInvalid( array( 'type' => 'posts', 'rule' => 'is', 'post_type' => 'secreto', 'posts' => array( 10 ) ), 'conditions.0.post_type' );
		$this->assertCondInvalid( array( 'type' => 'posts', 'rule' => 'is', 'post_type' => 'attachment', 'posts' => array( 10 ) ), 'conditions.0.post_type' );
		$this->assertCondInvalid( array( 'type' => 'posts', 'rule' => 'is', 'posts' => array( 10 ) ), 'conditions.0.post_type' );
		$this->assertCondInvalid( array( 'type' => 'posts', 'rule' => 'is', 'post_type' => 'page' ), 'conditions.0.posts' );
	}

	public function test_a_post_type_named_like_a_divi_condition_cannot_hold_specific_posts(): void {
		// Con el slug «cookie» Divi evaluaría la condición de cookies, no «posts concretos».
		$this->assertCondInvalid( array( 'type' => 'posts', 'rule' => 'is', 'post_type' => 'cookie', 'posts' => array( 10 ) ), 'conditions.0.post_type' );
	}

	public function test_products_are_required_only_with_specific_rules(): void {
		$this->assertCondInvalid( array( 'type' => 'cart_contents', 'rule' => 'has_product' ), 'conditions.0.products' );
		$this->assertCondInvalid( array( 'type' => 'cart_contents', 'rule' => 'has_products', 'products' => array( 50 ) ), 'conditions.0.products' );
		$this->assertCondInvalid( array( 'type' => 'product_stock', 'rule' => 'in_stock' ), 'conditions.0.products' );
		$this->assertCondInvalid( array( 'type' => 'product_stock', 'rule' => 'in_stock', 'products' => array( 77 ) ), 'conditions.0.products' );
		$this->assertCondInvalid( array( 'type' => 'product_purchase', 'rule' => 'has_bought', 'products' => array( 50 ) ), 'conditions.0.products' );
		$this->assertCondInvalid( array( 'type' => 'product_purchase', 'rule' => 'has_bought_product', 'products' => array() ), 'conditions.0.products' );
	}

	public function test_url_parameter_name_and_value_are_strict(): void {
		foreach ( array( '', 'a b', 'a.b', 'a[]', str_repeat( 'a', 41 ), 'ñ', "a\n", 5, null ) as $name ) {
			$this->assertCondInvalid( array( 'type' => 'url_parameter', 'rule' => 'exists', 'name' => $name ), 'conditions.0.name' );
		}

		$this->assertCondInvalid( array( 'type' => 'url_parameter', 'rule' => 'exists' ), 'conditions.0.name' );
		$this->assertIsArray( $this->schema()->validate( $this->one( array( 'type' => 'url_parameter', 'rule' => 'exists', 'name' => str_repeat( 'a', 40 ) ) ) ) );
	}

	public function test_url_parameter_values_are_rejected_never_sanitized(): void {
		$bad = array(
			'<b>', 'a>b', '[x]', 'a]', '$x', 'a\\b', "a\nb", "a\tb", "a\0b", "a\x7Fb", "\x1Fx", '', ' x', 'x ', 'a  b', '100%25', '%e2%82', "caf\xE9", str_repeat( 'a', 101 ), 5, null, array( 'a' ),
		);

		foreach ( $bad as $value ) {
			$this->assertCondInvalid( array( 'type' => 'url_parameter', 'rule' => 'equals', 'name' => 'p', 'value' => $value ), 'conditions.0.value' );
		}

		foreach ( array( str_repeat( 'a', 100 ), 'ñandú', 'a%b', "o'k", 'a&b=c', '100%' ) as $good ) {
			$this->assertIsArray( $this->schema()->validate( $this->one( array( 'type' => 'url_parameter', 'rule' => 'contains', 'name' => 'p', 'value' => $good ) ) ), $good );
		}

		$this->assertCondInvalid( array( 'type' => 'url_parameter', 'rule' => 'equals', 'name' => 'p' ), 'conditions.0.value' );
		$this->assertCondInvalid( array( 'type' => 'url_parameter', 'rule' => 'exists', 'name' => 'p', 'value' => 'x' ), 'conditions.0.value' );
		$this->assertCondInvalid( array( 'type' => 'url_parameter', 'rule' => 'not_exists', 'name' => 'p', 'value' => '' ), 'conditions.0.value' );
	}

	/**
	 * @return array<string, array<int, mixed>>
	 */
	public static function garbage_dates(): array {
		$out = array();

		foreach ( array( '', 'hoy', 'tomorrow', '2026-02-30', '2026-13-01', '2026-00-10', '2026-04-31', '2027-02-29', '26-01-01', '2026-1-1', '2026/01/01', '2026-01-01 ', ' 2026-01-01', '2026-01-01T10:00', '2026-01-01 10:00', '1969-12-31', '2101-01-01', '0000-00-00', '+1 day', 'first day of next month', '2026-01-01; DROP', "2026-01-01\n", '２０２６-01-01' ) as $date ) {
			$out[ json_encode( $date ) ] = array( $date );
		}

		$out['int']   = array( 20260101 );
		$out['null']  = array( null );
		$out['array'] = array( array( '2026-01-01' ) );

		return $out;
	}

	/**
	 * @dataProvider garbage_dates
	 * @param mixed $date Valor.
	 */
	public function test_garbage_dates_are_rejected_for_every_rule_that_takes_a_date( $date ): void {
		foreach ( array( 'before', 'after', 'on_date' ) as $rule ) {
			$this->assertCondInvalid( array( 'type' => 'date_time', 'rule' => $rule, 'date' => $date ), 'conditions.0.date' );
		}
	}

	public function test_real_dates_are_accepted(): void {
		foreach ( array( '1970-01-01', '2000-02-29', '2024-02-29', '2100-12-31', '2026-12-31' ) as $date ) {
			$this->assertIsArray( $this->schema()->validate( $this->one( array( 'type' => 'date_time', 'rule' => 'after', 'date' => $date ) ) ), $date );
		}
	}

	/**
	 * @return array<string, array<int, mixed>>
	 */
	public static function garbage_times(): array {
		$out = array();

		foreach ( array( '', '9:00', '09', '09:0', '09:00:00', '24:00', '23:60', '99:99', '-1:00', 'ab:cd', '09:00 ', ' 09:00', '09.00', '9am', 'noon', '+1 hour', "09:00\n", '٠٩:٠٠', '11:', ':30' ) as $time ) {
			$out[ json_encode( $time ) ] = array( $time );
		}

		$out['int']   = array( 900 );
		$out['null']  = array( null );
		$out['array'] = array( array( '09:00' ) );

		return $out;
	}

	/**
	 * @dataProvider garbage_times
	 * @param mixed $time Valor.
	 */
	public function test_garbage_times_are_rejected_everywhere_a_time_is_taken( $time ): void {
		$this->assertCondInvalid( array( 'type' => 'date_time', 'rule' => 'after', 'date' => '2026-01-01', 'time' => $time ), 'conditions.0.time' );
		$this->assertCondInvalid( array( 'type' => 'date_time', 'rule' => 'on_date', 'date' => '2026-01-01', 'all_day' => false, 'from_time' => $time, 'until_time' => '23:00' ), 'conditions.0.from_time' );
		$this->assertCondInvalid( array( 'type' => 'date_time', 'rule' => 'on_days', 'weekdays' => array( 'monday' ), 'all_day' => false, 'from_time' => '01:00', 'until_time' => $time ), 'conditions.0.until_time' );
	}

	public function test_time_boundaries_are_accepted(): void {
		foreach ( array( '00:00', '00:01', '12:30', '23:59' ) as $time ) {
			$this->assertIsArray( $this->schema()->validate( $this->one( array( 'type' => 'date_time', 'rule' => 'before', 'date' => '2026-01-01', 'time' => $time ) ) ), $time );
		}
	}

	public function test_time_windows_must_be_ordered_and_only_with_all_day_false(): void {
		$base = array( 'type' => 'date_time', 'rule' => 'on_date', 'date' => '2026-01-01' );

		$this->assertCondInvalid( $base + array( 'all_day' => false ), 'conditions.0.from_time' );
		$this->assertCondInvalid( $base + array( 'all_day' => false, 'from_time' => '10:00' ), 'conditions.0.until_time' );
		$this->assertCondInvalid( $base + array( 'all_day' => false, 'from_time' => '10:00', 'until_time' => '10:00' ), 'conditions.0.until_time' );
		$this->assertCondInvalid( $base + array( 'all_day' => false, 'from_time' => '22:00', 'until_time' => '02:00' ), 'conditions.0.until_time' );
		$this->assertCondInvalid( $base + array( 'from_time' => '10:00', 'until_time' => '11:00' ), 'conditions.0.from_time' );
		$this->assertCondInvalid( $base + array( 'all_day' => 'off' ), 'conditions.0.all_day' );
		$this->assertCondInvalid( $base + array( 'all_day' => 0 ), 'conditions.0.all_day' );
		$this->assertCondInvalid( $base + array( 'all_day' => null ), 'conditions.0.all_day' );
	}

	public function test_date_time_keys_must_match_the_rule(): void {
		$this->assertCondInvalid( array( 'type' => 'date_time', 'rule' => 'after' ), 'conditions.0.date' );
		$this->assertCondInvalid( array( 'type' => 'date_time', 'rule' => 'after', 'date' => '2026-01-01', 'weekdays' => array( 'monday' ) ), 'conditions.0.weekdays' );
		$this->assertCondInvalid( array( 'type' => 'date_time', 'rule' => 'after', 'date' => '2026-01-01', 'all_day' => true ), 'conditions.0.all_day' );
		$this->assertCondInvalid( array( 'type' => 'date_time', 'rule' => 'on_date', 'date' => '2026-01-01', 'time' => '10:00' ), 'conditions.0.time' );
		$this->assertCondInvalid( array( 'type' => 'date_time', 'rule' => 'on_days', 'weekdays' => array( 'monday' ), 'date' => '2026-01-01' ), 'conditions.0.date' );
		$this->assertCondInvalid( array( 'type' => 'date_time', 'rule' => 'on_days' ), 'conditions.0.weekdays' );
		$this->assertCondInvalid( array( 'type' => 'date_time', 'rule' => 'on_days', 'weekdays' => array() ), 'conditions.0.weekdays' );
		$this->assertCondInvalid( array( 'type' => 'date_time', 'rule' => 'on_days', 'weekdays' => array( 'Monday' ) ), 'conditions.0.weekdays' );
		$this->assertCondInvalid( array( 'type' => 'date_time', 'rule' => 'on_days', 'weekdays' => array( 'lunes' ) ), 'conditions.0.weekdays' );
		$this->assertCondInvalid( array( 'type' => 'date_time', 'rule' => 'on_days', 'weekdays' => 'monday' ), 'conditions.0.weekdays' );
	}

	public function test_any_rejects_a_second_condition_of_the_same_type_that_divi_would_drop(): void {
		$role = array( 'type' => 'user_role', 'rule' => 'is', 'roles' => array( 'editor' ) );

		$this->assertInvalid( $this->schema()->validate( array( 'match' => 'any', 'conditions' => array( $role, $role ) ) ), 'conditions.1.type', 'any' );
		$this->assertIsArray( $this->schema()->validate( array( 'match' => 'all', 'conditions' => array( $role, $role ) ) ), 'con AND no hay conflicto' );

		$dates = array(
			array( 'type' => 'date_time', 'rule' => 'after', 'date' => '2026-01-01' ),
			array( 'type' => 'date_time', 'rule' => 'before', 'date' => '2026-02-01' ),
			array( 'type' => 'url_parameter', 'rule' => 'exists', 'name' => 'a' ),
			array( 'type' => 'url_parameter', 'rule' => 'exists', 'name' => 'b' ),
		);
		$this->assertIsArray( $this->schema()->validate( array( 'match' => 'any', 'conditions' => $dates ) ), 'fecha/hora y parámetro de URL están exentos' );

		$this->assertInvalid(
			$this->schema()->validate( array( 'match' => 'any', 'conditions' => array( array( 'type' => 'posts', 'rule' => 'is', 'post_type' => 'page', 'posts' => array( 10 ) ), array( 'type' => 'posts', 'rule' => 'is_not', 'post_type' => 'page', 'posts' => array( 11 ) ) ) ) ),
			'conditions.1.type'
		);
		$this->assertIsArray(
			$this->schema()->validate( array( 'match' => 'any', 'conditions' => array( array( 'type' => 'posts', 'rule' => 'is', 'post_type' => 'page', 'posts' => array( 10 ) ), array( 'type' => 'posts', 'rule' => 'is', 'post_type' => 'post', 'posts' => array( 12 ) ) ) ) ),
			'tipos de contenido distintos = nombres distintos'
		);
	}

	public function test_rejected_input_stores_nothing_and_does_not_consume_ids(): void {
		$schema = $this->schema();
		$schema->validate( $this->one( array( 'type' => 'customField', 'rule' => 'is' ) ) );
		$params = $schema->validate( $this->one( array( 'type' => 'logged_in', 'rule' => 'logged_in' ) ) );

		$this->assertSame( '00000000-0000-4000-8000-000000000001', $schema->stored( $params )[0]['id'] );
	}

	// ---- Ida y vuelta ----

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function every_type(): array {
		return array(
			array( 'type' => 'logged_in', 'rule' => 'logged_out' ),
			array( 'type' => 'user_role', 'rule' => 'is', 'roles' => array( 'administrator', 'editor' ) ),
			array( 'type' => 'date_time', 'rule' => 'after', 'date' => '2026-12-24', 'time' => '09:30' ),
			array( 'type' => 'date_time', 'rule' => 'before', 'date' => '2026-12-25' ),
			array( 'type' => 'date_time', 'rule' => 'on_date', 'date' => '2026-05-01' ),
			array( 'type' => 'date_time', 'rule' => 'on_date', 'date' => '2026-05-01', 'all_day' => false, 'from_time' => '09:00', 'until_time' => '17:30' ),
			array( 'type' => 'date_time', 'rule' => 'on_days', 'weekdays' => array( 'monday', 'friday' ) ),
			array( 'type' => 'date_time', 'rule' => 'on_days', 'weekdays' => array( 'sunday' ), 'all_day' => false, 'from_time' => '08:00', 'until_time' => '12:00' ),
			array( 'type' => 'post_type', 'rule' => 'is_not', 'post_types' => array( 'page' ) ),
			array( 'type' => 'categories', 'rule' => 'is', 'terms' => array( 3, 4 ) ),
			array( 'type' => 'tags', 'rule' => 'is', 'terms' => array( 9 ) ),
			array( 'type' => 'posts', 'rule' => 'is', 'post_type' => 'page', 'posts' => array( 10, 11 ) ),
			array( 'type' => 'browser', 'rule' => 'is', 'browsers' => array( 'firefox' ) ),
			array( 'type' => 'operating_system', 'rule' => 'is_not', 'systems' => array( 'windows' ) ),
			array( 'type' => 'url_parameter', 'rule' => 'equals', 'name' => 'promo', 'value' => 'si' ),
			array( 'type' => 'url_parameter', 'rule' => 'not_exists', 'name' => 'promo' ),
			array( 'type' => 'cart_contents', 'rule' => 'has_products' ),
			array( 'type' => 'cart_contents', 'rule' => 'has_product', 'products' => array( 50 ) ),
			array( 'type' => 'product_stock', 'rule' => 'in_stock', 'products' => array( 51 ) ),
			array( 'type' => 'product_purchase', 'rule' => 'has_not_bought' ),
			array( 'type' => 'product_purchase', 'rule' => 'has_bought_product', 'products' => array( 50, 51 ) ),
		);
	}

	public function test_valid_stored_recognises_exactly_what_stored_writes_for_every_type_and_match(): void {
		foreach ( $this->every_type() as $c ) {
			foreach ( array( 'all', 'any' ) as $match ) {
				$schema = $this->schema();
				$params = $schema->validate( $this->one( $c, $match ) );
				$this->assertIsArray( $params, json_encode( $c ) );
				$stored = $schema->stored( $params );

				$this->assertSame( $params, $schema->valid_stored( $stored ), json_encode( $c ) . ' ' . $match );
			}
		}
	}

	public function test_valid_stored_roundtrips_a_full_list_and_ignores_labels(): void {
		$schema = $this->schema();
		$params = $schema->validate( array( 'match' => 'any', 'conditions' => array( $this->every_type()[0], $this->every_type()[2], $this->every_type()[11], $this->every_type()[14] ) ) );
		$stored = $schema->stored( $params );

		$this->assertSame( $params, $schema->valid_stored( $stored ) );

		$stored[1]['conditionSettings']['dynamicPosts'] = null;
		$this->assertNull( $schema->valid_stored( $stored ) );

		$relabeled                                            = $schema->stored( $params );
		$relabeled[2]['conditionSettings']['dynamicPosts'][0]['label'] = 'Otro título';
		$relabeled[2]['conditionSettings']['postTypeLabel']            = 'Pages';
		$this->assertSame( $params, $schema->valid_stored( $relabeled ), 'las etiquetas cambian con el idioma y el título' );
	}

	public function test_valid_stored_rejects_anything_else(): void {
		$schema = $this->schema();
		$good   = $schema->stored( $schema->validate( array( 'match' => 'all', 'conditions' => array( array( 'type' => 'user_role', 'rule' => 'is', 'roles' => array( 'editor' ) ), array( 'type' => 'logged_in', 'rule' => 'logged_in' ) ) ) ) );
		$this->assertIsArray( $schema->valid_stored( $good ) );

		$mutations = array(
			'enableCondition off'   => static function ( array $v ): array {
				$v[0]['conditionSettings']['enableCondition'] = 'off';
				return $v;
			},
			'operador mezclado'     => static function ( array $v ): array {
				$v[1]['operator'] = 'OR';
				return $v;
			},
			'operador raro'         => static function ( array $v ): array {
				$v[0]['operator'] = 'and';
				return $v;
			},
			'sin operador'          => static function ( array $v ): array {
				unset( $v[0]['operator'] );
				return $v;
			},
			'ajuste de más'         => static function ( array $v ): array {
				$v[0]['conditionSettings']['extra'] = 'x';
				return $v;
			},
			'ajuste de menos'       => static function ( array $v ): array {
				unset( $v[0]['conditionSettings']['userIds'] );
				return $v;
			},
			'userIds con valor'     => static function ( array $v ): array {
				$v[0]['conditionSettings']['userIds'] = '1,2';
				return $v;
			},
			'clave de más'          => static function ( array $v ): array {
				$v[0]['extra'] = 1;
				return $v;
			},
			'rol inexistente'       => static function ( array $v ): array {
				$v[0]['conditionSettings']['userRoles'][0]['value'] = 'fantasma';
				return $v;
			},
			'id ajeno'              => static function ( array $v ): array {
				$v[0]['id'] = 'abc';
				return $v;
			},
			'id repetido'           => static function ( array $v ): array {
				$v[1]['id'] = $v[0]['id'];
				return $v;
			},
			'id mayúsculas'         => static function ( array $v ): array {
				$v[0]['id'] = '0000000A-0000-4000-8000-000000000001';
				return $v;
			},
			'nombre desconocido'    => static function ( array $v ): array {
				$v[0]['conditionName'] = 'bogusCondition';
				return $v;
			},
			'nombre excluido'       => static function ( array $v ): array {
				$v[0]['conditionName'] = 'customField';
				return $v;
			},
			'lista con claves'      => static function ( array $v ): array {
				return array( 'a' => $v[0] );
			},
			'una condición ajena'   => static function ( array $v ): array {
				$v[] = array( 'id' => '00000000-0000-4000-8000-0000000000aa', 'conditionName' => 'cookie', 'conditionSettings' => array( 'displayRule' => 'cookieExists', 'cookieName' => 'x', 'enableCondition' => 'on' ), 'operator' => 'AND' );
				return $v;
			},
			'más de cinco'          => static function ( array $v ): array {
				return array_merge( $v, $v, $v );
			},
			'orden de claves cambia' => static function ( array $v ): array {
				$v[0]['conditionSettings'] = array_reverse( $v[0]['conditionSettings'], true );
				return $v;
			},
		);

		foreach ( $mutations as $name => $mutate ) {
			$bad = $mutate( $good );

			// El orden de claves no cambia el significado en JSON, pero la salida es la de Divi: se acepta si es el mismo contenido.
			if ( 'orden de claves cambia' === $name ) {
				$this->assertIsArray( $schema->valid_stored( $bad ), $name );
				continue;
			}

			$this->assertNull( $schema->valid_stored( $bad ), $name );
		}

		foreach ( array( null, 'x', 5, array(), array( 'a' ), array( array() ), array( null ) ) as $junk ) {
			$this->assertNull( $schema->valid_stored( $junk ) );
		}
	}

	public function test_valid_stored_rejects_a_value_that_is_no_longer_valid_on_this_site(): void {
		$schema = $this->schema();
		$stored = $schema->stored( $schema->validate( $this->one( array( 'type' => 'categories', 'rule' => 'is', 'terms' => array( 3 ) ) ) ) );
		$stored[0]['conditionSettings']['categories'][0]['value'] = 99;

		$this->assertNull( $schema->valid_stored( $stored ), 'el término ya no existe' );

		$stored = $schema->stored( $schema->validate( $this->one( array( 'type' => 'cart_contents', 'rule' => 'is_empty' ) ) ) );
		$this->assertNull( $this->schema( false )->valid_stored( $stored ), 'WooCommerce ya no está activo' );
	}

	public function test_valid_stored_rejects_type_coerced_values(): void {
		$schema = $this->schema();
		$stored = $schema->stored( $schema->validate( $this->one( array( 'type' => 'categories', 'rule' => 'is', 'terms' => array( 3 ) ) ) ) );
		$stored[0]['conditionSettings']['categories'][0]['value'] = '3';

		$this->assertNull( $schema->valid_stored( $stored ) );
	}

	// ---- Resumen de las ajenas ----

	public function test_summary_of_our_own_list(): void {
		$schema = $this->schema();
		$stored = $schema->stored( $schema->validate( array( 'match' => 'all', 'conditions' => array( $this->every_type()[1], $this->every_type()[14] ) ) ) );
		$sum    = $schema->summary( $stored );

		$this->assertTrue( $sum['ours'] );
		$this->assertSame( 'all', $sum['match'] );
		$this->assertSame( 2, $sum['total'] );
		$this->assertSame( 'userRole', $sum['conditions'][0]['type'] );
		$this->assertSame( 'AND', $sum['conditions'][0]['operator'] );
		$this->assertTrue( $sum['conditions'][0]['enabled'] );
		$this->assertTrue( $sum['conditions'][0]['supported'] );
		$this->assertSame( 'user_role is roles=administrator,editor', $sum['conditions'][0]['summary'] );
		$this->assertSame( 'url_parameter equals name=promo', $sum['conditions'][1]['summary'], 'el valor del parámetro no se repite' );
	}

	public function test_summary_of_foreign_entries_shows_only_the_name_for_unsupported_types(): void {
		$foreign = array(
			array(
				'id'                => 'vb-1',
				'conditionName'     => 'customField',
				'conditionSettings' => array( 'displayRule' => 'is', 'selectedFieldName' => '_secret_key', 'selectedFieldValue' => 'TOP-SECRET', 'enableCondition' => 'on' ),
				'operator'          => 'OR',
			),
			array(
				'id'                => 'vb-2',
				'conditionName'     => 'cookie',
				'conditionSettings' => array( 'displayRule' => 'cookieExists', 'cookieName' => 'sess_abc', 'cookieValue' => '<script>', 'enableCondition' => 'off' ),
				'operator'          => 'AND',
			),
			array( 'id' => 'vb-3', 'conditionName' => 'bogus name!<x>', 'conditionSettings' => array( 'x' => 'LEAK' ), 'operator' => 'OR' ),
			'basura',
			array( 'conditionName' => str_repeat( 'a', 41 ) ),
			array( 'id' => 'vb-4', 'conditionName' => 'numberOfViews', 'conditionSettings' => 'LEAK', 'operator' => array( 'LEAK' ) ),
		);

		$sum = $this->schema()->summary( $foreign );
		$this->assertFalse( $sum['ours'] );
		$this->assertNull( $sum['match'], 'operadores mezclados' );
		$this->assertSame( 6, $sum['total'] );
		$this->assertSame( array( 'customField', 'cookie', 'unknown', 'unknown', 'unknown', 'numberOfViews' ), array_column( $sum['conditions'], 'type' ) );
		$this->assertSame( array( 'customField', 'cookie', 'unknown', 'unknown', 'unknown', 'numberOfViews' ), array_column( $sum['conditions'], 'summary' ) );
		$this->assertSame( array( false, false, false, false, false, false ), array_column( $sum['conditions'], 'supported' ) );
		$this->assertSame( array( true, false, true, true, true, true ), array_column( $sum['conditions'], 'enabled' ) );
		$this->assertSame( array( 'OR', 'AND', 'OR', null, null, null ), array_column( $sum['conditions'], 'operator' ) );

		$json = (string) json_encode( $sum );

		foreach ( array( 'TOP-SECRET', '_secret_key', 'sess_abc', '<script>', 'LEAK' ) as $leak ) {
			$this->assertStringNotContainsString( $leak, $json );
		}
	}

	public function test_summary_of_a_vb_made_supported_type_is_marked_foreign_but_described(): void {
		$vb = array(
			array(
				'id'                => 'a1b2',
				'conditionName'     => 'userRole',
				'conditionSettings' => array( 'displayRule' => 'is', 'userRoles' => array( array( 'value' => 'administrator', 'label' => '<img src=x onerror=1>' ) ), 'userIds' => '1,2', 'enableCondition' => 'on' ),
				'operator'          => 'OR',
			),
			array(
				'id'                => 'c3',
				'conditionName'     => 'page',
				'conditionSettings' => array( 'displayRule' => 'isNot', 'dynamicPosts' => array( array( 'value' => 7, 'label' => '"><svg>' ) ), 'postTypeLabel' => 'Páginas' ),
				'operator'          => 'OR',
			),
		);

		$sum = $this->schema()->summary( $vb );
		$this->assertFalse( $sum['ours'], 'id que no es el del conector' );
		$this->assertSame( 'any', $sum['match'] );
		$this->assertSame( 'user_role is roles=administrator', $sum['conditions'][0]['summary'] );
		$this->assertSame( 'posts is_not posts=7 post_type=page', $sum['conditions'][1]['summary'] );
		$this->assertStringNotContainsString( 'svg', (string) json_encode( $sum ) );
		$this->assertStringNotContainsString( 'img', (string) json_encode( $sum ) );
	}

	public function test_summary_of_non_lists_and_big_lists_is_safe(): void {
		foreach ( array( null, 'x', 5, array(), array( 'a' => 1 ) ) as $junk ) {
			$sum = $this->schema()->summary( $junk );
			$this->assertSame( array( 'ours' => false, 'match' => null, 'total' => 0, 'conditions' => array() ), $sum );
		}

		$many = array_fill( 0, 200, array( 'conditionName' => 'cookie' ) );
		$sum  = $this->schema()->summary( $many );
		$this->assertSame( 200, $sum['total'] );
		$this->assertCount( 50, $sum['conditions'], 'el resumen no crece sin tope' );
	}

	public function test_a_disabled_foreign_condition_is_reported_as_disabled(): void {
		$sum = $this->schema()->summary( array( array( 'id' => 'x', 'conditionName' => 'loggedInStatus', 'conditionSettings' => array( 'displayRule' => 'loggedIn', 'enableCondition' => 'off' ), 'operator' => 'AND' ) ) );

		$this->assertFalse( $sum['conditions'][0]['enabled'] );
		$this->assertFalse( $sum['ours'] );
	}

	// ---- Producción ----

	public function test_the_catalog_constants_cover_the_d2_modules_that_support_conditions(): void {
		$this->assertContains( 'divi/section', DiviConditionsSchema::MODULES );
		$this->assertContains( 'divi/video', DiviConditionsSchema::MODULES );
		$this->assertNotContains( 'divi/global-layout', DiviConditionsSchema::MODULES );
		$this->assertSame( 13, count( DiviConditionsSchema::TYPES ) );
		$this->assertSame( 5, DiviConditionsSchema::MAX_CONDITIONS );
	}
}
