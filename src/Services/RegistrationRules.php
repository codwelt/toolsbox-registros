<?php
/**
 * Reglas públicas sobre la versión actual del formulario.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Services;

use Codwelt\ToolsBoxRegistros\Domain\Form;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Cupos y duplicados también se comprueban al insertar bajo bloqueo. */
final class RegistrationRules {
	/**
	 * Verifica publicación, fechas locales, acceso y disponibilidad.
	 *
	 * @param Form     $form Formulario.
	 * @param int|null $now Instante UTC opcional para pruebas.
	 * @return \WP_Error|null
	 */
	public function check( Form $form, ?int $now = null ): ?\WP_Error {
		if ( 'published' !== $form->get( 'status' ) ) {
			return new \WP_Error( 'tbreg_closed', __( 'Las inscripciones están cerradas.', 'toolsbox-registros' ), array( 'status' => 403 ) );
		}
		$settings = $form->settings();
		$time     = $now ?? time();
		foreach ( array( 'opens_at', 'closes_at' ) as $name ) {
			if ( '' === $settings[ $name ] ) {
				continue;
			}
			$date = new \DateTimeImmutable( $settings[ $name ], wp_timezone() );
			if ( 'opens_at' === $name && $time < $date->getTimestamp() ) {
				return new \WP_Error( 'tbreg_not_open', __( 'Las inscripciones aún no están abiertas.', 'toolsbox-registros' ), array( 'status' => 403 ) );
			}
			if ( 'closes_at' === $name && $time >= $date->getTimestamp() ) {
				return new \WP_Error( 'tbreg_closed', __( 'Las inscripciones están cerradas.', 'toolsbox-registros' ), array( 'status' => 403 ) );
			}
		}
		if ( $settings['require_login'] && ! is_user_logged_in() ) {
			return new \WP_Error( 'tbreg_login', __( 'Inicia sesión para inscribirte.', 'toolsbox-registros' ), array( 'status' => 401 ) );
		}
		if ( $settings['capacity'] > 0 && $form->get( 'entries_count' ) >= $settings['capacity'] && ! $settings['waitlist'] ) {
			return new \WP_Error( 'tbreg_full', __( 'No hay cupos disponibles.', 'toolsbox-registros' ), array( 'status' => 409 ) );
		}
		return null;
	}
}
