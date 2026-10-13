<?php
/**
 * Mensajes PRG privados y de un solo uso.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Frontend;

use Codwelt\ToolsBoxRegistros\Services\TransientStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Vincula el transient al navegador mediante cookie HttpOnly. */
final class Feedback {
	/**
	 * Obtiene o crea el identificador efímero del navegador.
	 *
	 * @return string
	 */
	private static function owner(): string {
		$owner = isset( $_COOKIE['tbreg_feedback_owner'] ) && is_string( $_COOKIE['tbreg_feedback_owner'] ) ? sanitize_key( wp_unslash( $_COOKIE['tbreg_feedback_owner'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9]{32}$/D', $owner ) ) {
			$owner = bin2hex( random_bytes( 16 ) );
			if ( ! headers_sent() ) {
				setcookie(
					'tbreg_feedback_owner',
					$owner,
					array(
						'expires'  => time() + HOUR_IN_SECONDS,
						'path'     => '/',
						'secure'   => is_ssl(),
						'httponly' => true,
						'samesite' => 'Lax',
					)
				);
			}
			$_COOKIE['tbreg_feedback_owner'] = $owner;
		}
		return $owner;
	}
	/**
	 * Guarda solo valores saneados y mensajes seguros durante cinco minutos.
	 *
	 * @param int                 $form_id Formulario.
	 * @param array<string,mixed> $payload Resultado del envío.
	 * @return string
	 */
	public static function store( int $form_id, array $payload ): string {
		$token = bin2hex( random_bytes( 16 ) );
		$key   = 'tbreg_feedback_' . hash( 'sha256', self::owner() . '|' . $form_id . '|' . $token );
		TransientStore::set( $key, $payload, 5 * MINUTE_IN_SECONDS );
		return $token;
	}
	/**
	 * Consume exclusivamente el mensaje del navegador que lo creó.
	 *
	 * @param int    $form_id Formulario renderizado.
	 * @param string $token Token de lectura.
	 * @return array<string,mixed>
	 */
	public static function consume( int $form_id, string $token ): array {
		if ( ! preg_match( '/^[a-f0-9]{32}$/D', $token ) || ! isset( $_COOKIE['tbreg_feedback_owner'] ) ) {
			return array();
		}
		$key     = 'tbreg_feedback_' . hash( 'sha256', self::owner() . '|' . $form_id . '|' . $token );
		$payload = TransientStore::get( $key );
		TransientStore::delete( $key );
		return is_array( $payload ) ? $payload : array();
	}
}
