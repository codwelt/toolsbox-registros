<?php
/**
 * Catálogo de demostración editable, sin horarios de una escuela real.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }
/** Implementación del servicio propio del plugin. */
final class LanguageTemplate {
	/**
	 * Crea la plantilla con relaciones entre opciones y PDF.
	 *
	 * @return array<string,mixed>
	 */
	public static function get(): array {
		$courses = array(
			'japones'  => __( 'Japonés', 'toolsbox-registros' ),
			'mandarin' => __( 'Mandarín', 'toolsbox-registros' ),
			'coreano'  => __( 'Coreano', 'toolsbox-registros' ),
		);
		$levels  = array(
			'basico'     => __( 'Básico', 'toolsbox-registros' ),
			'intermedio' => __( 'Intermedio', 'toolsbox-registros' ),
			'avanzado'   => __( 'Avanzado', 'toolsbox-registros' ),
			'especial'   => __( 'Programas especiales', 'toolsbox-registros' ),
		);
		$modules = array();
		foreach ( array( 'basico', 'intermedio', 'avanzado' ) as $level ) {
			foreach ( array( 1, 2 ) as $number ) {
				$modules[] = array(
					'value' => $level . '_' . $number,
					'label' => $levels[ $level ] . ' ' . $number,
					'when'  => array( 'f_level' => array( $level ) ),
				); }
		}
		$modules[]      = array(
			'value' => 'turista',
			'label' => __( 'Turista', 'toolsbox-registros' ),
			'when'  => array( 'f_level' => array( 'especial' ) ),
		);
		$modules[]      = array(
			'value' => 'negocios',
			'label' => __( 'Negocios', 'toolsbox-registros' ),
			'when'  => array( 'f_level' => array( 'especial' ) ),
		);
		$course_options = self::options( $courses );
		$level_options  = self::options( $levels, array( 'f_course' => array_keys( $courses ) ) );
		$fields         = array(
			self::choice( 'f_course', __( 'Curso', 'toolsbox-registros' ), $course_options ),
			self::choice( 'f_level', __( 'Nivel', 'toolsbox-registros' ), $level_options ),
			self::choice( 'f_module', __( 'Subnivel / módulo', 'toolsbox-registros' ), $modules ),
			self::choice(
				'f_mode',
				__( 'Modalidad', 'toolsbox-registros' ),
				self::options(
					array(
						'presencial' => __( 'Presencial', 'toolsbox-registros' ),
						'virtual'    => __( 'Virtual', 'toolsbox-registros' ),
					),
					array( 'f_module' => array_column( $modules, 'value' ) )
				)
			),
			self::choice(
				'f_days',
				__( 'Días de clase', 'toolsbox-registros' ),
				array(
					array(
						'value' => 'lunes_miercoles',
						'label' => __( 'Lunes y miércoles', 'toolsbox-registros' ),
						'when'  => array( 'f_mode' => array( 'virtual' ) ),
					),
					array(
						'value' => 'sabados',
						'label' => __( 'Sábados', 'toolsbox-registros' ),
						'when'  => array( 'f_mode' => array( 'presencial', 'virtual' ) ),
					),
				)
			),
			self::choice(
				'f_schedule',
				__( 'Horario disponible', 'toolsbox-registros' ),
				array(
					array(
						'value' => 'virtual_18_20',
						'label' => __( '18:00 a 20:00', 'toolsbox-registros' ),
						'when'  => array(
							'f_course' => array( 'japones', 'mandarin' ),
							'f_days'   => array( 'lunes_miercoles' ),
							'f_mode'   => array( 'virtual' ),
							'f_module' => array_column( $modules, 'value' ),
						),
					),
					array(
						'value' => 'virtual_19_21',
						'label' => __( '19:00 a 21:00', 'toolsbox-registros' ),
						'when'  => array(
							'f_course' => array( 'coreano' ),
							'f_days'   => array( 'lunes_miercoles' ),
							'f_mode'   => array( 'virtual' ),
							'f_module' => array_column( $modules, 'value' ),
						),
					),
					array(
						'value' => 'presencial_08_11',
						'label' => __( '08:00 a 11:00', 'toolsbox-registros' ),
						'when'  => array(
							'f_course' => array( 'japones', 'mandarin' ),
							'f_days'   => array( 'sabados' ),
							'f_mode'   => array( 'presencial' ),
							'f_module' => array_column( $modules, 'value' ),
						),
					),
					array(
						'value' => 'presencial_14_17',
						'label' => __( '14:00 a 17:00', 'toolsbox-registros' ),
						'when'  => array(
							'f_course' => array( 'coreano' ),
							'f_days'   => array( 'sabados' ),
							'f_mode'   => array( 'presencial' ),
							'f_module' => array_column( $modules, 'value' ),
						),
					),
					array(
						'value' => 'virtual_10_13',
						'label' => __( '10:00 a 13:00', 'toolsbox-registros' ),
						'when'  => array(
							'f_days'   => array( 'sabados' ),
							'f_mode'   => array( 'virtual' ),
							'f_module' => array_column( $modules, 'value' ),
						),
					),
				)
			),
			array(
				'key'      => 'f_name',
				'type'     => 'text',
				'label'    => __( 'Nombres y apellidos', 'toolsbox-registros' ),
				'role'     => 'name',
				'required' => true,
			),
			array(
				'key'        => 'f_birth',
				'type'       => 'date',
				'label'      => __( 'Fecha de nacimiento', 'toolsbox-registros' ),
				'required'   => true,
				'validation' => array( 'max' => wp_date( 'Y-m-d' ) ),
			),
			array(
				'key'        => 'f_age',
				'type'       => 'number',
				'label'      => __( 'Edad', 'toolsbox-registros' ),
				'validation' => array(
					'min' => 0,
					'max' => 120,
				),
			),
			array(
				'key'      => 'f_document',
				'type'     => 'document',
				'label'    => __( 'Documento (CC, TI o pasaporte)', 'toolsbox-registros' ),
				'role'     => 'document',
				'required' => true,
			),
			array(
				'key'   => 'f_institution',
				'type'  => 'text',
				'label' => __( 'Empresa, colegio o universidad', 'toolsbox-registros' ),
			),
			array(
				'key'   => 'f_home_phone',
				'type'  => 'tel',
				'label' => __( 'Teléfono de casa', 'toolsbox-registros' ),
			),
			array(
				'key'   => 'f_guardian',
				'type'  => 'text',
				'label' => __( 'Nombre del padre, madre o acudiente', 'toolsbox-registros' ),
			),
			array(
				'key'   => 'f_work_phone',
				'type'  => 'tel',
				'label' => __( 'Teléfono de oficina', 'toolsbox-registros' ),
			),
			array(
				'key'      => 'f_mobile',
				'type'     => 'tel',
				'label'    => __( 'Celular del estudiante', 'toolsbox-registros' ),
				'required' => true,
			),
			array(
				'key'      => 'f_email',
				'type'     => 'email',
				'label'    => __( 'Correo electrónico', 'toolsbox-registros' ),
				'role'     => 'email',
				'required' => true,
			),
			array(
				'key'   => 'f_photo',
				'type'  => 'photo',
				'label' => __( 'Fotografía del estudiante', 'toolsbox-registros' ),
			),
			array(
				'key'   => 'f_consent',
				'type'  => 'consent',
				'label' => __( 'Autorizo el tratamiento de mis datos personales y fotografía para gestionar mi inscripción.', 'toolsbox-registros' ),
			),
		);
		return array(
			'label'    => __( 'Inscripción de idiomas (ejemplo editable)', 'toolsbox-registros' ),
			'fields'   => $fields,
			'settings' => array(
				'pdf_enabled'  => true,
				'receipt_text' => __( 'Revisa los datos de tu inscripción. Conserva este documento y entrégalo firmado si tu institución lo solicita. Para estudiantes menores de edad, puede firmar el padre, madre o acudiente.', 'toolsbox-registros' ),
			),
		);
	}
	/**
	 * Construye opciones con condiciones comunes.
	 *
	 * @param array<string,string>        $labels Etiquetas.
	 * @param array<string,array<string>> $when Condiciones.
	 * @return array<array<string,mixed>>
	 */
	private static function options( array $labels, array $when = array() ): array {
		$result = array();
		foreach ( $labels as $value => $label ) {
			$option = array(
				'value' => $value,
				'label' => $label,
			);
			if ( array() !== $when ) {
				$option['when'] = $when;
			} $result[] = $option;
		} return $result;
	}
	/**
	 * Campo obligatorio de selección.
	 *
	 * @param string                     $key Clave inicial.
	 * @param string                     $label Etiqueta.
	 * @param array<array<string,mixed>> $options Opciones.
	 * @return array<string,mixed>
	 */
	private static function choice( string $key, string $label, array $options ): array {
		return array(
			'key'      => $key,
			'type'     => 'select',
			'label'    => $label,
			'required' => true,
			'options'  => $options,
			'width'    => 'half',
			'help'     => '',
		); }
}
