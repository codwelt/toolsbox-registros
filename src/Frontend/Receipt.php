<?php
/**
 * Descarga privada de la constancia PDF.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Frontend;

use Codwelt\ToolsBoxRegistros\Domain\Form;
use Codwelt\ToolsBoxRegistros\Repository\FormRepository;
use Codwelt\ToolsBoxRegistros\Repository\EntryRepository;
use Codwelt\ToolsBoxRegistros\Services\TransientStore;
use Codwelt\ToolsBoxRegistros\Export\RegistrationPdf;
use Codwelt\ToolsBoxRegistros\Export\EntriesExporter;
use Codwelt\ToolsBoxRegistros\Export\XlsxWriter;
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
/** No permite enumerar recibos públicos por ID de inscripción. */
final class Receipt {
	/**
	 * Formularios.
	 *
	 * @var FormRepository
	 */
	private FormRepository $forms;
	/**
	 * Inscripciones.
	 *
	 * @var EntryRepository
	 */
	private EntryRepository $entries;
	/**
	 * Repositorios inyectables.
	 *
	 * @param FormRepository|null  $forms Formularios.
	 * @param EntryRepository|null $entries Inscripciones.
	 */
	public function __construct( ?FormRepository $forms = null, ?EntryRepository $entries = null ) {
		$this->forms   = $forms ?? new FormRepository();
		$this->entries = $entries ?? new EntryRepository(); }
	/**
	 * Registra ambas rutas de lectura.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_tbreg_receipt', array( $this, 'download' ) );
		add_action( 'admin_post_nopriv_tbreg_receipt', array( $this, 'download' ) ); }
	/**
	 * Emite acceso solo después de una inscripción confirmada en BD.
	 *
	 * @param int  $id Inscripción guardada.
	 * @param Form $form Esquema usado al inscribir.
	 * @return string
	 */
	public function issue( int $id, Form $form ): string {
		$owner = self::owner();
		if ( '' === $owner ) {
			$owner = bin2hex( random_bytes( 16 ) ); }
		if ( ! headers_sent() ) {
			setcookie(
				'tbreg_receipt_owner',
				$owner,
				array(
					'expires'  => time() + DAY_IN_SECONDS,
					'path'     => '/',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}
		$_COOKIE['tbreg_receipt_owner'] = $owner;
		$token                          = bin2hex( random_bytes( 32 ) );
		TransientStore::set(
			'tbreg_receipt_' . hash( 'sha256', $owner . '|' . $token ),
			array(
				'entry_id' => $id,
				'form_id'  => $form->id(),
				'schema'   => $form->toArray(),
			),
			DAY_IN_SECONDS
		);
		return add_query_arg(
			array(
				'action' => 'tbreg_receipt',
				'token'  => $token,
			),
			admin_url( 'admin-post.php' )
		);
	}
	/**
	 * Lee únicamente una cookie con formato propio.
	 *
	 * @return string
	 */
	private static function owner(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Se acepta únicamente el patrón aleatorio hexadecimal comprobado abajo.
		$value = isset( $_COOKIE['tbreg_receipt_owner'] ) && is_string( $_COOKIE['tbreg_receipt_owner'] ) ? wp_unslash( $_COOKIE['tbreg_receipt_owner'] ) : '';
		return preg_match( '/^[a-f0-9]{32}$/D', $value ) ? $value : '';
	}
	/**
	 * Autoriza acceso administrativo o del navegador que inscribió.
	 *
	 * @param array<string,mixed> $input Consulta.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function resolve( array $input ): array|\WP_Error {
		$schema = null;
		if ( isset( $input['entry_id'] ) ) {
			$id = is_scalar( $input['entry_id'] ) ? filter_var( $input['entry_id'], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ) : false;
			if ( false === $id || ! current_user_can( 'tbreg_view_entries' ) || ! is_string( $input['_wpnonce'] ?? null ) || ! wp_verify_nonce( $input['_wpnonce'], 'tbreg_receipt_' . $id ) ) {
				return self::denied(); }
		} else {
			$token = $input['token'] ?? null;
			$owner = self::owner();
			if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{64}$/D', $token ) || '' === $owner ) {
				return self::denied(); }
			$grant = TransientStore::get( 'tbreg_receipt_' . hash( 'sha256', $owner . '|' . $token ) );
			if ( ! is_array( $grant ) || ! is_int( $grant['entry_id'] ?? null ) || ! is_int( $grant['form_id'] ?? null ) || ! is_array( $grant['schema'] ?? null ) ) {
				return self::denied(); }
			$id     = $grant['entry_id'];
			$schema = Form::fromArray( $grant['schema'] );
		}
		$entry = $this->entries->find( $id );
		$form  = null === $entry ? null : $this->forms->find( (int) $entry['form_id'] );
		if ( null === $form || null === $entry || ( null !== $schema && $schema->id() !== (int) $entry['form_id'] ) ) {
			return self::denied(); }
		if ( ! ( $schema ?? $form )->settings()['pdf_enabled'] ) {
			return self::denied(); }
		return array(
			'entry'  => $entry,
			'form'   => $schema ?? $form,
			'values' => $this->entries->values( $id ),
		);
	}
	/**
	 * Mensaje genérico que no revela IDs existentes.
	 *
	 * @return \WP_Error
	 */
	private static function denied(): \WP_Error {
		return new \WP_Error( 'tbreg_receipt_denied', __( 'Este enlace no está disponible o ha caducado. Usa el navegador con el que completaste la inscripción.', 'toolsbox-registros' ), array( 'status' => 403 ) ); }
	/**
	 * Genera y entrega PDF con cabeceras privadas, eliminando el temporal.
	 *
	 * @throws \RuntimeException Si falla el archivo temporal.
	 * @return void
	 */
	public function download(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Comparación estricta del método, sin almacenarlo ni mostrarlo.
		if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'La descarga requiere GET.', 'toolsbox-registros' ), '', array( 'response' => 405 ) ); }
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- resolve valida capacidad/nonce o token aleatorio ligado a cookie HttpOnly.
		$context = $this->resolve( wp_unslash( $_GET ) );
		if ( is_wp_error( $context ) ) {
			wp_die( esc_html( $context->get_error_message() ), '', array( 'response' => 403 ) ); }
		$path = '';
		try {
			$path = XlsxWriter::temporary();
			$pdf  = ( new RegistrationPdf() )->build( $context['form'], $context['entry'], $context['values'] );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Temporal propio con permisos 0600, generado antes de emitir cabeceras.
			if ( strlen( $pdf ) !== file_put_contents( $path, $pdf ) ) {
				throw new \RuntimeException( 'tbreg_pdf_io' ); }
			( new EntriesExporter() )->send(
				array(
					'path'     => $path,
					'mime'     => 'application/pdf',
					'filename' => 'inscripcion-' . $context['entry']['id'] . '.pdf',
				)
			);
		} catch ( \Throwable $exception ) {
			if ( ! headers_sent() ) {
				wp_die( esc_html__( 'No se pudo generar el PDF. Inténtalo de nuevo.', 'toolsbox-registros' ), '', array( 'response' => 500 ) ); }
		} finally {
			if ( '' !== $path ) {
				wp_delete_file( $path ); }
		}
		exit;
	}
}
