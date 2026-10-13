<?php
/**
 * Shortcode por ID o slug.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Frontend;

use Codwelt\ToolsBoxRegistros\Repository\FormRepository;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Resuelve únicamente formularios públicos, sin exponer borradores. */
final class Shortcode {
	/**
	 * Repositorio de formularios.
	 *
	 * @var FormRepository
	 */
	private FormRepository $forms;
	/**
	 * Inicializa el repositorio.
	 *
	 * @param FormRepository|null $forms Repositorio opcional.
	 */
	public function __construct( ?FormRepository $forms = null ) {
		$this->forms = $forms ?? new FormRepository();
	}
	/**
	 * Registra el shortcode público.
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( 'toolsbox_registro', array( $this, 'render' ) );
	}
	/**
	 * Devuelve el HTML del formulario indicado.
	 *
	 * @param mixed $attributes Atributos del shortcode.
	 * @return string
	 */
	public function render( mixed $attributes ): string {
		$args = shortcode_atts(
			array(
				'id'   => '',
				'slug' => '',
			),
			is_array( $attributes ) ? $attributes : array(),
			'toolsbox_registro'
		);
		$id   = is_scalar( $args['id'] ) ? filter_var( $args['id'], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ) : false;
		$form = false !== $id ? $this->forms->find( $id ) : ( is_string( $args['slug'] ) && '' !== $args['slug'] ? $this->forms->findBySlug( $args['slug'] ) : null );
		return ( new FormRenderer() )->render( $form );
	}
}
