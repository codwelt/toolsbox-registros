<?php
/**
 * Plantillas sobrescribibles desde el tema.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Resuelve rutas internas y entrega el contexto sin extract(). */
final class Template {
	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- El include recibe $tbreg_context en este ámbito.
	/**
	 * Renderiza una plantilla controlada por el plugin o el tema.
	 *
	 * @param string              $name Nombre relativo interno.
	 * @param array<string,mixed> $tbreg_context Contexto disponible en la plantilla.
	 * @return string
	 * @throws \Throwable Si falla una plantilla del tema o del plugin.
	 */
	public static function render( string $name, array $tbreg_context ): string {
		if ( ! preg_match( '~^[a-z0-9_/-]+\.php$~D', $name ) || str_contains( $name, '..' ) ) {
			return '';
		}
		$path = locate_template( 'toolsbox-registros/' . $name, false, false );
		$path = apply_filters( 'tbreg_template_path', '' !== $path ? $path : TBREG_PATH . 'templates/' . $name, $name );
		if ( ! is_string( $path ) || ! is_file( $path ) ) {
			return '';
		}
		ob_start();
		try {
			include $path;
			return (string) ob_get_clean();
		} catch ( \Throwable $exception ) {
			ob_end_clean();
			throw $exception;
		}
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
}
