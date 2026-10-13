<?php
/**
 * Catálogo extensible de tipos de campo.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Domain;

use Codwelt\ToolsBoxRegistros\Domain\FieldTypes\FieldTypeInterface;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Resuelve los tipos nativos y permite sustituir sus implementaciones. */
final class FieldTypeRegistry {
	/**
	 * Busca una implementación válida.
	 *
	 * @param string $type Identificador del tipo.
	 * @return FieldTypeInterface
	 * @throws \InvalidArgumentException Si el tipo no está registrado.
	 */
	public function get( string $type ): FieldTypeInterface {
		$types = array();
		foreach ( Field::TYPES as $name ) {
			$class          = __NAMESPACE__ . '\\FieldTypes\\' . ucfirst( $name ) . 'Field';
			$types[ $name ] = new $class();
		}
		$types = apply_filters( 'tbreg_field_types', $types );
		if ( ! is_array( $types ) || ! isset( $types[ $type ] ) || ! $types[ $type ] instanceof FieldTypeInterface || $type !== $types[ $type ]->type() ) {
			throw new \InvalidArgumentException( __( 'El tipo de campo no está disponible.', 'toolsbox-registros' ) );
		}
		return $types[ $type ];
	}
}
