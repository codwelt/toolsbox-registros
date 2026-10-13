<?php
/**
 * Persistencia de formularios y conservación de su esquema histórico.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);

namespace Codwelt\ToolsBoxRegistros\Repository;

use Codwelt\ToolsBoxRegistros\Domain\Field;
use Codwelt\ToolsBoxRegistros\Domain\Form;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Almacena formularios sin permitir que una edición altere sus contadores.
 */
final class FormRepository extends Repository {
	/**
	 * Busca un formulario por su identificador.
	 *
	 * @param int $id Identificador del formulario.
	 * @return Form|null
	 */
	public function find( int $id ): ?Form {
		$row = $this->row( 'SELECT * FROM ' . $this->table( 'forms' ) . ' WHERE id = %d', array( $id ) );
		return null === $row ? null : Form::fromArray( $row );
	}

	/**
	 * Busca un formulario por su slug.
	 *
	 * @param string $slug Slug del formulario.
	 * @return Form|null
	 */
	public function findBySlug( string $slug ): ?Form {
		$row = $this->row( 'SELECT * FROM ' . $this->table( 'forms' ) . ' WHERE slug = %s', array( sanitize_title( $slug ) ) );
		return null === $row ? null : Form::fromArray( $row );
	}

	/**
	 * Lista formularios con filtros y paginación.
	 *
	 * @param array<string,mixed> $args Estado, búsqueda, orden y paginación.
	 * @return array<Form>
	 */
	public function all( array $args = array() ): array {
		$where  = array( 'status <> %s' );
		$values = array( 'trash' );
		if ( isset( $args['status'] ) ) {
			$where  = array( 'status = %s' );
			$values = array( (string) $args['status'] );
		}
		if ( ! empty( $args['search'] ) ) {
			$where[]  = '(title LIKE %s OR slug LIKE %s)';
			$search   = '%' . $this->db->esc_like( (string) $args['search'] ) . '%';
			$values[] = $search;
			$values[] = $search;
		}
		$order    = in_array( $args['orderby'] ?? '', array( 'id', 'title', 'created_at', 'updated_at' ), true ) ? $args['orderby'] : 'id';
		$dir      = 'ASC' === strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) ? 'ASC' : 'DESC';
		$values[] = min( 200, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
		$values[] = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$rows     = $this->rows( 'SELECT * FROM ' . $this->table( 'forms' ) . ' WHERE ' . implode( ' AND ', $where ) . " ORDER BY {$order} {$dir} LIMIT %d OFFSET %d", $values );
		return array_map( array( Form::class, 'fromArray' ), $rows );
	}

	/**
	 * Guarda el formulario conservando sus datos históricos.
	 *
	 * @param Form        $form Formulario validado.
	 * @param string|null $revision Revisión que estaba abierta en el editor.
	 * @return Form|\WP_Error
	 */
	public function save( Form $form, ?string $revision = null ): Form|\WP_Error {
		try {
			$saved = $this->transaction(
				function () use ( $form, $revision ): Form {
					$data = $form->toArray();
					$old  = null;
					if ( $form->id() > 0 ) {
						$row = $this->row( 'SELECT * FROM ' . $this->table( 'forms' ) . ' WHERE id = %d FOR UPDATE', array( $form->id() ) );
						if ( null === $row ) {
							throw new \InvalidArgumentException( __( 'El formulario ya no existe.', 'toolsbox-registros' ) );
						}
						$old = Form::fromArray( $row );
						if ( null !== $revision && ! hash_equals( $old->revision(), $revision ) ) {
							throw new \InvalidArgumentException( __( 'Otro usuario modificó el formulario. Recarga la página antes de guardar para revisar los cambios.', 'toolsbox-registros' ) );
						}
						$data['fields'] = $this->preserve_history( $old, $form );
					}
					$data['slug'] = $this->unique_slug( $data['slug'], $form->id() );
					$values       = array( $data['title'], $data['slug'], $data['description'], $data['status'], wp_json_encode( $data['fields'], JSON_UNESCAPED_UNICODE ), wp_json_encode( $data['settings'], JSON_UNESCAPED_UNICODE ), current_time( 'mysql', true ) );
					if ( null !== $old ) {
						$values[] = $form->id();
						$this->execute( 'UPDATE ' . $this->table( 'forms' ) . ' SET title = %s, slug = %s, description = %s, status = %s, fields = %s, settings = %s, updated_at = %s WHERE id = %d', $values );
						return $this->find( $form->id() );
					}
					$id = $this->insert(
						'forms',
						array(
							'title'         => $values[0],
							'slug'          => $values[1],
							'description'   => $values[2],
							'status'        => $values[3],
							'fields'        => $values[4],
							'settings'      => $values[5],
							'updated_at'    => $values[6],
							'created_at'    => $values[6],
							'created_by'    => get_current_user_id(),
							'entries_count' => 0,
						)
					);
					return $this->find( $id );
				}
			);
		} catch ( \RuntimeException | \InvalidArgumentException $exception ) {
			return $this->error( $exception );
		}
		do_action( 'tbreg_form_saved', $saved );
		return $saved;
	}

	/**
	 * Genera un slug disponible sin modificar otros formularios.
	 *
	 * @param string $slug Slug solicitado.
	 * @param int    $id Identificador excluido.
	 * @return string
	 */
	private function unique_slug( string $slug, int $id ): string {
		$base      = '' === $slug ? 'registro' : $slug;
		$candidate = $base;
		$counter   = 2;
		while ( null !== $this->scalar( 'SELECT id FROM ' . $this->table( 'forms' ) . ' WHERE slug = %s AND id <> %d', array( $candidate, $id ) ) ) {
			$candidate = utf8_uri_encode( rawurldecode( $base ), 180, true ) . '-' . $counter;
			++$counter;
		}
		return $candidate;
	}

	/**
	 * Archiva los campos retirados que tienen valores guardados.
	 *
	 * @param Form $old Formulario almacenado y bloqueado.
	 * @param Form $updated Esquema solicitado.
	 * @return array<array<string,mixed>>
	 * @throws \InvalidArgumentException Si se cambia el tipo de un campo con datos.
	 */
	private function preserve_history( Form $old, Form $updated ): array {
		$fields = array();
		foreach ( $updated->fields() as $field ) {
			$fields[ $field->key() ] = $field->toArray();
		}
		$used = $this->rows( 'SELECT DISTINCT v.field_key FROM ' . $this->table( 'entry_values' ) . ' v INNER JOIN ' . $this->table( 'entries' ) . ' e ON e.id = v.entry_id WHERE e.form_id = %d', array( $old->id() ) );
		$keys = array_column( $used, 'field_key' );
		foreach ( $old->fields() as $field ) {
			$key = $field->key();
			if ( ! in_array( $key, $keys, true ) && ! $field->get( 'archived' ) ) {
				continue;
			}
			if ( ! isset( $fields[ $key ] ) ) {
				$fields[ $key ]             = $field->toArray();
				$fields[ $key ]['archived'] = true;
			} elseif ( $fields[ $key ]['type'] !== $field->get( 'type' ) ) {
				throw new \InvalidArgumentException( __( 'No puedes cambiar el tipo de un campo que ya tiene datos.', 'toolsbox-registros' ) );
			} elseif ( $field->get( 'archived' ) ) {
				$fields[ $key ]['archived'] = true;
			}
		}
		// Revalida los roles después de archivar campos históricos.
		return Form::fromArray( array_merge( $updated->toArray(), array( 'fields' => array_values( $fields ) ) ) )->toArray()['fields'];
	}

	/**
	 * Copia el esquema como borrador, sin copiar inscripciones.
	 *
	 * @param int $id Identificador original.
	 * @return Form|\WP_Error
	 */
	public function duplicate( int $id ): Form|\WP_Error {
		$form = $this->find( $id );
		if ( null === $form ) {
			return new \WP_Error( 'tbreg_not_found', __( 'El formulario no existe.', 'toolsbox-registros' ) );
		}
		$data           = $form->toArray();
		$data['id']     = 0;
		$data['title']  = mb_substr( $data['title'], 0, 190 ) . __( ' (copia)', 'toolsbox-registros' );
		$data['slug']   = utf8_uri_encode( rawurldecode( $data['slug'] ), 180, true ) . '-copia';
		$data['status'] = 'draft';
		return $this->save( Form::fromArray( $data ) );
	}

	/**
	 * Envía el formulario a la papelera sin borrar sus datos.
	 *
	 * @param int $id Identificador del formulario.
	 * @return bool
	 */
	public function trash( int $id ): bool {
		return $this->execute( 'UPDATE ' . $this->table( 'forms' ) . ' SET status = %s, updated_at = %s WHERE id = %d', array( 'trash', current_time( 'mysql', true ), $id ) ) > 0;
	}

	/**
	 * Elimina el registro y sus valores de manera atómica.
	 *
	 * @param int $id Identificador del formulario.
	 * @return bool|\WP_Error
	 */
	public function delete( int $id ): bool|\WP_Error {
		try {
			return $this->transaction(
				function () use ( $id ): bool {
					if ( null === $this->row( 'SELECT id FROM ' . $this->table( 'forms' ) . ' WHERE id = %d FOR UPDATE', array( $id ) ) ) {
						return false;
					}
					$this->execute( 'DELETE v FROM ' . $this->table( 'entry_values' ) . ' v INNER JOIN ' . $this->table( 'entries' ) . ' e ON e.id = v.entry_id WHERE e.form_id = %d', array( $id ) );
					$this->execute( 'DELETE FROM ' . $this->table( 'entries' ) . ' WHERE form_id = %d', array( $id ) );
					return $this->execute( 'DELETE FROM ' . $this->table( 'forms' ) . ' WHERE id = %d', array( $id ) ) > 0;
				}
			);
		} catch ( \RuntimeException $exception ) {
			return $this->error( $exception );
		}
	}

	/**
	 * Actualiza el contador de cupos sin permitir valores negativos.
	 *
	 * @param int $id Identificador del formulario.
	 * @param int $amount Incremento, negativo para liberar cupos.
	 * @return bool
	 */
	public function incrementCount( int $id, int $amount = 1 ): bool {
		return $this->execute( 'UPDATE ' . $this->table( 'forms' ) . ' SET entries_count = GREATEST(0, CAST(entries_count AS SIGNED) + %d), updated_at = %s WHERE id = %d', array( $amount, current_time( 'mysql', true ), $id ) ) > 0;
	}

	/**
	 * Opciones ligeras de todos los formularios, incluida la papelera histórica.
	 *
	 * @return array<array<string,mixed>>
	 */
	public function summaries(): array {
		return $this->rows( 'SELECT id, title, status FROM ' . $this->table( 'forms' ) . ' ORDER BY title ASC' );
	}
	/**
	 * Carga esquemas en una sola consulta para una página de inscripciones.
	 *
	 * @param array<int> $ids Formularios de la página.
	 * @return array<int,Form>
	 */
	public function findMany( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn ( int $id ): bool => $id > 0 ) ) );
		if ( array() === $ids ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$forms        = array();
		foreach ( $this->rows( 'SELECT * FROM ' . $this->table( 'forms' ) . " WHERE id IN ({$placeholders})", $ids ) as $row ) {
			$forms[ (int) $row['id'] ] = Form::fromArray( $row );
		}
		return $forms;
	}

	/**
	 * Cuenta formularios para la paginación del administrador.
	 *
	 * @param array<string,mixed> $args Filtros de estado y búsqueda.
	 * @return int
	 */
	public function count( array $args = array() ): int {
		$sql    = 'SELECT COUNT(*) FROM ' . $this->table( 'forms' ) . ' WHERE status ' . ( isset( $args['status'] ) ? '= %s' : '<> %s' );
		$values = array( $args['status'] ?? 'trash' );
		if ( ! empty( $args['search'] ) ) {
			$search   = '%' . $this->db->esc_like( (string) $args['search'] ) . '%';
			$sql     .= ' AND (title LIKE %s OR slug LIKE %s)';
			$values[] = $search;
			$values[] = $search;
		}
		return (int) $this->scalar( $sql, $values );
	}

	/**
	 * Devuelve los identificadores de campos con datos históricos.
	 *
	 * @param int $id Formulario.
	 * @return array<string>
	 */
	public function usedFieldKeys( int $id ): array {
		return array_column( $this->rows( 'SELECT DISTINCT v.field_key FROM ' . $this->table( 'entry_values' ) . ' v INNER JOIN ' . $this->table( 'entries' ) . ' e ON e.id = v.entry_id WHERE e.form_id = %d', array( $id ) ), 'field_key' );
	}
}
