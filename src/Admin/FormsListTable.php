<?php
/**
 * Listado paginado de formularios.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);

namespace Codwelt\ToolsBoxRegistros\Admin;

use Codwelt\ToolsBoxRegistros\Repository\FormRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Adapta el repositorio a la tabla nativa de WordPress.
 */
final class FormsListTable extends \WP_List_Table {
	/**
	 * Repositorio del listado.
	 *
	 * @var FormRepository
	 */
	private FormRepository $forms;

	/**
	 * Inicializa la tabla.
	 *
	 * @param FormRepository|null $forms Repositorio opcional.
	 */
	public function __construct( ?FormRepository $forms = null ) {
		parent::__construct(
			array(
				'singular' => 'tbreg_form',
				'plural'   => 'tbreg_forms',
				'ajax'     => false,
			)
		);
		$this->forms = $forms ?? new FormRepository();
	}

	/**
	 * Nombres traducidos de estados de formularios.
	 *
	 * @return array<string,string>
	 */
	public static function form_statuses(): array {
		return array(
			'draft'     => __( 'Borrador', 'toolsbox-registros' ),
			'published' => __( 'Publicado', 'toolsbox-registros' ),
			'closed'    => __( 'Cerrado', 'toolsbox-registros' ),
			'trash'     => __( 'Papelera', 'toolsbox-registros' ),
		);
	}

	/**
	 * Nombres traducidos de estados de inscripciones.
	 *
	 * @return array<string,string>
	 */
	public static function entry_statuses(): array {
		return array(
			'confirmed' => __( 'Confirmado', 'toolsbox-registros' ),
			'pending'   => __( 'Pendiente', 'toolsbox-registros' ),
			'attended'  => __( 'Asistió', 'toolsbox-registros' ),
			'cancelled' => __( 'Cancelado', 'toolsbox-registros' ),
			'waitlist'  => __( 'Lista de espera', 'toolsbox-registros' ),
		);
	}

	/**
	 * Define las columnas.
	 *
	 * @return array<string,string>
	 */
	public function get_columns(): array {
		return array(
			'title'     => __( 'Título', 'toolsbox-registros' ),
			'shortcode' => __( 'Shortcode', 'toolsbox-registros' ),
			'status'    => __( 'Estado', 'toolsbox-registros' ),
			'entries'   => __( 'Inscritos / cupo', 'toolsbox-registros' ),
			'dates'     => __( 'Apertura / cierre', 'toolsbox-registros' ),
		);
	}

	/**
	 * Orden disponible.
	 *
	 * @return array<string,array>
	 */
	protected function get_sortable_columns(): array {
		return array( 'title' => array( 'title', false ) );
	}

	/**
	 * Obtiene filtros de lectura y carga una página.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		Menu::require_access( 'tbreg_manage_forms' );
		$args = array(
			'per_page' => 20,
			'offset'   => ( $this->get_pagenum() - 1 ) * 20,
		);
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Filtros de lectura; no modifican datos.
		foreach ( array( 'status', 's', 'orderby', 'order' ) as $key ) {
			$value = isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
			if ( 'status' === $key && isset( self::form_statuses()[ $value ] ) ) {
				$args['status'] = $value;
			} elseif ( 's' === $key ) {
				$args['search'] = $value;
			} elseif ( in_array( $key, array( 'orderby', 'order' ), true ) ) {
				$args[ $key ] = $value;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$this->items           = $this->forms->all( $args );
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'title' );
		$this->set_pagination_args(
			array(
				'total_items' => $this->forms->count( $args ),
				'per_page'    => 20,
			)
		);
	}

	/**
	 * Título y acciones por fila.
	 *
	 * @param mixed $item Formulario.
	 * @return string
	 */
	public function column_title( $item ): string {
		$edit       = add_query_arg(
			array(
				'page' => 'toolsbox-registros-edit',
				'id'   => $item->id(),
			),
			admin_url( 'admin.php' )
		);
		$actions    = array( 'edit' => '<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Editar', 'toolsbox-registros' ) . '</a>' );
		$operations = 'trash' === $item->get( 'status' ) ? array( 'restore' => __( 'Restaurar como borrador', 'toolsbox-registros' ) ) : array(
			'duplicate' => __( 'Duplicar', 'toolsbox-registros' ),
			'close'     => __( 'Cerrar inscripciones', 'toolsbox-registros' ),
			'trash'     => __( 'Papelera', 'toolsbox-registros' ),
		);
		foreach ( $operations as $operation => $label ) {
			$url                   = wp_nonce_url(
				add_query_arg(
					array(
						'action'    => 'tbreg_form_action',
						'operation' => $operation,
						'id'        => $item->id(),
					),
					admin_url( 'admin-post.php' )
				),
				'tbreg_form_action_' . $operation . '_' . $item->id()
			);
			$actions[ $operation ] = '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		foreach ( array(
			'entries' => array( 'tbreg_view_entries', __( 'Ver inscritos', 'toolsbox-registros' ) ),
			'export'  => array( 'tbreg_export_entries', __( 'Exportar', 'toolsbox-registros' ) ),
		) as $action => $details ) {
			if ( current_user_can( $details[0] ) ) {
				$url                = add_query_arg(
					array(
						'page'    => 'toolsbox-registros-' . $action,
						'form_id' => $item->id(),
					),
					admin_url( 'admin.php' )
				);
				$actions[ $action ] = '<a href="' . esc_url( $url ) . '">' . esc_html( $details[1] ) . '</a>';
			}
		}
		return '<strong><a class="row-title" href="' . esc_url( $edit ) . '">' . esc_html( $item->get( 'title' ) ) . '</a></strong>' . $this->row_actions( $actions );
	}

	/**
	 * Valores escapados del resto de columnas.
	 *
	 * @param mixed  $item Formulario.
	 * @param string $column_name Columna solicitada.
	 * @return string
	 */
	public function column_default( $item, $column_name ): string {
		if ( 'shortcode' === $column_name ) {
			$shortcode = '[toolsbox_registro id="' . $item->id() . '"]';
			return '<input class="tbreg-shortcode" readonly aria-label="' . esc_attr__( 'Shortcode del formulario', 'toolsbox-registros' ) . '" value="' . esc_attr( $shortcode ) . '"> <button type="button" class="button" data-tbreg-copy="' . esc_attr( $shortcode ) . '">' . esc_html__( 'Copiar', 'toolsbox-registros' ) . '</button>';
		}
		if ( 'status' === $column_name ) {
			return esc_html( self::form_statuses()[ $item->get( 'status' ) ] );
		}
		$settings = $item->settings();
		if ( 'entries' === $column_name ) {
			return esc_html( $item->get( 'entries_count' ) . ' / ' . ( $settings['capacity'] > 0 ? $settings['capacity'] : __( 'Ilimitado', 'toolsbox-registros' ) ) );
		}
		if ( 'dates' === $column_name ) {
			return esc_html( '' !== $settings['opens_at'] ? $settings['opens_at'] : '—' ) . '<br>' . esc_html( '' !== $settings['closes_at'] ? $settings['closes_at'] : '—' );
		}
		return '';
	}

	/**
	 * Mensaje sin resultados.
	 *
	 * @return void
	 */
	public function no_items(): void {
		esc_html_e( 'No se encontraron formularios.', 'toolsbox-registros' );
	}
}
