<?php
/**
 * Plantilla del campo document.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- FieldView escapa atributos y textos antes de construir el HTML.
echo \Codwelt\ToolsBoxRegistros\Frontend\FieldView::render( $tbreg_context );
