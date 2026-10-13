<?php
/**
 * Inscripciones con columnas históricas, filtros y consultas por lote.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Admin;

use Codwelt\ToolsBoxRegistros\Domain\Form;
use Codwelt\ToolsBoxRegistros\Domain\FieldTypeRegistry;
use Codwelt\ToolsBoxRegistros\Repository\EntryRepository;
use Codwelt\ToolsBoxRegistros\Repository\FormRepository;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}
/** Integra ocultación de columnas y paginación nativas de WordPress. */
final class EntriesListTable extends \WP_List_Table {
	/**
	 * Repositorio de inscripciones.
	 *
	 * @var EntryRepository
	 */
	private EntryRepository $entries;
	/**
	 * Repositorio de formularios.
	 *
	 * @var FormRepository
	 */
	private FormRepository $forms;
	/**
	 * Formulario del filtro.
	 *
	 * @var Form|null
	 */
	private ?Form $form = null;
	/**
	 * Formularios de la página.
	 *
	 * @var array<int,Form>
	 */
	private array $page_forms = array();
	/**
	 * Valores de la página.
	 *
	 * @var array<int,array>
	 */
	private array $values = array();
	/**
	 * Filtros válidos.
	 *
	 * @var array<string,mixed>
	 */
	private array $filters;
	/**
	 * Error de filtro.
	 *
	 * @var \WP_Error|null
	 */
	public ?\WP_Error $error = null;
	/**
	 * Inicializa tabla y filtros sin mutaciones.
	 *
	 * @param EntryRepository|null     $entries Repositorio opcional.
	 * @param FormRepository|null      $forms Repositorio opcional.
	 * @param array<string,mixed>|null $input Filtros sin slashes.
	 */
	public function __construct( ?EntryRepository $entries = null, ?FormRepository $forms = null, ?array $input = null ) {
		parent::__construct(
			array(
				'singular' => 'tbreg_entry',
				'plural'   => 'tbreg_entries',
				'ajax'     => false,
				'screen'   => get_current_screen(),
			)
		);
		$this->entries = $entries ?? new EntryRepository();
		$this->forms   = $forms ?? new FormRepository();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Filtros de solo lectura validados por parse().
		$parsed        = EntriesFilters::parse( $input ?? wp_unslash( $_GET ) );
		$this->error   = is_wp_error( $parsed ) ? $parsed : null;
		$this->filters = is_wp_error( $parsed ) ? array() : $parsed;
		if ( isset( $this->filters['form_id'] ) ) {
			$this->form = $this->forms->find( $this->filters['form_id'] );
			if ( null === $this->form ) {
				$this->error = new \WP_Error( 'tbreg_not_found', __( 'El formulario seleccionado ya no existe.', 'toolsbox-registros' ) );
			}
		}
	}
	/**
	 * Filtros normalizados para controles y retorno.
	 *
	 * @return array<string,mixed>
	 */
	public function filters(): array {
		return $this->filters;
	}
	/**
	 * Define todas las columnas; muestra cuatro campos inicialmente.
	 *
	 * @return array<string,string>
	 */
	public function get_columns(): array {
		$columns = array();
		if ( current_user_can( 'tbreg_edit_entries' ) || current_user_can( 'tbreg_export_entries' ) ) {
			$columns['cb'] = '<input type="checkbox">';
		}
		$columns['id'] = __( 'ID', 'toolsbox-registros' );
		if ( null !== $this->form ) {
			foreach ( $this->form->fields() as $field ) {
				if ( 'heading' !== $field->get( 'type' ) ) {
					$columns[ 'field_' . $field->key() ] = $field->get( 'label' ) . ( $field->get( 'archived' ) ? __( ' (eliminado)', 'toolsbox-registros' ) : '' );
				}
			}
		} else {
			$columns['form']  = __( 'Formulario', 'toolsbox-registros' );
			$columns['name']  = __( 'Nombre', 'toolsbox-registros' );
			$columns['email'] = __( 'Correo', 'toolsbox-registros' );
		}
		$columns['created_at'] = __( 'Fecha de inscripción', 'toolsbox-registros' );
		$columns['status']     = __( 'Estado', 'toolsbox-registros' );
		return $columns;
	}
	/**
	 * Columnas fuera de los cuatro primeros campos activos.
	 *
	 * @return array<string>
	 */
	public function default_hidden(): array {
		$hidden = array();
		$shown  = 0;
		if ( null !== $this->form ) {
			foreach ( $this->form->fields() as $field ) {
				if ( 'heading' === $field->get( 'type' ) ) {
					continue;
				}
				if ( $field->get( 'archived' ) || in_array( $field->get( 'type' ), array( 'hidden', 'consent' ), true ) || $shown >= 4 ) {
					$hidden[] = 'field_' . $field->key();
				} else {
					++$shown;
				}
			}
		}
		return $hidden;
	}
	/**
	 * Orden de columnas desnormalizadas.
	 *
	 * @return array<string,array>
	 */
	protected function get_sortable_columns(): array {
		return array(
			'id'         => array( 'id', true ),
			'email'      => array( 'email', false ),
			'created_at' => array( 'created_at', true ),
			'status'     => array( 'status', false ),
		);
	}
	/**
	 * Acciones según las capacidades de quien consulta.
	 *
	 * @return array<string,string>
	 */
	protected function get_bulk_actions(): array {
		$actions = array();
		if ( current_user_can( 'tbreg_edit_entries' ) ) {
			foreach ( array( 'confirmed', 'attended', 'cancelled' ) as $status ) {
				$actions[ $status ] = FormsListTable::entry_statuses()[ $status ];
			}
			$actions['delete'] = __( 'Eliminar', 'toolsbox-registros' );
		}
		if ( current_user_can( 'tbreg_export_entries' ) && null !== $this->form ) {
			$actions['export'] = __( 'Exportar seleccionados', 'toolsbox-registros' );
		}
		return $actions;
	}
	/**
	 * Usa nombres distintos del action de admin-post.php.
	 *
	 * @param string $which Posición top/bottom.
	 * @return void
	 */
	protected function bulk_actions( $which = '' ): void {
		$actions = $this->get_bulk_actions();
		if ( array() === $actions ) {
			return;
		}
		$name = 'bottom' === $which ? 'operation2' : 'operation';
		echo '<label class="screen-reader-text" for="tbreg-' . esc_attr( $name ) . '">' . esc_html__( 'Acciones en lote', 'toolsbox-registros' ) . '</label><select name="' . esc_attr( $name ) . '" id="tbreg-' . esc_attr( $name ) . '"><option value="">' . esc_html__( 'Acciones en lote', 'toolsbox-registros' ) . '</option>';
		foreach ( $actions as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		submit_button( __( 'Aplicar', 'toolsbox-registros' ), 'action', 'tbreg_apply_' . $which, false );
	}

	/**
	 * Carga una página y sus valores sin consultas por fila.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		Menu::require_access( 'tbreg_view_entries' );
		$preference = (int) get_user_option( 'tbreg_entries_per_page' );
		$per_page   = $preference > 0 ? min( 100, $preference ) : 20;
		$filters    = array_merge(
			$this->filters,
			array(
				'per_page' => $per_page,
				'page'     => $this->get_pagenum(),
			)
		);
		try {
			$result           = null === $this->error ? $this->entries->paginate( $filters ) : array(
				'items' => array(),
				'total' => 0,
			);
			$this->items      = $result['items'];
			$this->page_forms = $this->forms->findMany( array_map( 'intval', array_column( $this->items, 'form_id' ) ) );
			$this->values     = $this->entries->valuesForEntries( $this->items, $this->page_forms );
		} catch ( \RuntimeException $exception ) {
			$this->error = new \WP_Error( 'tbreg_db_error', __( 'No se pudo cargar el listado.', 'toolsbox-registros' ) );
			$this->items = array();
			$result      = array( 'total' => 0 );
		}
		$this->_column_headers = array( $this->get_columns(), get_hidden_columns( $this->screen ), $this->get_sortable_columns(), 'id' );
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
			)
		);
	}
	/**
	 * Selección de inscripción por ID.
	 *
	 * @param mixed $item Inscripción.
	 * @return string
	 */
	public function column_cb( $item ): string {
		/* translators: %d: ID de la inscripción. */
		return '<input type="checkbox" name="tbreg_ids[]" value="' . esc_attr( (string) $item['id'] ) . '" aria-label="' . esc_attr( sprintf( __( 'Seleccionar inscripción %d', 'toolsbox-registros' ), $item['id'] ) ) . '">';
	}
	/**
	 * Identificador y acceso al detalle.
	 *
	 * @param mixed $item Inscripción.
	 * @return string
	 */
	public function column_id( $item ): string {
		$url = add_query_arg(
			array(
				'page' => 'toolsbox-registros-entry',
				'id'   => (int) $item['id'],
			),
			admin_url( 'admin.php' )
		);
		return '<strong><a class="row-title" href="' . esc_url( $url ) . '">#' . esc_html( (string) $item['id'] ) . '</a></strong><span class="tbreg-mobile-name">' . $this->column_default( $item, 'name' ) . '</span>' . $this->row_actions( array( 'view' => '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Ver detalle', 'toolsbox-registros' ) . '</a>' ) );
	}
	/**
	 * La función row_actions() ya incluye el botón responsive; evita duplicarlo.
	 *
	 * @param mixed  $item Inscripción.
	 * @param string $column_name Columna.
	 * @param string $primary Columna principal.
	 * @return string
	 */
	protected function handle_row_actions( $item, $column_name, $primary ): string {
		return ''; }

	/**
	 * Datos escapados con labels visibles y fechas del sitio.
	 *
	 * @param mixed $item Inscripción.
	 * @param mixed $column_name Columna interna.
	 * @return string
	 */
	public function column_default( $item, $column_name ): string {
		$form   = $this->page_forms[ (int) $item['form_id'] ] ?? null;
		$values = $this->values[ (int) $item['id'] ] ?? array();
		if ( 'form' === $column_name ) {
			return esc_html( null !== $form ? $form->get( 'title' ) : __( 'Formulario eliminado', 'toolsbox-registros' ) );
		}
		if ( 'name' === $column_name && null !== $form ) {
			$name = '';
			foreach ( $form->fields() as $field ) {
				if ( 'name' === $field->get( 'role' ) && isset( $values[ $field->key() ] ) ) {
							$name = (string) $values[ $field->key() ];
					if ( ! $field->get( 'archived' ) ) {
						break;
					}
				}
			}
			return esc_html( $name );
		}
		if ( 'email' === $column_name ) {
			return esc_html( (string) $item['email'] );
		}
		if ( 'status' === $column_name ) {
			return esc_html( FormsListTable::entry_statuses()[ $item['status'] ] ?? $item['status'] );
		}
		if ( 'created_at' === $column_name ) {
			return esc_html( EntriesFilters::display_date( $item['created_at'] ) );
		}
		if ( str_starts_with( $column_name, 'field_' ) && null !== $form ) {
			foreach ( $form->fields() as $field ) {
				if ( 'field_' . $field->key() === $column_name ) {
								return esc_html( ( new FieldTypeRegistry() )->get( $field->get( 'type' ) )->toExport( $values[ $field->key() ] ?? '', $field ) );
				}
			}
		}
		return '—';
	}
	/**
	 * Mensaje de tabla vacía.
	 *
	 * @return void
	 */
	public function no_items(): void {
		esc_html_e( 'No hay inscripciones para estos filtros.', 'toolsbox-registros' );
	}
}
