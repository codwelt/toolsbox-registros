<?php
/**
 * HTML accesible y escapado de los controles nativos.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Helpers usados por las plantillas de cada tipo. */
final class FieldView {
	/**
	 * Construye atributos permitidos por el renderer.
	 *
	 * @param array<string,mixed> $attributes Atributos internos.
	 * @return string
	 */
	public static function attributes( array $attributes ): string {
		$html = '';
		foreach ( $attributes as $name => $value ) {
			if ( false === $value || null === $value ) {
				continue;
			}
			$html .= ' ' . esc_attr( $name ) . ( true === $value ? '' : '="' . esc_attr( (string) $value ) . '"' );
		}
		return $html;
	}
	/**
	 * Renderiza etiquetas, grupos, ayuda y región de error estable.
	 *
	 * @param array<string,mixed> $context Campo, valor, ID, error y consentimiento.
	 * @return string
	 */
	public static function render( array $context ): string {
		$field = $context['field'];
		$value = $context['value'];
		$id    = $context['id'];
		$type  = $field->get( 'type' );
		$name  = 'tbreg_values[' . $field->key() . ']';
		$error = $context['error'];
		if ( 'hidden' === $type ) {
			return '<input' . self::attributes(
				array(
					'type'  => 'hidden',
					'id'    => $id,
					'name'  => $name,
					'value' => is_scalar( $value ) ? $value : '',
				)
			) . '>';
		}
		$class = 'tbreg-field tbreg-width-' . $field->get( 'width' ) . ' ' . $field->get( 'css_class' );
		if ( 'heading' === $type ) {
			return '<div class="' . esc_attr( $class ) . '"><h3>' . esc_html( $field->get( 'label' ) ) . '</h3><p>' . esc_html( $field->get( 'help' ) ) . '</p></div>';
		}
		$required   = $field->get( 'required' ) || 'consent' === $type;
		$described  = ( '' !== $field->get( 'help' ) ? $id . '-help ' : '' ) . $id . '-error';
		$attributes = array(
			'id'               => $id,
			'name'             => $name,
			'aria-required'    => $required ? 'true' : 'false',
			'aria-describedby' => $described,
			'aria-invalid'     => '' !== $error ? 'true' : 'false',
			'required'         => $required,
			'placeholder'      => $field->get( 'placeholder' ),
			'autofocus'        => $context['autofocus'] ?? false,
		);
		$label      = esc_html( $field->get( 'label' ) ) . ( $required ? ' <span aria-hidden="true">*</span>' : '' );
		$html       = '<div class="' . esc_attr( $class ) . '" data-tbreg-field="' . esc_attr( $field->key() ) . '">';
		if ( in_array( $type, array( 'radio', 'checkbox' ), true ) ) {
			$html .= '<fieldset' . self::attributes(
				array(
					'aria-describedby' => $described,
					'data-required'    => $required ? 'true' : 'false',
				)
			) . '><legend>' . $label . '</legend>';
			foreach ( $field->get( 'options' ) as $index => $option ) {
				$attrs              = array_merge(
					$attributes,
					array(
						'type'     => $type,
						'id'       => $id . '-' . $index,
						'name'     => $name . ( 'checkbox' === $type ? '[]' : '' ),
						'value'    => $option['value'],
						'required' => 'radio' === $type && $required,
						'checked'  => in_array( $option['value'], is_array( $value ) ? $value : array( $value ), true ),
					)
				);
				$attrs['autofocus'] = 0 === $index && ( $context['autofocus'] ?? false );
				$html              .= '<label class="tbreg-choice" for="' . esc_attr( $attrs['id'] ) . '"><input' . self::attributes( $attrs ) . '> ' . esc_html( $option['label'] ) . '</label>';
			}
			$html .= '</fieldset>';
		} elseif ( 'consent' === $type ) {
			$html .= '<label class="tbreg-choice" for="' . esc_attr( $id ) . '"><input' . self::attributes(
				array_merge(
					$attributes,
					array(
						'type'    => 'checkbox',
						'value'   => '1',
						'checked' => '1' === (string) $value,
					)
				)
			) . '> ' . esc_html( '' !== $context['consent_text'] ? $context['consent_text'] : $field->get( 'label' ) ) . '</label>';
		} else {
			$html  .= '<label for="' . esc_attr( $id ) . '">' . $label . '</label>';
			$limits = $field->get( 'validation' );
			foreach ( array( 'minlength', 'maxlength', 'pattern' ) as $limit ) {
				$attributes[ $limit ] = $limits[ $limit ];
			}
			if ( in_array( $type, array( 'number', 'date', 'time' ), true ) ) {
				$attributes['min'] = $limits['min'];
				$attributes['max'] = $limits['max'];
				if ( 'number' === $type ) {
					$attributes['step'] = 'any';
				}
			}
			if ( 'textarea' === $type ) {
				$html .= '<textarea' . self::attributes( $attributes ) . ' rows="4">' . esc_textarea( (string) $value ) . '</textarea>'; } elseif ( 'select' === $type ) {
				$html .= '<select' . self::attributes( $attributes ) . '><option value="">' . esc_html__( 'Selecciona una opción', 'toolsbox-registros' ) . '</option>';
				foreach ( $field->get( 'options' ) as $option ) {
					$html .= '<option value="' . esc_attr( $option['value'] ) . '" ' . selected( $value, $option['value'], false ) . '>' . esc_html( $option['label'] ) . '</option>';
				}
				$html .= '</select>';
				} else {
					$attributes['type']  = 'document' === $type ? 'text' : $type;
					$attributes['value'] = $value;
					if ( 'time' === $type ) {
						$attributes['step'] = '1';
					}
					$html .= '<input' . self::attributes( $attributes ) . '>';
				}
		}
		if ( '' !== $field->get( 'help' ) ) {
			$html .= '<p id="' . esc_attr( $id . '-help' ) . '" class="tbreg-help">' . esc_html( $field->get( 'help' ) ) . '</p>';
		}
		$html .= '<p id="' . esc_attr( $id . '-error' ) . '" class="tbreg-error" data-tbreg-error' . ( '' === $error ? ' hidden' : '' ) . '>' . esc_html( $error ) . '</p></div>';
		return $html;
	}
}
