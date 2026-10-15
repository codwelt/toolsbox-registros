<?php
/**
 * Escritor SpreadsheetML nativo con filas y paquete en disco.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Export;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Mantiene en memoria solo una fila, las anchuras y las partes pequeñas. */
final class XlsxWriter implements ExporterInterface {
	private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
	/**
	 * Filas XML temporales.
	 *
	 * @var resource|null
	 */
	private $handle = null;
	/**
	 * Archivo de filas.
	 *
	 * @var string
	 */
	private string $body_path = '';
	/**
	 * Hoja final temporal.
	 *
	 * @var string
	 */
	private string $sheet_path = '';
	/**
	 * Destino incompleto.
	 *
	 * @var string
	 */
	private string $path = '';
	/**
	 * Nombre de hoja.
	 *
	 * @var string
	 */
	private string $name;
	/**
	 * Tipos de columna text/number/date.
	 *
	 * @var array<string>
	 */
	private array $types;
	/**
	 * Anchuras por columna.
	 *
	 * @var array<int>
	 */
	private array $widths = array();
	/**
	 * Última fila escrita.
	 *
	 * @var int
	 */
	private int $rows = 0;
	/**
	 * Número de columnas.
	 *
	 * @var int
	 */
	private int $columns = 0;
	/**
	 * Inicializa el nombre y los tipos, sin abrir recursos.
	 *
	 * @param string        $name Título.
	 * @param array<string> $types Tipos.
	 */
	public function __construct( string $name = '', array $types = array() ) {
		$this->name  = SpreadsheetText::sheet_name( $name );
		$this->types = $types;
	}
	/**
	 * Comprueba soporte real; el filtro permite deshabilitarlo.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return class_exists( '\ZipArchive' ) && (bool) apply_filters( 'tbreg_xlsx_available', true );
	}
	/**
	 * Crea un temporal propio con nombre aleatorio no predecible.
	 *
	 * @return string
	 * @throws \RuntimeException Si no hay espacio o permisos.
	 */
	public static function temporary(): string {
		$path = wp_tempnam( 'tbreg-' . bin2hex( random_bytes( 16 ) ) );
		if ( ! is_string( $path ) || '' === $path ) {
			throw new \RuntimeException( 'tbreg_export_io' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Temporal local con datos personales, limitado al propietario.
		if ( ! chmod( $path, 0600 ) ) {
			wp_delete_file( $path );
			throw new \RuntimeException( 'tbreg_export_io' );
		}
		register_shutdown_function(
			static function () use ( $path ): void {
				wp_delete_file( $path );
			}
		);
		return $path;
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
		if ( ! self::available() ) {
			throw new \RuntimeException( 'tbreg_zip_unavailable' );
		}
		if ( '' !== $this->path ) {
			throw new \LogicException( 'tbreg_writer_open' );
		}
		$this->path      = $path;
		$this->body_path = self::temporary();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Filas en disco para exportar sin acumular XML.
		$this->handle = fopen( $this->body_path, 'wb' );
		if ( false === $this->handle ) {
			$this->handle = null;
			throw new \RuntimeException( 'tbreg_export_io' );
		}
		$this->rows    = 0;
		$this->columns = 0;
		$this->widths  = array();
	}
	/**
	 * Escribe la cabecera del archivo.
	 *
	 * @param array<string> $headers Etiquetas.
	 * @throws \LogicException Si no se puede escribir la cabecera.
	 * @return void
	 */
	public function writeHeader( array $headers ): void {
		if ( 0 !== $this->rows || ! is_resource( $this->handle ) || array() === $headers || count( $headers ) > 16384 ) {
			throw new \LogicException( 'tbreg_export_header' );
		}
		$this->columns = count( $headers );
		$this->write_cells( $headers, true );
	}
	/**
	 * Escribe una fila del archivo.
	 *
	 * @param array<mixed> $cells Celdas.
	 * @throws \LogicException Si no coincide el ancho de la fila.
	 * @return void
	 */
	public function writeRow( array $cells ): void {
		if ( 0 === $this->columns || ! is_resource( $this->handle ) || count( $cells ) !== $this->columns || $this->rows >= 1048576 ) {
			throw new \LogicException( 'tbreg_export_row' );
		}
		$this->write_cells( $cells, false );
	}
	/**
	 * Escribe un fragmento controlando escrituras parciales.
	 *
	 * @param resource $handle Recurso.
	 * @param string   $text Fragmento.
	 * @return void
	 * @throws \RuntimeException Si falla el disco.
	 */
	private static function put( $handle, string $text ): void {
		$offset = 0;
		$length = strlen( $text );
		while ( $offset < $length ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Escritura incremental de partes del paquete.
			$written = fwrite( $handle, substr( $text, $offset ) );
			if ( false === $written || 0 === $written ) {
				throw new \RuntimeException( 'tbreg_export_io' );
			}
			$offset += $written;
		}
	}
	/**
	 * Convierte un índice de columna en la dirección Excel.
	 *
	 * @param int $index Índice desde uno.
	 * @return string
	 */
	public static function column( int $index ): string {
		$name = '';
		do {
			--$index;
			$name  = chr( 65 + $index % 26 ) . $name;
			$index = intdiv( $index, 26 );
		} while ( $index > 0 );
		return $name;
	}
	/**
	 * Escribe celdas tipadas; todas las cadenas permanecen como inlineStr.
	 *
	 * @param array<mixed> $cells Fila.
	 * @param bool         $header Cabecera.
	 * @return void
	 * @throws \InvalidArgumentException Si hay un objeto no admitido.
	 */
	private function write_cells( array $cells, bool $header ): void {
		++$this->rows;
		$height = 30;
		foreach ( $cells as $index => $cell ) {
			if ( $header ) {
				$height = max( $height, min( 409, 15 * (int) ceil( mb_strlen( (string) $cell ) / 45 ) ) );
			}
		}
		self::put( $this->handle, '<row r="' . $this->rows . '"' . ( $header ? ' ht="' . $height . '" customHeight="1"' : '' ) . '>' );
		foreach ( array_values( $cells ) as $index => $value ) {
			$type    = $this->types[ $index ] ?? 'text';
			$style   = $header ? 1 : 0;
			$numeric = false;
			if ( $value instanceof \DateTimeInterface ) {
				if ( 'date' === $type && ! $header ) {
					$wall    = new \DateTimeImmutable( $value->format( 'Y-m-d H:i:s' ), new \DateTimeZone( 'UTC' ) );
					$base    = new \DateTimeImmutable( $wall->format( 'Y-m-d' ) < '1900-03-01' ? '1899-12-31' : '1899-12-30', new \DateTimeZone( 'UTC' ) );
					$value   = (string) ( ( $wall->getTimestamp() - $base->getTimestamp() ) / 86400 );
					$style   = 2;
					$numeric = true;
				} else {
					$value = $value->format( 'Y-m-d H:i:s' );
				}
			}
			if ( null !== $value && ! is_scalar( $value ) ) {
				throw new \InvalidArgumentException( 'tbreg_export_cell' );
			}
			$text = SpreadsheetText::limit( SpreadsheetText::clean( (string) $value ) );
			if ( ! $header && 'number' === $type && is_numeric( $text ) && is_finite( (float) $text ) ) {
				$mantissa = preg_split( '/[eE]/', $text )[0];
				$digits   = ltrim( (string) preg_replace( '/[^0-9]/', '', $mantissa ), '0' );
				$numeric  = strlen( $digits ) <= 15 && ( ( 0.0 !== (float) $text && abs( (float) $text ) >= 2.2250738585072014e-308 ) || '' === $digits );
			}
			$first_line = preg_split( '/\r\n|\r|\n/', $text, 2 )[0];
			$width      = $header ? mb_strlen( $text ) + 3 : ( 2 === $style ? 28 : mb_strlen( $first_line ) + 2 );
			if ( $header || $this->rows <= 501 ) {
				$this->widths[ $index ] = max( $this->widths[ $index ] ?? 12, min( 60, $width ) );
			}
			$address = self::column( $index + 1 ) . $this->rows;
			if ( ! $header && ! $numeric ) {
				$style = 3;
			}
			self::put( $this->handle, '<c r="' . $address . '" s="' . $style . '"' . ( $numeric ? ' t="n"><v>' . SpreadsheetText::xml( $text ) . '</v></c>' : ' t="inlineStr"><is><t xml:space="preserve">' . SpreadsheetText::xml( $text ) . '</t></is></c>' ) );
		}
		self::put( $this->handle, '</row>' );
	}
	/**
	 * Finaliza el archivo.
	 *
	 * @throws \LogicException Si el escritor no está abierto.
	 * @throws \RuntimeException Si falla el almacenamiento.
	 * @throws \Throwable Si el empaquetado falla.
	 * @return void
	 */
	public function close(): void {
		if ( ! is_resource( $this->handle ) || 0 === $this->columns ) {
			throw new \LogicException( 'tbreg_writer_closed' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Cierra un recurso local incremental.
		if ( ! fclose( $this->handle ) ) {
			throw new \RuntimeException( 'tbreg_export_io' );
		}
		$this->handle     = null;
		$this->sheet_path = self::temporary();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Hoja XML ensamblada en disco.
		$sheet = fopen( $this->sheet_path, 'wb' );
		if ( false === $sheet ) {
			throw new \RuntimeException( 'tbreg_export_io' );
		}
		try {
			$last = self::column( $this->columns );
			self::put( $sheet, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="' . self::NS . '"><dimension ref="A1:' . $last . $this->rows . '"/><sheetViews><sheetView workbookViewId="0" showGridLines="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft" activeCell="A2" sqref="A2"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="15"/><cols>' );
			foreach ( $this->widths as $index => $width ) {
				self::put( $sheet, '<col min="' . ( $index + 1 ) . '" max="' . ( $index + 1 ) . '" width="' . $width . '" customWidth="1"/>' );
			}
			self::put( $sheet, '</cols><sheetData>' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Lee las filas sin cargarlas completas.
			$body = fopen( $this->body_path, 'rb' );
			if ( false === $body ) {
				throw new \RuntimeException( 'tbreg_export_io' );
			}
			try {
				if ( false === stream_copy_to_stream( $body, $sheet ) ) {
					throw new \RuntimeException( 'tbreg_export_io' );
				}
			} finally {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Recurso local de escritura o descarga incremental.
				fclose( $body );
			}
				self::put( $sheet, '</sheetData><autoFilter ref="A1:' . $last . $this->rows . '"/></worksheet>' );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Recurso local de escritura o descarga incremental.
			fclose( $sheet );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $this->path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			throw new \RuntimeException( 'tbreg_export_zip' );
		}
		$closing = false;
		try {
			foreach ( $this->parts() as $name => $xml ) {
				if ( ! $zip->addFromString( $name, $xml ) ) {
					throw new \RuntimeException( 'tbreg_export_zip' );
				}
			}
			if ( ! $zip->addFile( $this->sheet_path, 'xl/worksheets/sheet1.xml' ) ) {
				throw new \RuntimeException( 'tbreg_export_zip' );
			}
			$closing = true;
			if ( ! $zip->close() ) {
				throw new \RuntimeException( 'tbreg_export_zip' );
			}
		} catch ( \Throwable $exception ) {
			if ( ! $closing ) {
				$zip->close();
			}
			throw $exception;
		}
		wp_delete_file( $this->body_path );
		wp_delete_file( $this->sheet_path );
		$this->body_path  = '';
		$this->sheet_path = '';
		$this->path       = '';
	}
	/**
	 * Partes pequeñas del paquete Open XML.
	 *
	 * @return array<string,string>
	 */
	private function parts(): array {
		$prefix = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
		$rels   = 'http://schemas.openxmlformats.org/package/2006/relationships';
		$office = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
		return array(
			'[Content_Types].xml'        => $prefix . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>',
			'_rels/.rels'                => $prefix . '<Relationships xmlns="' . $rels . '"><Relationship Id="rId1" Type="' . $office . '/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="' . $office . '/extended-properties" Target="docProps/app.xml"/></Relationships>',
			'xl/workbook.xml'            => $prefix . '<workbook xmlns="' . self::NS . '" xmlns:r="' . $office . '"><bookViews><workbookView/></bookViews><sheets><sheet name="' . SpreadsheetText::xml( $this->name ) . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
			'xl/_rels/workbook.xml.rels' => $prefix . '<Relationships xmlns="' . $rels . '"><Relationship Id="rId1" Type="' . $office . '/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="' . $office . '/styles" Target="styles.xml"/></Relationships>',
			'xl/styles.xml'              => $prefix . '<styleSheet xmlns="' . self::NS . '"><numFmts count="1"><numFmt numFmtId="164" formatCode="dd/mm/yyyy hh:mm:ss"/></numFmts><fonts count="2"><font><sz val="11"/><name val="Arial"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Arial"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF135E96"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="2"><border/><border><left style="thin"><color rgb="FFFFFFFF"/></left><right style="thin"><color rgb="FFFFFFFF"/></right><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"><alignment vertical="center"/></xf><xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"><alignment vertical="center"/></xf><xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"><alignment vertical="center"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
			'docProps/core.xml'          => $prefix . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:creator>ToolsBox Registros</dc:creator><dc:title>' . SpreadsheetText::xml( $this->name ) . '</dc:title><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate( 'Y-m-d\TH:i:s\Z' ) . '</dcterms:created></cp:coreProperties>',
			'docProps/app.xml'           => $prefix . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>ToolsBox Registros</Application></Properties>',
		);
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
		foreach ( array( $this->body_path, $this->sheet_path, $this->path ) as $path ) {
			if ( '' !== $path ) {
				wp_delete_file( $path );
			}
		}
		$this->body_path  = '';
		$this->sheet_path = '';
		$this->path       = '';
	}
	/** Elimina archivos parciales si se interrumpe al llamador. */
	public function __destruct() {
		$this->abort();
	}
}
