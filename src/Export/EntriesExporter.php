<?php
/**
 * Exportación por formulario con columnas históricas y lotes acotados.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Export;

use Codwelt\ToolsBoxRegistros\Domain\Form;
use Codwelt\ToolsBoxRegistros\Domain\FieldTypeRegistry;
use Codwelt\ToolsBoxRegistros\Admin\FormsListTable;
use Codwelt\ToolsBoxRegistros\Repository\EntryRepository;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Genera el archivo entero antes de emitir cabeceras de descarga. */
final class EntriesExporter {
	/**
	 * Fuente de filas.
	 *
	 * @var EntryRepository
	 */
	private EntryRepository $entries;
	/**
	 * Inicializa la fuente.
	 *
	 * @param EntryRepository|null $entries Repositorio opcional.
	 */
	public function __construct( ?EntryRepository $entries = null ) {
		$this->entries = $entries ?? new EntryRepository();
	}
	/**
	 * Define columnas ordenadas y extensibles.
	 *
	 * @param Form $form Esquema de la exportación.
	 * @return array<string,array<string,string>>
	 * @throws \InvalidArgumentException Si el filtro devuelve columnas incompatibles.
	 */
	public function columns( Form $form ): array {
		$columns = array(
			'id'         => array(
				'label' => __( 'ID', 'toolsbox-registros' ),
				'type'  => 'number',
			),
			'created_at' => array(
				'label' => __( 'Fecha de inscripción', 'toolsbox-registros' ),
				'type'  => 'date',
			),
			'status'     => array(
				'label' => __( 'Estado', 'toolsbox-registros' ),
				'type'  => 'text',
			),
		);
		foreach ( $form->fields() as $field ) {
			if ( 'heading' === $field->get( 'type' ) ) {
				continue;
			}
			$columns[ 'field_' . $field->key() ] = array(
				'label' => $field->get( 'label' ) . ( $field->get( 'archived' ) ? __( ' (eliminado)', 'toolsbox-registros' ) : '' ),
				'type'  => 'number' === $field->get( 'type' ) ? 'number' : 'text',
			);
		}
		$columns = apply_filters( 'tbreg_export_columns', $columns, $form );
		if ( ! is_array( $columns ) || array() === $columns || count( $columns ) > 16384 ) {
			throw new \InvalidArgumentException( 'tbreg_export_columns' );
		}
		foreach ( $columns as $key => $column ) {
			if ( ! is_string( $key ) || ! is_array( $column ) || ! is_string( $column['label'] ?? null ) || ! in_array( $column['type'] ?? '', array( 'text', 'number', 'date' ), true ) ) {
				throw new \InvalidArgumentException( 'tbreg_export_columns' );
			}
		}
		return $columns;
	}
	/**
	 * Reduce el lote según la cota de tamaño del esquema.
	 *
	 * @param Form $form Esquema.
	 * @return int
	 */
	private function chunk_size( Form $form ): int {
		$bytes = 1024;
		foreach ( $form->fields() as $field ) {
			if ( 'heading' === $field->get( 'type' ) ) {
				continue;
			}
			$limit = $field->get( 'validation' )['maxlength'] ?? 5000;
			if ( 'photo' === $field->get( 'type' ) ) {
				$bytes += 420000;
				continue; }
			$bytes += min( 32767, max( 32, (int) $limit ) ) * 4 + 512;
		}
		return min( 500, max( 1, intdiv( 4 * 1024 * 1024, $bytes * 3 ) ) );
	}
	/**
	 * Genera un temporal propio; el llamador autorizado debe enviarlo y borrarlo.
	 *
	 * @param Form                $form Formulario actual.
	 * @param string              $format xlsx/csv.
	 * @param array<string,mixed> $filters Filtros UTC.
	 * @param array<int>|null     $ids Selección opcional del mismo formulario.
	 * @param string              $delimiter Separador CSV.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException Si el formato no es válido.
	 * @throws \RuntimeException Si no está disponible XLSX.
	 * @throws \Throwable Si la generación falla, tras limpiar todos los temporales.
	 */
	public function build( Form $form, string $format, array $filters = array(), ?array $ids = null, string $delimiter = ';' ): array {
		if ( ! in_array( $format, array( 'xlsx', 'csv' ), true ) ) {
			throw new \InvalidArgumentException( 'tbreg_export_format' );
		}
		if ( 'xlsx' === $format && ! XlsxWriter::available() ) {
			throw new \RuntimeException( 'tbreg_zip_unavailable' );
		}
		$columns  = $this->columns( $form );
		$writer   = 'xlsx' === $format ? new XlsxWriter( $form->get( 'title' ), array_column( $columns, 'type' ) ) : new CsvWriter( $delimiter );
		$path     = XlsxWriter::temporary();
		$count    = 0;
		$registry = new FieldTypeRegistry();
		if ( null !== $ids ) {
			$filters['entry_ids'] = $ids;
		}
		try {
			$writer->open( $path );
			$writer->writeHeader( array_column( $columns, 'label' ) );
			foreach ( $this->entries->iterateForExport( $form->id(), $filters, $this->chunk_size( $form ), $form ) as $entry ) {
				$row = array(
					'id'         => $entry['id'],
					'created_at' => new \DateTimeImmutable( get_date_from_gmt( $entry['created_at'], 'Y-m-d H:i:s' ), wp_timezone() ),
					'status'     => FormsListTable::entry_statuses()[ $entry['status'] ] ?? $entry['status'],
				);
				foreach ( $form->fields() as $field ) {
					if ( 'heading' !== $field->get( 'type' ) ) {
						$row[ 'field_' . $field->key() ] = $registry->get( $field->get( 'type' ) )->toExport( $entry['values'][ $field->key() ] ?? '', $field );
					}
				}
				$row = apply_filters( 'tbreg_export_row', $row, $entry, $form );
				if ( ! is_array( $row ) ) {
					throw new \InvalidArgumentException( 'tbreg_export_row' );
				}
				$cells = array();
				foreach ( $columns as $key => $column ) {
					$cells[] = $row[ $key ] ?? '';
				}
				$writer->writeRow( $cells );
				++$count;
			}
			$writer->close();
		} catch ( \Throwable $exception ) {
			$writer->abort();
			wp_delete_file( $path );
			throw $exception;
		}
		return array(
			'path'     => $path,
			'filename' => sanitize_file_name( 'inscritos-' . $form->get( 'slug' ) . '-' . wp_date( 'Y-m-d_His' ) . '.' . $format ),
			'mime'     => 'xlsx' === $format ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=UTF-8',
			'rows'     => $count,
		);
	}
	/**
	 * Envía un archivo generado y lo borra incluso si se cancela la descarga.
	 *
	 * @param array<string,mixed> $file Descriptor interno.
	 * @return void
	 * @throws \RuntimeException Si la salida o el archivo no está disponible.
	 */
	public function send( array $file ): void {
		try {
			if ( headers_sent() ) {
				throw new \RuntimeException( 'tbreg_export_headers' );
			}
			// Una descarga no debe contener avisos o HTML retenidos por otro plugin.
			while ( ob_get_level() > 0 ) {
				$status = ob_get_status();
				if ( 0 === ( $status['flags'] & PHP_OUTPUT_HANDLER_REMOVABLE ) || ! ob_end_clean() ) {
					throw new \RuntimeException( 'tbreg_export_headers' );
				}
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Descarga local en streaming.
			$handle = fopen( $file['path'], 'rb' );
			if ( false === $handle ) {
				throw new \RuntimeException( 'tbreg_export_io' );
			}
			try {
				$stat = fstat( $handle );
				nocache_headers();
				header( 'Content-Type: ' . $file['mime'] );
				header( 'Content-Disposition: attachment; filename="' . $file['filename'] . '"; filename*=UTF-8\'\'' . rawurlencode( $file['filename'] ) );
				header( 'Content-Length: ' . $stat['size'] );
				header( 'X-Content-Type-Options: nosniff' );
				header( 'X-Robots-Tag: noindex, nofollow' );
				if ( false === fpassthru( $handle ) ) {
					throw new \RuntimeException( 'tbreg_export_io' );
				}
			} finally {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Recurso local de escritura o descarga incremental.
				fclose( $handle );
			}
		} finally {
			wp_delete_file( $file['path'] );
		}
	}
}
