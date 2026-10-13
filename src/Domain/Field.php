<?php
/**
 * Esquema inmutable de un campo.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);

namespace Codwelt\ToolsBoxRegistros\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Valida y normaliza la definición de un campo.
 */
final class Field {
	public const TYPES = array( 'text', 'email', 'tel', 'number', 'textarea', 'select', 'radio', 'checkbox', 'date', 'time', 'url', 'document', 'consent', 'heading', 'hidden' );

	/**
	 * Definición normalizada.
	 *
	 * @var array<string,mixed>
	 */
	private array $data;

	/**
	 * Inicializa los datos de la instancia.
	 *
	 * @param array<string,mixed> $data Definición validada.
	 */
	private function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * Construye la entidad y valida los datos recibidos.
	 *
	 * @param array<string,mixed> $data Definición recibida.
	 * @return self
	 * @throws \InvalidArgumentException Si el esquema no es válido.
	 */
	public static function fromArray( array $data ): self {
		$key  = $data['key'] ?? '';
		$type = $data['type'] ?? '';
		if ( ! is_string( $key ) || ! preg_match( '/^[a-z0-9_]{3,64}$/D', $key ) ) {
			throw new \InvalidArgumentException( __( 'El identificador del campo no es válido.', 'toolsbox-registros' ) );
		}
		if ( ! is_string( $type ) || ! in_array( $type, self::TYPES, true ) ) {
			throw new \InvalidArgumentException( __( 'El tipo de campo no es válido.', 'toolsbox-registros' ) );
		}

		$result = array(
			'key'  => $key,
			'type' => $type,
		);
		foreach ( array( 'label', 'placeholder', 'help', 'css_class' ) as $name ) {
			$value = $data[ $name ] ?? '';
			if ( ! is_string( $value ) ) {
				throw new \InvalidArgumentException( __( 'Los textos del campo deben ser cadenas.', 'toolsbox-registros' ) );
			}
			$result[ $name ] = 'help' === $name ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
		}
		if ( '' === $result['label'] ) {
			throw new \InvalidArgumentException( __( 'Todos los campos necesitan una etiqueta.', 'toolsbox-registros' ) );
		}
		$result['css_class'] = implode( ' ', array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', $result['css_class'] ) ) ) );
		foreach ( array( 'required', 'archived' ) as $name ) {
			$value = $data[ $name ] ?? false;
			if ( ! is_bool( $value ) ) {
				throw new \InvalidArgumentException( __( 'Las opciones de obligatorio y archivado deben ser booleanas.', 'toolsbox-registros' ) );
			}
			$result[ $name ] = $value;
		}
		$result['width'] = $data['width'] ?? 'full';
		$result['role']  = $data['role'] ?? null;
		if ( ! in_array( $result['width'], array( 'full', 'half', 'third' ), true ) || ! in_array( $result['role'], array( null, 'email', 'document', 'name' ), true ) ) {
			throw new \InvalidArgumentException( __( 'El ancho o el rol del campo no es válido.', 'toolsbox-registros' ) );
		}
		$options = $data['options'] ?? array();
		if ( ! is_array( $options ) || array_values( $options ) !== $options ) {
			throw new \InvalidArgumentException( __( 'Las opciones deben ser una lista.', 'toolsbox-registros' ) );
		}
		$choice = in_array( $type, array( 'select', 'radio', 'checkbox' ), true );
		if ( ( $choice && array() === $options ) || ( ! $choice && array() !== $options ) ) {
			throw new \InvalidArgumentException( __( 'Los campos de selección necesitan opciones; los demás tipos no las admiten.', 'toolsbox-registros' ) );
		}
		$result['options'] = array();
		$seen              = array();
		foreach ( $options as $option ) {
			if ( ! is_array( $option ) || ! isset( $option['value'], $option['label'] ) || ! is_string( $option['value'] ) || ! is_string( $option['label'] ) ) {
				throw new \InvalidArgumentException( __( 'Cada opción necesita un valor y una etiqueta de texto.', 'toolsbox-registros' ) );
			}
			$value = sanitize_text_field( $option['value'] );
			$label = sanitize_text_field( $option['label'] );
			if ( '' === $value || '' === $label || in_array( $value, $seen, true ) ) {
				throw new \InvalidArgumentException( __( 'Las opciones no pueden estar vacías ni repetir valores.', 'toolsbox-registros' ) );
			}
			$seen[]              = $value;
			$result['options'][] = array(
				'value' => $value,
				'label' => $label,
			);
		}

		$default = $data['default'] ?? '';
		if ( ! is_scalar( $default ) && ! ( 'checkbox' === $type && is_array( $default ) && array_values( $default ) === $default ) ) {
			throw new \InvalidArgumentException( __( 'El valor predeterminado no es válido.', 'toolsbox-registros' ) );
		}
		foreach ( is_array( $default ) ? $default : array( $default ) as $value ) {
			if ( ! is_scalar( $value ) || ( $choice && '' !== (string) $value && ! in_array( (string) $value, $seen, true ) ) ) {
				throw new \InvalidArgumentException( __( 'El valor predeterminado debe pertenecer a las opciones del campo.', 'toolsbox-registros' ) );
			}
		}
		$result['default'] = is_array( $default ) ? array_map( 'sanitize_text_field', $default ) : sanitize_text_field( (string) $default );
		$validation        = $data['validation'] ?? array();
		if ( ! is_array( $validation ) ) {
			throw new \InvalidArgumentException( __( 'La validación del campo debe ser un objeto.', 'toolsbox-registros' ) );
		}
		$result['validation'] = array();
		foreach ( array( 'min', 'max', 'minlength', 'maxlength' ) as $name ) {
			$value = $validation[ $name ] ?? null;
			if ( null !== $value && ( ! is_int( $value ) && ! is_float( $value ) ) ) {
				throw new \InvalidArgumentException( __( 'Los límites de validación deben ser números.', 'toolsbox-registros' ) );
			}
			if ( null !== $value && ( ! is_finite( (float) $value ) || ( str_contains( $name, 'length' ) && ( ! is_int( $value ) || $value < 0 ) ) ) ) {
				throw new \InvalidArgumentException( __( 'Los límites de longitud deben ser enteros no negativos.', 'toolsbox-registros' ) );
			}
			$result['validation'][ $name ] = $value;
		}
		foreach ( array( array( 'min', 'max' ), array( 'minlength', 'maxlength' ) ) as $pair ) {
			if ( null !== $result['validation'][ $pair[0] ] && null !== $result['validation'][ $pair[1] ] && $result['validation'][ $pair[0] ] > $result['validation'][ $pair[1] ] ) {
				throw new \InvalidArgumentException( __( 'El mínimo no puede superar el máximo.', 'toolsbox-registros' ) );
			}
		}
		$pattern = $validation['pattern'] ?? null;
		if ( null !== $pattern && ( ! is_string( $pattern ) || strlen( $pattern ) > 500 ) ) {
			throw new \InvalidArgumentException( __( 'El patrón de validación no es válido.', 'toolsbox-registros' ) );
		}
		$result['validation']['pattern'] = $pattern;
		return new self( $result );
	}

	/**
	 * Devuelve una copia del esquema normalizado.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return $this->data;
	}

	/**
	 * Consulta un atributo del esquema.
	 *
	 * @param string $name Atributo del campo.
	 * @return mixed
	 */
	public function get( string $name ): mixed {
		return $this->data[ $name ] ?? null;
	}

	/**
	 * Devuelve el identificador inmutable.
	 *
	 * @return string
	 */
	public function key(): string {
		return $this->data['key'];
	}

	/**
	 * Indica si el campo activo almacena valores.
	 *
	 * @return bool
	 */
	public function storesValue(): bool {
		return ! $this->data['archived'] && 'heading' !== $this->data['type'];
	}
}
