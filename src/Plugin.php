<?php
/**
 * Arranque y registro de servicios del plugin.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);

namespace Codwelt\ToolsBoxRegistros;

use Codwelt\ToolsBoxRegistros\Install\Migrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordina el ciclo de vida de los servicios.
 */
final class Plugin {
	/**
	 * Evita registrar los servicios más de una vez.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Registra los servicios cuando WordPress ha cargado los plugins.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;
		Migrations::maybe_upgrade();
		add_action( 'init', array( $this, 'load_translations' ) );
	}

	/**
	 * Registra las traducciones en init para evitar su carga anticipada.
	 *
	 * @return void
	 */
	public function load_translations(): void {
		load_plugin_textdomain( 'toolsbox-registros', false, dirname( TBREG_BASENAME ) . '/languages' );
	}
}
