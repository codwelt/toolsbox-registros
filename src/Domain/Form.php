<?php
/**
 * Entidad de formulario y validación de su esquema.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);

namespace Codwelt\ToolsBoxRegistros\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Representa un formulario sin mutaciones de sus campos.
 */
final class Form {
	public const STATUSES = array( 'draft', 'published', 'closed', 'trash' );

	/**
	 * Datos normalizados del formulario.
	 *
	 * @var array<string,mixed>
	 */
	private array $data;

	/**
	 * Campos ordenados del formulario.
	 *
	 * @var array<Field> Campos ordenados.
	 */
	private array $fields;

	/**
	 * Inicializa los datos de la instancia.
	 *
	 * @param array<string,mixed> $data Datos validados.
	 * @param array<Field>        $fields Campos validados.
	 */
	private function __construct( array $data, array $fields ) {
		$this->data   = $data;
		$this->fields = $fields;
	}

	/**
	 * Construye la entidad y valida los datos recibidos.
	 *
	 * @param array<string,mixed> $data Datos de edición o fila de la base de datos.
	 * @return self
	 * @throws \InvalidArgumentException Si el esquema no es válido.
	 */
	public static function fromArray( array $data ): self {
		$result = array();
		foreach ( array( 'id', 'entries_count', 'created_by' ) as $name ) {
			$value = $data[ $name ] ?? 0;
			if ( ! is_int( $value ) && ! ( is_string( $value ) && preg_match( '/^\d+$/D', $value ) ) ) {
				throw new \InvalidArgumentException( __( 'Los identificadores y contadores deben ser enteros no negativos.', 'toolsbox-registros' ) );
			}
			if ( $value < 0 || (float) $value > PHP_INT_MAX ) {
				throw new \InvalidArgumentException( __( 'El identificador o contador está fuera de rango.', 'toolsbox-registros' ) );
			}
			$result[ $name ] = (int) $value;
		}
		foreach ( array( 'title', 'slug', 'description', 'created_at', 'updated_at' ) as $name ) {
			$value = $data[ $name ] ?? '';
			if ( ! is_string( $value ) ) {
				throw new \InvalidArgumentException( __( 'Los textos del formulario deben ser cadenas.', 'toolsbox-registros' ) );
			}
			$result[ $name ] = 'description' === $name ? wp_kses_post( $value ) : sanitize_text_field( $value );
		}
		if ( '' === $result['title'] || mb_strlen( $result['title'] ) > 200 ) {
			throw new \InvalidArgumentException( __( 'El título es obligatorio y admite hasta 200 caracteres.', 'toolsbox-registros' ) );
		}
		$result['slug'] = sanitize_title( '' === $result['slug'] ? $result['title'] : $result['slug'] );
		if ( '' === ( $data['slug'] ?? '' ) && strlen( $result['slug'] ) > 200 ) {
			$result['slug'] = utf8_uri_encode( rawurldecode( $result['slug'] ), 200, true );
		}
		$result['status'] = $data['status'] ?? 'draft';
		if ( strlen( $result['slug'] ) > 200 || ! in_array( $result['status'], self::STATUSES, true ) ) {
			throw new \InvalidArgumentException( __( 'El slug o el estado del formulario no es válido.', 'toolsbox-registros' ) );
		}
		foreach ( array( 'created_at', 'updated_at' ) as $name ) {
			if ( '' !== $result[ $name ] && ! self::valid_date( $result[ $name ], 'Y-m-d H:i:s' ) ) {
				throw new \InvalidArgumentException( __( 'Las fechas del formulario deben usar el formato UTC de la base de datos.', 'toolsbox-registros' ) );
			}
		}

		$raw_fields = self::decode( $data['fields'] ?? array() );
		if ( array_values( $raw_fields ) !== $raw_fields ) {
			throw new \InvalidArgumentException( __( 'Los campos deben ser una lista ordenada.', 'toolsbox-registros' ) );
		}
		$fields = array();
		$keys   = array();
		$roles  = array();
		foreach ( $raw_fields as $raw ) {
			if ( ! is_array( $raw ) ) {
				throw new \InvalidArgumentException( __( 'Cada campo debe ser un objeto.', 'toolsbox-registros' ) );
			}
			$field = Field::fromArray( $raw );
			if ( in_array( $field->key(), $keys, true ) ) {
				throw new \InvalidArgumentException( __( 'Los identificadores de los campos no pueden repetirse.', 'toolsbox-registros' ) );
			}
			$role = $field->get( 'role' );
			if ( $field->storesValue() && null !== $role ) {
				if ( in_array( $role, $roles, true ) ) {
					throw new \InvalidArgumentException( __( 'Cada rol principal solo puede asignarse a un campo activo.', 'toolsbox-registros' ) );
				}
				$roles[] = $role;
			}
			$keys[]   = $field->key();
			$fields[] = $field;
		}
		$result['settings'] = self::normalize_settings( self::decode( $data['settings'] ?? array() ) );
		return new self( $result, $fields );
	}

	/**
	 * Decodifica el esquema JSON sin aceptar datos mal formados.
	 *
	 * @param mixed $value JSON o array.
	 * @return array<mixed>
	 * @throws \InvalidArgumentException Si el JSON es inválido.
	 */
	private static function decode( mixed $value ): array {
		if ( is_string( $value ) ) {
			try {
				$value = json_decode( $value, true, 64, JSON_THROW_ON_ERROR );
			} catch ( \JsonException $exception ) {
				throw new \InvalidArgumentException( __( 'El esquema JSON está mal formado.', 'toolsbox-registros' ), 0, $exception );
			}
		}
		if ( ! is_array( $value ) ) {
			throw new \InvalidArgumentException( __( 'El esquema debe ser un array o un objeto JSON.', 'toolsbox-registros' ) );
		}
		return $value;
	}

	/**
	 * Comprueba el formato y la existencia de una fecha.
	 *
	 * @param string $value Fecha recibida.
	 * @param string $format Formato esperado.
	 * @return bool
	 */
	private static function valid_date( string $value, string $format ): bool {
		$date = \DateTimeImmutable::createFromFormat( '!' . $format, $value, new \DateTimeZone( 'UTC' ) );
		return false !== $date && $date->format( $format ) === $value;
	}

	/**
	 * Normaliza y valida los ajustes del formulario.
	 *
	 * @param array<string,mixed> $settings Ajustes recibidos.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException Si los ajustes no son válidos.
	 */
	private static function normalize_settings( array $settings ): array {
		$result = array();
		foreach ( array( 'waitlist', 'require_login' ) as $name ) {
			$result[ $name ] = $settings[ $name ] ?? false;
			if ( ! is_bool( $result[ $name ] ) ) {
				throw new \InvalidArgumentException( __( 'Los ajustes de lista de espera y acceso deben ser booleanos.', 'toolsbox-registros' ) );
			}
		}
		$result['capacity'] = $settings['capacity'] ?? 0;
		if ( ! is_int( $result['capacity'] ) || $result['capacity'] < 0 || $result['capacity'] > 4294967295 ) {
			throw new \InvalidArgumentException( __( 'El cupo debe ser un entero no negativo.', 'toolsbox-registros' ) );
		}
		foreach ( array(
			'prevent_duplicates' => array( 'none', 'email', 'document' ),
			'default_status'     => array( 'confirmed', 'pending', 'attended', 'cancelled', 'waitlist' ),
			'captcha'            => array( 'none', 'turnstile', 'recaptcha' ),
		) as $name => $allowed ) {
			$result[ $name ] = $settings[ $name ] ?? $allowed[0];
			if ( ! in_array( $result[ $name ], $allowed, true ) ) {
				throw new \InvalidArgumentException( __( 'Una opción de configuración del formulario no es válida.', 'toolsbox-registros' ) );
			}
		}
		$texts = array(
			'submit_label'    => __( 'Inscribirme', 'toolsbox-registros' ),
			'success_message' => __( '¡Gracias! Tu inscripción fue registrada.', 'toolsbox-registros' ),
			'redirect_url'    => '',
			'opens_at'        => '',
			'closes_at'       => '',
			'consent_text'    => '',
		);
		foreach ( $texts as $name => $default ) {
			$value = $settings[ $name ] ?? $default;
			if ( ! is_string( $value ) ) {
				throw new \InvalidArgumentException( __( 'Los mensajes y las fechas deben ser cadenas.', 'toolsbox-registros' ) );
			}
			$result[ $name ] = 'redirect_url' === $name ? esc_url_raw( $value ) : sanitize_textarea_field( $value );
			if ( in_array( $name, array( 'opens_at', 'closes_at' ), true ) && '' !== $value ) {
				$value = str_replace( 'T', ' ', $value );
				$value = 16 === strlen( $value ) ? $value . ':00' : $value;
				if ( ! self::valid_date( $value, 'Y-m-d H:i:s' ) ) {
					throw new \InvalidArgumentException( __( 'La fecha de apertura o cierre no es válida.', 'toolsbox-registros' ) );
				}
				$result[ $name ] = $value;
			}
		}
		if ( '' !== $result['opens_at'] && '' !== $result['closes_at'] && $result['opens_at'] > $result['closes_at'] ) {
			throw new \InvalidArgumentException( __( 'La apertura no puede ser posterior al cierre.', 'toolsbox-registros' ) );
		}
		$event = $settings['event'] ?? array();
		if ( ! is_array( $event ) ) {
			throw new \InvalidArgumentException( __( 'La configuración del evento debe ser un objeto.', 'toolsbox-registros' ) );
		}
		$result['event'] = array();
		foreach ( array( 'name', 'starts_at', 'ends_at', 'location', 'modality' ) as $name ) {
			$value = $event[ $name ] ?? ( 'modality' === $name ? 'presencial' : '' );
			if ( ! is_string( $value ) || ( 'modality' === $name && ! in_array( $value, array( 'presencial', 'virtual', 'hibrido' ), true ) ) ) {
				throw new \InvalidArgumentException( __( 'La configuración del evento no es válida.', 'toolsbox-registros' ) );
			}
			$result['event'][ $name ] = sanitize_text_field( $value );
		}
		foreach ( array( 'notify_admin', 'confirm_attendee' ) as $name ) {
			$raw = $settings[ $name ] ?? array();
			if ( ! is_array( $raw ) || ( isset( $raw['enabled'] ) && ! is_bool( $raw['enabled'] ) ) ) {
				throw new \InvalidArgumentException( __( 'La configuración de notificaciones no es válida.', 'toolsbox-registros' ) );
			}
			$result[ $name ] = array( 'enabled' => $raw['enabled'] ?? true );
			foreach ( 'notify_admin' === $name ? array( 'to', 'subject' ) : array( 'subject', 'body' ) as $attribute ) {
				$value = $raw[ $attribute ] ?? '';
				if ( ! is_string( $value ) ) {
					throw new \InvalidArgumentException( __( 'Los textos de las notificaciones deben ser cadenas.', 'toolsbox-registros' ) );
				}
				$result[ $name ][ $attribute ] = 'body' === $attribute ? wp_kses_post( $value ) : sanitize_text_field( $value );
			}
		}
		return $result;
	}

	/**
	 * Devuelve el identificador del formulario.
	 *
	 * @return int
	 */
	public function id(): int {
		return $this->data['id'];
	}

	/**
	 * Consulta un atributo del esquema.
	 *
	 * @param string $name Atributo del formulario.
	 * @return mixed
	 */
	public function get( string $name ): mixed {
		return $this->data[ $name ] ?? null;
	}

	/**
	 * Devuelve los campos en el orden guardado.
	 *
	 * @return array<Field>
	 */
	public function fields(): array {
		return $this->fields;
	}

	/**
	 * Devuelve la configuración del formulario.
	 *
	 * @return array<string,mixed>
	 */
	public function settings(): array {
		return $this->data['settings'];
	}

	/**
	 * Devuelve una copia del esquema normalizado.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array_merge( $this->data, array( 'fields' => array_map( static fn ( Field $field ): array => $field->toArray(), $this->fields ) ) );
	}
}
