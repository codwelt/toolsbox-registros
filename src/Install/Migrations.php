<?php
/**
 * Versionado del esquema de datos.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);

namespace Codwelt\ToolsBoxRegistros\Install;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Actualiza la instalación existente antes de usar los repositorios.
 */
final class Migrations {
	public const DB_VERSION = '1.0.0';

	/**
	 * Ejecuta una actualización pendiente en el sitio actual.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( version_compare( (string) get_option( 'tbreg_db_version', '0' ), self::DB_VERSION, '>=' ) ) {
			return;
		}

		try {
			Installer::install_site();
		} catch ( \RuntimeException $exception ) {
			add_action(
				'admin_notices',
				static function (): void {
					if ( current_user_can( 'manage_options' ) ) {
						echo '<div class="notice notice-error"><p>' . esc_html__( 'ToolsBox Registros no pudo actualizar sus tablas. Comprueba los permisos y la conexión de la base de datos.', 'toolsbox-registros' ) . '</p></div>';
					}
				}
			);
		}
	}
}
