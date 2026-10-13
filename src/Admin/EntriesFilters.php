<?php
/**
 * Filtros administrativos y conversión de días locales a UTC.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Admin;

use Codwelt\ToolsBoxRegistros\Repository\EntryRepository;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Comparte los límites con la futura pantalla de exportación. */
final class EntriesFilters {
	/**
	 * Valida exclusivamente parámetros de lectura conocidos.
	 *
	 * @param array<string,mixed> $input Parámetros sin slashes.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function parse( array $input ): array|\WP_Error {
		$result = array(
			'status'    => '',
			'search'    => '',
			'date_from' => '',
			'date_to'   => '',
			'orderby'   => 'id',
			'order'     => 'DESC',
		);
		foreach ( array( 'form_id', 'status', 's', 'date_from', 'date_to', 'orderby', 'order' ) as $key ) {
			if ( isset( $input[ $key ] ) && ! is_scalar( $input[ $key ] ) ) {
				return new \WP_Error( 'tbreg_invalid_filter', __( 'Los filtros contienen un valor no válido.', 'toolsbox-registros' ) );
			}
		}
		$form_id = filter_var( $input['form_id'] ?? '0', FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
		if ( false === $form_id ) {
			return new \WP_Error( 'tbreg_invalid_filter', __( 'El formulario seleccionado no es válido.', 'toolsbox-registros' ) );
		}
		if ( $form_id > 0 ) {
			$result['form_id'] = $form_id;
		}
		$status = (string) ( $input['status'] ?? '' );
		if ( '' !== $status && ! in_array( $status, EntryRepository::STATUSES, true ) ) {
			return new \WP_Error( 'tbreg_invalid_filter', __( 'El estado seleccionado no es válido.', 'toolsbox-registros' ) );
		}
		$result['status'] = $status;
		$result['search'] = mb_substr( sanitize_text_field( (string) ( $input['s'] ?? '' ) ), 0, 190 );
		foreach ( array( 'date_from', 'date_to' ) as $key ) {
			$day                       = (string) ( $input[ $key ] ?? '' );
			$result[ 'local_' . $key ] = $day;
			if ( '' === $day ) {
				continue;
			}
			$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $day, wp_timezone() );
			if ( false === $date || $date->format( 'Y-m-d' ) !== $day ) {
				return new \WP_Error( 'tbreg_invalid_filter', __( 'Selecciona fechas válidas para el intervalo.', 'toolsbox-registros' ) );
			}
			$result[ $key ] = get_gmt_from_date( $day . ( 'date_from' === $key ? ' 00:00:00' : ' 23:59:59' ) );
		}
		if ( '' !== $result['date_from'] && '' !== $result['date_to'] && $result['date_from'] > $result['date_to'] ) {
			return new \WP_Error( 'tbreg_invalid_filter', __( 'La fecha inicial no puede superar la final.', 'toolsbox-registros' ) );
		}
		if ( in_array( $input['orderby'] ?? '', array( 'id', 'created_at', 'email', 'status' ), true ) ) {
			$result['orderby'] = $input['orderby'];
		}
		$result['order'] = 'ASC' === strtoupper( (string) ( $input['order'] ?? '' ) ) ? 'ASC' : 'DESC';
		return $result;
	}
	/**
	 * Convierte una fecha guardada en UTC para mostrarla en el sitio.
	 *
	 * @param string $utc Fecha de BD.
	 * @return string
	 */
	public static function display_date( string $utc ): string {
		return get_date_from_gmt( $utc, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
	}
	/**
	 * URL de retorno construida solo con filtros propios.
	 *
	 * @param array<string,mixed> $filters Filtros validados.
	 * @return string
	 */
	public static function list_url( array $filters = array() ): string {
		$args = array( 'page' => 'toolsbox-registros-entries' );
		foreach ( array( 'form_id', 'status', 'orderby', 'order' ) as $key ) {
			if ( isset( $filters[ $key ] ) ) {
				$args[ $key ] = $filters[ $key ];
			}
		}
		$args['s'] = $filters['search'] ?? '';
		foreach ( array( 'date_from', 'date_to' ) as $key ) {
			$args[ $key ] = $filters[ 'local_' . $key ] ?? '';
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}
}
