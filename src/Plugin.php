<?php
/**
 * Arranque y registro de servicios del plugin.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);

namespace Codwelt\ToolsBoxRegistros;

use Codwelt\ToolsBoxRegistros\Install\Migrations;
use Codwelt\ToolsBoxRegistros\Admin\Menu;
use Codwelt\ToolsBoxRegistros\Frontend\Shortcode;
use Codwelt\ToolsBoxRegistros\Frontend\Block;
use Codwelt\ToolsBoxRegistros\Frontend\FormRenderer;
use Codwelt\ToolsBoxRegistros\Frontend\SubmissionHandler;
use Codwelt\ToolsBoxRegistros\Rest\SubmitController;
use Codwelt\ToolsBoxRegistros\Services\TransientStore;

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
		add_action( 'init', array( FormRenderer::class, 'register_assets' ) );
		add_action( 'init', array( new Shortcode(), 'register' ) );
		add_action( 'init', array( new Block(), 'register' ) );
		add_action( 'rest_api_init', array( new SubmitController(), 'register' ) );
		( new SubmissionHandler() )->register();
		add_action( 'wp_scheduled_delete', array( TransientStore::class, 'cleanup' ), 11 );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Evita cachear páginas de resultados PRG privados; no ejecuta acciones.
		if ( isset( $_GET['tbreg_result'] ) && ! defined( 'DONOTCACHEPAGE' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Constante pública reconocida por plugins de caché.
			define( 'DONOTCACHEPAGE', true );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Los resultados PRG son privados aunque un plugin de caché defina su propia constante.
		if ( isset( $_GET['tbreg_result'] ) ) {
			nocache_headers();
			add_action( 'send_headers', 'nocache_headers' );
		}
		if ( is_admin() ) {
			( new Menu() )->register();
		}
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
