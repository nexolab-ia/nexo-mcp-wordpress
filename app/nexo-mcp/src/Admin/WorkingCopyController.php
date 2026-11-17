<?php
/**
 * Copias de trabajo en la lista de páginas y entradas.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Admin;

use NexoDev\NexoMCP\Audit\AuditLogger;
use NexoDev\NexoMCP\WordPress\WorkingCopy;

/**
 * Deja al dueño publicar o descartar desde wp-admin lo que la IA preparó.
 *
 * No depende de los interruptores del MCP, sólo de las capacidades de WordPress
 * de quien hace clic: así «Publicación remota» puede quedar apagada, la IA
 * prepara la copia y el humano la publica. Usa las mismas funciones de fusión y
 * descarte que la herramienta (ADR-031).
 */
final class WorkingCopyController {

	public const MERGE_ACTION   = 'nexo_mcp_draft_merge';
	public const DISCARD_ACTION = 'nexo_mcp_draft_discard';

	private const NOTICE_TRANSIENT = 'nexo_mcp_draft_notice_';

	/**
	 * Copias de trabajo.
	 *
	 * @var WorkingCopy
	 */
	private WorkingCopy $copies;

	/**
	 * Auditoría.
	 *
	 * @var AuditLogger
	 */
	private AuditLogger $audit;

	/**
	 * Construye el controlador.
	 *
	 * @param WorkingCopy $copies Copias de trabajo.
	 * @param AuditLogger $audit  Auditoría.
	 */
	public function __construct( WorkingCopy $copies, AuditLogger $audit ) {
		$this->copies = $copies;
		$this->audit  = $audit;
	}

	/**
	 * Registra los hooks de la lista y de las acciones.
	 */
	public function register(): void {
		add_filter( 'display_post_states', array( $this, 'post_states' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_action( 'admin_post_' . self::MERGE_ACTION, array( $this, 'handle_merge' ) );
		add_action( 'admin_post_' . self::DISCARD_ACTION, array( $this, 'handle_discard' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * Marca la copia y el original en la lista.
	 *
	 * WordPress imprime estos estados sin escapar, así que se escapan aquí.
	 *
	 * @param array<string, string> $states Estados de la fila.
	 * @param \WP_Post              $post   Contenido de la fila.
	 * @return array<string, string>
	 */
	public function post_states( array $states, \WP_Post $post ): array {
		$original = WorkingCopy::original_of( $post );

		if ( $original > 0 ) {
			$states['nexo_mcp_draft'] = esc_html(
				sprintf(
					/* translators: %s: título del contenido original. */
					__( 'Copia de trabajo de «%s»', 'nexo-mcp' ),
					get_the_title( $original )
				)
			);

			return $states;
		}

		if ( WorkingCopy::copy_of( $post ) > 0 ) {
			$states['nexo_mcp_pending'] = esc_html__( 'Con cambios pendientes', 'nexo-mcp' );
		}

		return $states;
	}

	/**
	 * Agrega «Publicar cambios» y «Descartar copia» a la fila de una copia.
	 *
	 * @param array<string, string> $actions Acciones de la fila.
	 * @param \WP_Post              $post    Contenido de la fila.
	 * @return array<string, string>
	 */
	public function row_actions( array $actions, \WP_Post $post ): array {
		$original = WorkingCopy::original_of( $post );

		if ( 0 === $original || ! $this->can_manage( $post, $original ) ) {
			return $actions;
		}

		$actions[ self::MERGE_ACTION ] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $this->action_url( self::MERGE_ACTION, (int) $post->ID ) ),
			esc_html__( 'Publicar cambios', 'nexo-mcp' )
		);

		// Descartar no se deshace: la copia se borra de forma permanente.
		$actions[ self::DISCARD_ACTION ] = sprintf(
			'<a href="%s" onclick="return confirm(\'%s\');">%s</a>',
			esc_url( $this->action_url( self::DISCARD_ACTION, (int) $post->ID ) ),
			esc_js( __( '¿Descartar la copia? Se borra de forma permanente.', 'nexo-mcp' ) ),
			esc_html__( 'Descartar copia', 'nexo-mcp' )
		);

		return $actions;
	}

	/**
	 * Atiende «Publicar cambios».
	 */
	public function handle_merge(): void {
		$this->redirect( $this->process( self::MERGE_ACTION ) );
	}

	/**
	 * Atiende «Descartar copia».
	 */
	public function handle_discard(): void {
		$this->redirect( $this->process( self::DISCARD_ACTION ) );
	}

	/**
	 * Valida nonce y capacidades, ejecuta la acción y deja el aviso.
	 *
	 * Va separado de la redirección para poder probarlo sin cortar la
	 * petición. Un nonce inválido o un permiso que falta terminan en
	 * `wp_die()` sin tocar nada.
	 *
	 * @param string $action MERGE_ACTION o DISCARD_ACTION.
	 * @return string Dirección de vuelta a la lista.
	 */
	public function process( string $action ): string {
		// El ID es parte de la acción del nonce, así que se lee antes de
		// verificarlo; sólo se usa como entero.
		$copy_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- El nonce se verifica en la línea siguiente con este mismo ID.

		check_admin_referer( $action . '_' . $copy_id );

		$merge    = self::MERGE_ACTION === $action;
		$tool     = $merge ? 'draft_merge' : 'draft_discard';
		$copy     = get_post( $copy_id );
		$original = $copy instanceof \WP_Post ? WorkingCopy::original_of( $copy ) : 0;

		if ( 0 === $original || ! $this->can_manage( $copy, $original ) ) {
			$this->audit->log( $tool, 'admin', 'denied', array( 'id' => $copy_id ) );

			wp_die(
				esc_html__( 'No tienes permisos para completar esta acción.', 'nexo-mcp' ),
				'',
				array( 'response' => 403 )
			);
		}

		$result = $merge ? $this->copies->merge( $copy ) : $this->copies->discard( $copy );

		$this->audit->log( $tool, 'admin', is_wp_error( $result ) ? 'error' : 'success', array( 'id' => $copy_id ), $result );

		if ( is_wp_error( $result ) ) {
			$message = $result->get_error_message();
		} else {
			$message = $merge
				? __( 'Cambios publicados.', 'nexo-mcp' )
				: __( 'Copia descartada.', 'nexo-mcp' );
		}

		set_transient(
			self::NOTICE_TRANSIENT . get_current_user_id(),
			array(
				'type'    => is_wp_error( $result ) ? 'error' : 'success',
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);

		return admin_url( 'edit.php?post_type=' . $copy->post_type );
	}

	/**
	 * Muestra, una sola vez, el resultado de la última acción.
	 */
	public function render_notice(): void {
		$key    = self::NOTICE_TRANSIENT . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}

		delete_transient( $key );

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( 'success' === ( $notice['type'] ?? '' ) ? 'success' : 'error' ),
			esc_html( (string) $notice['message'] )
		);
	}

	/**
	 * Comprueba que quien hace clic puede editar el original y publicar el tipo.
	 *
	 * @param \WP_Post $copy     Copia de trabajo.
	 * @param int      $original ID del original.
	 */
	private function can_manage( \WP_Post $copy, int $original ): bool {
		return current_user_can( 'edit_post', $original )
			&& current_user_can( 'page' === $copy->post_type ? 'publish_pages' : 'publish_posts' );
	}

	/**
	 * Dirección de una acción, con su nonce propio por copia.
	 *
	 * @param string $action  Acción.
	 * @param int    $copy_id Copia de trabajo.
	 */
	private function action_url( string $action, int $copy_id ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=' . $action . '&post=' . $copy_id ),
			$action . '_' . $copy_id
		);
	}

	/**
	 * Vuelve a la lista y corta la petición.
	 *
	 * @param string $url Dirección de vuelta.
	 */
	private function redirect( string $url ): never {
		wp_safe_redirect( $url );
		exit;
	}
}
