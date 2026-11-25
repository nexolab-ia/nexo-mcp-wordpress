<?php
/**
 * Doble mínimo de wp-admin/includes/upgrade.php para probar el instalador (ABSPATH apunta a tests/).
 *
 * @package NexoDev\NexoMCP
 */

if ( ! function_exists( 'dbDelta' ) ) {
	/**
	 * Registra las sentencias que el instalador pediría crear.
	 *
	 * @param string $sql Sentencia CREATE TABLE.
	 * @return array<string, string>
	 */
	function dbDelta( $sql ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
		$GLOBALS['nexo_test_dbdelta'][] = $sql;
		return array();
	}
}
