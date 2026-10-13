<?php
/**
 * Transients de seguridad coherentes entre admin y frontend.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Usa opciones temporales sin depender de un backend externo consistente. */
final class TransientStore {
	/**
	 * Ejecuta solo una operación propia con el backend de BD de WordPress.
	 *
	 * @param string   $key Clave interna del plugin.
	 * @param callable $operation Operación de transient.
	 * @return mixed
	 */
	private static function database( string $key, callable $operation ): mixed {
		$external = wp_using_ext_object_cache();
		wp_using_ext_object_cache( false );
		try {
			wp_cache_delete( '_transient_' . $key, 'options' );
			wp_cache_delete( '_transient_timeout_' . $key, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			return $operation();
		} finally {
			wp_using_ext_object_cache( $external );
		}
	}
	/**
	 * Lee un transient temporal sin utilizar contadores cacheados obsoletos.
	 *
	 * @param string $key Clave interna.
	 * @return mixed
	 */
	public static function get( string $key ): mixed {
		return self::database( $key, static fn (): mixed => get_transient( $key ) );
	}
	/**
	 * Guarda con caducidad explícita y sin autoload.
	 *
	 * @param string $key Clave interna.
	 * @param mixed  $value Valor.
	 * @param int    $ttl Segundos de caducidad.
	 * @return bool
	 */
	public static function set( string $key, mixed $value, int $ttl ): bool {
		return self::database( $key, static fn (): bool => set_transient( $key, $value, max( 1, $ttl ) ) );
	}
	/**
	 * Borra un transient ya consumido.
	 *
	 * @param string $key Clave interna.
	 * @return void
	 */
	public static function delete( string $key ): void {
		self::database( $key, static fn (): bool => delete_transient( $key ) );
	}
	/**
	 * Elimina datos propios vencidos también cuando hay un drop-in externo.
	 *
	 * Se acopla a wp_scheduled_delete, que WordPress ya ejecuta diariamente.
	 * No necesita otra tarea cron ni modifica transients de otros componentes.
	 *
	 * @return void
	 */
	public static function cleanup(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Mantenimiento de transients propios, sin cachear una lista de filas caducadas.
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE (option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s) AND CAST(option_value AS UNSIGNED) < %d", $wpdb->esc_like( '_transient_timeout_tbreg_feedback_' ) . '%', $wpdb->esc_like( '_transient_timeout_tbreg_rate_' ) . '%', $wpdb->esc_like( '_transient_timeout_tbreg_selection_' ) . '%', $wpdb->esc_like( '_transient_timeout_tbreg_entry_error_' ) . '%', time() ) );
		foreach ( $names as $name ) {
			self::delete( substr( $name, strlen( '_transient_timeout_' ) ) );
		}
	}
}
