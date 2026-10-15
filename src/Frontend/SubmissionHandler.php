<?php
/**
 * Canal compartido entre POST tradicional y REST.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Frontend;

use Codwelt\ToolsBoxRegistros\Domain\Form;
use Codwelt\ToolsBoxRegistros\Domain\FieldTypeRegistry;
use Codwelt\ToolsBoxRegistros\Repository\FormRepository;
use Codwelt\ToolsBoxRegistros\Repository\EntryRepository;
use Codwelt\ToolsBoxRegistros\Services\SpamGuard;
use Codwelt\ToolsBoxRegistros\Services\Validator;
use Codwelt\ToolsBoxRegistros\Services\Photo;
use Codwelt\ToolsBoxRegistros\Services\RegistrationRules;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** No acepta estados, usuario ni IP provenientes de los campos del formulario. */
final class SubmissionHandler {
	/**
	 * Repositorio de formularios.
	 *
	 * @var FormRepository
	 */
	private FormRepository $forms;
	/**
	 * Repositorio de inscripciones.
	 *
	 * @var EntryRepository
	 */
	private EntryRepository $entries;
	/**
	 * Protección antispam.
	 *
	 * @var SpamGuard
	 */
	private SpamGuard $spam;
	/**
	 * Inicializa los servicios, inyectables en pruebas.
	 *
	 * @param FormRepository|null  $forms Formularios.
	 * @param EntryRepository|null $entries Inscripciones.
	 * @param SpamGuard|null       $spam Antispam.
	 */
	public function __construct( ?FormRepository $forms = null, ?EntryRepository $entries = null, ?SpamGuard $spam = null ) {
		$this->forms   = $forms ?? new FormRepository();
		$this->entries = $entries ?? new EntryRepository();
		$this->spam    = $spam ?? new SpamGuard();
	}
	/**
	 * Conecta ambos endpoints admin-post, incluidos visitantes anónimos.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_tbreg_submit', array( $this, 'handle_post' ) );
		add_action( 'admin_post_nopriv_tbreg_submit', array( $this, 'handle_post' ) );
	}
	/**
	 * Obtiene metadatos únicamente del transporte HTTP.
	 *
	 * @return array<string,string>
	 */
	public static function transport(): array {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- FILTER_VALIDATE_IP conserva la dirección solo en memoria.
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		return array(
			'ip'         => filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '',
			'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) && is_string( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
		);
	}
	/**
	 * Ejecuta el envío, volviendo a validar bajo SELECT FOR UPDATE.
	 *
	 * @param int                  $form_id Formulario.
	 * @param array<string,mixed>  $input Entrada deslasheada o parámetros REST.
	 * @param array<string,string> $transport Metadatos del servidor.
	 * @param array<string,mixed>  $files Archivos recibidos por PHP.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function submit( int $form_id, array $input, array $transport, array $files = array() ): array|\WP_Error {
		try {
			$form = $this->forms->find( $form_id );
			if ( null === $form || in_array( $form->get( 'status' ), array( 'draft', 'trash' ), true ) ) {
				return new \WP_Error( 'tbreg_not_found', __( 'El formulario no está disponible.', 'toolsbox-registros' ), array( 'status' => 404 ) );
			}
			$rules = new RegistrationRules();
			$error = $rules->check( $form );
			if ( null !== $error ) {
				return $error;
			}
			if ( strlen( (string) wp_json_encode( $input ) ) > 1048576 ) {
				return new \WP_Error( 'tbreg_payload', __( 'El envío es demasiado grande.', 'toolsbox-registros' ), array( 'status' => 413 ) );
			}
			$error = $this->spam->check( $form, $input, $transport['ip'] ?? '' );
			if ( null !== $error ) {
				return $error;
			}
			$raw = $input['tbreg_values'] ?? array();
			if ( ! is_array( $raw ) ) {
				return new \WP_Error( 'tbreg_values', __( 'Los valores del formulario no son válidos.', 'toolsbox-registros' ), array( 'status' => 422 ) );
			}
			$raw = Photo::uploads( $form, $raw, $files );
			if ( is_wp_error( $raw ) ) {
				return $raw; }
			if ( ! $this->entries->supportsTransactions() ) {
				return new \WP_Error( 'tbreg_storage', __( 'El servicio de inscripciones no está disponible. Contacta al administrador.', 'toolsbox-registros' ), array( 'status' => 503 ) );
			}
			do_action( 'tbreg_before_submit', $form, $raw );
			$snapshot = $form;
			$id       = $this->entries->create(
				$form,
				$raw,
				array(
					'ip'         => $transport['ip'],
					'user_agent' => $transport['user_agent'] ?? '',
					'user_id'    => get_current_user_id(),
					'source_url' => self::safe_url( $input['tbreg_return'] ?? '' ),
				),
				static function ( Form $current, array $values ) use ( $rules, &$snapshot ): array|\WP_Error {
					$snapshot = $current;
					$error    = $rules->check( $current );
					return null !== $error ? $error : ( new Validator() )->validate( $current, $values );
				}
			);
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			$entry = $this->entries->find( $id );
			return array(
				'success'      => true,
				'entry_id'     => $id,
				'status'       => $entry['status'],
				'pdf_url'      => $snapshot->settings()['pdf_enabled'] ? ( new Receipt( $this->forms, $this->entries ) )->issue( $id, $snapshot ) : '',
				'message'      => 'waitlist' === $entry['status'] ? __( 'Tu inscripción quedó en lista de espera.', 'toolsbox-registros' ) : $snapshot->settings()['success_message'],
				'redirect_url' => '' !== $snapshot->settings()['redirect_url'] ? self::safe_url( $snapshot->settings()['redirect_url'] ) : '',
			);
		} catch ( \RuntimeException | \InvalidArgumentException $exception ) {
			return new \WP_Error( 'tbreg_unavailable', __( 'No se pudo completar la inscripción. Inténtalo de nuevo.', 'toolsbox-registros' ), array( 'status' => 503 ) );
		}
	}
	/**
	 * Solo permite URLs del origen del sitio, sin tokens de resultados previos.
	 *
	 * @param mixed $value URL del cliente o del administrador.
	 * @return string
	 */
	public static function safe_url( mixed $value ): string {
		$fallback = home_url( '/' );
		if ( ! is_string( $value ) ) {
			return $fallback;
		}
		$url    = esc_url_raw( $value, array( 'http', 'https' ) );
		$origin = wp_parse_url( home_url() );
		$parts  = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['host'], $parts['scheme'] ) || strtolower( $parts['host'] ) !== strtolower( $origin['host'] ) || strtolower( $parts['scheme'] ) !== strtolower( $origin['scheme'] ) || ( $parts['port'] ?? null ) !== ( $origin['port'] ?? null ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || str_contains( $url, '\\' ) ) {
			return $fallback;
		}
		return remove_query_arg( array( 'tbreg_result', 'tbreg_form' ), $url );
	}
	/**
	 * Guarda, conserva errores saneados y redirige sin datos personales en la URL.
	 *
	 * @return void
	 */
	public function handle_post(): void {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			wp_die( esc_html__( 'El envío requiere POST.', 'toolsbox-registros' ), '', array( 'response' => 405 ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- submit() valida token firmado, reglas, estructura y cada campo.
		$input = wp_unslash( $_POST );
		$id    = isset( $input['tbreg_form_id'] ) && is_scalar( $input['tbreg_form_id'] ) ? absint( $input['tbreg_form_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- submit comprueba el token firmado antes de procesar archivos temporales de PHP.
		$result = $this->submit( $id, $input, self::transport(), $_FILES );
		$url    = explode( '#', self::safe_url( $input['tbreg_return'] ?? '' ), 2 )[0];
		if ( ! is_wp_error( $result ) && '' !== $result['redirect_url'] && empty( $result['pdf_url'] ) ) {
			wp_safe_redirect( $result['redirect_url'], 303 );
			exit;
		}
		$payload = is_wp_error( $result ) ? array(
			'success'     => false,
			'message'     => $result->get_error_message(),
			'errors'      => $result->get_error_data()['fields'] ?? array(),
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- submit ya validó el token; solo se conserva un indicador booleano, nunca el archivo.
			'photo_retry' => ! empty( $_FILES['tbreg_photos'] ),
			'values'      => $this->redisplay_values( $id, $input['tbreg_values'] ?? array() ),
		) : $result;
		$token = Feedback::store( $id, $payload );
		wp_safe_redirect(
			add_query_arg(
				array(
					'tbreg_result' => $token,
					'tbreg_form'   => $id,
				),
				$url
			) . '#tbreg-form-' . $id,
			303
		);
		exit;
	}
	/**
	 * Conserva solo campos conocidos sin incluir captcha, IP ni tokens.
	 *
	 * @param int   $id Formulario.
	 * @param mixed $raw Valores publicados.
	 * @return array<string,mixed>
	 */
	private function redisplay_values( int $id, mixed $raw ): array {
		$form   = $this->forms->find( $id );
		$result = array();
		if ( null === $form || ! is_array( $raw ) ) {
			return $result;
		}
		foreach ( $form->fields() as $field ) {
			$value = $raw[ $field->key() ] ?? '';
			if ( ! $field->storesValue() || in_array( $field->get( 'type' ), array( 'hidden', 'consent', 'photo' ), true ) ) {
				continue;
			}
			if ( is_array( $value ) && 'checkbox' === $field->get( 'type' ) ) {
				$value = array_slice( array_values( array_filter( $value, 'is_scalar' ) ), 0, count( $field->get( 'options' ) ) );
			}
			if ( is_scalar( $value ) || ( is_array( $value ) && 'checkbox' === $field->get( 'type' ) ) ) {
				$type                    = ( new FieldTypeRegistry() )->get( $field->get( 'type' ) );
				$value                   = $type->sanitize( $value, $field );
				$result[ $field->key() ] = is_array( $value ) ? array_map( static fn ( string $item ): string => mb_substr( $item, 0, 5000 ), $value ) : mb_substr( $value, 0, 5000 );
			}
		}
		return $result;
	}
}
