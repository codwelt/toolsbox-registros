<?php
/**
 * Constancia de inscripción y hoja de firma.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Export;

use Codwelt\ToolsBoxRegistros\Domain\Form;
use Codwelt\ToolsBoxRegistros\Domain\FieldTypeRegistry;
use Codwelt\ToolsBoxRegistros\Services\Photo;
use Codwelt\ToolsBoxRegistros\Admin\FormsListTable;
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
/** Conserva datos completos mediante paginación, sin recortar valores largos. */
final class RegistrationPdf {
	/**
	 * Genera bytes del PDF para una inscripción autorizada.
	 *
	 * @param Form                $form Esquema.
	 * @param array<string,mixed> $entry Metadatos.
	 * @param array<string,mixed> $values Respuestas guardadas.
	 * @return string
	 */
	public function build( Form $form, array $entry, array $values ): string {
		$pdf      = new PdfDocument();
		$registry = new FieldTypeRegistry();
		$page     = 0;
		$header   = static function ( string $title ) use ( $pdf, $form, $entry, &$page ): void {
			$pdf->page();
			++$page;
			$pdf->rectangle( 0, 0, 612, 100, '0.07 0.23 0.36' );
			foreach ( $pdf->wrap( wp_strip_all_tags( get_bloginfo( 'name' ) ), 520, 12 ) as $i => $line ) {
				if ( $i < 2 ) {
					$pdf->text( $line, 46, 30 + $i * 15, 12, '1 1 1' ); }
			}
			$pdf->text( $title, 46, 76, 19, '1 1 1' );
			foreach ( $pdf->wrap( __( 'Inscripción', 'toolsbox-registros' ) . ' #' . $entry['id'] . ' | ' . $form->get( 'title' ), 520, 9 ) as $index => $line ) {
				$pdf->text( $line, 46, 126 + $index * 12, 9 ); }
			$pdf->text( __( 'Página', 'toolsbox-registros' ) . ' ' . $page, 46, 761, 9 );
		};
		$header( __( 'Datos de inscripción', 'toolsbox-registros' ) );
		$y      = 161;
		$date   = get_date_from_gmt( $entry['created_at'], 'd/m/Y H:i:s' );
		$status = FormsListTable::entry_statuses()[ $entry['status'] ] ?? $entry['status'];
		$pdf->text( __( 'Fecha:', 'toolsbox-registros' ) . ' ' . $date, 46, $y );
		$y += 18;
		$pdf->text( __( 'Estado:', 'toolsbox-registros' ) . ' ' . $status, 46, $y );
		$y        += 28;
		$has_photo = false;
		foreach ( $form->fields() as $field ) {
			if ( 'photo' === $field->get( 'type' ) ) {
				$jpeg = Photo::jpeg( (string) ( $values[ $field->key() ] ?? '' ) );
				if ( '' !== $jpeg ) {
					$pdf->photo( $jpeg, 441, 151, 124, 125 );
					$has_photo = true;
					break; }
			}
		}
		$items = array();
		foreach ( $form->fields() as $field ) {
			if ( in_array( $field->get( 'type' ), array( 'heading', 'hidden', 'photo' ), true ) || ! array_key_exists( $field->key(), $values ) ) {
				continue; }
			$label   = $field->get( 'label' ) . ( $field->get( 'archived' ) ? __( ' (eliminado)', 'toolsbox-registros' ) : '' );
			$text    = 'consent' === $field->get( 'type' ) ? ( '1' === $values[ $field->key() ] ? __( 'Aceptado', 'toolsbox-registros' ) : __( 'No aceptado', 'toolsbox-registros' ) ) : $registry->get( $field->get( 'type' ) )->toExport( $values[ $field->key() ], $field );
			$items[] = array( $label, '' === $text ? '-' : $text );
		}
		$y = $has_photo ? 300 : 220;
		foreach ( array_chunk( $items, 2 ) as $pair ) {
			$prepared = array();
			$height   = 0;
			foreach ( $pair as $item ) {
				$label_lines = $pdf->wrap( $item[0], 245, 9 );
				$value_lines = $pdf->wrap( $item[1], 245, 10 );
				$prepared[]  = array( $label_lines, $value_lines );
				$height      = max( $height, count( $label_lines ) * 12 + count( $value_lines ) * 13 + 14 );
			}
			if ( $height > 480 ) {
				foreach ( $pair as $item ) {
					foreach ( array( array( $item[0], 9, '0.1 0.34 0.51' ), array( $item[1], 10, '0.12 0.17 0.23' ) ) as $part ) {
						foreach ( $pdf->wrap( $part[0], 519, $part[1] ) as $line ) {
							if ( $y > 710 ) {
								$header( __( 'Datos de inscripción (continuación)', 'toolsbox-registros' ) );
								$y = 180;
							} $pdf->text( $line, 46, $y, $part[1], $part[2] );
							$y += 14; }
					}
					$y += 14;
				}
				continue;
			}
			if ( $y + $height > 729 ) {
				$header( __( 'Datos de inscripción (continuación)', 'toolsbox-registros' ) );
				$y = 180; }
			foreach ( $prepared as $index => $cell ) {
				$x      = 46 + $index * 276;
				$cursor = $y;
				foreach ( $cell[0] as $line ) {
					$pdf->text( $line, $x, $cursor, 9, '0.1 0.34 0.51' );
					$cursor += 12; }
				foreach ( $cell[1] as $line ) {
					$pdf->text( $line, $x, $cursor, 10 );
					$cursor += 13; }
				$pdf->rectangle( $x, $y + $height - 8, 245, 0.5, '0.85 0.89 0.91' );
			}
			$y += $height;
		}
		$header( __( 'Constancia y firma', 'toolsbox-registros' ) );
		$y = 169;
		foreach ( $pdf->wrap( $form->settings()['receipt_text'], 520, 11 ) as $line ) {
			if ( $y > 640 ) {
				$header( __( 'Constancia y firma (continuación)', 'toolsbox-registros' ) );
				$y = 169; }
			$pdf->text( $line, 46, $y, 11 );
			$y += 17;
		}
		if ( $y > 480 ) {
			$header( __( 'Firmas', 'toolsbox-registros' ) ); }
		$pdf->rectangle( 46, 526, 245, 1, '0.3 0.4 0.47' );
		$pdf->rectangle( 322, 526, 245, 1, '0.3 0.4 0.47' );
		$pdf->text( __( 'Firma del estudiante', 'toolsbox-registros' ), 46, 549, 10 );
		$pdf->text( __( 'Firma del padre, madre o acudiente', 'toolsbox-registros' ), 322, 549, 10 );
		$pdf->text( __( 'Nombre:', 'toolsbox-registros' ) . ' ______________________________', 46, 589, 10 );
		$pdf->text( __( 'Documento:', 'toolsbox-registros' ) . ' ___________________________', 46, 622, 10 );
		$pdf->text( __( 'Fecha:', 'toolsbox-registros' ) . ' _______________________________', 46, 655, 10 );
		return $pdf->bytes();
	}
}
