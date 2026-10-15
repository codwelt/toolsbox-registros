<?php
/**
 * Integración opcional con Elementor gratuito.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Elementor;

use Codwelt\ToolsBoxRegistros\Frontend\FormRenderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Carga el widget únicamente cuando Elementor registra sus widgets. */
final class Integration {
	/**
	 * Registra hooks sin depender de clases de Elementor en el arranque.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'elementor/widgets/register', array( $this, 'register_widget' ) );
		add_action( 'elementor/frontend/after_register_scripts', array( self::class, 'register_assets' ) );
	}

	/**
	 * Declara dependencias para las inserciones dinámicas del editor.
	 *
	 * @return void
	 */
	public static function register_assets(): void {
		FormRenderer::register_assets();
		wp_register_script( 'tbreg-elementor', TBREG_URL . 'assets/public/js/elementor.js', array( 'jquery', 'elementor-frontend', 'tbreg-form' ), TBREG_VERSION, true );
	}

	/**
	 * Elementor 3.5 introdujo el hook y la API utilizados.
	 *
	 * @param \Elementor\Widgets_Manager $manager Gestor de widgets.
	 * @return void
	 */
	public function register_widget( \Elementor\Widgets_Manager $manager ): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) || version_compare( ELEMENTOR_VERSION, '3.5.0', '<' ) || ! class_exists( '\Elementor\Widget_Base' ) ) {
			return;
		}
		self::register_assets();
		$manager->register( new FormWidget() );
	}
}
