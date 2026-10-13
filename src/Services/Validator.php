<?php
/**
 * Validación de valores publicados contra el esquema actual.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Services;

use Codwelt\ToolsBoxRegistros\Domain\Form;
use Codwelt\ToolsBoxRegistros\Domain\FieldTypeRegistry;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Ignora campos desconocidos y archivados; no confía en la validación HTML. */
final class Validator {
	/**
	 * Devuelve valores saneados o errores indexados por clave.
	 *
	 * @param Form                $form Esquema actual.
	 * @param array<string,mixed> $raw Valores publicados.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function validate( Form $form, array $raw ): array|\WP_Error {
		$values   = array();
		$errors   = array();
		$registry = new FieldTypeRegistry();
		foreach ( $form->fields() as $field ) {
			if ( ! $field->storesValue() ) {
				continue;
			}
			$key   = $field->key();
			$value = $raw[ $key ] ?? '';
			if ( 'hidden' === $field->get( 'type' ) && '' !== $field->get( 'default' ) && '' === $field->get( 'query_param' ) ) {
				$value = $field->get( 'default' );
			}
			if ( ! is_scalar( $value ) && ! ( 'checkbox' === $field->get( 'type' ) && is_array( $value ) && array_values( $value ) === $value && count( $value ) <= count( $field->get( 'options' ) ) ) ) {
				$errors[ $key ] = __( 'El valor de este campo no es válido.', 'toolsbox-registros' );
				continue;
			}
			if ( is_array( $value ) && count( array_filter( $value, 'is_scalar' ) ) !== count( $value ) ) {
				$errors[ $key ] = __( 'Selecciona opciones válidas.', 'toolsbox-registros' );
				continue;
			}
			$type  = $registry->get( $field->get( 'type' ) );
			$value = $type->sanitize( $value, $field );
			$error = $type->validate( $value, $field );
			if ( null !== $error ) {
				$errors[ $key ] = $error->get_error_message();
			} else {
				$values[ $key ] = $value;
			}
		}
		if ( array() !== $errors ) {
			return new \WP_Error(
				'tbreg_validation',
				__( 'Revisa los campos indicados.', 'toolsbox-registros' ),
				array(
					'status' => 422,
					'fields' => $errors,
				)
			);
		}
		$result = apply_filters( 'tbreg_validate_submission', $values, $form, $raw );
		return is_array( $result ) || is_wp_error( $result ) ? $result : new \WP_Error( 'tbreg_validation', __( 'No se pudo validar el formulario.', 'toolsbox-registros' ), array( 'status' => 422 ) );
	}
	/**
	 * Valida solo cambios administrativos y conserva el historial sin modificar.
	 *
	 * @param Form                $form Esquema bloqueado.
	 * @param array<string,mixed> $raw Valores administrativos.
	 * @param array<string,mixed> $stored Valores anteriores.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function validate_edit( Form $form, array $raw, array $stored ): array|\WP_Error {
		$changed  = array();
		$errors   = array();
		$registry = new FieldTypeRegistry();
		foreach ( $form->fields() as $field ) {
			if ( ! $field->storesValue() || in_array( $field->get( 'type' ), array( 'hidden', 'consent' ), true ) ) {
				continue;
			}
			$key = $field->key();
			if ( ! array_key_exists( $key, $raw ) ) {
				continue;
			}
			$value = $raw[ $key ] ?? array();
			if ( ! is_scalar( $value ) && ! ( 'checkbox' === $field->get( 'type' ) && is_array( $value ) && array_values( $value ) === $value && count( $value ) <= 500 && count( array_filter( $value, 'is_scalar' ) ) === count( $value ) ) ) {
				$errors[ $key ] = __( 'El valor de este campo no es válido.', 'toolsbox-registros' );
				continue;
			}
			$type   = $registry->get( $field->get( 'type' ) );
			$value  = $type->sanitize( $value, $field );
			$before = $stored[ $key ] ?? ( 'checkbox' === $field->get( 'type' ) ? array() : '' );
			$same   = $value === $before;
			if ( is_array( $value ) && is_array( $before ) ) {
				$sorted = $value;
				sort( $sorted );
				sort( $before );
				$same = $sorted === $before;
			}
			if ( $same ) {
				continue;
			}
			$error = $type->validate( $value, $field );
			if ( null !== $error ) {
				$errors[ $key ] = $error->get_error_message();
			} else {
				$changed[ $key ] = $value;
			}
		}
		return array() === $errors ? $changed : new \WP_Error( 'tbreg_validation', __( 'Revisa los campos indicados.', 'toolsbox-registros' ), array( 'fields' => $errors ) );
	}
}
