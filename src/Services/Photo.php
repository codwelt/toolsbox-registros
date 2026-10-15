<?php
/**
 * Imágenes pequeñas y privadas en la tabla de valores.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Services;

use Codwelt\ToolsBoxRegistros\Domain\Form;
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
/** Implementación del servicio propio del plugin. */
final class Photo {
	public const MAX_BYTES = 5242880;
	/**
	 * Normaliza un data URI, nunca una URL remota ni una ruta del cliente.
	 *
	 * @param mixed $raw Imagen codificada.
	 * @return string|\WP_Error
	 */
	public static function normalize( mixed $raw ): string|\WP_Error {
		if ( ! is_string( $raw ) || strlen( $raw ) > 4 * self::MAX_BYTES / 3 + 100 || ! preg_match( '~\Adata:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)\z~D', $raw, $match ) ) {
			return self::error(); }
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Codificación de imágenes privadas, nunca código ejecutable.
		$bytes = base64_decode( $match[2], true );
		if ( false === $bytes || strlen( $bytes ) > self::MAX_BYTES ) {
			return self::error(); }
		if ( ! function_exists( 'imagecreatefromstring' ) || ! function_exists( 'imagejpeg' ) ) {
			return new \WP_Error( 'tbreg_photo', __( 'El servidor necesita GD para procesar fotografías.', 'toolsbox-registros' ) ); }
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Un archivo inválido se convierte en error de validación.
		$info = @getimagesizefromstring( $bytes );
		if ( false === $info || ! in_array( $info[2], array( IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP ), true ) || $info[0] < 1 || $info[1] < 1 || $info[0] > 4096 || $info[1] > 4096 || $info[0] * $info[1] > 4000000 || 'image/' . $match[1] !== $info['mime'] ) {
			return self::error(); }
		$memory_limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		if ( $memory_limit > 0 && $memory_limit - memory_get_usage( true ) < $info[0] * $info[1] * 6 + 8388608 ) {
			return new \WP_Error( 'tbreg_photo', __( 'Reduce el tamaño de la foto y vuelve a adjuntarla.', 'toolsbox-registros' ) );
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Verifica también que GD decodifica el contenido real.
		$source = @imagecreatefromstring( $bytes );
		if ( false === $source ) {
			return self::error(); }
		$scale  = min( 1, 800 / max( $info[0], $info[1] ) );
		$width  = max( 1, (int) round( $info[0] * $scale ) );
		$height = max( 1, (int) round( $info[1] * $scale ) );
		$target = imagecreatetruecolor( $width, $height );
		try {
			imagefill( $target, 0, 0, imagecolorallocate( $target, 255, 255, 255 ) );
			imagecopyresampled( $target, $source, 0, 0, 0, 0, $width, $height, $info[0], $info[1] );
			ob_start();
			$encoded = imagejpeg( $target, null, 80 );
			$jpeg    = (string) ob_get_clean();
			if ( ! $encoded || '' === $jpeg ) {
				return self::error(); }
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Codificación de imágenes privadas, nunca código ejecutable.
			return strlen( $jpeg ) <= 307200 ? 'data:image/jpeg;base64,' . base64_encode( $jpeg ) : self::error();
		} finally {
			imagedestroy( $source );
			imagedestroy( $target ); }
	}
	/**
	 * Convierte exclusivamente archivos recibidos por el transporte HTTP.
	 *
	 * @param Form                $form Esquema.
	 * @param array<string,mixed> $raw Respuestas.
	 * @param array<string,mixed> $files Archivos de PHP/REST.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function uploads( Form $form, array $raw, array $files ): array|\WP_Error {
		$photos = $files['tbreg_photos'] ?? array();
		if ( ! is_array( $photos ) ) {
			return self::error(); }
		foreach ( $form->fields() as $field ) {
			$key = $field->key();
			if ( ! $field->storesValue() || 'photo' !== $field->get( 'type' ) || ! isset( $photos['error'][ $key ] ) ) {
				continue; }
			$error = $photos['error'][ $key ];
			if ( UPLOAD_ERR_NO_FILE === $error ) {
				continue; }
			$path = $photos['tmp_name'][ $key ] ?? null;
			if ( UPLOAD_ERR_OK !== $error || ! is_string( $path ) || ! is_uploaded_file( $path ) || filesize( $path ) > self::MAX_BYTES ) {
				return self::field_error( $key ); }
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Lee solo un archivo temporal de PHP comprobado y acotado.
			$bytes = file_get_contents( $path );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- MIME real, nunca el Content-Type suministrado por el navegador.
			$info = is_string( $bytes ) ? @getimagesizefromstring( $bytes ) : false;
			if ( false === $info || ! in_array( $info['mime'], array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
				return self::field_error( $key ); }
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Codificación de imágenes privadas, nunca código ejecutable.
			$raw[ $key ] = 'data:' . $info['mime'] . ';base64,' . base64_encode( $bytes );
		}
		return $raw;
	}
	/**
	 * Obtiene JPEG saneado para el detalle/PDF, sin abrir rutas.
	 *
	 * @param string $stored Valor de base de datos.
	 * @return string
	 */
	public static function jpeg( string $stored ): string {
		if ( strlen( $stored ) > 409623 || ! str_starts_with( $stored, 'data:image/jpeg;base64,' ) ) {
			return ''; }
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Codificación de imágenes privadas, nunca código ejecutable.
		$bytes = base64_decode( substr( $stored, 23 ), true );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Una imagen histórica dañada no se muestra.
		$info = false !== $bytes ? @getimagesizefromstring( $bytes ) : false;
		return false !== $info && IMAGETYPE_JPEG === $info[2] && max( $info[0], $info[1] ) <= 800 ? $bytes : '';
	}
	/**
	 * Error sin datos personales ni rutas del servidor.
	 *
	 * @return \WP_Error
	 */
	private static function error(): \WP_Error {
		return new \WP_Error( 'tbreg_photo', sprintf( /* translators: %s: límite de tamaño del servidor. */ __( 'Adjunta una foto JPEG, PNG o WebP de hasta %s y 4 millones de píxeles.', 'toolsbox-registros' ), size_format( min( self::MAX_BYTES, wp_max_upload_size() ) ) ) ); }
	/**
	 * Error accesible del transporte de archivos.
	 *
	 * @param string $key Campo.
	 * @return \WP_Error
	 */
	private static function field_error( string $key ): \WP_Error {
		return new \WP_Error(
			'tbreg_validation',
			__( 'Revisa la fotografía adjunta.', 'toolsbox-registros' ),
			array(
				'status' => 422,
				'fields' => array( $key => self::error()->get_error_message() ),
			)
		); }
}
