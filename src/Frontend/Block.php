<?php
/**
 * Bloque dinámico de Gutenberg sin compilación.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Frontend;

use Codwelt\ToolsBoxRegistros\Repository\FormRepository;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Comparte renderer con el shortcode y protege las vistas previas. */
final class Block {
	/**
	 * Registra metadatos y script sin build.
	 *
	 * @return void
	 */
	public function register(): void {
		wp_register_script( 'tbreg-block', TBREG_URL . 'blocks/registro-form/index.js', array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-api-fetch', 'wp-i18n' ), TBREG_VERSION, true );
		wp_set_script_translations( 'tbreg-block', 'toolsbox-registros', TBREG_PATH . 'languages' );
		register_block_type( TBREG_PATH . 'blocks/registro-form', array( 'render_callback' => array( $this, 'render' ) ) );
	}
	/**
	 * Renderiza un ID con vista previa solo para administradores autorizados.
	 *
	 * @param array<string,mixed> $attributes Atributos del bloque.
	 * @return string
	 */
	public function render( array $attributes ): string {
		$id      = isset( $attributes['formId'] ) && is_int( $attributes['formId'] ) ? $attributes['formId'] : 0;
		$preview = defined( 'REST_REQUEST' ) && REST_REQUEST && current_user_can( 'tbreg_manage_forms' );
		return ( new FormRenderer() )->render( $id > 0 ? ( new FormRepository() )->find( $id ) : null, $preview );
	}
}
