<?php
/**
 * Persistencia de clientes, códigos y tokens OAuth.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\OAuth;

// Toda la clase opera sobre tablas propias del plugin, sin API de Core equivalente.
// Los nombres de tabla se componen desde $wpdb->prefix, nunca desde entrada externa,
// y cada valor variable viaja como placeholder de $wpdb->prepare().
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared

/**
 * Guarda el estado del servidor de autorización sin almacenar secretos en claro.
 *
 * Los códigos y tokens se persisten únicamente como hash SHA-256. El valor
 * original existe una sola vez, en la respuesta HTTP que lo entrega. Un volcado
 * de la base de datos no permite reconstruir ninguna credencial viva.
 */
final class TokenRepository {

	/**
	 * Vigencia de un authorization code, en segundos.
	 */
	public const CODE_TTL = 60;

	/**
	 * Vigencia de un access token, en segundos.
	 */
	public const ACCESS_TTL = 3600;

	/**
	 * Vigencia de un refresh token, en segundos.
	 */
	public const REFRESH_TTL = 2592000;

	/**
	 * Margen sin actividad tras el que un cliente huérfano se descarta, en segundos.
	 *
	 * Coincide con la vigencia del token de refresco: pasado ese plazo ningún
	 * token nacido de ese cliente puede seguir vivo, de modo que la fila ya no
	 * sostiene ninguna sesión y sólo ocupa espacio.
	 */
	public const ORPHAN_CLIENT_TTL = 2592000;

	/**
	 * Tipo de token de acceso.
	 */
	public const TYPE_ACCESS = 'access';

	/**
	 * Tipo de token de refresco.
	 */
	public const TYPE_REFRESH = 'refresh';

	/**
	 * Calcula el hash de almacenamiento de un secreto.
	 *
	 * @param string $secret Valor entregado al cliente.
	 */
	public static function hash( string $secret ): string {
		return hash( 'sha256', $secret );
	}

	/**
	 * Genera un secreto aleatorio seguro en formato URL.
	 *
	 * @param int $bytes Entropía solicitada.
	 */
	public static function generate_secret( int $bytes = 32 ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Codificación base64url del secreto, no ofuscación.
		return rtrim( strtr( base64_encode( random_bytes( $bytes ) ), '+/', '-_' ), '=' );
	}

	/**
	 * Entrega el nombre de la tabla de clientes.
	 */
	public function clients_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'nexo_mcp_oauth_clients';
	}

	/**
	 * Entrega el nombre de la tabla de códigos.
	 */
	public function codes_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'nexo_mcp_oauth_codes';
	}

	/**
	 * Entrega el nombre de la tabla de tokens.
	 */
	public function tokens_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'nexo_mcp_oauth_tokens';
	}

	/**
	 * Registra o actualiza un cliente.
	 *
	 * @param string             $client_id     Identificador del cliente.
	 * @param string             $client_name   Nombre legible.
	 * @param array<int, string> $redirect_uris URIs de retorno registradas.
	 * @param string             $metadata_url  Documento CIMD de origen, si aplica.
	 */
	public function store_client( string $client_id, string $client_name, array $redirect_uris, string $metadata_url = '' ): bool {
		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Sólo se interpola el nombre de tabla ($wpdb->prefix + constante); los valores van por prepare().
		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO `' . $this->clients_table() . '` (client_id, client_name, redirect_uris, metadata_url, created_at, updated_at)
				VALUES (%s, %s, %s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE client_name = VALUES(client_name), redirect_uris = VALUES(redirect_uris), metadata_url = VALUES(metadata_url), updated_at = VALUES(updated_at)',
				$client_id,
				$client_name,
				(string) wp_json_encode( array_values( $redirect_uris ) ),
				$metadata_url,
				$now,
				$now
			)
		);

		return false !== $result;
	}

	/**
	 * Recupera un cliente registrado.
	 *
	 * @param string $client_id Identificador del cliente.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_client( string $client_id ): ?array {
		global $wpdb;

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Sólo se interpola el nombre de tabla ($wpdb->prefix + constante); los valores van por prepare().
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM `' . $this->clients_table() . '` WHERE client_id = %s',
				$client_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		$redirect_uris = json_decode( (string) $row['redirect_uris'], true );

		$row['redirect_uris'] = is_array( $redirect_uris ) ? $redirect_uris : array();

		return $row;
	}

	/**
	 * Persiste un authorization code recién emitido.
	 *
	 * @param string             $code                  Valor entregado al cliente.
	 * @param string             $client_id             Cliente solicitante.
	 * @param int                $user_id               Usuario que consintió.
	 * @param string             $redirect_uri          URI de retorno exacta.
	 * @param array<int, string> $scopes                Scopes concedidos.
	 * @param string             $audience              Recurso destinatario del token.
	 * @param string             $code_challenge        Desafío PKCE.
	 * @param string             $code_challenge_method Método PKCE.
	 */
	public function store_code(
		string $code,
		string $client_id,
		int $user_id,
		string $redirect_uri,
		array $scopes,
		string $audience,
		string $code_challenge,
		string $code_challenge_method
	): bool {
		global $wpdb;

		$inserted = $wpdb->insert(
			$this->codes_table(),
			array(
				'code_hash'             => self::hash( $code ),
				'client_id'             => $client_id,
				'user_id'               => $user_id,
				'redirect_uri'          => $redirect_uri,
				'scope'                 => ScopeMap::to_string( $scopes ),
				'resource'              => $audience,
				'code_challenge'        => $code_challenge,
				'code_challenge_method' => $code_challenge_method,
				'consumed'              => 0,
				'expires_at'            => gmdate( 'Y-m-d H:i:s', time() + self::CODE_TTL ),
				'created_at'            => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return false !== $inserted;
	}

	/**
	 * Consume un authorization code de forma atómica.
	 *
	 * La marca de consumo se aplica con un UPDATE condicional, de modo que dos
	 * canjes simultáneos no puedan obtener el mismo código: sólo la primera
	 * consulta afecta una fila.
	 *
	 * @param string $code Valor recibido del cliente.
	 *
	 * @return array<string, mixed>|null
	 */
	public function consume_code( string $code ): ?array {
		global $wpdb;

		$hash = self::hash( $code );
		$now  = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Sólo se interpola el nombre de tabla ($wpdb->prefix + constante); los valores van por prepare().
		$affected = $wpdb->query(
			$wpdb->prepare(
				'UPDATE `' . $this->codes_table() . '` SET consumed = 1, consumed_at = %s
				WHERE code_hash = %s AND consumed = 0 AND expires_at > %s',
				$now,
				$hash,
				$now
			)
		);

		if ( 1 !== $affected ) {
			return null;
		}

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Sólo se interpola el nombre de tabla ($wpdb->prefix + constante); los valores van por prepare().
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM `' . $this->codes_table() . '` WHERE code_hash = %s',
				$hash
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Revoca todos los tokens nacidos de un código concreto.
	 *
	 * OAuth 2.1 exige invalidar los tokens derivados cuando un authorization
	 * code se reutiliza, porque la reutilización delata una posible captura.
	 *
	 * @param string $code_hash Hash del código comprometido.
	 */
	public function revoke_lineage( string $code_hash ): void {
		global $wpdb;

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Sólo se interpola el nombre de tabla ($wpdb->prefix + constante); los valores van por prepare().
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE `' . $this->tokens_table() . '` SET revoked = 1 WHERE code_hash = %s',
				$code_hash
			)
		);
	}

	/**
	 * Indica si un código ya fue consumido previamente.
	 *
	 * @param string $code Valor recibido del cliente.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_consumed_code( string $code ): ?array {
		global $wpdb;

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Sólo se interpola el nombre de tabla ($wpdb->prefix + constante); los valores van por prepare().
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM `' . $this->codes_table() . '` WHERE code_hash = %s AND consumed = 1',
				self::hash( $code )
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Persiste un token emitido.
	 *
	 * @param string             $token     Valor entregado al cliente.
	 * @param string             $type      Tipo de token.
	 * @param string             $client_id Cliente titular.
	 * @param int                $user_id   Usuario representado.
	 * @param array<int, string> $scopes    Scopes concedidos.
	 * @param string             $audience  Recurso destinatario del token.
	 * @param string             $code_hash Código de origen, para trazar linaje.
	 * @param int                $ttl       Vigencia en segundos.
	 */
	public function store_token(
		string $token,
		string $type,
		string $client_id,
		int $user_id,
		array $scopes,
		string $audience,
		string $code_hash,
		int $ttl
	): bool {
		global $wpdb;

		$inserted = $wpdb->insert(
			$this->tokens_table(),
			array(
				'token_hash' => self::hash( $token ),
				'token_type' => $type,
				'client_id'  => $client_id,
				'user_id'    => $user_id,
				'scope'      => ScopeMap::to_string( $scopes ),
				'resource'   => $audience,
				'code_hash'  => $code_hash,
				'revoked'    => 0,
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + $ttl ),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return false !== $inserted;
	}

	/**
	 * Recupera un token vigente y no revocado.
	 *
	 * @param string $token Valor recibido del cliente.
	 * @param string $type  Tipo esperado.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_active_token( string $token, string $type ): ?array {
		global $wpdb;

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Sólo se interpola el nombre de tabla ($wpdb->prefix + constante); los valores van por prepare().
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM `' . $this->tokens_table() . '`
				WHERE token_hash = %s AND token_type = %s AND revoked = 0 AND expires_at > %s',
				self::hash( $token ),
				$type,
				gmdate( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Recupera un token ya rotado, para distinguir un reintento de un robo.
	 *
	 * A diferencia de `find_active_token()`, aquí interesan precisamente los
	 * revocados: un refresh que reaparece después de haberse canjeado puede ser
	 * el reenvío de una petición perdida o la copia de un ladrón, y sin mirar
	 * el registro revocado no hay forma de saber cuál de los dos es.
	 *
	 * @param string $token Valor recibido del cliente.
	 * @param string $type  Tipo esperado.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_rotated_token( string $token, string $type ): ?array {
		global $wpdb;

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Sólo se interpola el nombre de tabla ($wpdb->prefix + constante); los valores van por prepare().
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM `' . $this->tokens_table() . '`
				WHERE token_hash = %s AND token_type = %s AND revoked = 1',
				self::hash( $token ),
				$type
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Revoca un token de refresco dejando constancia de cuándo se rotó.
	 *
	 * La fecha viaja en `last_used_at`, que en un token de refresco no sirve
	 * para nada más: sólo los de acceso registran ahí su último uso. Sin ella
	 * no se puede separar el reintento honesto de la reutilización hostil, y
	 * ambos acababan derribando la sesión.
	 *
	 * @param string $token Valor recibido del cliente.
	 */
	public function rotate_token( string $token ): void {
		global $wpdb;

		$wpdb->update(
			$this->tokens_table(),
			array(
				'revoked'      => 1,
				'last_used_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'token_hash' => self::hash( $token ) ),
			array( '%d', '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Registra el último uso de un token de acceso.
	 *
	 * @param string $token_hash Hash del token utilizado.
	 */
	public function touch_token( string $token_hash ): void {
		global $wpdb;

		$wpdb->update(
			$this->tokens_table(),
			array( 'last_used_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array( 'token_hash' => $token_hash ),
			array( '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Revoca un token puntual.
	 *
	 * @param string $token Valor recibido del cliente.
	 */
	public function revoke_token( string $token ): void {
		global $wpdb;

		$wpdb->update(
			$this->tokens_table(),
			array( 'revoked' => 1 ),
			array( 'token_hash' => self::hash( $token ) ),
			array( '%d' ),
			array( '%s' )
		);
	}

	/**
	 * Revoca todos los tokens de un usuario, opcionalmente por cliente.
	 *
	 * @param int    $user_id   Usuario afectado.
	 * @param string $client_id Cliente concreto o cadena vacía para todos.
	 */
	public function revoke_for_user( int $user_id, string $client_id = '' ): void {
		global $wpdb;

		if ( '' === $client_id ) {
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Sólo se interpola el nombre de tabla ($wpdb->prefix + constante); los valores van por prepare().
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE `' . $this->tokens_table() . '` SET revoked = 1 WHERE user_id = %d',
					$user_id
				)
			);

			return;
		}

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Sólo se interpola el nombre de tabla ($wpdb->prefix + constante); los valores van por prepare().
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE `' . $this->tokens_table() . '` SET revoked = 1 WHERE user_id = %d AND client_id = %s',
				$user_id,
				$client_id
			)
		);
	}

	/**
	 * Cambia los alcances de una conexión (usuario + cliente).
	 *
	 * Actualiza todos sus tokens vivos —acceso y refresco— para que el cambio
	 * rija de inmediato y sobreviva a la rotación del token.
	 *
	 * @param int    $user_id   Usuario titular de la conexión.
	 * @param string $client_id Cliente OAuth.
	 * @param string $scope     Scopes normalizados separados por espacio.
	 *
	 * @return int Tokens actualizados.
	 */
	public function update_connection_scope( int $user_id, string $client_id, string $scope ): int {
		global $wpdb;

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Sólo se interpola el nombre de tabla ($wpdb->prefix + constante); los valores van por prepare().
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE `' . $this->tokens_table() . '` SET scope = %s WHERE user_id = %d AND client_id = %s AND revoked = 0',
				$scope,
				$user_id,
				$client_id
			)
		);

		return is_int( $updated ) ? $updated : 0;
	}

	/**
	 * Lista los tokens de acceso vigentes de un usuario para el panel.
	 *
	 * @param int $user_id Usuario consultado.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_active_for_user( int $user_id ): array {
		global $wpdb;

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Sólo se interpola el nombre de tabla ($wpdb->prefix + constante); los valores van por prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT t.client_id, c.client_name, t.scope, t.created_at, t.expires_at, t.last_used_at
				FROM `' . $this->tokens_table() . '` t
				LEFT JOIN `' . $this->clients_table() . '` c ON c.client_id = t.client_id
				WHERE t.user_id = %d AND t.token_type = %s AND t.revoked = 0 AND t.expires_at > %s
				ORDER BY t.created_at DESC',
				$user_id,
				self::TYPE_ACCESS,
				gmdate( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Lista las conexiones OAuth vigentes para el panel administrativo.
	 *
	 * Se agrupa por cliente y usuario: un cliente que ha rotado su token varias
	 * veces sigue siendo una sola conexión desde el punto de vista del dueño.
	 *
	 * Cuenta cualquier token vivo, no sólo el de acceso. El de acceso dura una
	 * hora y el de refresco treinta días, así que mirar sólo el primero hacía
	 * desaparecer del panel conexiones que seguían plenamente operativas: el
	 * dueño no las veía y por lo tanto tampoco podía revocarlas.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_active_connections(): array {
		global $wpdb;

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Sólo se interpola el nombre de tabla ($wpdb->prefix + constante); los valores van por prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT t.client_id, t.user_id, c.client_name,
					MAX(t.scope) AS scope,
					MIN(t.created_at) AS connected_at,
					MAX(t.expires_at) AS expires_at,
					MAX(t.last_used_at) AS last_used_at,
					SUM(CASE WHEN t.token_type = %s THEN 1 ELSE 0 END) AS token_count
				FROM `' . $this->tokens_table() . '` t
				LEFT JOIN `' . $this->clients_table() . '` c ON c.client_id = t.client_id
				WHERE t.revoked = 0 AND t.expires_at > %s
				GROUP BY t.client_id, t.user_id, c.client_name
				ORDER BY MIN(t.created_at) DESC',
				self::TYPE_ACCESS,
				gmdate( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Elimina códigos y tokens vencidos.
	 *
	 * Los códigos consumidos se conservan una hora para poder detectar
	 * reutilización antes de descartarlos.
	 */
	public function purge_expired(): void {
		global $wpdb;

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Sólo se interpola el nombre de tabla ($wpdb->prefix + constante); los valores van por prepare().
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM `' . $this->codes_table() . '` WHERE expires_at < %s',
				gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS )
			)
		);

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Sólo se interpola el nombre de tabla ($wpdb->prefix + constante); los valores van por prepare().
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM `' . $this->tokens_table() . '` WHERE expires_at < %s',
				gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
			)
		);
	}

	/**
	 * Cuenta los clientes OAuth registrados en el sitio.
	 */
	public function count_clients(): int {
		global $wpdb;

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Sólo se interpola el nombre de tabla ($wpdb->prefix + constante); los valores van por prepare().
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $this->clients_table() . '`' );
	}

	/**
	 * Elimina los clientes que ya no sostienen ninguna sesión.
	 *
	 * Cada reautorización con registro dinámico deja una fila nueva, porque el
	 * `client_id` se sortea en cada alta. Sin esta poda la tabla sólo crece.
	 *
	 * Un cliente sobrevive si cumple cualquiera de estas condiciones:
	 *
	 * - Se registró o se actualizó dentro del margen. Cubre el alta reciente,
	 *   donde median segundos entre el registro y el primer canje de código.
	 * - Conserva un token vigente y sin revocar, aunque sea de refresco.
	 * - Tuvo cualquier token dentro del margen, revocado o vencido. Un cliente
	 *   que operó ayer no es basura aunque hoy no tenga nada vivo.
	 * - Tiene un código de autorización dentro del margen, es decir, está a
	 *   mitad de un canje en curso.
	 *
	 * La consulta usa subconsultas sobre otras tablas, no sobre la propia, para
	 * que MySQL admita el DELETE sin recurrir a sintaxis multitabla.
	 *
	 * @param int $grace_seconds Antigüedad mínima exigida para descartar.
	 *
	 * @return int Filas eliminadas.
	 */
	public function purge_orphan_clients( int $grace_seconds = self::ORPHAN_CLIENT_TTL ): int {
		global $wpdb;

		$now    = gmdate( 'Y-m-d H:i:s' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 0, $grace_seconds ) );

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Sólo se interpola el nombre de tabla ($wpdb->prefix + constante); los valores van por prepare().
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM `' . $this->clients_table() . '`
				WHERE created_at < %s AND updated_at < %s
				AND client_id NOT IN (
					SELECT client_id FROM `' . $this->tokens_table() . '`
					WHERE ( revoked = 0 AND expires_at > %s ) OR created_at > %s
				)
				AND client_id NOT IN (
					SELECT client_id FROM `' . $this->codes_table() . '`
					WHERE created_at > %s
				)',
				$cutoff,
				$cutoff,
				$now,
				$cutoff,
				$cutoff
			)
		);

		return (int) $deleted;
	}
}
