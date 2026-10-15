<?php
/**
 * Plantillas rápidas y nombres de tipos de campo.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);

namespace Codwelt\ToolsBoxRegistros\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Crea esquemas iniciales sin reutilizar identificadores entre campos.
 */
final class Templates {
	/**
	 * Nombres traducidos del catálogo v1.
	 *
	 * @return array<string,string>
	 */
	public static function types(): array {
		return array(
			'photo'    => __( 'Fotografía (archivo o cámara)', 'toolsbox-registros' ),
			'text'     => __( 'Texto', 'toolsbox-registros' ),
			'email'    => __( 'Correo electrónico', 'toolsbox-registros' ),
			'tel'      => __( 'Teléfono', 'toolsbox-registros' ),
			'number'   => __( 'Número', 'toolsbox-registros' ),
			'textarea' => __( 'Texto largo', 'toolsbox-registros' ),
			'select'   => __( 'Lista desplegable', 'toolsbox-registros' ),
			'radio'    => __( 'Selección única', 'toolsbox-registros' ),
			'checkbox' => __( 'Casillas de selección', 'toolsbox-registros' ),
			'date'     => __( 'Fecha', 'toolsbox-registros' ),
			'time'     => __( 'Hora', 'toolsbox-registros' ),
			'url'      => __( 'Dirección web', 'toolsbox-registros' ),
			'document' => __( 'Documento', 'toolsbox-registros' ),
			'consent'  => __( 'Consentimiento', 'toolsbox-registros' ),
			'heading'  => __( 'Título o separador', 'toolsbox-registros' ),
			'hidden'   => __( 'Campo oculto', 'toolsbox-registros' ),
		);
	}

	/**
	 * Define las plantillas disponibles.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		$name                   = array(
			'type'     => 'text',
			'label'    => __( 'Nombre completo', 'toolsbox-registros' ),
			'role'     => 'name',
			'required' => true,
		);
		$email                  = array(
			'type'     => 'email',
			'label'    => __( 'Correo electrónico', 'toolsbox-registros' ),
			'role'     => 'email',
			'required' => true,
		);
		$phone                  = array(
			'type'  => 'tel',
			'label' => __( 'Teléfono', 'toolsbox-registros' ),
		);
		$consent                = array(
			'type'     => 'consent',
			'label'    => __( 'Autorizo el tratamiento de mis datos personales', 'toolsbox-registros' ),
			'required' => true,
		);
		$city                   = array(
			'type'    => 'select',
			'label'   => __( 'Ciudad', 'toolsbox-registros' ),
			'options' => array(
				array(
					'value' => 'bogota',
					'label' => __( 'Bogotá', 'toolsbox-registros' ),
				),
				array(
					'value' => 'medellin',
					'label' => __( 'Medellín', 'toolsbox-registros' ),
				),
				array(
					'value' => 'otra',
					'label' => __( 'Otra', 'toolsbox-registros' ),
				),
			),
		);
		$templates              = array(
			'event'   => array(
				'label'  => __( 'Evento básico', 'toolsbox-registros' ),
				'fields' => array(
					$name,
					$email,
					$phone,
					array(
						'type'  => 'document',
						'label' => __( 'Documento', 'toolsbox-registros' ),
						'role'  => 'document',
					),
					$city,
					$consent,
				),
			),
			'course'  => array(
				'label'  => __( 'Clase o curso', 'toolsbox-registros' ),
				'fields' => array(
					$name,
					$email,
					$phone,
					array(
						'type'    => 'radio',
						'label'   => __( 'Nivel', 'toolsbox-registros' ),
						'options' => array(
							array(
								'value' => 'inicial',
								'label' => __( 'Inicial', 'toolsbox-registros' ),
							),
							array(
								'value' => 'intermedio',
								'label' => __( 'Intermedio', 'toolsbox-registros' ),
							),
						),
					),
					array(
						'type'  => 'textarea',
						'label' => __( '¿Qué esperas aprender?', 'toolsbox-registros' ),
					),
					$consent,
				),
			),
			'webinar' => array(
				'label'    => __( 'Webinar', 'toolsbox-registros' ),
				'fields'   => array(
					$name,
					$email,
					array(
						'type'  => 'text',
						'label' => __( 'Empresa u organización', 'toolsbox-registros' ),
					),
					$city,
					$consent,
				),
				'settings' => array( 'event' => array( 'modality' => 'virtual' ) ),
			),
		);
		$templates['languages'] = LanguageTemplate::get();
		foreach ( $templates as &$template ) {
			$map = array();
			foreach ( $template['fields'] as $field ) {
				if ( isset( $field['key'] ) ) {
					$map[ $field['key'] ] = 'f_' . bin2hex( random_bytes( 8 ) ); }
			}
			foreach ( $template['fields'] as &$field ) {
				$field['key'] = $map[ $field['key'] ?? '' ] ?? ( 'f_' . bin2hex( random_bytes( 8 ) ) );
				foreach ( $field['options'] ?? array() as $index => $option ) {
					if ( ! empty( $option['when'] ) ) {
						$when = array();
						foreach ( $option['when'] as $parent => $values ) {
							$when[ $map[ $parent ] ?? $parent ] = $values; }
						$field['options'][ $index ]['when'] = $when;
					}
				}
			}
			unset( $field );
		}
		unset( $template );
		return $templates;
	}
}
