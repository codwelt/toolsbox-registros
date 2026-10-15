<?php
/**
 * Menús, assets y acciones del administrador.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);

namespace Codwelt\ToolsBoxRegistros\Admin;

use Codwelt\ToolsBoxRegistros\Domain\Form;
use Codwelt\ToolsBoxRegistros\Repository\FormRepository;
use Codwelt\ToolsBoxRegistros\Repository\EntryRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registra exclusivamente las pantallas y handlers propios.
 */
final class Menu {
	/**
	 * Repositorio compartido.
	 *
	 * @var FormRepository
	 */
	private FormRepository $forms;

	/**
	 * Editor de formularios.
	 *
	 * @var FormEditorPage
	 */
	private FormEditorPage $editor;

	/**
	 * Listado y acciones de inscripciones.
	 *
	 * @var EntriesPage
	 */
	private EntriesPage $entries_page;

	/**
	 * Detalle y edición de inscripciones.
	 *
	 * @var EntryDetailPage
	 */
	private EntryDetailPage $entry_detail;

	/**
	 * Configuración y descarga de exportaciones.
	 *
	 * @var ExportPage
	 */
	private ExportPage $export_page;

	/**
	 * Hooks autorizados para assets.
	 *
	 * @var array<string>
	 */
	private array $screens = array();

	/**
	 * Inicializa los servicios.
	 *
	 * @param FormRepository|null  $forms Repositorio opcional.
	 * @param EntryRepository|null $entries Inscripciones opcionales.
	 */
	public function __construct( ?FormRepository $forms = null, ?EntryRepository $entries = null ) {
		$this->forms        = $forms ?? new FormRepository();
		$this->editor       = new FormEditorPage( $this->forms, $entries );
		$this->entries_page = new EntriesPage( $entries, $this->forms );
		$this->entry_detail = new EntryDetailPage( $entries, $this->forms );
		$this->export_page  = new ExportPage( $this->forms, $entries );
	}

	/**
	 * Conecta WordPress con los servicios.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_tbreg_save_form', array( $this->editor, 'handle_save' ) );
		add_action( 'admin_post_tbreg_preview_form', array( new FormPreviewPage( $this->forms ), 'handle' ) );
		add_action( 'admin_post_tbreg_form_action', array( $this, 'handle_action' ) );
		add_action( 'admin_post_tbreg_entries_action', array( $this->entries_page, 'handle_action' ) );
		add_action( 'admin_post_tbreg_export_entries', array( $this->export_page, 'handle_download' ) );
		add_action( 'admin_post_tbreg_save_entry', array( $this->entry_detail, 'handle_save' ) );
		add_filter( 'set-screen-option', array( EntriesPage::class, 'screen_option' ), 10, 3 );
		add_action( 'admin_post_tbreg_save_captcha', array( new SettingsPage(), 'handle_save' ) );
	}

	/**
	 * Rechaza incluso el acceso directo a una pantalla.
	 *
	 * @param string $capability Capacidad requerida.
	 * @return void
	 */
	public static function require_access( string $capability ): void {
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'No tienes permiso para acceder a esta pantalla.', 'toolsbox-registros' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Define la navegación del plugin y las rutas del editor.
	 *
	 * @return void
	 */
	public function menu(): void {
		$primary_cap     = current_user_can( 'tbreg_manage_forms' ) ? 'tbreg_manage_forms' : ( current_user_can( 'tbreg_view_entries' ) ? 'tbreg_view_entries' : 'tbreg_export_entries' );
		$primary_render  = current_user_can( 'tbreg_manage_forms' ) ? 'render_forms' : ( current_user_can( 'tbreg_view_entries' ) ? 'render_entries' : 'render_export' );
		$primary_hook    = add_menu_page( __( 'ToolsBox Registros', 'toolsbox-registros' ), __( 'ToolsBox Registros', 'toolsbox-registros' ), $primary_cap, 'toolsbox-registros', array( $this, $primary_render ), 'dashicons-clipboard' );
		$this->screens[] = $primary_hook;
		if ( 'render_entries' === $primary_render ) {
			add_action( 'load-' . $primary_hook, array( $this->entries_page, 'load' ) );
		}
		$this->screens[] = add_submenu_page( 'toolsbox-registros', __( 'Formularios', 'toolsbox-registros' ), __( 'Formularios', 'toolsbox-registros' ), 'tbreg_manage_forms', 'toolsbox-registros', array( $this, 'render_forms' ) );
		$entries_hook    = add_submenu_page( 'toolsbox-registros', __( 'Inscripciones', 'toolsbox-registros' ), __( 'Inscripciones', 'toolsbox-registros' ), 'tbreg_view_entries', 'toolsbox-registros-entries', array( $this, 'render_entries' ) );
		$this->screens[] = $entries_hook;
		add_action( 'load-' . $entries_hook, array( $this->entries_page, 'load' ) );
		$detail_hook     = add_submenu_page( 'toolsbox-registros', __( 'Detalle de inscripción', 'toolsbox-registros' ), __( 'Detalle de inscripción', 'toolsbox-registros' ), 'tbreg_view_entries', 'toolsbox-registros-entry', array( $this->entry_detail, 'render' ) );
		$this->screens[] = $detail_hook;
		remove_submenu_page( 'toolsbox-registros', 'toolsbox-registros-entry' );
		$this->screens[] = add_submenu_page( 'toolsbox-registros', __( 'Ajustes', 'toolsbox-registros' ), __( 'Ajustes', 'toolsbox-registros' ), 'tbreg_manage_forms', 'toolsbox-registros-settings', array( $this, 'render_settings' ) );
		add_submenu_page( 'toolsbox-registros', __( 'Acerca de / Más herramientas', 'toolsbox-registros' ), __( 'Acerca de / Más herramientas', 'toolsbox-registros' ), 'tbreg_manage_forms', 'https://codwelt.com/toolsbox-herramientas-digitales-gratis/' );
		$this->screens[] = add_submenu_page( 'toolsbox-registros', __( 'Editar formulario', 'toolsbox-registros' ), __( 'Crear formulario', 'toolsbox-registros' ), 'tbreg_manage_forms', 'toolsbox-registros-edit', array( $this->editor, 'render' ) );
		$this->screens[] = add_submenu_page( 'toolsbox-registros', __( 'Exportar', 'toolsbox-registros' ), __( 'Exportar', 'toolsbox-registros' ), 'tbreg_export_entries', 'toolsbox-registros-export', array( $this, 'render_export' ) );
	}

	/**
	 * Valida una acción de fila y devuelve su resultado.
	 *
	 * @param string $operation Acción solicitada.
	 * @param int    $id Formulario seleccionado.
	 * @param string $nonce Nonce específico de acción y formulario.
	 * @return Form|bool|\WP_Error
	 */
	public function perform_action( string $operation, int $id, string $nonce ): Form|bool|\WP_Error {
		if ( ! current_user_can( 'tbreg_manage_forms' ) ) {
			return new \WP_Error( 'tbreg_forbidden', __( 'No tienes permiso para administrar formularios.', 'toolsbox-registros' ) );
		}
		if ( ! in_array( $operation, array( 'duplicate', 'close', 'trash', 'restore' ), true ) || $id < 1 || ! wp_verify_nonce( $nonce, 'tbreg_form_action_' . $operation . '_' . $id ) ) {
			return new \WP_Error( 'tbreg_invalid_action', __( 'La acción no es válida o ha caducado.', 'toolsbox-registros' ) );
		}
		try {
			$form = $this->forms->find( $id );
			if ( null === $form ) {
				return new \WP_Error( 'tbreg_not_found', __( 'El formulario no existe.', 'toolsbox-registros' ) );
			}
			if ( 'duplicate' === $operation ) {
				return $this->forms->duplicate( $id );
			}
			$data           = $form->toArray();
			$data['status'] = array(
				'close'   => 'closed',
				'trash'   => 'trash',
				'restore' => 'draft',
			)[ $operation ];
			return $this->forms->save( Form::fromArray( $data ), $form->revision() );
		} catch ( \RuntimeException $exception ) {
			return new \WP_Error( 'tbreg_db_error', __( 'No se pudo completar la acción.', 'toolsbox-registros' ) );
		}
	}

	/**
	 * Ejecuta una acción autenticada y aplica PRG.
	 *
	 * @return void
	 */
	public function handle_action(): void {
		self::require_access( 'tbreg_manage_forms' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Nonce específico verificado a continuación y en perform_action().
		$operation = isset( $_GET['operation'] ) && is_string( $_GET['operation'] ) ? sanitize_key( $_GET['operation'] ) : '';
		$id        = isset( $_GET['id'] ) && is_string( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$nonce     = isset( $_GET['_wpnonce'] ) && is_string( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		check_admin_referer( 'tbreg_form_action_' . $operation . '_' . $id );
		$result = $this->perform_action( $operation, $id, $nonce );
		$token  = Notices::put( is_wp_error( $result ) ? $result->get_error_message() : __( 'Acción completada.', 'toolsbox-registros' ), is_wp_error( $result ) );
		$args   = array(
			'page'         => 'toolsbox-registros',
			'tbreg_notice' => $token,
		);
		if ( ! is_wp_error( $result ) && $result instanceof Form && 'duplicate' === $operation ) {
			$args['page'] = 'toolsbox-registros-edit';
			$args['id']   = $result->id();
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Lista y búsqueda de formularios.
	 *
	 * @return void
	 */
	public function render_forms(): void {
		self::require_access( 'tbreg_manage_forms' );
		$table = new FormsListTable( $this->forms );
		$table->prepare_items();
		?>
		<div class="wrap tbreg-admin"><h1 class="wp-heading-inline"><?php esc_html_e( 'Formularios', 'toolsbox-registros' ); ?></h1>
		<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=toolsbox-registros-edit' ) ); ?>"><?php esc_html_e( 'Crear formulario', 'toolsbox-registros' ); ?></a><hr class="wp-header-end">
		<?php Notices::render(); ?>
		<form method="get"><input type="hidden" name="page" value="toolsbox-registros">
		<label for="tbreg-status"><?php esc_html_e( 'Estado', 'toolsbox-registros' ); ?></label>
		<select id="tbreg-status" name="status"><option value=""><?php esc_html_e( 'Todos (sin papelera)', 'toolsbox-registros' ); ?></option>
		<?php
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Filtro de lectura.
		$status = isset( $_GET['status'] ) && is_string( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
		foreach ( FormsListTable::form_statuses() as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $status, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		?>
		</select><?php submit_button( __( 'Filtrar', 'toolsbox-registros' ), 'secondary', '', false ); ?>
		<?php
		$table->search_box( __( 'Buscar formularios', 'toolsbox-registros' ), 'tbreg-search' );
		$table->display();
		?>
		</form><p id="tbreg-copy-status" role="status" aria-live="polite"></p></div>
		<?php
	}

	/**
	 * Pantalla reservada a la fase de inscripciones.
	 *
	 * @return void
	 */
	public function render_entries(): void {
		$this->entries_page->render();
	}

	/**
	 * Pantalla reservada a ajustes globales.
	 *
	 * @return void
	 */
	public function render_settings(): void {
		( new SettingsPage() )->render();
	}

	/**
	 * Pantalla reservada a la fase de exportación.
	 *
	 * @return void
	 */
	public function render_export(): void {
		$this->export_page->render();
	}

	/**
	 * Carga assets solo en pantallas propias.
	 *
	 * @param string $hook Pantalla actual.
	 * @return void
	 */
	public function assets( string $hook ): void {
		if ( ! in_array( $hook, $this->screens, true ) ) {
			return;
		}
		wp_enqueue_style( 'tbreg-admin', TBREG_URL . 'assets/admin/css/admin.css', array(), TBREG_VERSION );
		$editing = str_ends_with( $hook, '_page_toolsbox-registros-edit' );
		if ( str_ends_with( $hook, '_page_toolsbox-registros-entry' ) ) {
			wp_enqueue_style( 'tbreg-form', TBREG_URL . 'assets/public/css/form.css', array(), TBREG_VERSION );
		}
		if ( str_ends_with( $hook, '_page_toolsbox-registros-entry' ) || str_ends_with( $hook, '_page_toolsbox-registros-entries' ) || str_ends_with( $hook, '_page_toolsbox-registros-export' ) ) {
			return;
		}
		if ( ! current_user_can( 'tbreg_manage_forms' ) ) {
			return;
		}
		wp_enqueue_script( 'tbreg-builder', TBREG_URL . 'assets/admin/js/builder.js', $editing ? array( 'jquery-ui-sortable' ) : array(), TBREG_VERSION, true );
		$context = $editing ? $this->editor->context() : array();
		wp_localize_script(
			'tbreg-builder',
			'tbreg_builder',
			array(
				'types'     => Templates::types(),
				'templates' => $editing ? Templates::all() : array(),
				'fields'    => $context['data']['fields'] ?? array(),
				'used'      => $context['used'] ?? array(),
				'labels'    => $this->labels(),
			)
		);
	}

	/**
	 * Textos traducibles del constructor sin build.
	 *
	 * @return array<string,string>
	 */
	private function labels(): array {
		return array(
			'label'           => __( 'Etiqueta', 'toolsbox-registros' ),
			'type'            => __( 'Tipo', 'toolsbox-registros' ),
			'placeholder'     => __( 'Placeholder', 'toolsbox-registros' ),
			'help'            => __( 'Ayuda', 'toolsbox-registros' ),
			'required'        => __( 'Obligatorio', 'toolsbox-registros' ),
			'width'           => __( 'Ancho', 'toolsbox-registros' ),
			'full'            => __( 'Completo', 'toolsbox-registros' ),
			'half'            => __( 'Mitad', 'toolsbox-registros' ),
			'third'           => __( 'Tercio', 'toolsbox-registros' ),
			'role'            => __( 'Rol principal', 'toolsbox-registros' ),
			'none'            => __( 'Ninguno', 'toolsbox-registros' ),
			'email'           => __( 'Correo', 'toolsbox-registros' ),
			'document'        => __( 'Documento', 'toolsbox-registros' ),
			'name'            => __( 'Nombre', 'toolsbox-registros' ),
			'up'              => __( 'Subir', 'toolsbox-registros' ),
			'down'            => __( 'Bajar', 'toolsbox-registros' ),
			'remove'          => __( 'Eliminar', 'toolsbox-registros' ),
			'drag'            => __( 'Arrastrar para ordenar', 'toolsbox-registros' ),
			'options'         => __( 'Opciones', 'toolsbox-registros' ),
			'value'           => __( 'Valor guardado', 'toolsbox-registros' ),
			'condition'       => __( 'Disponible cuando', 'toolsbox-registros' ),
			'conditionValues' => __( 'Opciones permitidas', 'toolsbox-registros' ),
			'addCondition'    => __( 'Agregar condición', 'toolsbox-registros' ),
			'conditionsHelp'  => __( 'Todas las condiciones deben cumplirse. Marca una o más opciones del campo del que depende.', 'toolsbox-registros' ),
			'addOption'       => __( 'Agregar opción', 'toolsbox-registros' ),
			'default'         => __( 'Valor predeterminado', 'toolsbox-registros' ),
			'multipleDefault' => __( 'Valores predeterminados separados por coma', 'toolsbox-registros' ),
			'min'             => __( 'Mínimo', 'toolsbox-registros' ),
			'max'             => __( 'Máximo', 'toolsbox-registros' ),
			'minlength'       => __( 'Longitud mínima', 'toolsbox-registros' ),
			'maxlength'       => __( 'Longitud máxima', 'toolsbox-registros' ),
			'pattern'         => __( 'Patrón de validación (expresión regular)', 'toolsbox-registros' ),
			'css_class'       => __( 'Clases CSS', 'toolsbox-registros' ),
			'archiveWarning'  => __( 'Este campo tiene inscripciones. Se ocultará, pero sus valores se conservarán. ¿Continuar?', 'toolsbox-registros' ),
			'lockedType'      => __( 'El tipo no puede cambiar porque el campo tiene datos.', 'toolsbox-registros' ),
			'optionWarning'   => __( 'Cambiar un valor guardado no modifica las inscripciones anteriores. Conserva los valores para mantener su correspondencia.', 'toolsbox-registros' ),
			'templateWarning' => __( 'La plantilla reemplazará los campos activos. Los campos con datos se archivarán. ¿Continuar?', 'toolsbox-registros' ),
			'invalid'         => __( 'Revisa las etiquetas, claves, roles y opciones: no pueden estar vacíos ni repetir valores. Comprueba también los valores predeterminados y los límites.', 'toolsbox-registros' ),
			'changed'         => __( 'Cambios sin guardar.', 'toolsbox-registros' ),
			'saving'          => __( 'Guardando formulario…', 'toolsbox-registros' ),
			'noDate'          => __( 'Sin fecha', 'toolsbox-registros' ),
			'copied'          => __( 'Shortcode copiado.', 'toolsbox-registros' ),
			'copyManual'      => __( 'Selecciona el shortcode y cópialo con el teclado.', 'toolsbox-registros' ),
			'slugAutomatic'   => __( 'Se genera al guardar', 'toolsbox-registros' ),
			'newOption'       => __( 'Nueva opción', 'toolsbox-registros' ),
			'key'             => __( 'Identificador permanente', 'toolsbox-registros' ),
			'queryParam'      => __( 'Parámetro de URL (opcional, por ejemplo utm_source)', 'toolsbox-registros' ),
			'archived'        => __( 'Archivado', 'toolsbox-registros' ),
		);
	}
}
