<?php
/**
 * Campo de fotografía privada.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Domain\FieldTypes;

use Codwelt\ToolsBoxRegistros\Domain\Field;
use Codwelt\ToolsBoxRegistros\Services\Photo;
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
/** Implementación del servicio propio del plugin. */
final class PhotoField extends AbstractFieldType {
	public const TYPE = 'photo';
	/**
	 * Decodifica y recomprime la imagen, suprimiendo metadatos.
	 *
	 * @param mixed $raw Entrada.
	 * @param Field $field Campo.
	 * @return mixed
	 */
	public function sanitize( mixed $raw, Field $field ): mixed {
		return '' === $raw ? '' : Photo::normalize( $raw ); }
	/**
	 * Rechaza archivos inválidos y comprueba obligatoriedad.
	 *
	 * @param mixed $value Imagen saneada.
	 * @param Field $field Campo.
	 * @return \WP_Error|null
	 */
	public function validate( mixed $value, Field $field ): ?\WP_Error {
		if ( is_wp_error( $value ) ) {
			return $value; }
		return $field->get( 'required' ) && '' === $value ? new \WP_Error( 'tbreg_required', __( 'Adjunta tu fotografía.', 'toolsbox-registros' ) ) : null;
	}
	/**
	 * No expone la imagen ni una URL en exportaciones.
	 *
	 * @param mixed $stored Valor.
	 * @param Field $field Campo.
	 * @return string
	 */
	public function toExport( mixed $stored, Field $field ): string {
		return '' !== (string) $stored ? __( 'Foto adjunta', 'toolsbox-registros' ) : ''; }
}
