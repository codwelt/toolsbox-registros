<?php
/**
 * Render de formularios y resultados públicos.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Frontend;

use Codwelt\ToolsBoxRegistros\Domain\Form;
use Codwelt\ToolsBoxRegistros\Domain\FieldTypeRegistry;
use Codwelt\ToolsBoxRegistros\Services\RegistrationRules;
use Codwelt\ToolsBoxRegistros\Services\SpamGuard;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Cada instancia usa IDs únicos, con assets cargados bajo demanda. */
final class FormRenderer {
	/**
	 * Instancias por formulario para evitar IDs duplicados en la misma página.
	 *
	 * @var array<int,int>
	 */
	private static array $instances = array();
	/**
	 * Registra handles sin encolarlos en páginas ajenas.
	 *
	 * @return void
	 */
	public static function register_assets(): void {
		wp_register_style( 'tbreg-form', TBREG_URL . 'assets/public/css/form.css', array(), TBREG_VERSION );
		wp_register_script( 'tbreg-form', TBREG_URL . 'assets/public/js/form.js', array(), TBREG_VERSION, true );
	}
	/**
	 * Carga CSS aun si el shortcode se renderiza después de wp_head.
	 *
	 * @param bool $preview Vista previa sin envío.
	 * @return string
	 */
	private function assets( bool $preview ): string {
		self::register_assets();
		wp_enqueue_style( 'tbreg-form' );
		if ( ! $preview ) {
			wp_enqueue_script( 'tbreg-form' );
		}
		if ( did_action( 'wp_head' ) && ! wp_style_is( 'tbreg-form', 'done' ) ) {
			ob_start();
			wp_print_styles( 'tbreg-form' );
			return (string) ob_get_clean();
		}
		return '';
	}
	/**
	 * Obtiene la URL actual dentro del origen autorizado.
	 *
	 * @return string
	 */
	public static function current_url(): string {
		$origin = wp_parse_url( home_url() );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- safe_url valida esquema, host, puerto y caracteres de la URI.
		$uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		return SubmissionHandler::safe_url( $origin['scheme'] . '://' . $origin['host'] . ( isset( $origin['port'] ) ? ':' . $origin['port'] : '' ) . '/' . ltrim( $uri, '/' ) );
	}
	/**
	 * Renderiza datos públicos o una vista previa autorizada.
	 *
	 * @param Form|null           $form Formulario.
	 * @param bool                $preview Vista previa del editor.
	 * @param array<string,mixed> $presentation Clases por campo y carga de assets; solo afecta la presentación.
	 * @return string
	 */
	public function render( ?Form $form, bool $preview = false, array $presentation = array() ): string {
		$public_preview = true === ( $presentation['public_preview'] ?? false ) && current_user_can( 'edit_posts' ) && null !== $form && ! in_array( $form->get( 'status' ), array( 'draft', 'trash' ), true );
		$preview        = $preview && ( current_user_can( 'tbreg_manage_forms' ) || $public_preview );
		if ( null === $form || ( ! $preview && in_array( $form->get( 'status' ), array( 'draft', 'trash' ), true ) ) ) {
			return '';
		}
		self::$instances[ $form->id() ] = ( self::$instances[ $form->id() ] ?? 0 ) + 1;
		$instance                       = self::$instances[ $form->id() ];
		$prefix                         = 'tbreg-form-' . $form->id() . ( $instance > 1 ? '-' . $instance : '' );
		$assets                         = false === ( $presentation['enqueue_assets'] ?? true ) ? '' : $this->assets( $preview );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Solo consume el resultado PRG ligado a la cookie del navegador.
		$token       = isset( $_GET['tbreg_result'] ) && is_string( $_GET['tbreg_result'] ) ? sanitize_key( $_GET['tbreg_result'] ) : '';
		$result_form = isset( $_GET['tbreg_form'] ) && is_scalar( $_GET['tbreg_form'] ) ? absint( $_GET['tbreg_form'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$feedback = ! $preview && $form->id() === $result_form ? Feedback::consume( $form->id(), $token ) : array();
		if ( array() !== $feedback && ! headers_sent() ) {
			nocache_headers();
		}
		if ( true === ( $feedback['success'] ?? false ) ) {
			return $assets . Template::render(
				'messages/success.php',
				array(
					'message' => $feedback['message'],
					'pdf_url' => $feedback['pdf_url'] ?? '',
					'form_id' => $form->id(),
					'id'      => $prefix,
				)
			);
		}
		$error = $preview ? null : ( new RegistrationRules() )->check( $form );
		if ( null !== $error ) {
			return $assets . Template::render(
				'messages/' . ( 'tbreg_full' === $error->get_error_code() ? 'full' : ( 'tbreg_not_open' === $error->get_error_code() ? 'not-open' : 'closed' ) ) . '.php',
				array(
					'message'   => $error->get_error_message(),
					'form_id'   => $form->id(),
					'id'        => $prefix,
					'login_url' => 'tbreg_login' === $error->get_error_code() ? wp_login_url( self::current_url() ) : '',
				)
			);
		}
		$settings = $form->settings();
		$provider = $settings['captcha'];
		$captcha  = SpamGuard::captcha_config( $provider );
		if ( ! $preview && 'none' !== $provider && ( '' === $captcha['site_key'] || '' === $captcha['secret_key'] ) ) {
			return $assets . Template::render(
				'messages/closed.php',
				array(
					'message' => __( 'Este formulario no está disponible temporalmente. Contacta al administrador.', 'toolsbox-registros' ),
					'form_id' => $form->id(),
					'id'      => $prefix,
				)
			);
		}
		if ( ! $preview && 'none' !== $provider ) {
			// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- API del proveedor sin versión pública; no se añade ?ver para alterar su URL oficial.
			wp_enqueue_script( 'tbreg-captcha-' . $provider, 'turnstile' === $provider ? 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=tbreg_turnstile_ready' : 'https://www.google.com/recaptcha/api.js?render=explicit&onload=tbreg_recaptcha_ready', array( 'tbreg-form' ), null, true );
		}
		$registry    = new FieldTypeRegistry();
		$fields_html = '';
		$summary     = array();
		$focused     = false;
		foreach ( $form->fields() as $field ) {
			if ( $field->get( 'archived' ) ) {
				continue;
			}
			$classes = array();
			foreach ( (array) ( $presentation['field_classes'][ $field->key() ] ?? array() ) as $class ) {
				if ( is_string( $class ) && preg_match( '/^elementor-repeater-item-[a-z0-9_-]{1,64}$/D', $class ) ) {
					$classes[] = $class;
				}
			}
			$value = $feedback['values'][ $field->key() ] ?? $field->get( 'default' );
			if ( 'consent' === $field->get( 'type' ) ) {
				$value = '';
			}
			if ( 'hidden' === $field->get( 'type' ) && '' !== $field->get( 'query_param' ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Se sanea y deslashea abajo solo si es escalar; no es autorización.
				$raw = $_GET[ $field->get( 'query_param' ) ] ?? $value;
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- El tipo sanea el parámetro y el template escapa el atributo.
				$value = is_scalar( $raw ) ? $registry->get( 'hidden' )->sanitize( wp_unslash( $raw ), $field ) : $field->get( 'default' );
			}
			$id      = $prefix . '-' . $field->key();
			$message = $feedback['errors'][ $field->key() ] ?? '';
			$visible = ! in_array( $field->get( 'type' ), array( 'hidden', 'heading' ), true );
			if ( '' !== $message && $visible ) {
				$summary[] = array(
					'id'      => $id . ( in_array( $field->get( 'type' ), array( 'radio', 'checkbox' ), true ) ? '-0' : '' ),
					'label'   => $field->get( 'label' ),
					'message' => $message,
				);
			}
			$fields_html .= $registry->get( $field->get( 'type' ) )->render(
				$field,
				$value,
				array(
					'id'           => $id,
					'error'        => $message,
					'photo_retry'  => $feedback['photo_retry'] ?? false,
					'css_classes'  => implode( ' ', $classes ),
					'consent_text' => $settings['consent_text'],
					'autofocus'    => ! $focused && $visible && '' !== $message,
				)
			);
			if ( $visible && '' !== $message ) {
				$focused = true;
			}
		}
		return $assets . Template::render(
			'form.php',
			array(
				'form'        => $form,
				'prefix'      => $prefix,
				'fields_html' => $fields_html,
				'summary'     => $summary,
				'message'     => $feedback['message'] ?? '',
				'token'       => ( new SpamGuard() )->issue( $form->id() ),
				'return_url'  => self::current_url(),
				'captcha'     => $provider,
				'site_key'    => $captcha['site_key'],
				'preview'     => $preview,
			)
		);
	}
}
