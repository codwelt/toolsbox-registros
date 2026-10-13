<?php
/**
 * Tokens compatibles con caché, honeypot, límite de intentos y captcha.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Services;

use Codwelt\ToolsBoxRegistros\Domain\Form;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** No usa cabeceras de proxy no verificadas ni persiste direcciones IP. */
final class SpamGuard {
	/**
	 * Conexión para el bloqueo del contador.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;
	/**
	 * Inicializa la conexión del sitio.
	 *
	 * @param \wpdb|null $db Conexión opcional.
	 */
	public function __construct( ?\wpdb $db = null ) {
		global $wpdb;
		$this->db = $db ?? $wpdb;
	}
	/**
	 * Emite un token de 12 horas ligado a formulario y usuario.
	 *
	 * @param int      $form_id Formulario.
	 * @param int|null $now Instante de emisión para pruebas.
	 * @return array<string,string>
	 */
	public function issue( int $form_id, ?int $now = null ): array {
		$timestamp = (string) ( $now ?? time() );
		$random    = bin2hex( random_bytes( 8 ) );
		$signature = hash_hmac( 'sha256', $form_id . '|' . get_current_user_id() . '|' . $timestamp . '|' . $random, wp_salt( 'nonce' ) );
		return array(
			'tbreg_ts'    => $timestamp,
			'tbreg_token' => $random . '.' . $signature,
		);
	}
	/**
	 * Comprueba token y tiempo sin consumir el límite.
	 *
	 * @param int                 $form_id Formulario.
	 * @param array<string,mixed> $input Datos publicados.
	 * @param int|null            $now Instante UTC opcional.
	 * @return \WP_Error|null
	 */
	public function token_error( int $form_id, array $input, ?int $now = null ): ?\WP_Error {
		$timestamp = $input['tbreg_ts'] ?? '';
		$token     = $input['tbreg_token'] ?? '';
		if ( ! is_string( $timestamp ) || ! preg_match( '/^[0-9]{1,12}$/D', $timestamp ) || ! is_string( $token ) || ! preg_match( '/^([a-f0-9]{16})\.([a-f0-9]{64})$/D', $token, $matches ) ) {
			return new \WP_Error( 'tbreg_token', __( 'Recarga el formulario para renovar la sesión.', 'toolsbox-registros' ), array( 'status' => 403 ) );
		}
		$expected = hash_hmac( 'sha256', $form_id . '|' . get_current_user_id() . '|' . $timestamp . '|' . $matches[1], wp_salt( 'nonce' ) );
		$age      = ( $now ?? time() ) - (int) $timestamp;
		if ( ! hash_equals( $expected, $matches[2] ) || $age < 0 || $age > 12 * HOUR_IN_SECONDS ) {
			return new \WP_Error( 'tbreg_token', __( 'La sesión ha caducado. Recarga el formulario.', 'toolsbox-registros' ), array( 'status' => 403 ) );
		}
		if ( $age < 3 ) {
			return new \WP_Error( 'tbreg_too_fast', __( 'Espera unos segundos antes de enviar el formulario.', 'toolsbox-registros' ), array( 'status' => 429 ) );
		}
		return null;
	}
	/**
	 * Valida los intentos, honeypot y el captcha configurado.
	 *
	 * @param Form                $form Formulario actual.
	 * @param array<string,mixed> $input Petición.
	 * @param string              $ip IP del transporte, no de un campo publicado.
	 * @return \WP_Error|null
	 */
	public function check( Form $form, array $input, string $ip ): ?\WP_Error {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return new \WP_Error( 'tbreg_transport', __( 'No se pudo verificar el origen del envío.', 'toolsbox-registros' ), array( 'status' => 403 ) );
		}
		$limit = $this->rate_limit( $ip );
		if ( null !== $limit ) {
			return $limit;
		}
		$token = $this->token_error( $form->id(), $input );
		if ( null !== $token ) {
			return $token;
		}
		$honey = $input['tbreg_website'] ?? '';
		if ( ! is_string( $honey ) || '' !== $honey ) {
			return new \WP_Error( 'tbreg_spam', __( 'No se pudo verificar el envío.', 'toolsbox-registros' ), array( 'status' => 403 ) );
		}
		return $this->captcha_error( $form, $input );
	}
	/**
	 * Consume uno de cinco intentos en una ventana fija de diez minutos.
	 *
	 * @param string $ip Dirección validada.
	 * @return \WP_Error|null
	 */
	private function rate_limit( string $ip ): ?\WP_Error {
		$hash = hash_hmac( 'sha256', (string) inet_pton( $ip ), wp_salt() );
		$key  = 'tbreg_rate_' . $hash;
		$lock = 'tbreg_rate_' . substr( hash( 'sha256', $this->db->prefix . $hash ), 0, 48 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Bloqueo con nombre y parámetros; no se cachean resultados de locks.
		$held = $this->db->get_var( $this->db->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 2 ) );
		if ( '1' !== (string) $held ) {
			return new \WP_Error( 'tbreg_busy', __( 'El servicio está ocupado. Inténtalo de nuevo.', 'toolsbox-registros' ), array( 'status' => 503 ) );
		}
		try {
			$counter = TransientStore::get( $key );
			$now     = time();
			if ( ! is_array( $counter ) || ( $counter['until'] ?? 0 ) <= $now ) {
				$counter = array(
					'count' => 0,
					'until' => $now + 10 * MINUTE_IN_SECONDS,
				);
			}
			if ( $counter['count'] >= 5 ) {
				return new \WP_Error( 'tbreg_rate_limit', __( 'Has alcanzado el límite de intentos. Inténtalo dentro de diez minutos.', 'toolsbox-registros' ), array( 'status' => 429 ) );
			}
			++$counter['count'];
			if ( ! TransientStore::set( $key, $counter, max( 1, $counter['until'] - $now ) ) ) {
				return new \WP_Error( 'tbreg_busy', __( 'No se pudo verificar el límite de envíos. Inténtalo de nuevo.', 'toolsbox-registros' ), array( 'status' => 503 ) );
			}
			return null;
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Libera exactamente el bloqueo adquirido por esta petición.
			$this->db->get_var( $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}
	/**
	 * Lee claves opcionales sin exponer el secreto al cliente.
	 *
	 * @param string $provider Proveedor permitido.
	 * @return array<string,string>
	 */
	public static function captcha_config( string $provider ): array {
		if ( ! in_array( $provider, array( 'turnstile', 'recaptcha' ), true ) ) {
			return array(
				'site_key'   => '',
				'secret_key' => '',
			);
		}
		$raw = apply_filters( 'tbreg_captcha_config', get_option( 'tbreg_captcha_' . $provider, array() ), $provider );
		return array(
			'site_key'   => is_array( $raw ) && is_string( $raw['site_key'] ?? null ) ? $raw['site_key'] : '',
			'secret_key' => is_array( $raw ) && is_string( $raw['secret_key'] ?? null ) ? $raw['secret_key'] : '',
		);
	}
	/**
	 * Requiere confirmación del proveedor y hostname del sitio.
	 *
	 * @param Form                $form Configuración.
	 * @param array<string,mixed> $input Token del widget.
	 * @return \WP_Error|null
	 */
	private function captcha_error( Form $form, array $input ): ?\WP_Error {
		$provider = $form->settings()['captcha'];
		if ( 'none' === $provider ) {
			return null;
		}
		$keys  = self::captcha_config( $provider );
		$token = $input[ 'turnstile' === $provider ? 'cf-turnstile-response' : 'g-recaptcha-response' ] ?? '';
		$error = new \WP_Error( 'tbreg_captcha', __( 'Completa la verificación antispam o inténtalo de nuevo.', 'toolsbox-registros' ), array( 'status' => 403 ) );
		if ( '' === $keys['site_key'] || '' === $keys['secret_key'] || ! is_string( $token ) || '' === $token || strlen( $token ) > 2048 ) {
			return $error;
		}
		$response = wp_remote_post(
			'turnstile' === $provider ? 'https://challenges.cloudflare.com/turnstile/v0/siteverify' : 'https://www.google.com/recaptcha/api/siteverify',
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => $keys['secret_key'],
					'response' => $token,
				),
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return $error;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		return is_array( $data ) && true === ( $data['success'] ?? false ) && is_string( $data['hostname'] ?? null ) && strtolower( $data['hostname'] ) === $host ? null : $error;
	}
}
