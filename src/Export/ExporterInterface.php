<?php
/**
 * Contrato de escritura secuencial de exportaciones.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Export;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }
/** Un destino privado, cabecera y filas sin acumular el libro completo. */
interface ExporterInterface {
	/**
	 * Abre el destino confiable creado por el exportador.
	 *
	 * @param string $path Ruta temporal.
	 * @return void
	 */
	public function open( string $path ): void;
	/**
	 * Escribe nombres de columnas.
	 *
	 * @param array<string> $headers Etiquetas.
	 * @return void
	 */
	public function writeHeader( array $headers ): void;
	/**
	 * Escribe una fila del mismo ancho que la cabecera.
	 *
	 * @param array<mixed> $cells Valores escalares o fechas.
	 * @return void
	 */
	public function writeRow( array $cells ): void;
	/**
	 * Finaliza el archivo de salida.
	 *
	 * @return void
	 */
	public function close(): void;
	/**
	 * Libera recursos y elimina destinos incompletos.
	 *
	 * @return void
	 */
	public function abort(): void;
}
