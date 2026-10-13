<?php
/**
 * Persistencia transaccional de inscripciones y consultas para exportación.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);

namespace Codwelt\ToolsBoxRegistros\Repository;

use Codwelt\ToolsBoxRegistros\Domain\Form;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serializa las escrituras de cada formulario bloqueando primero su fila.
 */
final class EntryRepository extends Repository {
	public const STATUSES = array( 'pending', 'confirmed', 'attended', 'cancelled', 'waitlist' );

	/**
	 * Guarda la inscripción y sus valores en una transacción.
	 *
	 * @param Form                $form Formulario guardado.
	 * @param array<string,mixed> $values Valores saneados por el servicio de envío.
	 * @param array<string,mixed> $meta Estado, usuario y metadatos de origen.
	 * @return int|\WP_Error
	 */
	public function create( Form $form, array $values, array $meta = array() ): int|\WP_Error {
		try {
			list( $id, $saved_form, $saved_values ) = $this->transaction(
				function () use ( $form, $values, $meta ): array {
					$row = $this->row( 'SELECT * FROM ' . $this->table( 'forms' ) . ' WHERE id = %d FOR UPDATE', array( $form->id() ) );
					if ( null === $row || 'trash' === $row['status'] ) {
						throw new \InvalidArgumentException( __( 'El formulario no existe o está en la papelera.', 'toolsbox-registros' ) );
					}
					$current  = Form::fromArray( $row );
					$settings = $current->settings();
					$status   = $meta['status'] ?? $settings['default_status'];
					if ( ! in_array( $status, self::STATUSES, true ) ) {
						throw new \InvalidArgumentException( __( 'El estado de la inscripción no es válido.', 'toolsbox-registros' ) );
					}
					$stored   = $this->normalize_values( $current, $values );
					$email    = null;
					$document = null;
					foreach ( $current->fields() as $field ) {
						$value = $values[ $field->key() ] ?? '';
						if ( ! $field->storesValue() || ! is_scalar( $value ) || '' === (string) $value ) {
							continue;
						}
						if ( 'email' === $field->get( 'role' ) ) {
							$email = strtolower( sanitize_email( (string) $value ) );
							if ( ! is_email( $email ) || strlen( $email ) > 190 ) {
								throw new \InvalidArgumentException( __( 'El correo principal no es válido.', 'toolsbox-registros' ) );
							}
						} elseif ( 'document' === $field->get( 'role' ) ) {
							$document = sanitize_text_field( (string) $value );
							if ( '' === $document || mb_strlen( $document ) > 60 ) {
								throw new \InvalidArgumentException( __( 'El documento principal debe contener entre 1 y 60 caracteres.', 'toolsbox-registros' ) );
							}
						}
					}
					if ( ( 'email' === $settings['prevent_duplicates'] && null === $email ) || ( 'document' === $settings['prevent_duplicates'] && null === $document ) ) {
						throw new \InvalidArgumentException( __( 'Debes completar el campo principal configurado para controlar duplicados.', 'toolsbox-registros' ) );
					}
					if ( ( 'email' === $settings['prevent_duplicates'] && null !== $email && $this->existsByEmail( $form->id(), $email ) ) || ( 'document' === $settings['prevent_duplicates'] && null !== $document && $this->existsByDocument( $form->id(), $document ) ) ) {
						throw new \InvalidArgumentException( __( 'Ya existe una inscripción con estos datos.', 'toolsbox-registros' ) );
					}
					if ( self::occupies_capacity( $status ) && $settings['capacity'] > 0 && (int) $row['entries_count'] >= $settings['capacity'] ) {
						if ( ! $settings['waitlist'] ) {
							throw new \InvalidArgumentException( __( 'El formulario no tiene cupos disponibles.', 'toolsbox-registros' ) );
						}
						$status = 'waitlist';
					}
					$ip_hash = '';
					if ( isset( $meta['ip'] ) && is_string( $meta['ip'] ) && filter_var( $meta['ip'], FILTER_VALIDATE_IP ) ) {
						$ip_hash = hash_hmac( 'sha256', $meta['ip'], wp_salt() );
					} elseif ( isset( $meta['ip_hash'] ) && is_string( $meta['ip_hash'] ) && preg_match( '/^[a-f0-9]{64}$/D', $meta['ip_hash'] ) ) {
						$ip_hash = $meta['ip_hash'];
					}
					$now = current_time( 'mysql', true );
					$id  = $this->insert(
						'entries',
						array(
							'form_id'    => $form->id(),
							'status'     => $status,
							'user_id'    => empty( $meta['user_id'] ) ? null : absint( $meta['user_id'] ),
							'email'      => $email,
							'document'   => $document,
							'ip_hash'    => $ip_hash,
							'user_agent' => mb_substr( sanitize_text_field( (string) ( $meta['user_agent'] ?? '' ) ), 0, 255 ),
							'source_url' => mb_substr( esc_url_raw( (string) ( $meta['source_url'] ?? '' ) ), 0, 255 ),
							'created_at' => $now,
							'updated_at' => $now,
						)
					);
					foreach ( $stored as $key => $value ) {
						$this->insert(
							'entry_values',
							array(
								'entry_id'  => $id,
								'field_key' => $key,
								'value'     => $value,
							)
						);
					}
					if ( self::occupies_capacity( $status ) ) {
						( new FormRepository( $this->db ) )->incrementCount( $form->id() );
					}
					return array( $id, ( new FormRepository( $this->db ) )->find( $form->id() ), $this->values( $id ) );
				}
			);
		} catch ( \RuntimeException | \InvalidArgumentException $exception ) {
			return $this->error( $exception );
		}
		do_action( 'tbreg_entry_created', $id, $saved_form, $saved_values );
		return $id;
	}

	/**
	 * Rechaza valores ajenos al esquema y normaliza su almacenamiento.
	 *
	 * @param Form                $form Esquema actual.
	 * @param array<string,mixed> $values Valores recibidos.
	 * @return array<string,string>
	 * @throws \InvalidArgumentException Si hay claves o valores incompatibles.
	 */
	private function normalize_values( Form $form, array $values ): array {
		$fields = array();
		foreach ( $form->fields() as $field ) {
			if ( $field->storesValue() ) {
				$fields[ $field->key() ] = $field;
			}
		}
		$stored = array();
		foreach ( $values as $key => $value ) {
			if ( ! isset( $fields[ $key ] ) || ( ! is_scalar( $value ) && ! is_array( $value ) ) ) {
				throw new \InvalidArgumentException( __( 'La inscripción contiene un campo o un valor no permitido.', 'toolsbox-registros' ) );
			}
			$field = $fields[ $key ];
			if ( is_array( $value ) && ( 'checkbox' !== $field->get( 'type' ) || array_values( $value ) !== $value ) ) {
				throw new \InvalidArgumentException( __( 'Solo los checkbox admiten listas de valores.', 'toolsbox-registros' ) );
			}
			$options = array_column( $field->get( 'options' ), 'value' );
			foreach ( is_array( $value ) ? $value : array( $value ) as $item ) {
				if ( ! is_scalar( $item ) || ( array() !== $options && '' !== (string) $item && ! in_array( (string) $item, $options, true ) ) ) {
					throw new \InvalidArgumentException( __( 'El valor no pertenece a las opciones del campo.', 'toolsbox-registros' ) );
				}
			}
			$stored[ $key ] = is_array( $value ) ? (string) wp_json_encode( array_map( 'sanitize_text_field', $value ), JSON_UNESCAPED_UNICODE ) : sanitize_textarea_field( (string) $value );
		}
		return $stored;
	}

	/**
	 * Indica qué estados reservan un cupo.
	 *
	 * @param string $status Estado de inscripción.
	 * @return bool
	 */
	private static function occupies_capacity( string $status ): bool {
		return in_array( $status, array( 'pending', 'confirmed', 'attended' ), true );
	}

	/**
	 * Busca un registro por su identificador.
	 *
	 * @param int $id Identificador de inscripción.
	 * @return array<string,mixed>|null
	 */
	public function find( int $id ): ?array {
		return $this->row( 'SELECT * FROM ' . $this->table( 'entries' ) . ' WHERE id = %d', array( $id ) );
	}

	/**
	 * Recupera los valores conservando las listas de checkbox.
	 *
	 * @param int $entry_id Identificador de inscripción.
	 * @return array<string,mixed>
	 */
	public function values( int $entry_id ): array {
		$entry = $this->find( $entry_id );
		if ( null === $entry ) {
			return array();
		}
		$form = ( new FormRepository( $this->db ) )->find( (int) $entry['form_id'] );
		return $this->value_map( $this->rows( 'SELECT field_key, value FROM ' . $this->table( 'entry_values' ) . ' WHERE entry_id = %d ORDER BY id', array( $entry_id ) ), $form );
	}

	/**
	 * Convierte filas de valores según el tipo de campo.
	 *
	 * @param array<array<string,mixed>> $rows Valores almacenados.
	 * @param Form|null                  $form Esquema del formulario.
	 * @return array<string,mixed>
	 */
	private function value_map( array $rows, ?Form $form ): array {
		$checkboxes = array();
		foreach ( null === $form ? array() : $form->fields() as $field ) {
			if ( 'checkbox' === $field->get( 'type' ) ) {
				$checkboxes[] = $field->key();
			}
		}
		$values = array();
		foreach ( $rows as $row ) {
			$value = $row['value'];
			if ( in_array( $row['field_key'], $checkboxes, true ) ) {
				$decoded = json_decode( $value, true );
				$value   = is_array( $decoded ) ? $decoded : $value;
			}
			$values[ $row['field_key'] ] = $value;
		}
		return $values;
	}

	/**
	 * Busca una inscripción no cancelada por su correo.
	 *
	 * @param int    $form_id Formulario.
	 * @param string $email Correo principal.
	 * @return bool
	 */
	public function existsByEmail( int $form_id, string $email ): bool {
		return '' !== $email && null !== $this->scalar( 'SELECT id FROM ' . $this->table( 'entries' ) . ' WHERE form_id = %d AND email = %s AND status <> %s LIMIT 1', array( $form_id, strtolower( sanitize_email( $email ) ), 'cancelled' ) );
	}

	/**
	 * Busca una inscripción no cancelada por su documento.
	 *
	 * @param int    $form_id Formulario.
	 * @param string $document Documento principal.
	 * @return bool
	 */
	public function existsByDocument( int $form_id, string $document ): bool {
		return '' !== $document && null !== $this->scalar( 'SELECT id FROM ' . $this->table( 'entries' ) . ' WHERE form_id = %d AND document = %s AND status <> %s LIMIT 1', array( $form_id, sanitize_text_field( $document ), 'cancelled' ) );
	}

	/**
	 * Construye condiciones internas y enlaza los filtros recibidos.
	 *
	 * @param array<string,mixed> $filters Filtros de formulario, estado, fecha y búsqueda.
	 * @return array{0:string,1:array<mixed>}
	 * @throws \InvalidArgumentException Si una fecha no es válida.
	 */
	private function where( array $filters ): array {
		$where  = array( '1=1' );
		$values = array();
		if ( isset( $filters['form_id'] ) ) {
			$where[]  = 'e.form_id = %d';
			$values[] = (int) $filters['form_id'];
		}
		if ( ! empty( $filters['status'] ) ) {
			$where[]  = 'e.status = %s';
			$values[] = (string) $filters['status'];
		}
		foreach ( array(
			'date_from' => '>=',
			'date_to'   => '<=',
		) as $key => $operator ) {
			if ( empty( $filters[ $key ] ) ) {
				continue;
			}
			$date = (string) $filters[ $key ];
			$date = 10 === strlen( $date ) ? $date . ( 'date_from' === $key ? ' 00:00:00' : ' 23:59:59' ) : $date;
			$time = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $date, new \DateTimeZone( 'UTC' ) );
			if ( false === $time || $time->format( 'Y-m-d H:i:s' ) !== $date ) {
				throw new \InvalidArgumentException( __( 'El filtro de fecha no es válido. Usa fechas UTC.', 'toolsbox-registros' ) );
			}
			$where[]  = "e.created_at {$operator} %s";
			$values[] = $date;
		}
		if ( ! empty( $filters['search'] ) ) {
			$search   = '%' . $this->db->esc_like( (string) $filters['search'] ) . '%';
			$where[]  = '(e.email LIKE %s OR e.document LIKE %s OR EXISTS (SELECT 1 FROM ' . $this->table( 'entry_values' ) . ' v WHERE v.entry_id = e.id AND v.value LIKE %s))';
			$values[] = $search;
			$values[] = $search;
			$values[] = $search;
		}
		return array( implode( ' AND ', $where ), $values );
	}

	/**
	 * Devuelve las inscripciones paginadas y el total filtrado.
	 *
	 * @param array<string,mixed> $filters Filtros, per_page y page.
	 * @return array<string,mixed>
	 */
	public function paginate( array $filters = array() ): array {
		list( $where, $values ) = $this->where( $filters );
		$total                  = (int) $this->scalar( 'SELECT COUNT(*) FROM ' . $this->table( 'entries' ) . ' e WHERE ' . $where, $values );
		$limit                  = min( 200, max( 1, (int) ( $filters['per_page'] ?? 20 ) ) );
		$page                   = max( 1, (int) ( $filters['page'] ?? 1 ) );
		$items                  = $this->rows( 'SELECT e.* FROM ' . $this->table( 'entries' ) . ' e WHERE ' . $where . ' ORDER BY e.id DESC LIMIT %d OFFSET %d', array_merge( $values, array( $limit, ( $page - 1 ) * $limit ) ) );
		return array(
			'items' => $items,
			'total' => $total,
			'pages' => (int) ceil( $total / $limit ),
			'page'  => $page,
		);
	}

	/**
	 * Cambia el estado y ajusta el contador bajo bloqueo.
	 *
	 * @param int    $id Inscripción.
	 * @param string $status Nuevo estado.
	 * @return bool|\WP_Error
	 */
	public function updateStatus( int $id, string $status ): bool|\WP_Error {
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return new \WP_Error( 'tbreg_invalid_status', __( 'El estado de inscripción no es válido.', 'toolsbox-registros' ) );
		}
		$entry = $this->find( $id );
		if ( null === $entry ) {
			return false;
		}
		$old_status = '';
		try {
			$changed = $this->transaction(
				function () use ( $id, $status, $entry, &$old_status ): bool {
					$form = $this->row( 'SELECT * FROM ' . $this->table( 'forms' ) . ' WHERE id = %d FOR UPDATE', array( (int) $entry['form_id'] ) );
					$row  = $this->row( 'SELECT * FROM ' . $this->table( 'entries' ) . ' WHERE id = %d FOR UPDATE', array( $id ) );
					if ( null === $form || null === $row || $row['status'] === $status ) {
						return false;
					}
					$old_status = $row['status'];
					$delta      = (int) self::occupies_capacity( $status ) - (int) self::occupies_capacity( $old_status );
					$settings   = Form::fromArray( $form )->settings();
					if ( $delta > 0 && $settings['capacity'] > 0 && (int) $form['entries_count'] >= $settings['capacity'] ) {
						throw new \InvalidArgumentException( __( 'No hay cupo para confirmar esta inscripción.', 'toolsbox-registros' ) );
					}
					if ( ( $delta > 0 || 'cancelled' === $old_status ) && 'cancelled' !== $status && ( ( 'email' === $settings['prevent_duplicates'] && ! empty( $row['email'] ) && $this->other_duplicate( (int) $entry['form_id'], $id, 'email', $row['email'] ) ) || ( 'document' === $settings['prevent_duplicates'] && ! empty( $row['document'] ) && $this->other_duplicate( (int) $entry['form_id'], $id, 'document', $row['document'] ) ) ) ) {
						throw new \InvalidArgumentException( __( 'Ya existe otra inscripción con estos datos.', 'toolsbox-registros' ) );
					}
					$this->execute( 'UPDATE ' . $this->table( 'entries' ) . ' SET status = %s, updated_at = %s WHERE id = %d', array( $status, current_time( 'mysql', true ), $id ) );
					if ( 0 !== $delta ) {
						( new FormRepository( $this->db ) )->incrementCount( (int) $entry['form_id'], $delta );
					}
					return true;
				}
			);
		} catch ( \RuntimeException | \InvalidArgumentException $exception ) {
			return $this->error( $exception );
		}
		if ( $changed ) {
			do_action( 'tbreg_entry_status_changed', $id, $status, $old_status, (int) $entry['form_id'] );
		}
		return $changed;
	}

	/**
	 * Busca duplicados excluyendo la inscripción actual.
	 *
	 * @param int    $form_id Formulario.
	 * @param int    $id Inscripción excluida.
	 * @param string $column Columna interna email o document.
	 * @param string $value Valor de búsqueda.
	 * @return bool
	 */
	private function other_duplicate( int $form_id, int $id, string $column, string $value ): bool {
		return null !== $this->scalar( 'SELECT id FROM ' . $this->table( 'entries' ) . " WHERE form_id = %d AND id <> %d AND {$column} = %s AND status <> %s LIMIT 1", array( $form_id, $id, $value, 'cancelled' ) );
	}

	/**
	 * Elimina el registro y sus valores de manera atómica.
	 *
	 * @param int $id Inscripción.
	 * @return bool|\WP_Error
	 */
	public function delete( int $id ): bool|\WP_Error {
		$entry = $this->find( $id );
		if ( null === $entry ) {
			return false;
		}
		try {
			return $this->transaction(
				function () use ( $id, $entry ): bool {
					$this->row( 'SELECT id FROM ' . $this->table( 'forms' ) . ' WHERE id = %d FOR UPDATE', array( (int) $entry['form_id'] ) );
					$current = $this->row( 'SELECT * FROM ' . $this->table( 'entries' ) . ' WHERE id = %d FOR UPDATE', array( $id ) );
					if ( null === $current ) {
						return false;
					}
					$this->execute( 'DELETE FROM ' . $this->table( 'entry_values' ) . ' WHERE entry_id = %d', array( $id ) );
					$this->execute( 'DELETE FROM ' . $this->table( 'entries' ) . ' WHERE id = %d', array( $id ) );
					if ( self::occupies_capacity( $current['status'] ) ) {
						( new FormRepository( $this->db ) )->incrementCount( (int) $entry['form_id'], -1 );
					}
					return true;
				}
			);
		} catch ( \RuntimeException $exception ) {
			return $this->error( $exception );
		}
	}

	/**
	 * Recorre inscripciones por bloques con sus valores agrupados.
	 *
	 * @param int                 $form_id Formulario a exportar.
	 * @param array<string,mixed> $filters Filtros de exportación.
	 * @param int                 $chunk Tamaño de cada bloque.
	 * @return \Generator<array<string,mixed>>
	 */
	public function iterateForExport( int $form_id, array $filters = array(), int $chunk = 500 ): \Generator {
		$filters['form_id']     = $form_id;
		list( $where, $values ) = $this->where( $filters );
		$ceiling                = (int) $this->scalar( 'SELECT MAX(e.id) FROM ' . $this->table( 'entries' ) . ' e WHERE ' . $where, $values );
		$form                   = ( new FormRepository( $this->db ) )->find( $form_id );
		$chunk                  = min( 5000, max( 1, $chunk ) );
		$last                   = 0;
		do {
			$rows = $this->rows( 'SELECT e.* FROM ' . $this->table( 'entries' ) . ' e WHERE ' . $where . ' AND e.id > %d AND e.id <= %d ORDER BY e.id ASC LIMIT %d', array_merge( $values, array( $last, $ceiling, $chunk ) ) );
			if ( array() === $rows ) {
				break;
			}
			$ids          = array_map( 'intval', array_column( $rows, 'id' ) );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$raw_values   = $this->rows( 'SELECT entry_id, field_key, value FROM ' . $this->table( 'entry_values' ) . " WHERE entry_id IN ({$placeholders}) ORDER BY entry_id, id", $ids );
			$grouped      = array();
			foreach ( $raw_values as $value ) {
				$grouped[ (int) $value['entry_id'] ][] = $value;
			}
			foreach ( $rows as $row ) {
				$last          = (int) $row['id'];
				$row['values'] = $this->value_map( $grouped[ $last ] ?? array(), $form );
				yield $row;
			}
			$count = count( $rows );
		} while ( $count === $chunk );
	}
}
