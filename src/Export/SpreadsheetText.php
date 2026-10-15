<?php
/**
 * Texto XML válido y límites del formato Excel.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Export;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }
/** No interpreta texto de los inscritos como fórmulas. */
final class SpreadsheetText {
	/**
	 * Limpia caracteres no representables en XML 1.0.
	 *
	 * @param string $value Texto.
	 * @return string
	 */
	public static function clean( string $value ): string {
		$value = mb_convert_encoding( $value, 'UTF-8', 'UTF-8' );
		return (string) preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value );
	}
	/**
	 * Acota por unidades UTF-16 sin cortar pares de emoji.
	 *
	 * @param string $value Texto válido.
	 * @param int    $limit Unidades UTF-16 permitidas.
	 * @return string
	 */
	public static function limit( string $value, int $limit = 32767 ): string {
		$utf16 = mb_convert_encoding( $value, 'UTF-16LE', 'UTF-8' );
		return mb_convert_encoding( mb_strcut( $utf16, 0, $limit * 2, 'UTF-16LE' ), 'UTF-8', 'UTF-16LE' );
	}
	/**
	 * Escapa texto para partes XML del paquete.
	 *
	 * @param string $value Texto.
	 * @return string
	 */
	public static function xml( string $value ): string {
		return htmlspecialchars( self::clean( $value ), ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8' ); }
	/**
	 * Crea un nombre de hoja admitido por Excel.
	 *
	 * @param string $title Título de formulario.
	 * @return string
	 */
	public static function sheet_name( string $title ): string {
		$title = str_replace( array( '[', ']', ':', '*', '?', '/', '\\' ), '', self::clean( $title ) );
		$title = trim( self::limit( trim( $title, " '\t\r\n" ), 31 ), " '" );
		return '' === $title || 'history' === strtolower( $title ) ? __( 'Inscritos', 'toolsbox-registros' ) : $title;
	}
}
