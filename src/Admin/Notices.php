<?php
/**
 * Avisos de una sola lectura, ligados al usuario.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);

namespace Codwelt\ToolsBoxRegistros\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Conserva los avisos entre el POST y su redirección.
 */
final class Notices {
	/**
	 * Guarda un aviso para el usuario actual.
	 *
	 * @param string $message Mensaje visible.
	 * @param bool   $error Indica si ocurrió un error.
	 * @return string Token opaco del aviso.
	 */
	public static function put( string $message, bool $error = false ): string {
		$token = bin2hex( random_bytes( 16 ) );
		set_transient(
			'tbreg_notice_' . get_current_user_id() . '_' . $token,
			array(
				'message' => $message,
				'error'   => $error,
			),
			300
		);
		return $token;
	}

	/**
	 * Muestra y consume el aviso destinado al usuario actual.
	 *
	 * @return void
	 */
	public static function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lectura de un token opaco ligado al usuario; no ejecuta acciones.
		$token = isset( $_GET['tbreg_notice'] ) && is_string( $_GET['tbreg_notice'] ) ? sanitize_key( wp_unslash( $_GET['tbreg_notice'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9]{32}$/D', $token ) ) {
			return;
		}
		$key    = 'tbreg_notice_' . get_current_user_id() . '_' . $token;
		$notice = get_transient( $key );
		delete_transient( $key );
		if ( is_array( $notice ) ) {
			echo '<div class="notice ' . ( $notice['error'] ? 'notice-error' : 'notice-success' ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
		}
	}
}
