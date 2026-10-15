<?php
/**
 * Widget de inscripción con controles nativos de Elementor.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Elementor;

use Codwelt\ToolsBoxRegistros\Domain\Form;
use Codwelt\ToolsBoxRegistros\Frontend\FormRenderer;
use Codwelt\ToolsBoxRegistros\Repository\FormRepository;
use Elementor\Controls_Manager;
use Elementor\Controls_Stack;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Typography;
use Elementor\Repeater;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** El estilo pertenece a cada widget; los datos pertenecen al formulario. */
final class FormWidget extends Widget_Base {
	/**
	 * Identificador interno.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'tbreg_form';
	}
	/**
	 * Nombre visible.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'ToolsBox — Formulario de inscripción', 'toolsbox-registros' );
	}
	/**
	 * Icono del panel.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-form-horizontal';
	}
	/**
	 * Categorías disponibles en Elementor gratuito.
	 *
	 * @return array<string>
	 */
	public function get_categories(): array {
		return array( 'general' );
	}
	/**
	 * Palabras para encontrar el widget.
	 *
	 * @return array<string>
	 */
	public function get_keywords(): array {
		return array( 'toolsbox', 'registro', 'inscripción', 'formulario', 'form' );
	}
	/**
	 * CSS del renderer.
	 *
	 * @return array<string>
	 */
	public function get_style_depends(): array {
		Integration::register_assets();
		return array( 'tbreg-form' );
	}
	/**
	 * Inicialización del formulario y puente de Elementor.
	 *
	 * @return array<string>
	 */
	public function get_script_depends(): array {
		Integration::register_assets();
		return array( 'tbreg-elementor' );
	}
	/**
	 * Evita almacenar resultados, nonces y reglas en la caché del widget.
	 *
	 * @return bool
	 */
	protected function is_dynamic_content(): bool {
		return true;
	}

	/**
	 * Obtiene el catálogo completo con paginación; no muestra borradores sin permiso.
	 *
	 * @return array<Form>
	 */
	private function catalog(): array {
		$repository = new FormRepository();
		$forms      = array();
		$manage     = current_user_can( 'tbreg_manage_forms' );
		for ( $offset = 0; ; $offset += 200 ) {
			$page = $repository->all(
				array(
					'per_page' => 200,
					'offset'   => $offset,
					'orderby'  => 'title',
					'order'    => 'ASC',
				)
			);
			foreach ( $page as $form ) {
				if ( $manage || 'draft' !== $form->get( 'status' ) ) {
					$forms[] = $form;
				}
			}
			if ( count( $page ) < 200 ) {
				return $forms;
			}
		}
	}

	/**
	 * Contenido y estilos generados por el propio editor, limitados a WRAPPER.
	 *
	 * @return void
	 */
	protected function register_controls(): void {
		$forms  = array( '' => __( 'Selecciona un formulario', 'toolsbox-registros' ) );
		$fields = array( '' => __( 'Selecciona un campo del formulario elegido', 'toolsbox-registros' ) );
		foreach ( $this->catalog() as $form ) {
			$forms[ (string) $form->id() ] = $form->get( 'title' ) . ' (#' . $form->id() . ')' . ( 'draft' === $form->get( 'status' ) ? ' — ' . __( 'Borrador', 'toolsbox-registros' ) : '' );
			foreach ( $form->fields() as $field ) {
				if ( ! $field->get( 'archived' ) && 'hidden' !== $field->get( 'type' ) ) {
					$fields[ $form->id() . ':' . $field->key() ] = $form->get( 'title' ) . ' (#' . $form->id() . ') — ' . $field->get( 'label' );
				}
			}
		}
		$this->start_controls_section( 'form_content', array( 'label' => __( 'Formulario', 'toolsbox-registros' ) ) );
		$this->add_control(
			'form_id',
			array(
				'label'       => __( 'Formulario de inscripción', 'toolsbox-registros' ),
				'type'        => Controls_Manager::SELECT2,
				'options'     => $forms,
				'default'     => '',
				'label_block' => true,
				'description' => __( 'Crea y edita los campos en ToolsBox Registros → Formularios. Publica el formulario para recibir inscripciones. Si creas campos nuevos, vuelve a abrir Elementor para actualizar el catálogo.', 'toolsbox-registros' ),
			)
		);
		foreach ( array(
			'title'       => __( 'Mostrar título', 'toolsbox-registros' ),
			'description' => __( 'Mostrar descripción', 'toolsbox-registros' ),
		) as $name => $label ) {
			$this->add_control(
				'show_' . $name,
				array(
					'label'                => $label,
					'type'                 => Controls_Manager::SWITCHER,
					'default'              => 'yes',
					'selectors_dictionary' => array(
						''    => 'none',
						'yes' => 'block',
					),
					'selectors'            => array( 'title' === $name ? '{{WRAPPER}} .tbreg-registration > h2' : '{{WRAPPER}} .tbreg-registration > .tbreg-description' => 'display: {{VALUE}};' ),
				)
			);
		}
		$this->end_controls_section();

		$this->section( 'container', __( 'Contenedor y distribución', 'toolsbox-registros' ) );
		self::color( $this, 'container_background', __( 'Fondo', 'toolsbox-registros' ), '{{WRAPPER}} .tbreg-registration', 'background-color' );
		self::color( $this, 'container_color', __( 'Texto', 'toolsbox-registros' ), '{{WRAPPER}} .tbreg-registration' );
		self::slider( $this, 'container_width', __( 'Ancho máximo', 'toolsbox-registros' ), '{{WRAPPER}} .tbreg-registration', 'max-width', 1600, array( 'px', '%' ) );
		self::box( $this, 'container', '{{WRAPPER}} .tbreg-registration', true );
		self::slider( $this, 'row_gap', __( 'Separación entre filas', 'toolsbox-registros' ), '{{WRAPPER}} .tbreg-fields', 'row-gap', 100 );
		self::slider( $this, 'column_gap', __( 'Separación entre columnas', 'toolsbox-registros' ), '{{WRAPPER}} .tbreg-fields', 'column-gap', 100 );
		$this->end_controls_section();

		foreach ( array(
			'title'       => array( __( 'Título del formulario', 'toolsbox-registros' ), '{{WRAPPER}} .tbreg-registration > h2' ),
			'description' => array( __( 'Descripción', 'toolsbox-registros' ), '{{WRAPPER}} .tbreg-registration > .tbreg-description' ),
			'heading'     => array( __( 'Encabezados de campos', 'toolsbox-registros' ), '{{WRAPPER}} .tbreg-field h3' ),
			'labels'      => array( __( 'Etiquetas', 'toolsbox-registros' ), '{{WRAPPER}} .tbreg-field > label, {{WRAPPER}} .tbreg-field legend' ),
			'choices'     => array( __( 'Opciones y consentimiento', 'toolsbox-registros' ), '{{WRAPPER}} .tbreg-registration .tbreg-choice' ),
			'help'        => array( __( 'Texto de ayuda', 'toolsbox-registros' ), '{{WRAPPER}} .tbreg-registration .tbreg-help' ),
		) as $name => $config ) {
			$this->section( $name, $config[0] );
			self::text( $this, $name, $config[1] );
			if ( 'choices' === $name ) {
				self::color( $this, 'choice_accent', __( 'Color de selección', 'toolsbox-registros' ), '{{WRAPPER}} .tbreg-field input[type="radio"], {{WRAPPER}} .tbreg-field input[type="checkbox"]', 'accent-color' );
				$this->add_responsive_control(
					'choice_size',
					array(
						'label'      => __( 'Tamaño de selección', 'toolsbox-registros' ),
						'type'       => Controls_Manager::SLIDER,
						'size_units' => array( 'px' ),
						'range'      => array(
							'px' => array(
								'min' => 12,
								'max' => 40,
							),
						),
						'selectors'  => array( '{{WRAPPER}} .tbreg-field input[type="radio"], {{WRAPPER}} .tbreg-field input[type="checkbox"]' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};' ),
					)
				);
			}
			$this->end_controls_section();
		}

		$this->section( 'inputs', __( 'Campos de entrada', 'toolsbox-registros' ) );
		$inputs = self::inputs( '{{WRAPPER}} .tbreg-registration' );
		self::text( $this, 'input', $inputs );
		self::color( $this, 'input_background', __( 'Fondo', 'toolsbox-registros' ), $inputs, 'background-color' );
		self::box( $this, 'input', $inputs );
		self::color( $this, 'placeholder_color', __( 'Texto de ejemplo', 'toolsbox-registros' ), '{{WRAPPER}} .tbreg-field input::placeholder, {{WRAPPER}} .tbreg-field textarea::placeholder' );
		self::color( $this, 'input_focus', __( 'Borde al enfocar', 'toolsbox-registros' ), self::inputs( '{{WRAPPER}} .tbreg-registration', ':focus' ), 'border-color' );
		self::color( $this, 'focus_outline', __( 'Indicador de foco', 'toolsbox-registros' ), '{{WRAPPER}} .tbreg-registration :focus-visible', 'outline-color' );
		$this->end_controls_section();

		$this->section( 'buttons', __( 'Botón de envío y cámara', 'toolsbox-registros' ) );
		$buttons = '{{WRAPPER}} .tbreg-registration button';
		self::text( $this, 'button', $buttons );
		self::color( $this, 'button_background', __( 'Fondo', 'toolsbox-registros' ), $buttons, 'background-color' );
		self::box( $this, 'button', $buttons );
		self::slider( $this, 'submit_width', __( 'Ancho del botón de envío', 'toolsbox-registros' ), '{{WRAPPER}} .tbreg-form > button[type="submit"]', 'width', 100, array( '%' ) );
		self::color( $this, 'button_hover_color', __( 'Texto al pasar el cursor', 'toolsbox-registros' ), $buttons . ':hover' );
		self::color( $this, 'button_hover_background', __( 'Fondo al pasar el cursor', 'toolsbox-registros' ), $buttons . ':hover', 'background-color' );
		$this->end_controls_section();

		$this->section( 'photo', __( 'Fotografía', 'toolsbox-registros' ) );
		self::slider( $this, 'photo_width', __( 'Ancho de vista previa', 'toolsbox-registros' ), '{{WRAPPER}} .tbreg-photo-preview, {{WRAPPER}} .tbreg-camera-video', 'max-width', 800 );
		self::slider( $this, 'photo_radius', __( 'Radio de las esquinas', 'toolsbox-registros' ), '{{WRAPPER}} .tbreg-photo-preview, {{WRAPPER}} .tbreg-camera-video', 'border-radius', 100 );
		$this->end_controls_section();

		$this->section( 'messages', __( 'Errores, resultado y PDF', 'toolsbox-registros' ) );
		self::color( $this, 'error_color', __( 'Texto de error', 'toolsbox-registros' ), '{{WRAPPER}} .tbreg-error, {{WRAPPER}} .tbreg-summary a' );
		self::color( $this, 'invalid_border', __( 'Borde de campo inválido', 'toolsbox-registros' ), '{{WRAPPER}} .tbreg-registration [aria-invalid="true"]', 'border-color', true );
		self::color( $this, 'error_background', __( 'Fondo del resumen de errores', 'toolsbox-registros' ), '{{WRAPPER}} .tbreg-summary', 'background-color' );
		self::text( $this, 'success', '{{WRAPPER}} .tbreg-success' );
		self::color( $this, 'success_background', __( 'Fondo del resultado', 'toolsbox-registros' ), '{{WRAPPER}} .tbreg-success', 'background-color' );
		self::color( $this, 'pdf_color', __( 'Enlace de PDF', 'toolsbox-registros' ), '{{WRAPPER}} .tbreg-pdf-download' );
		$this->end_controls_section();

		$this->section( 'individual', __( 'Campos individuales', 'toolsbox-registros' ) );
		$repeater = new Repeater();
		$repeater->add_control(
			'label',
			array(
				'label'   => __( 'Nombre del ajuste', 'toolsbox-registros' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'Estilo de campo', 'toolsbox-registros' ),
			)
		);
		$repeater->add_control(
			'field_key',
			array(
				'label'       => __( 'Campo', 'toolsbox-registros' ),
				'type'        => Controls_Manager::SELECT2,
				'options'     => $fields,
				'label_block' => true,
				'default'     => '',
				'description' => __( 'Busca el nombre del formulario y el campo. Solo se aplican los ajustes que pertenecen al formulario elegido en Contenido.', 'toolsbox-registros' ),
			)
		);
		$individual = '{{WRAPPER}} .tbreg-registration {{CURRENT_ITEM}}';
		$repeater->add_responsive_control(
			'width',
			array(
				'label'     => __( 'Ancho del campo', 'toolsbox-registros' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => '',
				'options'   => array(
					''  => __( 'Usar ancho del constructor', 'toolsbox-registros' ),
					'6' => '100%',
					'3' => '50%',
					'2' => '33%',
				),
				'selectors' => array( $individual => 'grid-column: span {{VALUE}};' ),
			)
		);
		self::text( $repeater, 'label', $individual . ' > label, ' . $individual . ' legend, ' . $individual . ' h3' );
		self::text( $repeater, 'control', self::inputs( $individual ) );
		self::color( $repeater, 'background', __( 'Fondo del control', 'toolsbox-registros' ), self::inputs( $individual ), 'background-color' );
		self::box( $repeater, 'control', self::inputs( $individual ) );
		self::color( $repeater, 'accent', __( 'Color de selección', 'toolsbox-registros' ), $individual . ' input[type="checkbox"], ' . $individual . ' input[type="radio"]', 'accent-color' );
		self::color( $repeater, 'help_color', __( 'Color de ayuda', 'toolsbox-registros' ), $individual . ' .tbreg-help' );
		$this->add_control(
			'field_styles',
			array(
				'type'          => Controls_Manager::REPEATER,
				'fields'        => $repeater->get_controls(),
				'default'       => array(),
				'prevent_empty' => false,
				'title_field'   => '{{{ label }}}',
			)
		);
		$this->end_controls_section();
	}

	/**
	 * Inicia un grupo de estilo.
	 *
	 * @param string $name Identificador.
	 * @param string $label Título traducido.
	 * @return void
	 */
	private function section( string $name, string $label ): void {
		$this->start_controls_section(
			'style_' . $name,
			array(
				'label' => $label,
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);
	}

	/**
	 * Selector común sin afectar radio, checkbox, ocultos ni honeypot.
	 *
	 * @param string $base Selector base.
	 * @param string $state Pseudoestado opcional.
	 * @return string
	 */
	private static function inputs( string $base, string $state = '' ): string {
		return $base . ' input:not([type="checkbox"]):not([type="radio"]):not([type="hidden"])' . $state . ', ' . $base . ' select' . $state . ', ' . $base . ' textarea' . $state;
	}

	/**
	 * Color nativo, compatible con colores globales de Elementor.
	 *
	 * @param Controls_Stack $target Widget o repetidor.
	 * @param string         $name Identificador.
	 * @param string         $label Texto.
	 * @param string         $selector Selector limitado al widget.
	 * @param string         $property Propiedad CSS interna.
	 * @param bool           $important Prevalece sobre el borde de error original.
	 * @return void
	 */
	private static function color( Controls_Stack $target, string $name, string $label, string $selector, string $property = 'color', bool $important = false ): void {
		$target->add_control(
			$name,
			array(
				'label'     => $label,
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( $selector => $property . ': {{VALUE}}' . ( $important ? ' !important' : '' ) . ';' ),
			)
		);
	}

	/**
	 * Color, tipografía y alineación responsive.
	 *
	 * @param Controls_Stack $target Widget o repetidor.
	 * @param string         $name Identificador.
	 * @param string         $selector Selector limitado al widget.
	 * @return void
	 */
	private static function text( Controls_Stack $target, string $name, string $selector ): void {
		self::color( $target, $name . '_color', __( 'Color del texto', 'toolsbox-registros' ), $selector );
		$target->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => $name . '_typography',
				'selector' => $selector,
			)
		);
		$target->add_responsive_control(
			$name . '_align',
			array(
				'label'     => __( 'Alineación', 'toolsbox-registros' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => '',
				'options'   => array(
					''       => __( 'Predeterminada', 'toolsbox-registros' ),
					'start'  => __( 'Inicio', 'toolsbox-registros' ),
					'center' => __( 'Centro', 'toolsbox-registros' ),
					'end'    => __( 'Final', 'toolsbox-registros' ),
				),
				'selectors' => array( $selector => 'text-align: {{VALUE}};' ),
			)
		);
	}

	/**
	 * Medida responsive opcional; sin valor conserva el CSS del formulario.
	 *
	 * @param Controls_Stack $target Widget o repetidor.
	 * @param string         $name Identificador.
	 * @param string         $label Texto.
	 * @param string         $selector Selector.
	 * @param string         $property Propiedad CSS interna.
	 * @param int            $max Límite del control.
	 * @param array<string>  $units Unidades permitidas.
	 * @return void
	 */
	private static function slider( Controls_Stack $target, string $name, string $label, string $selector, string $property, int $max, array $units = array( 'px' ) ): void {
		$target->add_responsive_control(
			$name,
			array(
				'label'      => $label,
				'type'       => Controls_Manager::SLIDER,
				'size_units' => $units,
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => $max,
					),
					'%'  => array(
						'min' => 0,
						'max' => 100,
					),
				),
				'selectors'  => array( $selector => $property . ': {{SIZE}}{{UNIT}};' ),
			)
		);
	}

	/**
	 * Borde, esquinas, relleno y sombra; margen para el contenedor.
	 *
	 * @param Controls_Stack $target Widget o repetidor.
	 * @param string         $name Identificador.
	 * @param string         $selector Selector.
	 * @param bool           $margin Permite margen.
	 * @return void
	 */
	private static function box( Controls_Stack $target, string $name, string $selector, bool $margin = false ): void {
		$target->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => $name . '_border',
				'selector' => $selector,
			)
		);
		self::slider( $target, $name . '_radius', __( 'Radio de las esquinas', 'toolsbox-registros' ), $selector, 'border-radius', 100 );
		$properties = array( 'padding' => __( 'Relleno', 'toolsbox-registros' ) );
		if ( $margin ) {
			$properties['margin'] = __( 'Margen', 'toolsbox-registros' );
		}
		foreach ( $properties as $property => $label ) {
			$target->add_responsive_control(
				$name . '_' . $property,
				array(
					'label'      => $label,
					'type'       => Controls_Manager::DIMENSIONS,
					'size_units' => array( 'px', 'em', '%' ),
					'selectors'  => array( $selector => $property . ': {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
				)
			);
		}
		$target->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => $name . '_shadow',
				'selector' => $selector,
			)
		);
	}

	/**
	 * Renderiza con permisos de vista previa y clases de presentación verificadas.
	 *
	 * @return void
	 */
	protected function render(): void {
		$settings = $this->get_settings_for_display();
		$id       = is_scalar( $settings['form_id'] ?? null ) ? absint( $settings['form_id'] ) : 0;
		$form     = $id > 0 ? ( new FormRepository() )->find( $id ) : null;
		$editing  = \Elementor\Plugin::$instance->editor->is_edit_mode() || \Elementor\Plugin::$instance->preview->is_preview_mode();
		$preview  = $editing && ( current_user_can( 'tbreg_manage_forms' ) || current_user_can( 'edit_posts' ) );
		$draft    = $editing && current_user_can( 'tbreg_manage_forms' );
		if ( null === $form || ( ! $draft && 'draft' === $form->get( 'status' ) ) || 'trash' === $form->get( 'status' ) ) {
			if ( $editing && current_user_can( 'edit_posts' ) ) {
				echo '<p class="tbreg-elementor-placeholder">' . esc_html__( 'Selecciona un formulario disponible en Contenido. Los borradores requieren permiso para administrar formularios.', 'toolsbox-registros' ) . '</p>';
			}
			return;
		}
		$classes = array();
		$keys    = array_map( static fn ( $field ): string => $field->key(), $form->fields() );
		foreach ( is_array( $settings['field_styles'] ?? null ) ? $settings['field_styles'] : array() as $item ) {
			if ( ! is_array( $item ) || ! is_string( $item['field_key'] ?? null ) || ! is_string( $item['_id'] ?? null ) || ! preg_match( '/^[a-z0-9_-]{1,64}$/D', $item['_id'] ) ) {
				continue;
			}
			$parts = explode( ':', $item['field_key'], 2 );
			if ( (string) $id === $parts[0] && isset( $parts[1] ) && in_array( $parts[1], $keys, true ) ) {
				$classes[ $parts[1] ][] = 'elementor-repeater-item-' . $item['_id'];
			}
		}
		$html = ( new FormRenderer() )->render(
			$form,
			$preview,
			array(
				'field_classes'  => $classes,
				'enqueue_assets' => false,
				'public_preview' => true,
			)
		);
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- El renderer y cada plantilla escapan sus datos; conservar el HTML accesible.
		echo $html;
	}
}
