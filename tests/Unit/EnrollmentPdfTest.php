<?php
/**
 * Pruebas del diseño configurable y del formato de inscripción y matrícula.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);

namespace Codwelt\ToolsBoxRegistros\Tests\Unit;

use Codwelt\ToolsBoxRegistros\Domain\Form;
use Codwelt\ToolsBoxRegistros\Export\PdfDesign;
use Codwelt\ToolsBoxRegistros\Export\RegistrationPdf;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EnrollmentPdfTest extends TestCase {
	private function form( array $pdf ): Form {
		$data                    = tbreg_test_form_data();
		$data['fields'][]        = array( 'key' => 'f_head', 'type' => 'heading', 'label' => 'Horario de clases' );
		$data['fields'][]        = array( 'key' => 'f_ok', 'type' => 'consent', 'label' => 'Acepto las condiciones' );
		$data['settings']['pdf'] = $pdf;
		return Form::fromArray( $data );
	}

	public function test_forms_without_design_keep_the_receipt_and_its_colors(): void {
		$form = Form::fromArray( tbreg_test_form_data() );
		$this->assertSame( PdfDesign::defaults(), $form->settings()['pdf'] );
		$this->assertSame( '0.071 0.231 0.361', PdfDesign::rgb( '#123b5c' ) );
		$this->assertSame( '1.000 1.000 1.000', PdfDesign::tint( '#123b5c', 0 ) );
		$pdf = ( new RegistrationPdf() )->build( $form, array( 'id' => 5, 'created_at' => '2026-10-21 10:00:00', 'status' => 'confirmed' ), array( 'f_name' => 'Ana' ) );
		$this->assertStringStartsWith( '%PDF-1.4', $pdf );
	}

	public function test_rejects_invalid_design(): void {
		foreach ( array( array( 'template' => 'otro' ), array( 'primary_color' => 'rojo' ), array( 'show_options' => '1' ), array( 'logo_id' => -1 ), array( 'terms' => array() ) ) as $pdf ) {
			try {
				$this->form( $pdf );
				$this->fail( 'Se aceptó un diseño inválido: ' . wp_json_encode( $pdf ) );
			} catch ( \InvalidArgumentException $exception ) {
				$this->assertNotSame( '', $exception->getMessage() );
			}
		}
		$this->assertSame( '#AABBCC', $this->form( array( 'accent_color' => '#AABBCC' ) )->settings()['pdf']['accent_color'] );
	}

	public function test_enrollment_draws_configured_colors_sections_and_marks_choices(): void {
		$form   = $this->form(
			array(
				'template'      => 'enrollment',
				'primary_color' => '#336699',
				'accent_color'  => '#cc0000',
				'logo_id'       => 999999999,
				'terms'         => "## Condiciones\n**Importante**\nTexto",
				'office_fields' => "Profesor\nPuntaje",
				'footer'        => 'Pie',
			)
		);
		$entry  = array( 'id' => 7, 'created_at' => '2026-10-21 10:00:00', 'status' => 'confirmed' );
		$filled = gzuncompress( $this->content( ( new RegistrationPdf() )->build( $form, $entry, array( 'f_city' => 'bogota', 'f_choices' => array( 'b' ), 'f_ok' => '1' ) ) ) );
		$this->assertStringContainsString( '0.200 0.400 0.600 rg', $filled );
		$this->assertStringContainsString( '0.800 0.000 0.000 rg', $filled );
		$this->assertStringContainsString( '2 Tr', $filled );
		$this->assertStringContainsString( ' re S Q', $filled );
		$marks = substr_count( $filled, "q 0.800 0.000 0.000 rg" );
		$blank = ( new RegistrationPdf() )->build( $form, array( 'id' => 0, 'created_at' => '2026-10-21 10:00:00', 'status' => 'confirmed' ), array( 'f_city' => 'bogota' ) );
		$empty = gzuncompress( $this->content( $blank ) );
		$this->assertSame( 3, $marks - substr_count( $empty, "q 0.800 0.000 0.000 rg" ), 'Ciudad, intereses y autorización marcados; el formato en blanco ignora valores.' );
		$this->assertStringNotContainsString( '/Subtype /Image', $blank, 'Un logo inexistente se omite sin error.' );
	}

	public function test_long_terms_paginate_inside_boxes(): void {
		$form = $this->form(
			array(
				'template' => 'enrollment',
				'terms'    => "## Condiciones\n" . str_repeat( "Una condición larga que ocupa espacio en la página del formato impreso.\n", 120 ),
			)
		);
		$pdf  = ( new RegistrationPdf() )->build( $form, array( 'id' => 0, 'created_at' => '2026-10-21 10:00:00', 'status' => 'confirmed' ), array() );
		$this->assertMatchesRegularExpression( '~/Count [3-9]\b~', $pdf );
	}

	/**
	 * Primer stream de contenido de página comprimido.
	 */
	private function content( string $pdf ): string {
		preg_match_all( "~<< /Length (\d+) /Filter /FlateDecode>>\nstream\n~", $pdf, $matches, PREG_OFFSET_CAPTURE );
		$match = $matches[0][0];
		return substr( $pdf, $match[1] + strlen( $match[0] ), (int) $matches[1][0][0] );
	}
}
