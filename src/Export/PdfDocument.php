<?php
/**
 * PDF nativo con texto Unicode y fuente TrueType incluida.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Export;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }
/** Compone páginas carta sin HTML, JavaScript ni recursos remotos. */
final class PdfDocument {
	/**
	 * Métricas Unicode.
	 *
	 * @var array<string,mixed>
	 */
	private array $metrics;
	/**
	 * Código PDF por página.
	 *
	 * @var array<string>
	 */
	private array $pages = array();
	/**
	 * Imágenes JPEG por página.
	 *
	 * @var array<string>
	 */
	private array $photos = array();
	/**
	 * Mapa de carácter a CID.
	 *
	 * @var array<string,int>
	 */
	private array $characters = array();
	/**
	 * Página actual.
	 *
	 * @var int
	 */
	private int $page = -1;
	/**
	 * Carga únicamente recursos propios, de rutas fijas.
	 */
	public function __construct() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Recurso estático del plugin, no entrada del usuario.
		$this->metrics = json_decode( (string) file_get_contents( TBREG_PATH . 'assets/fonts/DejaVuSans-metrics.json' ), true, 512, JSON_THROW_ON_ERROR );
	}
	/**
	 * Agrega una página carta.
	 *
	 * @return void
	 */
	public function page(): void {
		$this->pages[] = '';
		++$this->page; }
	/**
	 * Mide texto con las métricas reales de la fuente incluida.
	 *
	 * @param string $text Texto.
	 * @param float  $size Puntos.
	 * @return float
	 */
	public function width( string $text, float $size ): float {
		$width = 0;
		foreach ( mb_str_split( $text ) as $char ) {
			$width += $this->metrics['chars'][ mb_ord( $char ) ][1] ?? 600;
		} return $width * $size / 1000;
	}
	/**
	 * Divide líneas por anchura, conservando contenido y saltos explícitos.
	 *
	 * @param string $text Texto.
	 * @param float  $width Ancho en puntos.
	 * @param float  $size Tamaño de fuente.
	 * @return array<string>
	 */
	public function wrap( string $text, float $width, float $size = 10 ): array {
		$lines = array();
		foreach ( explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", SpreadsheetText::clean( $text ) ) ) as $paragraph ) {
			$line       = '';
			$last_space = -1;
			foreach ( mb_str_split( $paragraph ) as $char ) {
				if ( '' !== $line && $this->width( $line . $char, $size ) > $width ) {
					if ( $last_space > 0 ) {
						$lines[] = mb_substr( $line, 0, $last_space );
						$line    = mb_substr( $line, $last_space + 1 );
					} else {
						$lines[] = $line;
						$line    = ''; }
					$position   = mb_strrpos( $line, ' ' );
					$last_space = false === $position ? -1 : $position;
				}
				$line .= $char;
				if ( ' ' === $char ) {
					$last_space = mb_strlen( $line ) - 1; }
			}
			$lines[] = $line;
		}
		return $lines;
	}
	/**
	 * Dibuja texto con CID y mapa ToUnicode; no interpola comandos PDF.
	 *
	 * @param string $text Texto.
	 * @param float  $x Posición horizontal.
	 * @param float  $y Distancia desde arriba.
	 * @param float  $size Tamaño.
	 * @param string $color Color interno RGB.
	 * @return void
	 * @throws \RuntimeException Si se supera el espacio de códigos CID del PDF.
	 */
	public function text( string $text, float $x, float $y, float $size = 10, string $color = '0.12 0.17 0.23' ): void {
		$hex = '';
		foreach ( mb_str_split( SpreadsheetText::clean( $text ) ) as $char ) {
			if ( ! isset( $this->characters[ $char ] ) ) {
				if ( count( $this->characters ) >= 65534 ) {
					throw new \RuntimeException( 'tbreg_pdf_characters' ); }
				$this->characters[ $char ] = count( $this->characters ) + 1; }
			$hex .= sprintf( '%04X', $this->characters[ $char ] );
		}
		$this->pages[ $this->page ] .= sprintf( "BT /F1 %.2F Tf %s rg 1 0 0 1 %.2F %.2F Tm <%s> Tj ET\n", $size, $color, $x, 792 - $y, $hex );
	}
	/**
	 * Dibuja una regla o un fondo.
	 *
	 * @param float  $x Izquierda.
	 * @param float  $y Arriba.
	 * @param float  $width Ancho.
	 * @param float  $height Alto.
	 * @param string $color RGB interno.
	 * @return void
	 */
	public function rectangle( float $x, float $y, float $width, float $height, string $color ): void {
		$this->pages[ $this->page ] .= sprintf( "q %s rg %.2F %.2F %.2F %.2F re f Q\n", $color, $x, 792 - $y - $height, $width, $height ); }
	/**
	 * Inserta una imagen previamente saneada, sin archivos externos.
	 *
	 * @param string $jpeg JPEG propio.
	 * @param float  $x Izquierda.
	 * @param float  $y Arriba.
	 * @param float  $width Ancho máximo.
	 * @param float  $height Alto máximo.
	 * @return void
	 */
	public function photo( string $jpeg, float $x, float $y, float $width, float $height ): void {
		$info                        = getimagesizefromstring( $jpeg );
		$scale                       = min( $width / $info[0], $height / $info[1] );
		$width                       = $info[0] * $scale;
		$height                      = $info[1] * $scale;
		$this->photos[ $this->page ] = $jpeg;
		$this->pages[ $this->page ] .= sprintf( "q %.2F 0 0 %.2F %.2F %.2F cm /Photo Do Q\n", $width, $height, $x, 792 - $y - $height );
	}
	/**
	 * Empaqueta objetos, streams y tabla xref sin dependencias.
	 *
	 * @return string
	 */
	public function bytes(): string {
		$objects = array(
			1 => '<< /Type /Catalog /Pages 2 0 R >>',
			2 => '',
		);
		$add     = static function ( string $body ) use ( &$objects ): int {
			$id             = count( $objects ) + 1;
			$objects[ $id ] = $body;
			return $id;
		};
		$stream  = static function ( string $bytes, string $extra = '' ): string {
			return '<< /Length ' . strlen( $bytes ) . ' ' . $extra . ">>\nstream\n" . $bytes . "\nendstream";
		};
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Fuente redistribuible incluida, ruta fija.
		$font_bytes = (string) file_get_contents( TBREG_PATH . 'assets/fonts/DejaVuSans.ttf' );
		$font_file  = $add( $stream( gzcompress( $font_bytes ), '/Filter /FlateDecode /Length1 ' . strlen( $font_bytes ) ) );
		$descriptor = $add( '<< /Type /FontDescriptor /FontName /DejaVuSans /Flags 32 /FontBBox [' . implode( ' ', $this->metrics['bbox'] ) . '] /ItalicAngle 0 /Ascent ' . $this->metrics['ascent'] . ' /Descent ' . $this->metrics['descent'] . ' /CapHeight 729 /StemV 80 /FontFile2 ' . $font_file . ' 0 R >>' );
		$glyph_map  = pack( 'n', 0 );
		$widths     = array();
		$maps       = array();
		foreach ( $this->characters as $char => $cid ) {
			$metric     = $this->metrics['chars'][ mb_ord( (string) $char ) ] ?? array( 0, 600 );
			$glyph_map .= pack( 'n', $metric[0] );
			$widths[]   = $metric[1];
			$maps[]     = sprintf( '<%04X> <%s>', $cid, strtoupper( bin2hex( mb_convert_encoding( (string) $char, 'UTF-16BE', 'UTF-8' ) ) ) );
		}
		$glyph_object = $add( $stream( $glyph_map ) );
		$cmap         = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n/CMapName /ToolsBoxUnicode def\n/CMapType 2 def\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n";
		foreach ( array_chunk( $maps, 100 ) as $chunk ) {
			$cmap .= count( $chunk ) . " beginbfchar\n" . implode( "\n", $chunk ) . "\nendbfchar\n"; }
		$cmap       .= "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend";
		$cmap_object = $add( $stream( $cmap ) );
		$descendant  = $add( '<< /Type /Font /Subtype /CIDFontType2 /BaseFont /DejaVuSans /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor ' . $descriptor . ' 0 R /DW 600 /W [1 [' . implode( ' ', $widths ) . ']] /CIDToGIDMap ' . $glyph_object . ' 0 R >>' );
		$font        = $add( '<< /Type /Font /Subtype /Type0 /BaseFont /DejaVuSans /Encoding /Identity-H /DescendantFonts [' . $descendant . ' 0 R] /ToUnicode ' . $cmap_object . ' 0 R >>' );
		$kids        = array();
		foreach ( $this->pages as $index => $content ) {
			$resources = '/Font << /F1 ' . $font . ' 0 R >>';
			if ( isset( $this->photos[ $index ] ) ) {
				$jpeg       = $this->photos[ $index ];
				$info       = getimagesizefromstring( $jpeg );
				$image      = $add( $stream( $jpeg, '/Type /XObject /Subtype /Image /Width ' . $info[0] . ' /Height ' . $info[1] . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode' ) );
				$resources .= ' /XObject << /Photo ' . $image . ' 0 R >>';
			}
			$content_id = $add( $stream( gzcompress( $content ), '/Filter /FlateDecode' ) );
			$page_id    = $add( '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << ' . $resources . ' >> /Contents ' . $content_id . ' 0 R >>' );
			$kids[]     = $page_id . ' 0 R';
		}
		$objects[2] = '<< /Type /Pages /Count ' . count( $kids ) . ' /Kids [' . implode( ' ', $kids ) . '] >>';
		$pdf        = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets    = array( 0 );
		foreach ( $objects as $id => $object ) {
			$offsets[ $id ] = strlen( $pdf );
			$pdf           .= $id . " 0 obj\n" . $object . "\nendobj\n"; }
		$xref  = strlen( $pdf );
		$count = count( $objects ) + 1;
		$pdf  .= "xref\n0 " . $count . "\n0000000000 65535 f \n";
		foreach ( array_slice( $offsets, 1 ) as $offset ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offset ); }
		return $pdf . 'trailer << /Size ' . $count . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
	}
}
