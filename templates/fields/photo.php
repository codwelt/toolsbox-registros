<?php
/**
 * Fotografía desde archivo o cámara.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
$tbreg_field = $tbreg_context['field'];
$tbreg_id    = $tbreg_context['id'];
?>
<div class="tbreg-field tbreg-photo tbreg-width-<?php echo esc_attr( $tbreg_field->get( 'width' ) ); ?> <?php echo esc_attr( $tbreg_field->get( 'css_class' ) . ' ' . ( $tbreg_context['css_classes'] ?? '' ) ); ?>" data-tbreg-field="<?php echo esc_attr( $tbreg_field->key() ); ?>">
<label for="<?php echo esc_attr( $tbreg_id ); ?>"><?php echo esc_html( $tbreg_field->get( 'label' ) ); ?>
<?php
if ( $tbreg_field->get( 'required' ) ) :
	?>
	<span aria-hidden="true">*</span><?php endif; ?></label>
<input type="file" id="<?php echo esc_attr( $tbreg_id ); ?>" name="tbreg_photos[<?php echo esc_attr( $tbreg_field->key() ); ?>]" accept="image/jpeg,image/png,image/webp" <?php echo $tbreg_field->get( 'required' ) ? 'required' : ''; ?> aria-required="<?php echo $tbreg_field->get( 'required' ) ? 'true' : 'false'; ?>" aria-invalid="<?php echo '' !== $tbreg_context['error'] ? 'true' : 'false'; ?>" aria-describedby="<?php echo esc_attr( $tbreg_id . '-help ' . $tbreg_id . '-error' ); ?>" <?php echo ! empty( $tbreg_context['autofocus'] ) ? 'autofocus' : ''; ?>>
<p id="<?php echo esc_attr( $tbreg_id . '-help' ); ?>" class="tbreg-help"><?php echo esc_html( sprintf( /* translators: %s: tamaño máximo permitido para la foto. */ __( 'JPEG, PNG o WebP, hasta %s. Puedes elegir una imagen o tomarla con la cámara.', 'toolsbox-registros' ), size_format( min( \Codwelt\ToolsBoxRegistros\Services\Photo::MAX_BYTES, wp_max_upload_size() ) ) ) ); ?> <?php echo esc_html( $tbreg_field->get( 'help' ) ); ?></p>
<?php
if ( ! empty( $tbreg_context['photo_retry'] ) ) :
	?>
	<p class="tbreg-help"><?php esc_html_e( 'Después de corregir los datos, vuelve a adjuntar la fotografía antes de enviar.', 'toolsbox-registros' ); ?></p><?php endif; ?>
<div class="tbreg-camera-controls" hidden>
<button type="button" data-camera-open><?php esc_html_e( 'Tomar foto', 'toolsbox-registros' ); ?></button>
<button type="button" data-camera-take hidden><?php esc_html_e( 'Capturar foto', 'toolsbox-registros' ); ?></button>
<button type="button" data-camera-cancel hidden><?php esc_html_e( 'Cerrar cámara', 'toolsbox-registros' ); ?></button>
</div>
<video class="tbreg-camera-video" autoplay muted playsinline hidden aria-label="<?php esc_attr_e( 'Vista previa de la cámara', 'toolsbox-registros' ); ?>"></video>
<img class="tbreg-photo-preview" hidden alt="<?php esc_attr_e( 'Fotografía seleccionada', 'toolsbox-registros' ); ?>">
<p class="tbreg-camera-status" role="status" data-camera-error="<?php esc_attr_e( 'No se pudo usar la cámara. Puedes seleccionar una foto desde tu dispositivo.', 'toolsbox-registros' ); ?>" data-camera-captured="<?php esc_attr_e( 'Foto capturada. Se enviará al completar la inscripción.', 'toolsbox-registros' ); ?>"></p>
<p id="<?php echo esc_attr( $tbreg_id . '-error' ); ?>" class="tbreg-error" data-tbreg-error <?php echo '' === $tbreg_context['error'] ? 'hidden' : ''; ?>><?php echo esc_html( $tbreg_context['error'] ); ?></p>
</div>
