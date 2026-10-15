<?php
/**
 * Rutas de envío, renovación de token y selector del bloque.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Rest;

use Codwelt\ToolsBoxRegistros\Frontend\SubmissionHandler;
use Codwelt\ToolsBoxRegistros\Repository\FormRepository;
use Codwelt\ToolsBoxRegistros\Services\RegistrationRules;
use Codwelt\ToolsBoxRegistros\Services\SpamGuard;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Las rutas públicas validan token propio y reglas en el servicio compartido. */
final class SubmitController {
	/**
	 * Servicio compartido de envío.
	 *
	 * @var SubmissionHandler
	 */
	private SubmissionHandler $handler;
	/**
	 * Repositorio de formularios.
	 *
	 * @var FormRepository
	 */
	private FormRepository $forms;
	/**
	 * Inicializa las dependencias.
	 *
	 * @param SubmissionHandler|null $handler Servicio de envío.
	 * @param FormRepository|null    $forms Repositorio de formularios.
	 */
	public function __construct( ?SubmissionHandler $handler = null, ?FormRepository $forms = null ) {
		$this->handler = $handler ?? new SubmissionHandler();
		$this->forms   = $forms ?? new FormRepository();
	}
	/**
	 * Registra endpoints.
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			'toolsbox-registros/v1',
			'/forms/(?P<id>\d+)/submit',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'submit' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'toolsbox-registros/v1',
			'/forms/(?P<id>\d+)/token',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'token' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'toolsbox-registros/v1',
			'/forms',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'forms' ),
				'permission_callback' => array( $this, 'can_list' ),
			)
		);
	}
	/**
	 * Recibe parámetros REST sin doble deslasheado.
	 *
	 * @param \WP_REST_Request $request Petición.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function submit( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = $this->handler->submit( (int) $request->get_url_params()['id'], $request->get_params(), SubmissionHandler::transport(), $request->get_file_params() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$response = new \WP_REST_Response( $result, 201 );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}
	/**
	 * Renueva tokens para páginas cacheadas sin incluir datos de inscritos.
	 *
	 * @param \WP_REST_Request $request Petición.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function token( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$form = $this->forms->find( (int) $request->get_url_params()['id'] );
		if ( null === $form || in_array( $form->get( 'status' ), array( 'draft', 'trash' ), true ) ) {
			return new \WP_Error( 'tbreg_not_found', __( 'El formulario no está disponible.', 'toolsbox-registros' ), array( 'status' => 404 ) );
		}
		$error = ( new RegistrationRules() )->check( $form );
		if ( null !== $error ) {
			return $error;
		}
		$response = new \WP_REST_Response( ( new SpamGuard() )->issue( $form->id() ) );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}
	/**
	 * Requiere la capacidad del constructor.
	 *
	 * @return bool
	 */
	public function can_list(): bool {
		return current_user_can( 'tbreg_manage_forms' );
	}
	/**
	 * Lista paginada de nombres para el editor del bloque.
	 *
	 * @param \WP_REST_Request $request Petición de lectura.
	 * @return \WP_REST_Response
	 */
	public function forms( \WP_REST_Request $request ): \WP_REST_Response {
		$page  = max( 1, (int) ( $request['page'] ?? 1 ) );
		$args  = array(
			'per_page' => 100,
			'offset'   => ( $page - 1 ) * 100,
		);
		$items = array_map(
			static fn ( \Codwelt\ToolsBoxRegistros\Domain\Form $form ): array => array(
				'id'     => $form->id(),
				'title'  => $form->get( 'title' ),
				'status' => $form->get( 'status' ),
			),
			$this->forms->all( $args )
		);
		return new \WP_REST_Response(
			array(
				'items' => $items,
				'pages' => (int) ceil( $this->forms->count() / 100 ),
			)
		);
	}
}
