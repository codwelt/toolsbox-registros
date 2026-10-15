<?php
/**
 * CSV UTF-8 con BOM, separador explícito y neutralización de fórmulas.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Export;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Escribe directamente al destino, sin dependencias ni buffers del libro. */
final class CsvWriter implements ExporterInterface {
	/**
	 * Recurso de salida.
	 *
	 * @var resource|null
	 */
	private $handle = null;
	/**
	 * Destino propio incompleto.
	 *
	 * @var string
	 */
	private string $path = '';
	/**
	 * Separador CSV.
	 *
	 * @var string
	 */
	private string $delimiter;
	/**
	 * Ancho de cabecera; cero antes de escribirla.
	 *
	 * @var int
	 */
	private int $columns = 0;
	/**
	 * Selecciona uno de los separadores soportados.
	 *
	 * @param string $delimiter Separador.
	 * @throws \InvalidArgumentException Si no es coma o punto y coma.
	 */
	public function __construct( string $delimiter = ';' ) {
		if ( ! in_array( $delimiter, array( ';', ',' ), true ) ) {
			throw new \InvalidArgumentException( 'tbreg_csv_delimiter' );
		}
		$this->delimiter = $delimiter;
	}
	/**
	 * Abre un destino local.
	 *
	 * @param string $path Destino.
	 * @throws \RuntimeException Si falla el almacenamiento.
	 * @throws \LogicException Si ya está abierto.
	 * @return void
	 */
	public function open( string $path ): void {
		if ( is_resource( $this->handle ) ) {
			throw new \LogicException( 'tbreg_writer_open' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Escritura secuencial local; WP_Filesystem cargaría el CSV entero.
		$this->handle = fopen( $path, 'wb' );
		if ( false === $this->handle ) {
			$this->handle = null;
			throw new \RuntimeException( 'tbreg_export_io' );
		}
		$this->path    = $path;
		$this->columns = 0;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- BOM del archivo local en streaming.
		if ( 3 !== fwrite( $this->handle, "\xEF\xBB\xBF" ) ) {
			throw new \RuntimeException( 'tbreg_export_io' );
		}
	}
	/**
	 * Escribe la cabecera del archivo.
	 *
	 * @param array<string> $headers Etiquetas.
	 * @throws \LogicException Si no se puede escribir la cabecera.
	 * @return void
	 */
	public function writeHeader( array $headers ): void {
		if ( $this->columns > 0 || array() === $headers ) {
			throw new \LogicException( 'tbreg_export_header' );
		}
		$this->columns = count( $headers );
		$this->writeRow( $headers );
	}
	/**
	 * Escribe una fila del archivo.
	 *
	 * @param array<mixed> $cells Celdas.
	 * @throws \LogicException Si no coincide el ancho de la fila.
	 * @throws \InvalidArgumentException Si una celda no es escalar.
	 * @throws \RuntimeException Si falla el almacenamiento.
	 * @return void
	 */
	public function writeRow( array $cells ): void {
		if ( ! is_resource( $this->handle ) || 0 === $this->columns || count( $cells ) !== $this->columns ) {
			throw new \LogicException( 'tbreg_export_row' );
		}
		$row = array();
		foreach ( $cells as $value ) {
			if ( $value instanceof \DateTimeInterface ) {
				$value = $value->format( 'Y-m-d H:i:s' );
			}
			if ( null !== $value && ! is_scalar( $value ) ) {
				throw new \InvalidArgumentException( 'tbreg_export_cell' );
			}
			$text = SpreadsheetText::clean( (string) $value );
			if ( preg_match( '/^(?:[=+@\-\t\r\n]|[\p{Z}\s]+[=+@\-])/u', $text ) ) {
				$text = "'" . $text;
			}
			$row[] = $text;
		}
		if ( false === fputcsv( $this->handle, $row, $this->delimiter, '"', '' ) ) {
			throw new \RuntimeException( 'tbreg_export_io' );
		}
	}
	/**
	 * Finaliza el archivo.
	 *
	 * @throws \LogicException Si el escritor no está abierto.
	 * @throws \RuntimeException Si falla el almacenamiento.
	 * @return void
	 */
	public function close(): void {
		if ( ! is_resource( $this->handle ) ) {
			throw new \LogicException( 'tbreg_writer_closed' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Cierra un recurso local incremental.
		if ( ! fclose( $this->handle ) ) {
			throw new \RuntimeException( 'tbreg_export_io' );
		}
		$this->handle = null;
		$this->path   = '';
	}
	/**
	 * Elimina archivos incompletos.
	 *
	 * @return void
	 */
	public function abort(): void {
		if ( is_resource( $this->handle ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Recurso local de escritura o descarga incremental.
			fclose( $this->handle );
		}
		$this->handle = null;
		if ( '' !== $this->path ) {
			wp_delete_file( $this->path );
		}
		$this->path = '';
	}
	/** Libera destinos incompletos si una excepción interrumpe al llamador. */
	public function __destruct() {
		$this->abort();
	}
}
