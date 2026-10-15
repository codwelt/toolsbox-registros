<?php
/**
 * Relaciones de opciones compartidas por esquema y validación.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }
/** Implementación del servicio propio del plugin. */
final class ConditionalOptions {
	/**
	 * Verifica referencias y ciclos sin depender del orden visual.
	 *
	 * @param array<Field> $fields Campos.
	 * @return void
	 * @throws \InvalidArgumentException Si hay una dependencia inválida.
	 */
	public static function schema( array $fields ): void {
		$map   = array();
		$edges = array();
		foreach ( $fields as $field ) {
			$map[ $field->key() ] = $field; }
		foreach ( $fields as $field ) {
			if ( $field->get( 'archived' ) ) {
				continue; }
			foreach ( $field->get( 'options' ) as $option ) {
				foreach ( $option['when'] ?? array() as $key => $values ) {
					$parent = $map[ $key ] ?? null;
					if ( null === $parent || $parent->get( 'archived' ) || ! in_array( $parent->get( 'type' ), array( 'select', 'radio' ), true ) || array() !== array_diff( $values, array_column( $parent->get( 'options' ), 'value' ) ) ) {
						throw new \InvalidArgumentException( __( 'Una condición usa un campo o una opción que ya no está disponible.', 'toolsbox-registros' ) );
					}
					$edges[ $field->key() ][] = $key;
				}
			}
		}
		$visited  = array();
		$visiting = array();
		$visit    = static function ( string $key ) use ( &$visit, &$visited, &$visiting, $edges ): void {
			if ( isset( $visiting[ $key ] ) ) {
				throw new \InvalidArgumentException( __( 'Las condiciones no pueden depender de sí mismas ni formar un ciclo.', 'toolsbox-registros' ) ); }
			if ( isset( $visited[ $key ] ) ) {
				return; }
			$visiting[ $key ] = true;
			foreach ( $edges[ $key ] ?? array() as $parent ) {
				$visit( $parent ); }
			unset( $visiting[ $key ] );
			$visited[ $key ] = true;
		};
		foreach ( array_keys( $edges ) as $key ) {
			$visit( $key ); }
	}
	/**
	 * Comprueba AND entre campos y OR entre valores.
	 *
	 * @param array<string,array<string>> $when Condiciones.
	 * @param array<string,mixed>         $values Respuestas.
	 * @return bool
	 */
	public static function matches( array $when, array $values ): bool {
		foreach ( $when as $key => $allowed ) {
			if ( ! is_scalar( $values[ $key ] ?? null ) || ! in_array( (string) $values[ $key ], $allowed, true ) ) {
				return false; }
		}
		return true;
	}
	/**
	 * Revalida relaciones; en edición solo cambios y sus descendientes.
	 *
	 * @param Form                $form Formulario.
	 * @param array<string,mixed> $values Valores finales.
	 * @param array<string>|null  $changed Claves modificadas, null para envío público.
	 * @return array<string,string>
	 */
	public static function errors( Form $form, array $values, ?array $changed = null ): array {
		$affected = null === $changed ? null : array_fill_keys( $changed, true );
		if ( null !== $affected ) {
			do {
				$before = count( $affected );
				foreach ( $form->fields() as $field ) {
					foreach ( $field->get( 'options' ) as $option ) {
						if ( array() !== array_intersect( array_keys( $option['when'] ?? array() ), array_keys( $affected ) ) ) {
							$affected[ $field->key() ] = true; }
					}
				}
				$after = count( $affected );
			} while ( $after !== $before );
		}
		$errors = array();
		foreach ( $form->fields() as $field ) {
			if ( ! $field->storesValue() || ( null !== $affected && ! isset( $affected[ $field->key() ] ) ) ) {
				continue; }
			$selected = $values[ $field->key() ] ?? '';
			foreach ( $field->get( 'options' ) as $option ) {
				if ( in_array( $option['value'], is_array( $selected ) ? $selected : array( $selected ), true ) && ! self::matches( $option['when'] ?? array(), $values ) ) {
					$errors[ $field->key() ] = __( 'Esta opción no está disponible para las selecciones anteriores. Elige otra.', 'toolsbox-registros' );
				}
			}
		}
		return $errors;
	}
}
