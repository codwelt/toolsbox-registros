<?php
/**
 * Formato de inscripción y matrícula con diseño configurable.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Export;

use Codwelt\ToolsBoxRegistros\Domain\Field;
use Codwelt\ToolsBoxRegistros\Domain\Form;
use Codwelt\ToolsBoxRegistros\Domain\FieldTypeRegistry;
use Codwelt\ToolsBoxRegistros\Services\Photo;
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
/** Imprime todas las opciones con casillas, como un formulario físico; sin inscripción queda en blanco. */
final class EnrollmentPdf {
	private const LEFT   = 36;
	private const WIDTH  = 540;
	private const BOTTOM = 742;
	private const TEXT   = '0.12 0.17 0.23';
	private const MUTED  = '0.42 0.47 0.52';
	private const RULE   = '0.62 0.66 0.70';
	/**
	 * Documento.
	 *
	 * @var PdfDocument
	 */
	private PdfDocument $pdf;
	/**
	 * Diseño normalizado.
	 *
	 * @var array<string,mixed>
	 */
	private array $design;
	/**
	 * Colores RGB internos.
	 *
	 * @var array<string,string>
	 */
	private array $colors;
	/**
	 * Título de la banda superior.
	 *
	 * @var string
	 */
	private string $title = '';
	/**
	 * Página actual.
	 *
	 * @var int
	 */
	private int $page = 0;
	/**
	 * Cursor vertical.
	 *
	 * @var float
	 */
	private float $y = 0;
	/**
	 * Inicio del recuadro abierto en la página actual; -1 sin recuadro.
	 *
	 * @var float
	 */
	private float $box_top = -1;
	/**
	 * Fin del recuadro de la foto, que reduce el ancho de las filas.
	 *
	 * @var float
	 */
	private float $photo_bottom = 0;
	/**
	 * Genera el PDF; con ID 0 produce el formato en blanco.
	 *
	 * @param Form                $form Esquema.
	 * @param array<string,mixed> $entry Metadatos.
	 * @param array<string,mixed> $values Respuestas guardadas.
	 * @return string
	 */
	public function build( Form $form, array $entry, array $values ): string {
		$this->pdf    = new PdfDocument();
		$this->design = $form->settings()['pdf'];
		$this->colors = array(
			'primary' => PdfDesign::rgb( $this->design['primary_color'] ),
			'accent'  => PdfDesign::rgb( $this->design['accent_color'] ),
		);
		$this->title  = '' !== $this->design['title'] ? $this->design['title'] : wp_strip_all_tags( get_bloginfo( 'name' ) );
		$blank        = 0 === (int) ( $entry['id'] ?? 0 );
		$this->header( $form, $entry, $blank );
		$this->fields( $form, $blank ? array() : $values, $blank );
		$this->office();
		$this->terms();
		$this->signatures();
		return $this->pdf->bytes();
	}
	/**
	 * Agrega una página con pie y, en continuaciones, una banda reducida.
	 *
	 * @param bool $first Primera página.
	 * @return void
	 */
	private function new_page( bool $first ): void {
		$this->pdf->page();
		++$this->page;
		$this->photo_bottom = 0;
		foreach ( array_slice( $this->pdf->wrap( $this->design['footer'], 460, 7.5 ), 0, 2 ) as $index => $line ) {
			$this->pdf->text( $line, self::LEFT, 764 + $index * 10, 7.5, self::MUTED ); }
		$number = __( 'Página', 'toolsbox-registros' ) . ' ' . $this->page;
		$this->pdf->text( $number, self::LEFT + self::WIDTH - $this->pdf->width( $number, 7.5 ), 764, 7.5, self::MUTED );
		if ( ! $first ) {
			$this->pdf->rectangle( 0, 0, 612, 24, $this->colors['primary'] );
			$this->pdf->text( $this->fit( mb_strtoupper( $this->title ), self::WIDTH, 10 ), self::LEFT, 16, 10, '1 1 1', true );
			$this->y = 44;
		}
	}
	/**
	 * Reserva espacio vertical, continuando el recuadro abierto en otra página.
	 *
	 * @param float $height Altura necesaria.
	 * @return void
	 */
	private function ensure( float $height ): void {
		if ( $this->y + $height <= self::BOTTOM ) {
			return; }
		$open = $this->box_top >= 0;
		if ( $open ) {
			$this->pdf->outline( self::LEFT, $this->box_top, self::WIDTH, $this->y - $this->box_top, self::RULE ); }
		$this->new_page( false );
		if ( $open ) {
			$this->box_top = $this->y;
			$this->y      += 14;
		}
	}
	/**
	 * Recorta texto de una sola línea al ancho disponible.
	 *
	 * @param string $text Texto.
	 * @param float  $width Ancho.
	 * @param float  $size Tamaño.
	 * @return string
	 */
	private function fit( string $text, float $width, float $size ): string {
		return $this->pdf->wrap( $text, $width, $size )[0] ?? '';
	}
	/**
	 * Banda, logo, subtítulo y fecha de la primera página.
	 *
	 * @param Form                $form Esquema.
	 * @param array<string,mixed> $entry Metadatos.
	 * @param bool                $blank Formato sin datos.
	 * @return void
	 */
	private function header( Form $form, array $entry, bool $blank ): void {
		$this->new_page( true );
		$this->pdf->rectangle( 0, 0, 612, 44, $this->colors['primary'] );
		$title = mb_strtoupper( $this->title );
		$size  = 20;
		while ( $size > 10 && $this->pdf->width( $title, $size ) > self::WIDTH ) {
			--$size; }
		$title = $this->fit( $title, self::WIDTH, $size );
		$this->pdf->text( $title, ( 612 - $this->pdf->width( $title, $size ) ) / 2, 22 + $size * 0.36, $size, '1 1 1', true );
		$logo  = PdfDesign::logo( $this->design['logo_id'] );
		$width = self::WIDTH;
		if ( '' !== $logo ) {
			$this->pdf->photo( $logo, self::LEFT + self::WIDTH - 96, 54, 96, 96 );
			$width -= 110;
		}
		$lines = '' !== $this->design['subtitle'] ? explode( "\n", $this->design['subtitle'] ) : array( __( 'Formato de inscripción y matrícula', 'toolsbox-registros' ), $form->get( 'title' ) );
		$y     = 76;
		foreach ( $lines as $line ) {
			foreach ( $this->pdf->wrap( mb_strtoupper( trim( $line ) ), $width, 13 ) as $wrapped ) {
				$this->pdf->text( $wrapped, self::LEFT, $y, 13, $this->colors['primary'], true );
				$y += 17;
			}
		}
		$y    += 4;
		$label = __( 'Fecha', 'toolsbox-registros' );
		$value = $blank ? __( 'DD / MM / AAAA', 'toolsbox-registros' ) : get_date_from_gmt( (string) $entry['created_at'], 'd/m/Y' );
		$this->pdf->outline( self::LEFT, $y, 180, 22, self::RULE );
		$this->pdf->text( $label, self::LEFT + 8, $y + 15, 9, self::TEXT, true );
		$this->pdf->text( $value, self::LEFT + 16 + $this->pdf->width( $label, 9 ), $y + 15, 10, $blank ? self::MUTED : self::TEXT );
		if ( ! $blank ) {
			$this->pdf->text( __( 'Inscripción', 'toolsbox-registros' ) . ' #' . $entry['id'], self::LEFT + 194, $y + 15, 9, self::MUTED ); }
		$this->y = max( $y + 40, '' !== $logo ? 164 : 0 );
	}
	/**
	 * Abre un recuadro, opcionalmente con una pestaña de título.
	 *
	 * @param string $title Título de sección.
	 * @return void
	 */
	private function open_box( string $title = '' ): void {
		$this->close_box();
		if ( '' !== $title ) {
			$this->ensure( 60 );
			$title = $this->fit( mb_strtoupper( $title ), self::WIDTH - 40, 9 );
			$width = $this->pdf->width( $title, 9 ) + 28;
			$x     = self::LEFT + ( self::WIDTH - $width ) / 2;
			$this->pdf->rectangle( $x, $this->y, $width, 18, $this->colors['accent'] );
			$this->pdf->text( $title, $x + 14, $this->y + 12.5, 9, '1 1 1', true );
			$this->y += 18;
		} else {
			$this->ensure( 40 );
		}
		$this->box_top = $this->y;
		$this->y      += 16;
	}
	/**
	 * Cierra el recuadro abierto.
	 *
	 * @return void
	 */
	private function close_box(): void {
		if ( $this->box_top < 0 ) {
			return; }
		$this->y = max( $this->y, $this->photo_bottom );
		$this->pdf->outline( self::LEFT, $this->box_top, self::WIDTH, $this->y - $this->box_top, self::RULE );
		$this->box_top = -1;
		$this->y      += 16;
	}
	/**
	 * Recorre los campos en orden: títulos, filas de datos, opciones y autorizaciones.
	 *
	 * @param Form                $form Esquema.
	 * @param array<string,mixed> $values Respuestas.
	 * @param bool                $blank Formato sin datos.
	 * @return void
	 */
	private function fields( Form $form, array $values, bool $blank ): void {
		$registry = new FieldTypeRegistry();
		$fields   = array_values( array_filter( $form->fields(), static fn ( Field $field ): bool => ! $field->get( 'archived' ) && 'hidden' !== $field->get( 'type' ) ) );
		$photo    = '';
		$framed   = false;
		foreach ( $fields as $field ) {
			if ( 'photo' === $field->get( 'type' ) ) {
				$framed = true;
				$photo  = Photo::jpeg( (string) ( $values[ $field->key() ] ?? '' ) );
				break;
			}
		}
		$framed = $framed && $this->design['photo_box'];
		$row    = array();
		$used   = 0.0;
		$first  = true;
		foreach ( $fields as $field ) {
			$type = $field->get( 'type' );
			if ( 'photo' === $type ) {
				continue; }
			$is_input = ! in_array( $type, array( 'heading', 'consent' ), true ) && ! ( $this->design['show_options'] && array() !== $field->get( 'options' ) );
			$share    = array(
				'half'  => 0.5,
				'third' => 1 / 3,
			)[ $field->get( 'width' ) ] ?? 1.0;
			if ( array() !== $row && ( ! $is_input || $used + $share > 1.001 ) ) {
				$this->input_row( $row );
				$row  = array();
				$used = 0.0;
			}
			if ( 'heading' === $type ) {
				$this->open_box( (string) $field->get( 'label' ) );
			} elseif ( $first ) {
				$this->open_box();
			}
			if ( $first && $framed ) {
				$this->photo_frame( $photo );
			}
			$first = false;
			if ( 'heading' === $type ) {
				continue; }
			$stored = $values[ $field->key() ] ?? null;
			if ( 'consent' === $type ) {
				$this->consent( (string) $field->get( 'label' ), '1' === $stored );
			} elseif ( $is_input ) {
				$text  = null === $stored || $blank ? '' : $registry->get( $type )->toExport( $stored, $field );
				$row[] = array( (string) $field->get( 'label' ), $text, $share );
				$used += $share;
			} else {
				$selected = array_map( 'strval', is_array( $stored ) ? $stored : ( null === $stored ? array() : array( $stored ) ) );
				$this->options( (string) $field->get( 'label' ), $field->get( 'options' ), $selected );
			}
		}
		if ( array() !== $row ) {
			$this->input_row( $row ); }
		$this->close_box();
	}
	/**
	 * Recuadro de la foto a la derecha del primer bloque.
	 *
	 * @param string $jpeg Foto de la inscripción, si existe.
	 * @return void
	 */
	private function photo_frame( string $jpeg ): void {
		$x = self::LEFT + self::WIDTH - 104;
		$this->pdf->outline( $x, $this->y - 6, 94, 112, self::RULE );
		if ( '' !== $jpeg ) {
			$this->pdf->photo( $jpeg, $x + 3, $this->y - 3, 88, 106 );
		} else {
			$label = __( 'Foto', 'toolsbox-registros' );
			$this->pdf->text( $label, $x + ( 94 - $this->pdf->width( $label, 8 ) ) / 2, $this->y + 52, 8, self::MUTED );
		}
		$this->photo_bottom = $this->y + 116;
	}
	/**
	 * Ancho útil dentro del recuadro, descontando la foto.
	 *
	 * @return float
	 */
	private function inner_width(): float {
		return $this->y < $this->photo_bottom ? self::WIDTH - 124 : self::WIDTH - 20;
	}
	/**
	 * Fila de campos de texto: etiqueta y valor sobre una línea.
	 *
	 * @param array<array{0:string,1:string,2:float}> $cells Etiqueta, valor y proporción.
	 * @return void
	 */
	private function input_row( array $cells ): void {
		$prepared = array();
		$height   = 0;
		$total    = $this->inner_width();
		$x        = self::LEFT + 10;
		foreach ( $cells as $cell ) {
			$width  = $total * $cell[2] - ( count( $cells ) > 1 ? 12 : 0 );
			$label  = $this->fit( mb_strtoupper( $cell[0] ), $width, 7.5 );
			$offset = $this->pdf->width( $label, 7.5 ) + 6;
			$lines  = $this->pdf->wrap( $cell[1], max( 40, $width - $offset ), 9.5 );
			if ( $width - $offset < 60 || count( $lines ) > 1 ) {
				$offset = 0;
				$lines  = $this->pdf->wrap( $cell[1], $width, 9.5 );
				array_unshift( $lines, null );
			}
			$prepared[] = array( $x, $width, $label, $offset, $lines );
			$height     = max( $height, count( $lines ) * 15 + 8 );
			$x         += $total * $cell[2];
		}
		$this->ensure( $height );
		foreach ( $prepared as $cell ) {
			list( $x, $width, $label, $offset, $lines ) = $cell;
			$this->pdf->text( $label, $x, $this->y, 7.5, self::TEXT, true );
			foreach ( $lines as $index => $line ) {
				$base = $this->y + $index * 15;
				if ( null === $line ) {
					continue; }
				$this->pdf->text( $line, $x + $offset, $base - 1, 9.5 );
				$this->pdf->rectangle( $x + $offset, $base + 3, $width - $offset, 0.6, self::RULE );
			}
		}
		$this->y += $height;
	}
	/**
	 * Todas las opciones de un campo con casillas, marcando las elegidas.
	 *
	 * @param string                         $label Etiqueta del campo.
	 * @param array<int,array<string,mixed>> $options Opciones.
	 * @param array<string>                  $selected Valores elegidos.
	 * @return void
	 */
	private function options( string $label, array $options, array $selected ): void {
		$label = $this->fit( mb_strtoupper( $label ), self::WIDTH - 40, 7.5 );
		$this->ensure( 34 );
		$this->pdf->rectangle( self::LEFT + 10, $this->y - 10, $this->pdf->width( $label, 7.5 ) + 14, 14, $this->colors['primary'] );
		$this->pdf->text( $label, self::LEFT + 17, $this->y, 7.5, '1 1 1', true );
		$this->y += 16;
		$columns  = count( $options ) <= 2 ? 2 : 3;
		$width    = $this->inner_width() / $columns;
		foreach ( array_chunk( $options, $columns ) as $chunk ) {
			$prepared = array();
			$height   = 0;
			foreach ( $chunk as $option ) {
				$lines      = $this->pdf->wrap( (string) $option['label'], $width - 22, 8.5 );
				$prepared[] = $lines;
				$height     = max( $height, count( $lines ) * 11 + 6 );
			}
			$this->ensure( $height );
			foreach ( $chunk as $index => $option ) {
				$x = self::LEFT + 10 + $index * $width;
				$this->checkbox( $x, in_array( (string) $option['value'], $selected, true ) );
				foreach ( $prepared[ $index ] as $number => $line ) {
					$this->pdf->text( $line, $x + 14, $this->y + $number * 11, 8.5 ); }
			}
			$this->y += $height;
		}
		$this->y += 6;
	}
	/**
	 * Casilla vacía o marcada, alineada con la línea base actual.
	 *
	 * @param float $x Izquierda.
	 * @param bool  $checked Marcada.
	 * @return void
	 */
	private function checkbox( float $x, bool $checked ): void {
		$this->pdf->outline( $x, $this->y - 8, 9, 9, self::TEXT, 0.7 );
		if ( $checked ) {
			$this->pdf->rectangle( $x + 2, $this->y - 6, 5, 5, $this->colors['accent'] ); }
	}
	/**
	 * Autorización con casilla.
	 *
	 * @param string $label Texto aceptado.
	 * @param bool   $checked Aceptada.
	 * @return void
	 */
	private function consent( string $label, bool $checked ): void {
		$lines = $this->pdf->wrap( $label, self::WIDTH - 44, 8.5 );
		$this->ensure( count( $lines ) * 11 + 8 );
		$this->checkbox( self::LEFT + 10, $checked );
		foreach ( $lines as $index => $line ) {
			$this->pdf->text( $line, self::LEFT + 24, $this->y + $index * 11, 8.5 ); }
		$this->y += count( $lines ) * 11 + 8;
	}
	/**
	 * Líneas para diligenciar por la institución.
	 *
	 * @return void
	 */
	private function office(): void {
		$labels = array_values( array_filter( array_map( 'trim', explode( "\n", $this->design['office_fields'] ) ) ) );
		if ( array() === $labels ) {
			return; }
		$caption = __( 'Espacio de uso exclusivo de la institución', 'toolsbox-registros' );
		$this->ensure( 30 + count( $labels ) * 20 );
		$this->pdf->text( $caption, self::LEFT + ( self::WIDTH - $this->pdf->width( $caption, 8 ) ) / 2, $this->y, 8, self::MUTED );
		$this->y += 22;
		foreach ( $labels as $label ) {
			$this->ensure( 20 );
			$label = $this->fit( $label, 220, 10 );
			$this->pdf->text( $label, self::LEFT, $this->y, 10 );
			$this->pdf->rectangle( self::LEFT + 230, $this->y + 2, self::WIDTH - 230, 0.7, self::TEXT );
			$this->y += 20;
		}
		$this->y += 12;
	}
	/**
	 * Introducción y condiciones; «## Título» abre una sección y **línea** va en negrita.
	 *
	 * @return void
	 */
	private function terms(): void {
		if ( '' !== trim( $this->design['intro'] ) ) {
			$this->open_box();
			$this->paragraphs( explode( "\n", $this->design['intro'] ) );
			$this->close_box();
		}
		$sections = array();
		$current  = null;
		foreach ( explode( "\n", $this->design['terms'] ) as $line ) {
			if ( str_starts_with( ltrim( $line ), '## ' ) ) {
				$sections[] = array( trim( substr( ltrim( $line ), 3 ) ), array() );
				$current    = count( $sections ) - 1;
				continue; }
			if ( null === $current ) {
				if ( '' === trim( $line ) ) {
					continue; }
				$sections[] = array( '', array() );
				$current    = 0;
			}
			$sections[ $current ][1][] = $line;
		}
		foreach ( $sections as $section ) {
			$this->open_box( $section[0] );
			$this->paragraphs( $section[1] );
			$this->close_box();
		}
	}
	/**
	 * Texto envuelto dentro del recuadro abierto.
	 *
	 * @param array<string> $lines Líneas escritas por el administrador.
	 * @return void
	 */
	private function paragraphs( array $lines ): void {
		while ( array() !== $lines && '' === trim( (string) end( $lines ) ) ) {
			array_pop( $lines ); }
		foreach ( $lines as $line ) {
			$line = trim( $line );
			$bold = strlen( $line ) > 4 && str_starts_with( $line, '**' ) && str_ends_with( $line, '**' );
			if ( $bold ) {
				$line = trim( substr( $line, 2, -2 ) ); }
			if ( '' === $line ) {
				$this->y += 5;
				continue; }
			foreach ( $this->pdf->wrap( $line, self::WIDTH - 20, 8.5 ) as $wrapped ) {
				$this->ensure( 11 );
				$this->pdf->text( $wrapped, self::LEFT + 10, $this->y, 8.5, self::TEXT, $bold );
				$this->y += 11;
			}
		}
		$this->y -= 4;
	}
	/**
	 * Líneas de firma, hasta tres por fila.
	 *
	 * @return void
	 */
	private function signatures(): void {
		$labels = array_values( array_filter( array_map( 'trim', explode( "\n", $this->design['signatures'] ) ) ) );
		foreach ( array_chunk( $labels, 3 ) as $chunk ) {
			$this->ensure( 70 );
			$this->y += 40;
			$width    = ( self::WIDTH - ( count( $chunk ) - 1 ) * 40 ) / count( $chunk );
			foreach ( $chunk as $index => $label ) {
				$x     = self::LEFT + $index * ( $width + 40 );
				$label = $this->fit( $label, $width, 8 );
				$this->pdf->rectangle( $x, $this->y, $width, 0.8, self::TEXT );
				$this->pdf->text( $label, $x + ( $width - $this->pdf->width( $label, 8 ) ) / 2, $this->y + 13, 8 );
			}
			$this->y += 30;
		}
	}
}
