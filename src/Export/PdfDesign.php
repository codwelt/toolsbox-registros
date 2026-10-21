<?php
/**
 * Diseño configurable del PDF de inscripción.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Export;

use Codwelt\ToolsBoxRegistros\Services\Photo;
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
/** Valida los ajustes de diseño y prepara colores y logo para el PDF. */
final class PdfDesign {
	public const TEMPLATES = array( 'receipt', 'enrollment' );
	/**
	 * Valores por defecto, compatibles con formularios anteriores.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'template'      => 'receipt',
			'primary_color' => '#123b5c',
			'accent_color'  => '#b3121f',
			'logo_id'       => 0,
			'title'         => '',
			'subtitle'      => '',
			'intro'         => '',
			'terms'         => '',
			'office_fields' => '',
			'signatures'    => __( "Nombre(s) y apellidos del estudiante\nNombre(s) y apellidos del acudiente", 'toolsbox-registros' ),
			'footer'        => '',
			'show_options'  => true,
			'photo_box'     => true,
		);
	}
	/**
	 * Normaliza el diseño guardado sin aceptar tipos inesperados.
	 *
	 * @param mixed $raw Diseño recibido.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException Si el diseño no es válido.
	 */
	public static function normalize( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			throw new \InvalidArgumentException( esc_html__( 'El diseño del PDF no es válido.', 'toolsbox-registros' ) );
		}
		$result = array();
		foreach ( self::defaults() as $name => $default ) {
			$value = $raw[ $name ] ?? $default;
			if ( is_bool( $default ) ) {
				if ( ! is_bool( $value ) ) {
					throw new \InvalidArgumentException( esc_html__( 'Las opciones del diseño del PDF deben ser booleanas.', 'toolsbox-registros' ) );
				}
			} elseif ( is_int( $default ) ) {
				if ( ! is_int( $value ) || $value < 0 ) {
					throw new \InvalidArgumentException( esc_html__( 'El logo del PDF no es válido.', 'toolsbox-registros' ) );
				}
			} else {
				if ( ! is_string( $value ) ) {
					throw new \InvalidArgumentException( esc_html__( 'Los textos del diseño del PDF deben ser cadenas.', 'toolsbox-registros' ) );
				}
				$value = str_ends_with( $name, '_color' ) ? (string) sanitize_hex_color( $value ) : sanitize_textarea_field( $value );
				if ( ( str_ends_with( $name, '_color' ) && 7 !== strlen( $value ) ) || ( 'template' === $name && ! in_array( $value, self::TEMPLATES, true ) ) ) {
					throw new \InvalidArgumentException( esc_html__( 'La plantilla o un color del PDF no es válido.', 'toolsbox-registros' ) );
				}
			}
			$result[ $name ] = $value;
		}
		return $result;
	}
	/**
	 * Convierte #rrggbb al formato RGB interno del PDF.
	 *
	 * @param string $hex Color validado.
	 * @return string
	 */
	public static function rgb( string $hex ): string {
		return implode( ' ', array_map( static fn ( string $pair ): string => sprintf( '%.3F', hexdec( $pair ) / 255 ), str_split( substr( $hex, 1, 6 ), 2 ) ) );
	}
	/**
	 * Mezcla un color con blanco para fondos suaves.
	 *
	 * @param string $hex Color validado.
	 * @param float  $amount Proporción del color original.
	 * @return string
	 */
	public static function tint( string $hex, float $amount ): string {
		return implode( ' ', array_map( static fn ( string $pair ): string => sprintf( '%.3F', 1 - ( 1 - hexdec( $pair ) / 255 ) * $amount ), str_split( substr( $hex, 1, 6 ), 2 ) ) );
	}
	/**
	 * Convierte una imagen de la biblioteca de medios a JPEG embebible.
	 *
	 * @param int $id Adjunto.
	 * @return string JPEG o cadena vacía si no es una imagen utilizable.
	 */
	public static function logo( int $id ): string {
		$path = $id > 0 && wp_attachment_is_image( $id ) ? get_attached_file( $id ) : false;
		if ( ! is_string( $path ) || ! is_readable( $path ) || filesize( $path ) > Photo::MAX_BYTES ) {
			return ''; }
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Archivo local de la biblioteca de medios.
		$bytes = (string) file_get_contents( $path );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Un adjunto dañado se omite.
		$info = @getimagesizefromstring( $bytes );
		if ( false === $info ) {
			return ''; }
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Imagen propia del sitio, nunca código ejecutable.
		$normalized = Photo::normalize( 'data:' . $info['mime'] . ';base64,' . base64_encode( $bytes ) );
		return is_wp_error( $normalized ) ? '' : Photo::jpeg( $normalized );
	}
}
