<?php
/**
 * Mensaje de disponibilidad o resultado.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div id="<?php echo esc_attr( $tbreg_context['id'] ?? wp_unique_id( 'tbreg-message-' ) ); ?>" class="tbreg-registration tbreg-message" role="status" tabindex="-1" data-form-id="<?php echo esc_attr( (string) $tbreg_context['form_id'] ); ?>"><p><?php echo esc_html( $tbreg_context['message'] ); ?></p>
<?php
if ( ! empty( $tbreg_context['login_url'] ) ) :
	?>
	<a href="<?php echo esc_url( $tbreg_context['login_url'] ); ?>"><?php esc_html_e( 'Iniciar sesión', 'toolsbox-registros' ); ?></a><?php endif; ?>
</div>
