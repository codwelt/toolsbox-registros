<?php
/** Escritores nativos: paquete, tipos, Unicode y CSV seguro. @package ToolsBoxRegistros */
declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Tests\Unit;
use Codwelt\ToolsBoxRegistros\Export\CsvWriter;
use Codwelt\ToolsBoxRegistros\Export\SpreadsheetText;
use Codwelt\ToolsBoxRegistros\Export\XlsxWriter;
use PHPUnit\Framework\TestCase;
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class ExportWritersTest extends TestCase {
	private array $paths = array();
	private function path(): string { $path = XlsxWriter::temporary(); $this->paths[] = $path; return $path; }
	protected function tearDown(): void { foreach ( $this->paths as $path ) wp_delete_file( $path ); }
	private function xml( string $path, string $part ): \DOMXPath {
		$zip = new \ZipArchive(); $this->assertTrue( $zip->open( $path ) ); $xml = $zip->getFromName( $part ); $zip->close();
		$this->assertIsString( $xml ); $doc = new \DOMDocument(); $this->assertTrue( $doc->loadXML( $xml, LIBXML_NONET ) );
		$xpath = new \DOMXPath( $doc ); $xpath->registerNamespace( 's', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' ); return $xpath;
	}
	public function test_package_has_valid_parts_styles_freeze_and_autofilter_without_formulas(): void {
		$path = $this->path(); $writer = new XlsxWriter( "'Bogotá [evento]: 😀 / más *?\\'", array( 'number', 'date', 'text', 'text', 'number', 'number', 'text' ) ); $writer->open( $path );
		$writer->writeHeader( array( 'ID', 'Fecha', 'Documento', 'Nombre & ciudad', 'Importe', 'Número largo', '=Cabecera' ) );
		$writer->writeRow( array( 123, new \DateTimeImmutable( '2026-10-13 00:00:00', new \DateTimeZone( 'America/Bogota' ) ), '00012345678901234567', "Muñoz 😀 & <hola> \"sí\"\nBogotá\x00\x01", '12.5', '1234567890123456', '=HYPERLINK("https://example.test","x")' ) ); $writer->close();
		$zip = new \ZipArchive(); $this->assertTrue( $zip->open( $path ) ); $this->assertSame( 8, $zip->numFiles );
		for ( $i = 0; $i < $zip->numFiles; ++$i ) { $doc = new \DOMDocument(); $this->assertTrue( $doc->loadXML( $zip->getFromIndex( $i ), LIBXML_NONET ), $zip->getNameIndex( $i ) ); } $zip->close();
		$sheet = $this->xml( $path, 'xl/worksheets/sheet1.xml' );
		$this->assertSame( 'A1:G2', $sheet->evaluate( 'string(/s:worksheet/s:dimension/@ref)' ) ); $this->assertSame( 'A1:G2', $sheet->evaluate( 'string(//s:autoFilter/@ref)' ) );
		$this->assertSame( 'frozen', $sheet->evaluate( 'string(//s:pane/@state)' ) ); $this->assertSame( 'A2', $sheet->evaluate( 'string(//s:pane/@topLeftCell)' ) ); $this->assertSame( 7.0, $sheet->evaluate( 'count(//s:col)' ) );
		$this->assertSame( 0.0, $sheet->evaluate( 'count(//s:f)' ) );
		$this->assertSame( 'n', $sheet->evaluate( 'string(//s:c[@r="A2"]/@t)' ) ); $this->assertSame( '2', $sheet->evaluate( 'string(//s:c[@r="B2"]/@s)' ) );
		$this->assertEqualsWithDelta( 46308, (float) $sheet->evaluate( 'string(//s:c[@r="B2"]/s:v)' ), 0.000001 );
		$this->assertSame( '00012345678901234567', $sheet->evaluate( 'string(//s:c[@r="C2"]/s:is/s:t)' ) );
		$this->assertSame( "Muñoz 😀 & <hola> \"sí\"\nBogotá", $sheet->evaluate( 'string(//s:c[@r="D2"]/s:is/s:t)' ) );
		$this->assertSame( 'n', $sheet->evaluate( 'string(//s:c[@r="E2"]/@t)' ) ); $this->assertSame( 'inlineStr', $sheet->evaluate( 'string(//s:c[@r="F2"]/@t)' ) );
		$this->assertSame( '=HYPERLINK("https://example.test","x")', $sheet->evaluate( 'string(//s:c[@r="G2"]/s:is/s:t)' ) );
		$this->assertSame( '=Cabecera', $sheet->evaluate( 'string(//s:c[@r="G1"]/s:is/s:t)' ) );
		$workbook = $this->xml( $path, 'xl/workbook.xml' ); $name = $workbook->evaluate( 'string(//s:sheet/@name)' ); $this->assertLessThanOrEqual( 31, strlen( mb_convert_encoding( $name, 'UTF-16LE', 'UTF-8' ) ) / 2 ); $this->assertDoesNotMatchRegularExpression( '/[\[\]:*?\/\\\\]/u', $name );
		$styles = $this->xml( $path, 'xl/styles.xml' ); $this->assertSame( 'dd/mm/yyyy hh:mm:ss', $styles->evaluate( 'string(//s:numFmt/@formatCode)' ) );
	}
	public function test_long_unicode_cells_do_not_cut_surrogate_pairs_and_empty_export_has_headers(): void {
		$path = $this->path(); $writer = new XlsxWriter( str_repeat( '😀', 30 ) ); $writer->open( $path ); $writer->writeHeader( array( 'Texto' ) ); $writer->writeRow( array( str_repeat( '😀', 17000 ) ) ); $writer->close();
		$text = $this->xml( $path, 'xl/worksheets/sheet1.xml' )->evaluate( 'string(//s:c[@r="A2"]/s:is/s:t)' ); $this->assertSame( str_repeat( '😀', 16383 ), $text );
		$this->assertSame( 'Inscritos', SpreadsheetText::sheet_name( "''[]:*?/\\" ) ); $this->assertSame( 'Inscritos', SpreadsheetText::sheet_name( 'History' ) );
		$empty = $this->path(); $writer = new XlsxWriter(); $writer->open( $empty ); $writer->writeHeader( array( 'Vacío' ) ); $writer->close(); $this->assertSame( 'A1:A1', $this->xml( $empty, 'xl/worksheets/sheet1.xml' )->evaluate( 'string(//s:dimension/@ref)' ) );
		foreach ( array( 1 => 'A', 26 => 'Z', 27 => 'AA', 702 => 'ZZ', 703 => 'AAA', 16384 => 'XFD' ) as $index => $expected ) $this->assertSame( $expected, XlsxWriter::column( $index ) );
	}
	public function test_numeric_cells_preserve_precision_underflow_and_text_identifiers(): void {
		$path = $this->path(); $writer = new XlsxWriter( '', array( 'number', 'number', 'number', 'number', 'number', 'number' ) ); $writer->open( $path ); $writer->writeHeader( array_fill( 0, 6, 'Valor' ) ); $writer->writeRow( array( '123456789012345', '1e-999', '1e999', '0', '-12.5', '=1+1' ) ); $writer->close();
		$sheet = $this->xml( $path, 'xl/worksheets/sheet1.xml' ); foreach ( array( 'A2' => 'n', 'B2' => 'inlineStr', 'C2' => 'inlineStr', 'D2' => 'n', 'E2' => 'n', 'F2' => 'inlineStr' ) as $address => $type ) $this->assertSame( $type, $sheet->evaluate( 'string(//s:c[@r="' . $address . '"]/@t)' ) );
	}
	public function test_csv_has_bom_both_delimiters_unicode_quotes_and_formula_protection(): void {
		foreach ( array( ';', ',' ) as $delimiter ) {
			$path = $this->path(); $writer = new CsvWriter( $delimiter ); $writer->open( $path ); $writer->writeHeader( array( '=Título', 'Nombre', 'Fecha' ) );
			foreach ( array( '=HYPERLINK("x")', '+SUM(1)', '-1+2', '@SUM(1)', "\t=1", "\r=1", "\n=1", "\u{00A0}=1" ) as $formula ) $writer->writeRow( array( $formula, "Ana Muñoz 😀; Bogotá, \"sí\"\nMedellín", new \DateTimeImmutable( '2026-10-13 12:34:56' ) ) ); $writer->close();
			$handle = fopen( $path, 'rb' ); $this->assertSame( "\xEF\xBB\xBF", fread( $handle, 3 ) ); $this->assertSame( array( "'=Título", 'Nombre', 'Fecha' ), fgetcsv( $handle, 0, $delimiter, '"', '' ) );
			while ( false !== ( $row = fgetcsv( $handle, 0, $delimiter, '"', '' ) ) ) { $this->assertStringStartsWith( "'", $row[0] ); $this->assertSame( "Ana Muñoz 😀; Bogotá, \"sí\"\nMedellín", $row[1] ); $this->assertSame( '2026-10-13 12:34:56', $row[2] ); } fclose( $handle );
		}
	}
	public function test_invalid_rows_and_abort_remove_partial_output(): void {
		foreach ( array( new CsvWriter(), new XlsxWriter() ) as $writer ) {
			$path = $this->path(); $writer->open( $path ); $writer->writeHeader( array( 'Valor' ) );
			try { $writer->writeRow( array() ); $this->fail( 'Fila incompatible admitida.' ); } catch ( \LogicException $exception ) { $this->assertSame( 'tbreg_export_row', $exception->getMessage() ); }
			try { $writer->writeRow( array( array( 'no escalar' ) ) ); $this->fail( 'Array admitido.' ); } catch ( \InvalidArgumentException $exception ) { $this->assertSame( 'tbreg_export_cell', $exception->getMessage() ); }
			$writer->abort(); $writer->abort(); $this->assertFileDoesNotExist( $path );
		}
		$this->expectException( \InvalidArgumentException::class ); new CsvWriter( '|' );
	}
}
