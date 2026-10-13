<?php
/**
 * Contrato de tipos de campo.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Domain\FieldTypes;

use Codwelt\ToolsBoxRegistros\Domain\Field;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** API extensible de sanitización, validación, render y exportación. */
interface FieldTypeInterface {
	/**
	 * Identificador del tipo.
	 *
	 * @return string
	 */
	public function type(): string;
	/**
	 * Etiqueta traducida.
	 *
	 * @return string
	 */
	public function label(): string;
	/**
	 * Admite opciones.
	 *
	 * @return bool
	 */
	public function supportsOptions(): bool;
	/**
	 * Almacena datos.
	 *
	 * @return bool
	 */
	public function storesValue(): bool;
	/**
	 * Sanea un valor del tipo.
	 *
	 * @param mixed $raw Valor bruto con estructura comprobada.
	 * @param Field $field Esquema.
	 * @return mixed
	 */
	public function sanitize( mixed $raw, Field $field ): mixed;
	/**
	 * Valida un valor saneado.
	 *
	 * @param mixed $value Valor.
	 * @param Field $field Esquema.
	 * @return \WP_Error|null
	 */
	public function validate( mixed $value, Field $field ): ?\WP_Error;
	/**
	 * Genera HTML escapado desde una plantilla.
	 *
	 * @param Field               $field Esquema.
	 * @param mixed               $value Valor inicial.
	 * @param array<string,mixed> $context IDs y errores opcionales.
	 * @return string
	 */
	public function render( Field $field, mixed $value, array $context = array() ): string;
	/**
	 * Convierte el valor almacenado a texto legible.
	 *
	 * @param mixed $stored Valor almacenado.
	 * @param Field $field Esquema.
	 * @return string
	 */
	public function toExport( mixed $stored, Field $field ): string;
}
