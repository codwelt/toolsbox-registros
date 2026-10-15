<?php
/**
 * Formulario público y resumen accesible de errores.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$tbreg_form     = $tbreg_context['form'];
$tbreg_settings = $tbreg_form->settings();
?>
<section class="tbreg-registration" id="<?php echo esc_attr( $tbreg_context['prefix'] ); ?>" aria-labelledby="<?php echo esc_attr( $tbreg_context['prefix'] . '-title' ); ?>">
<h2 id="<?php echo esc_attr( $tbreg_context['prefix'] . '-title' ); ?>"><?php echo esc_html( $tbreg_form->get( 'title' ) ); ?></h2>
<?php
if ( '' !== $tbreg_form->get( 'description' ) ) :
	?>
	<p class="tbreg-description"><?php echo esc_html( $tbreg_form->get( 'description' ) ); ?></p><?php endif; ?>
<form class="tbreg-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-pdf-label="<?php esc_attr_e( 'Descargar PDF de mi inscripción', 'toolsbox-registros' ); ?>" data-form-id="<?php echo esc_attr( (string) $tbreg_form->id() ); ?>" data-endpoint="<?php echo esc_url( rest_url( 'toolsbox-registros/v1/forms/' . $tbreg_form->id() . '/submit' ) ); ?>" data-token-endpoint="<?php echo esc_url( rest_url( 'toolsbox-registros/v1/forms/' . $tbreg_form->id() . '/token' ) ); ?>" data-rest-nonce="<?php echo esc_attr( is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '' ); ?>" data-preview="<?php echo $tbreg_context['preview'] ? 'true' : 'false'; ?>" data-network-error="<?php esc_attr_e( 'No se pudo confirmar la respuesta del servidor. Comprueba tu conexión antes de volver a enviar.', 'toolsbox-registros' ); ?>" data-group-required="<?php esc_attr_e( 'Selecciona al menos una opción.', 'toolsbox-registros' ); ?>">
<input type="hidden" name="action" value="tbreg_submit">
<input type="hidden" name="tbreg_form_id" value="<?php echo esc_attr( (string) $tbreg_form->id() ); ?>">
<input type="hidden" name="tbreg_return" value="<?php echo esc_url( $tbreg_context['return_url'] ); ?>">
<?php
foreach ( $tbreg_context['token'] as $tbreg_name => $tbreg_value ) :
	?>
	<input type="hidden" name="<?php echo esc_attr( $tbreg_name ); ?>" value="<?php echo esc_attr( $tbreg_value ); ?>"><?php endforeach; ?>
<div class="tbreg-honeypot" aria-hidden="true"><label><?php esc_html_e( 'Deja este campo vacío', 'toolsbox-registros' ); ?><input type="text" name="tbreg_website" value="" tabindex="-1" autocomplete="off"></label></div>
<div class="tbreg-summary tbreg-error" tabindex="-1" role="alert" <?php echo '' === $tbreg_context['message'] ? 'hidden' : ''; ?>>
<p><?php echo esc_html( $tbreg_context['message'] ); ?></p>
<?php
if ( array() !== $tbreg_context['summary'] ) :
	?>
	<ul>
	<?php
	foreach ( $tbreg_context['summary'] as $tbreg_error ) :
		?>
	<li><a href="#<?php echo esc_attr( $tbreg_error['id'] ); ?>"><?php echo esc_html( $tbreg_error['label'] . ': ' . $tbreg_error['message'] ); ?></a></li><?php endforeach; ?></ul><?php endif; ?>
</div>
<div class="tbreg-fields">
<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Cada plantilla de campo produce HTML escapado; conservar sus etiquetas accesibles.
echo $tbreg_context['fields_html'];
?>
</div>
<?php if ( 'none' !== $tbreg_context['captcha'] && ! $tbreg_context['preview'] ) : ?>
<div class="tbreg-captcha" data-provider="<?php echo esc_attr( $tbreg_context['captcha'] ); ?>" data-site-key="<?php echo esc_attr( $tbreg_context['site_key'] ); ?>"></div>
<noscript><p><?php esc_html_e( 'Activa JavaScript para completar la verificación antispam.', 'toolsbox-registros' ); ?></p></noscript>
<?php endif; ?>
<button type="submit" <?php disabled( $tbreg_context['preview'] ); ?>><?php echo esc_html( $tbreg_settings['submit_label'] ); ?></button>
<p class="tbreg-live-status" role="status" aria-live="polite"></p>
</form>
</section>
