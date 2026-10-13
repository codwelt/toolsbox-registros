<?php
/**
 * Comportamiento compartido de los tipos nativos.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Domain\FieldTypes;

use Codwelt\ToolsBoxRegistros\Domain\Field;
use Codwelt\ToolsBoxRegistros\Frontend\Template;
use Codwelt\ToolsBoxRegistros\Admin\Templates;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Implementa las reglas comunes y específicas por tipo nativo. */
abstract class AbstractFieldType implements FieldTypeInterface {
	/**
	 * Identificador nativo.
	 *
	 * @return string
	 */
	public function type(): string {
		return static::TYPE;
	}
	/**
	 * Etiqueta traducida.
	 *
	 * @return string
	 */
	public function label(): string {
		return Templates::types()[ $this->type() ];
	}
	/**
	 * Admite opciones.
	 *
	 * @return bool
	 */
	public function supportsOptions(): bool {
		return in_array( $this->type(), array( 'select', 'radio', 'checkbox' ), true );
	}
	/**
	 * Almacena un valor.
	 *
	 * @return bool
	 */
	public function storesValue(): bool {
		return 'heading' !== $this->type();
	}
	/**
	 * Sanea sin reparar silenciosamente correos o URLs inválidos.
	 *
	 * @param mixed $raw Valor bruto.
	 * @param Field $field Esquema.
	 * @return mixed
	 */
	public function sanitize( mixed $raw, Field $field ): mixed {
		if ( 'checkbox' === $this->type() ) {
			return array_values( array_unique( array_map( 'sanitize_text_field', is_array( $raw ) ? $raw : ( '' === (string) $raw ? array() : array( $raw ) ) ) ) );
		}
		$value = 'textarea' === $this->type() ? sanitize_textarea_field( (string) $raw ) : sanitize_text_field( (string) $raw );
		return 'email' === $this->type() || 'email' === $field->get( 'role' ) ? strtolower( trim( $value ) ) : $value;
	}
	/**
	 * Aplica obligatoriedad, formato, catálogo y límites del servidor.
	 *
	 * @param mixed $value Valor saneado.
	 * @param Field $field Esquema.
	 * @return \WP_Error|null
	 */
	public function validate( mixed $value, Field $field ): ?\WP_Error {
		$type  = $this->type();
		$empty = is_array( $value ) ? array() === $value : '' === trim( (string) $value );
		if ( ( $field->get( 'required' ) || 'consent' === $type ) && ( $empty || ( 'consent' === $type && '1' !== $value ) ) ) {
			return new \WP_Error( 'tbreg_required', __( 'Completa este campo obligatorio.', 'toolsbox-registros' ) );
		}
		if ( $empty ) {
			return null;
		}
		if ( $this->supportsOptions() ) {
			$allowed = array_column( $field->get( 'options' ), 'value' );
			foreach ( is_array( $value ) ? $value : array( $value ) as $item ) {
				if ( ! in_array( $item, $allowed, true ) ) {
					return new \WP_Error( 'tbreg_choice', __( 'Selecciona una opción válida.', 'toolsbox-registros' ) );
				}
			}
		}
		if ( 'email' === $type || 'email' === $field->get( 'role' ) ) {
			if ( ! is_string( $value ) || ! is_email( $value ) || strlen( $value ) > 190 ) {
				return new \WP_Error( 'tbreg_email', __( 'Escribe un correo válido (máximo 190 caracteres).', 'toolsbox-registros' ) );
			}
		}
		if ( 'document' === $type || 'document' === $field->get( 'role' ) ) {
			if ( ! is_string( $value ) || ! preg_match( '/^[\p{L}\p{N} .-]{1,60}$/uD', $value ) ) {
				return new \WP_Error( 'tbreg_document', __( 'Usa letras, números, espacios, puntos o guiones (máximo 60 caracteres).', 'toolsbox-registros' ) );
			}
		}
		if ( 'url' === $type && ( ! filter_var( $value, FILTER_VALIDATE_URL ) || ! in_array( strtolower( (string) wp_parse_url( $value, PHP_URL_SCHEME ) ), array( 'http', 'https' ), true ) ) ) {
			return new \WP_Error( 'tbreg_url', __( 'Escribe una URL completa con http o https.', 'toolsbox-registros' ) );
		}
		if ( 'number' === $type && ( ! is_numeric( $value ) || ! is_finite( (float) $value ) ) ) {
			return new \WP_Error( 'tbreg_number', __( 'Escribe un número válido.', 'toolsbox-registros' ) );
		}
		if ( in_array( $type, array( 'date', 'time' ), true ) ) {
			$format = 'date' === $type ? 'Y-m-d' : ( 8 === strlen( $value ) ? 'H:i:s' : 'H:i' );
			$date   = \DateTimeImmutable::createFromFormat( '!' . $format, $value );
			if ( false === $date || $date->format( $format ) !== $value ) {
				return new \WP_Error( 'tbreg_date', __( 'La fecha o la hora no es válida.', 'toolsbox-registros' ) );
			}
		}
		$limits = $field->get( 'validation' );
		foreach ( is_array( $value ) ? $value : array( $value ) as $item ) {
			$length = mb_strlen( (string) $item );
			if ( $length > ( $limits['maxlength'] ?? 5000 ) || ( null !== $limits['minlength'] && $length < $limits['minlength'] ) ) {
				return new \WP_Error( 'tbreg_length', __( 'La longitud no cumple los límites del campo.', 'toolsbox-registros' ) );
			}
			if ( 'number' === $type && ( ( null !== $limits['min'] && (float) $item < $limits['min'] ) || ( null !== $limits['max'] && (float) $item > $limits['max'] ) ) ) {
				return new \WP_Error( 'tbreg_range', __( 'El número está fuera del rango permitido.', 'toolsbox-registros' ) );
			}
			$comparable = 'time' === $type && 5 === strlen( (string) $item ) ? $item . ':00' : (string) $item;
			if ( in_array( $type, array( 'date', 'time' ), true ) && ( ( null !== $limits['min'] && strcmp( $comparable, $limits['min'] ) < 0 ) || ( null !== $limits['max'] && strcmp( $comparable, $limits['max'] ) > 0 ) ) ) {
				return new \WP_Error( 'tbreg_range', __( 'La fecha o la hora está fuera del rango permitido.', 'toolsbox-registros' ) );
			}
			if ( ! empty( $limits['pattern'] ) ) {
				// El patrón proviene del administrador; un patrón inválido se rechaza sin emitir warnings públicos.
				$pattern = '~\A(?:' . str_replace( '~', '\\~', $limits['pattern'] ) . ')\z~u';
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- preg_match devuelve false para patrones inválidos y se muestra un error seguro.
				if ( 1 !== @preg_match( $pattern, (string) $item ) ) {
					return new \WP_Error( 'tbreg_pattern', __( 'El valor no cumple el formato requerido.', 'toolsbox-registros' ) );
				}
			}
		}
		return null;
	}
	/**
	 * Renderiza el tipo usando una plantilla sustituible.
	 *
	 * @param Field               $field Esquema.
	 * @param mixed               $value Valor.
	 * @param array<string,mixed> $context Contexto accesible.
	 * @return string
	 */
	public function render( Field $field, mixed $value, array $context = array() ): string {
		return Template::render(
			'fields/' . $this->type() . '.php',
			array_merge(
				array(
					'id'           => wp_unique_id( 'tbreg-' . $field->key() . '-' ),
					'error'        => '',
					'consent_text' => '',
				),
				$context,
				array(
					'field' => $field,
					'value' => $value,
				)
			)
		);
	}
	/**
	 * Devuelve etiquetas visibles para las opciones.
	 *
	 * @param mixed $stored Valor guardado.
	 * @param Field $field Esquema.
	 * @return string
	 */
	public function toExport( mixed $stored, Field $field ): string {
		$options = array_column( $field->get( 'options' ), 'label', 'value' );
		$values  = is_array( $stored ) ? $stored : array( $stored );
		return implode( ', ', array_map( static fn ( mixed $value ): string => $options[ (string) $value ] ?? (string) $value, $values ) );
	}
}
