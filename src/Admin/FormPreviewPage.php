<?php
/**
 * Vista previa privada de la última versión guardada.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);

namespace Codwelt\ToolsBoxRegistros\Admin;

use Codwelt\ToolsBoxRegistros\Frontend\FormRenderer;
use Codwelt\ToolsBoxRegistros\Repository\FormRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Reutiliza el formulario público en modo sin envío, sin cargar el tema. */
final class FormPreviewPage {
	/**
	 * Formularios guardados.
	 *
	 * @var FormRepository
	 */
	private FormRepository $forms;

	/**
	 * Inicializa el acceso a formularios.
	 *
	 * @param FormRepository|null $forms Repositorio opcional para pruebas.
	 */
	public function __construct( ?FormRepository $forms = null ) {
		$this->forms = $forms ?? new FormRepository();
	}

	/**
	 * Valida una lectura privada antes de producir cualquier HTML.
	 *
	 * @param array<string,mixed> $request Entrada deslasheada.
	 * @param string              $method Método HTTP.
	 * @return string|\WP_Error
	 */
	public function document( array $request, string $method = 'GET' ): string|\WP_Error {
		if ( ! current_user_can( 'tbreg_manage_forms' ) ) {
			return new \WP_Error( 'tbreg_forbidden', __( 'No tienes permiso para ver esta vista previa.', 'toolsbox-registros' ), array( 'status' => 403 ) );
		}
		if ( 'GET' !== $method ) {
			return new \WP_Error( 'tbreg_method', __( 'La vista previa requiere una petición GET.', 'toolsbox-registros' ), array( 'status' => 405 ) );
		}
		$id = isset( $request['id'] ) && is_scalar( $request['id'] ) ? filter_var( $request['id'], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ) : false;
		if ( ! $id || ! isset( $request['_wpnonce'] ) || ! is_string( $request['_wpnonce'] ) || ! wp_verify_nonce( $request['_wpnonce'], 'tbreg_preview_form_' . $id ) ) {
			return new \WP_Error( 'tbreg_invalid_nonce', __( 'El enlace de vista previa ha caducado. Vuelve al editor y ábrelo otra vez.', 'toolsbox-registros' ), array( 'status' => 403 ) );
		}
		$form = $this->forms->find( $id );
		if ( null === $form || 'trash' === $form->get( 'status' ) ) {
			return new \WP_Error( 'tbreg_not_found', __( 'El formulario no está disponible.', 'toolsbox-registros' ), array( 'status' => 404 ) );
		}
		FormRenderer::register_assets();
		wp_register_style( 'tbreg-preview', TBREG_URL . 'assets/admin/css/preview.css', array( 'tbreg-form' ), TBREG_VERSION );
		$html = ( new FormRenderer() )->render( $form, true, array( 'enqueue_assets' => false ) );
		ob_start();
		?>
		<!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<meta name="robots" content="noindex, nofollow">
			<title><?php echo esc_html( $form->get( 'title' ) . ' — ' . __( 'Vista previa', 'toolsbox-registros' ) ); ?></title>
			<?php wp_print_styles( array( 'tbreg-preview' ) ); ?>
		</head>
		<body class="tbreg-preview-page">
			<main>
				<p class="tbreg-preview-notice"><?php esc_html_e( 'Vista previa de la última versión guardada. No se envían inscripciones ni se activa la cámara.', 'toolsbox-registros' ); ?></p>
				<?php
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- FormRenderer y sus plantillas escapan cada valor.
				echo $html;
				wp_print_scripts( array( 'tbreg-form' ) );
				?>
			</main>
		</body>
		</html>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Atiende admin-post sin exponer una ruta anónima ni permitir caché.
	 *
	 * @return void
	 */
	public function handle(): void {
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- document() valida método, capacidad, tipos y nonce antes de leer el formulario.
		$result = $this->document( wp_unslash( $_GET ), isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '' );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => absint( $result->get_error_data()['status'] ) ) );
		}
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Documento propio con valores escapados en document() y FormRenderer.
		echo $result;
		exit;
	}
}
