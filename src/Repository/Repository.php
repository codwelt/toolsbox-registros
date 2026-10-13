<?php
/**
 * Acceso común a consultas preparadas y transacciones.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);

namespace Codwelt\ToolsBoxRegistros\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centraliza los identificadores internos y los valores enlazados.
 */
abstract class Repository {
	/**
	 * Conexión del sitio.
	 *
	 * @var \wpdb
	 */
	protected \wpdb $db;

	/**
	 * Inicializa los datos de la instancia.
	 *
	 * @param \wpdb|null $db Conexión opcional para pruebas o sitios específicos.
	 */
	public function __construct( ?\wpdb $db = null ) {
		global $wpdb;
		$this->db = $db ?? $wpdb;
	}

	/**
	 * Construye un identificador de tabla a partir del prefijo interno.
	 *
	 * @param string $suffix Sufijo interno de tabla.
	 * @return string
	 */
	protected function table( string $suffix ): string {
		return '`' . str_replace( '`', '``', $this->db->prefix . 'tbreg_' . $suffix ) . '`';
	}

	/**
	 * Prepara los valores. Los identificadores provienen únicamente de table().
	 *
	 * @param string       $sql Consulta interna, nunca recibida del usuario.
	 * @param array<mixed> $args Valores enlazados.
	 * @return string
	 */
	private function prepared( string $sql, array $args ): string {
		// prepare() sin placeholders emite warnings; los comandos estáticos no llevan valores.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Plantillas internas; todos los valores variables están en $args.
		return array() === $args ? $sql : $this->db->prepare( $sql, $args );
	}

	/**
	 * Ejecuta una escritura y comprueba el resultado.
	 *
	 * @param string       $sql Consulta interna.
	 * @param array<mixed> $args Valores enlazados.
	 * @return int
	 * @throws \RuntimeException Si la consulta falla.
	 */
	protected function execute( string $sql, array $args = array() ): int {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared() centraliza los parámetros y los identificadores internos.
		$result = $this->db->query( $this->prepared( $sql, $args ) );
		if ( false === $result ) {
			throw new \RuntimeException( 'tbreg_db_error' );
		}
		return (int) $result;
	}

	/**
	 * Lee filas con valores enlazados y comprueba errores.
	 *
	 * @param string       $sql Consulta interna.
	 * @param array<mixed> $args Valores enlazados.
	 * @return array<array<string,mixed>>
	 * @throws \RuntimeException Si la consulta falla.
	 */
	protected function rows( string $sql, array $args = array() ): array {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared() enlaza los valores; los identificadores no proceden de entradas.
		$result = $this->db->get_results( $this->prepared( $sql, $args ), ARRAY_A );
		if ( '' !== $this->db->last_error ) {
			throw new \RuntimeException( 'tbreg_db_error' );
		}
		return $result ?? array();
	}

	/**
	 * Lee una fila de una consulta interna.
	 *
	 * @param string       $sql Consulta interna de una sola fila.
	 * @param array<mixed> $args Valores enlazados.
	 * @return array<string,mixed>|null
	 */
	protected function row( string $sql, array $args = array() ): ?array {
		return $this->rows( $sql, $args )[0] ?? null;
	}

	/**
	 * Lee el primer valor de una consulta interna.
	 *
	 * @param string       $sql Consulta interna de una sola columna.
	 * @param array<mixed> $args Valores enlazados.
	 * @return mixed
	 */
	protected function scalar( string $sql, array $args = array() ): mixed {
		$row = $this->row( $sql, $args );
		return null === $row ? null : reset( $row );
	}

	/**
	 * Inserta valores usando el mecanismo de preparación de wpdb.
	 *
	 * @param string              $suffix Tabla interna.
	 * @param array<string,mixed> $data Columnas internas y valores.
	 * @return int
	 * @throws \RuntimeException Si la inserción falla.
	 */
	protected function insert( string $suffix, array $data ): int {
		$formats = array_map( static fn ( mixed $value ): string => is_int( $value ) ? '%d' : '%s', $data );
		// wpdb::insert() prepara internamente los valores y trata null como SQL NULL.
		if ( false === $this->db->insert( $this->db->prefix . 'tbreg_' . $suffix, $data, $formats ) ) {
			throw new \RuntimeException( 'tbreg_db_error' );
		}
		return (int) $this->db->insert_id;
	}

	/**
	 * Confirma o revierte la operación completa.
	 *
	 * @param callable $operation Operación atómica sobre las tablas InnoDB.
	 * @return mixed
	 * @throws \Throwable Si la operación falla, después de revertirla.
	 */
	protected function transaction( callable $operation ): mixed {
		$this->execute( 'START TRANSACTION' );
		try {
			$result = $operation();
			$this->execute( 'COMMIT' );
			return $result;
		} catch ( \Throwable $exception ) {
			$this->execute( 'ROLLBACK' );
			throw $exception;
		}
	}

	/**
	 * Convierte los errores internos en mensajes públicos seguros.
	 *
	 * @param \Throwable $exception Error interno o de dominio.
	 * @return \WP_Error
	 */
	protected function error( \Throwable $exception ): \WP_Error {
		if ( $exception instanceof \InvalidArgumentException ) {
			return new \WP_Error( 'tbreg_invalid_data', $exception->getMessage() );
		}
		return new \WP_Error( 'tbreg_db_error', __( 'No se pudo guardar el registro. Inténtalo de nuevo.', 'toolsbox-registros' ) );
	}
}
