<?php
/**
 * Configuración opcional de servicios antispam.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Admin;

use Codwelt\ToolsBoxRegistros\Services\SpamGuard;
use Codwelt\ToolsBoxRegistros\Export\XlsxWriter;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Las claves secretas se conservan si el administrador deja el control vacío. */
final class SettingsPage {
	/**
	 * Valida todos los proveedores antes de modificar las opciones.
	 *
	 * @param array<string,mixed> $input Datos deslasheados.
	 * @return bool|\WP_Error
	 */
	public function save( array $input ): bool|\WP_Error {
		if ( ! current_user_can( 'tbreg_manage_forms' ) || ! is_string( $input['tbreg_nonce'] ?? null ) || ! wp_verify_nonce( $input['tbreg_nonce'], 'tbreg_save_captcha' ) ) {
			return new \WP_Error( 'tbreg_forbidden', __( 'No tienes permiso o la sesión ha caducado.', 'toolsbox-registros' ) );
		}
		$raw      = $input['tbreg_captcha'] ?? array();
		$settings = array();
		if ( ! is_array( $raw ) ) {
			return new \WP_Error( 'tbreg_invalid_data', __( 'Las claves no son válidas.', 'toolsbox-registros' ) );
		}
		foreach ( array( 'turnstile', 'recaptcha' ) as $provider ) {
			$data = $raw[ $provider ] ?? array();
			if ( ! is_array( $data ) ) {
				return new \WP_Error( 'tbreg_invalid_data', __( 'Las claves no son válidas.', 'toolsbox-registros' ) );
			}
			$old                   = get_option( 'tbreg_captcha_' . $provider, array() );
			$settings[ $provider ] = array();
			foreach ( array( 'site_key', 'secret_key' ) as $key ) {
				$value = $data[ $key ] ?? '';
				if ( ! is_string( $value ) || ( '' !== $value && ! preg_match( '/^[a-zA-Z0-9_-]{1,255}$/D', $value ) ) ) {
					return new \WP_Error( 'tbreg_invalid_data', __( 'Copia una clave válida del servicio antispam.', 'toolsbox-registros' ) );
				}
				$settings[ $provider ][ $key ] = 'secret_key' === $key && '' === $value && is_array( $old ) ? ( $old[ $key ] ?? '' ) : $value;
			}
		}
		foreach ( $settings as $provider => $values ) {
			update_option( 'tbreg_captcha_' . $provider, $values, false );
		}
		return true;
	}
	/**
	 * Handler POST autorizado.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		Menu::require_access( 'tbreg_manage_forms' );
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			wp_die( esc_html__( 'El guardado requiere POST.', 'toolsbox-registros' ), '', array( 'response' => 405 ) );
		}
		check_admin_referer( 'tbreg_save_captcha', 'tbreg_nonce' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- save() valida nonce, tipos y formato de cada clave.
		$result = $this->save( wp_unslash( $_POST ) );
		$token  = Notices::put( is_wp_error( $result ) ? $result->get_error_message() : __( 'Claves antispam guardadas.', 'toolsbox-registros' ), is_wp_error( $result ) );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => 'toolsbox-registros-settings',
					'tbreg_notice' => $token,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
	/**
	 * Renderiza únicamente los ajustes necesarios para el captcha opcional.
	 *
	 * @return void
	 */
	public function render(): void {
		Menu::require_access( 'tbreg_manage_forms' );
		?>
		<div class="wrap tbreg-admin"><h1><?php esc_html_e( 'Ajustes', 'toolsbox-registros' ); ?></h1><?php Notices::render(); ?>
		<?php
		if ( ! XlsxWriter::available() ) :
			?>
			<div class="notice notice-warning"><p><?php esc_html_e( 'El servidor no dispone de ZipArchive. La exportación Excel está deshabilitada; puedes descargar CSV.', 'toolsbox-registros' ); ?></p></div><?php endif; ?>
		<p><?php esc_html_e( 'Configura las claves de tu sitio y activa el servicio elegido en cada formulario. El honeypot y el límite de intentos funcionan sin captcha adicional.', 'toolsbox-registros' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="tbreg_save_captcha"><?php wp_nonce_field( 'tbreg_save_captcha', 'tbreg_nonce' ); ?>
		<?php
		foreach ( array(
			'turnstile' => __( 'Cloudflare Turnstile', 'toolsbox-registros' ),
			'recaptcha' => __( 'Google reCAPTCHA v2 (casilla)', 'toolsbox-registros' ),
		) as $tbreg_provider => $tbreg_label ) :
			$tbreg_keys = SpamGuard::captcha_config( $tbreg_provider );
			?>
		<h2><?php echo esc_html( $tbreg_label ); ?></h2>
		<label class="tbreg-control"><span><?php esc_html_e( 'Clave del sitio', 'toolsbox-registros' ); ?></span><input type="text" name="tbreg_captcha[<?php echo esc_attr( $tbreg_provider ); ?>][site_key]" value="<?php echo esc_attr( $tbreg_keys['site_key'] ); ?>" maxlength="255" autocomplete="off"></label>
		<label class="tbreg-control"><span><?php esc_html_e( 'Nueva clave secreta (vacío = conservar)', 'toolsbox-registros' ); ?></span><input type="password" name="tbreg_captcha[<?php echo esc_attr( $tbreg_provider ); ?>][secret_key]" value="" maxlength="255" autocomplete="new-password"></label>
		<p class="description"><?php echo esc_html( '' !== $tbreg_keys['secret_key'] ? __( 'Ya hay una clave secreta guardada.', 'toolsbox-registros' ) : __( 'Todavía no hay una clave secreta guardada.', 'toolsbox-registros' ) ); ?></p>
		<?php endforeach; ?>
		<?php submit_button( __( 'Guardar claves', 'toolsbox-registros' ) ); ?></form>
		<p><?php esc_html_e( 'Los ajustes de correo y privacidad estarán disponibles en una próxima actualización.', 'toolsbox-registros' ); ?></p></div>
		<?php
	}
}
