<?php
/**
 * Listado, opciones de pantalla y operaciones administrativas.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Admin;

use Codwelt\ToolsBoxRegistros\Repository\EntryRepository;
use Codwelt\ToolsBoxRegistros\Repository\FormRepository;
use Codwelt\ToolsBoxRegistros\Services\TransientStore;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Verifica autorización antes de leer selecciones o modificar registros. */
final class EntriesPage {
	/**
	 * Inscripciones.
	 *
	 * @var EntryRepository
	 */
	private EntryRepository $entries;
	/**
	 * Formularios.
	 *
	 * @var FormRepository
	 */
	private FormRepository $forms;
	/**
	 * Tabla actual.
	 *
	 * @var EntriesListTable|null
	 */
	private ?EntriesListTable $table = null;
	/**
	 * Inicializa los repositorios.
	 *
	 * @param EntryRepository|null $entries Inscripciones opcionales.
	 * @param FormRepository|null  $forms Formularios opcionales.
	 */
	public function __construct( ?EntryRepository $entries = null, ?FormRepository $forms = null ) {
		$this->entries = $entries ?? new EntryRepository();
		$this->forms   = $forms ?? new FormRepository();
	}
	/**
	 * Configura columnas antes de que WordPress renderice Opciones de pantalla.
	 *
	 * @return void
	 */
	public function load(): void {
		Menu::require_access( 'tbreg_view_entries' );
		$this->table = new EntriesListTable( $this->entries, $this->forms );
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Inscripciones por página', 'toolsbox-registros' ),
				'default' => 20,
				'option'  => 'tbreg_entries_per_page',
			)
		);
		$screen = get_current_screen();
		if ( null !== $screen ) {
			add_filter( 'manage_' . $screen->id . '_columns', array( $this->table, 'get_columns' ) );
		}
		add_filter( 'default_hidden_columns', array( $this, 'hidden_columns' ), 10, 2 );
	}
	/**
	 * Oculta campos adicionales solo en esta pantalla.
	 *
	 * @param array<string> $hidden Columnas de WordPress.
	 * @param \WP_Screen    $screen Pantalla.
	 * @return array<string>
	 */
	public function hidden_columns( array $hidden, \WP_Screen $screen ): array {
		return ( str_ends_with( $screen->id, '_page_toolsbox-registros-entries' ) || 'toplevel_page_toolsbox-registros' === $screen->id ) && null !== $this->table ? $this->table->default_hidden() : $hidden;
	}
	/**
	 * Guarda un límite seguro en las opciones de usuario nativas.
	 *
	 * @param mixed  $status Valor anterior.
	 * @param string $option Opción.
	 * @param mixed  $value Cantidad.
	 * @return mixed
	 */
	public static function screen_option( mixed $status, string $option, mixed $value ): mixed {
		return 'tbreg_entries_per_page' === $option && current_user_can( 'tbreg_view_entries' ) ? min( 100, max( 1, (int) $value ) ) : $status;
	}
	/**
	 * Renderiza filtros de lectura separados del POST de acciones.
	 *
	 * @return void
	 */
	public function render(): void {
		Menu::require_access( 'tbreg_view_entries' );
		$table = $this->table ?? new EntriesListTable( $this->entries, $this->forms );
		$table->prepare_items();
		$filters = $table->filters();
		?>
		<div class="wrap tbreg-admin tbreg-entries"><h1><?php esc_html_e( 'Inscripciones', 'toolsbox-registros' ); ?></h1>
		<?php
		Notices::render();
		if ( null !== $table->error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $table->error->get_error_message() ) . '</p></div>';}
		?>
		<?php
		if ( current_user_can( 'tbreg_export_entries' ) && null === $table->error && isset( $filters['form_id'] ) ) :
			?>
			<p><a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'toolsbox-registros-export' ), EntriesFilters::list_url( $filters ) ) ); ?>"><?php esc_html_e( 'Exportar este formulario', 'toolsbox-registros' ); ?></a></p><?php endif; ?>
		<form method="get" class="tbreg-entry-filters"><input type="hidden" name="page" value="toolsbox-registros-entries">
		<label for="tbreg-form-filter"><?php esc_html_e( 'Formulario', 'toolsbox-registros' ); ?></label>
		<select id="tbreg-form-filter" name="form_id"><option value="0"><?php esc_html_e( 'Todos los formularios', 'toolsbox-registros' ); ?></option>
		<?php
		foreach ( $this->forms->summaries() as $form ) :
			?>
			<option value="<?php echo esc_attr( (string) $form['id'] ); ?>" <?php selected( $filters['form_id'] ?? 0, $form['id'] ); ?>><?php echo esc_html( $form['title'] . ( 'trash' === $form['status'] ? __( ' (papelera)', 'toolsbox-registros' ) : '' ) ); ?></option><?php endforeach; ?></select>
		<label for="tbreg-entry-status"><?php esc_html_e( 'Estado', 'toolsbox-registros' ); ?></label><select id="tbreg-entry-status" name="status"><option value=""><?php esc_html_e( 'Todos los estados', 'toolsbox-registros' ); ?></option>
		<?php
		foreach ( FormsListTable::entry_statuses() as $key => $label ) :
			?>
			<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['status'] ?? '', $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select>
		<?php
		foreach ( array(
			'date_from' => __( 'Desde', 'toolsbox-registros' ),
			'date_to'   => __( 'Hasta', 'toolsbox-registros' ),
		) as $key => $label ) :
			?>
		<label for="tbreg-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label><input type="date" id="tbreg-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $filters[ 'local_' . $key ] ?? '' ); ?>">
		<?php endforeach; ?>
		<label class="screen-reader-text" for="tbreg-entry-search"><?php esc_html_e( 'Buscar inscritos', 'toolsbox-registros' ); ?></label><input type="search" id="tbreg-entry-search" name="s" maxlength="190" value="<?php echo esc_attr( $filters['search'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Nombre, correo o documento', 'toolsbox-registros' ); ?>">
		<?php submit_button( __( 'Filtrar', 'toolsbox-registros' ), 'secondary', '', false ); ?>
		<a href="<?php echo esc_url( EntriesFilters::list_url() ); ?>"><?php esc_html_e( 'Limpiar filtros', 'toolsbox-registros' ); ?></a></form>
		<p class="description"><?php /* translators: %s: zona horaria del sitio. */ echo esc_html( sprintf( __( 'Las fechas y horas se muestran en la zona del sitio: %s. Selecciona un formulario para ver sus campos y elegir columnas en Opciones de pantalla.', 'toolsbox-registros' ), wp_timezone_string() ) ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="tbreg_entries_action">
		<?php
		wp_nonce_field( 'tbreg_entries_action', 'tbreg_nonce' );
		self::filter_inputs( $filters );
		$table->display();
		?>
		</form></div>
		<?php
	}
	/**
	 * Mantiene únicamente filtros ya validados durante PRG.
	 *
	 * @param array<string,mixed> $filters Filtros.
	 * @return void
	 */
	private static function filter_inputs( array $filters ): void {
		$input = array(
			'form_id'   => $filters['form_id'] ?? 0,
			'status'    => $filters['status'] ?? '',
			's'         => $filters['search'] ?? '',
			'date_from' => $filters['local_date_from'] ?? '',
			'date_to'   => $filters['local_date_to'] ?? '',
		);
		foreach ( $input as $key => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '">';
		}
	}
	/**
	 * Valida la selección entera antes de efectuar cualquier cambio.
	 *
	 * @param array<string,mixed> $input Petición sin slashes.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function authorize( array $input ): array|\WP_Error {
		if ( ! current_user_can( 'tbreg_view_entries' ) ) {
			return new \WP_Error( 'tbreg_forbidden', __( 'No tienes permiso para consultar inscripciones.', 'toolsbox-registros' ) );
		}
		$operation = $input['operation'] ?? '';
		if ( '' === $operation ) {
			$operation = $input['operation2'] ?? '';
		}
		if ( ! is_string( $operation ) || ! in_array( $operation, array( 'confirmed', 'attended', 'cancelled', 'delete', 'export' ), true ) ) {
			return new \WP_Error( 'tbreg_invalid_action', __( 'Selecciona una acción válida.', 'toolsbox-registros' ) );
		}
		if ( ! current_user_can( 'export' === $operation ? 'tbreg_export_entries' : 'tbreg_edit_entries' ) ) {
			return new \WP_Error( 'tbreg_forbidden', __( 'No tienes permiso para realizar esa acción.', 'toolsbox-registros' ) );
		}
		if ( ! is_string( $input['tbreg_nonce'] ?? null ) || ! wp_verify_nonce( $input['tbreg_nonce'], 'tbreg_entries_action' ) ) {
			return new \WP_Error( 'tbreg_invalid_nonce', __( 'La sesión ha caducado. Recarga el listado.', 'toolsbox-registros' ) );
		}
		$filters = EntriesFilters::parse( $input );
		if ( is_wp_error( $filters ) ) {
			return $filters;
		}
		$ids = $input['tbreg_ids'] ?? array();
		if ( ! is_array( $ids ) || array_values( $ids ) !== $ids || array() === $ids || count( $ids ) > 200 ) {
			return new \WP_Error( 'tbreg_invalid_selection', __( 'Selecciona entre 1 y 200 inscripciones.', 'toolsbox-registros' ) );
		}
		foreach ( $ids as $id ) {
			if ( ! is_scalar( $id ) || false === filter_var( $id, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ) ) {
				return new \WP_Error( 'tbreg_invalid_selection', __( 'La selección contiene identificadores no válidos.', 'toolsbox-registros' ) );
			}
		}
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		foreach ( $ids as $id ) {
			$entry = $this->entries->find( $id );
			if ( null === $entry || ( isset( $filters['form_id'] ) && (int) $entry['form_id'] !== $filters['form_id'] ) ) {
				return new \WP_Error( 'tbreg_invalid_selection', __( 'Una inscripción ya no existe o no pertenece al formulario seleccionado.', 'toolsbox-registros' ) );
			}
		}
		return array(
			'operation' => $operation,
			'ids'       => $ids,
			'filters'   => $filters,
		);
	}
	/**
	 * Aplica operaciones atómicas por inscripción y comunica fallos parciales.
	 *
	 * @param array<string,mixed> $input Petición validable.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function perform_action( array $input ): array|\WP_Error {
		$selection = $this->authorize( $input );
		if ( is_wp_error( $selection ) ) {
			return $selection;
		}
		if ( 'export' === $selection['operation'] ) {
			if ( ! isset( $selection['filters']['form_id'] ) ) {
				return new \WP_Error( 'tbreg_invalid_selection', __( 'Filtra por un formulario para exportar la selección.', 'toolsbox-registros' ) );
			}
			$token = bin2hex( random_bytes( 16 ) );
			if ( ! TransientStore::set(
				'tbreg_selection_' . get_current_user_id() . '_' . $token,
				array(
					'form_id' => $selection['filters']['form_id'],
					'ids'     => $selection['ids'],
				),
				600
			) ) {
				return new \WP_Error( 'tbreg_db_error', __( 'No se pudo preparar la selección.', 'toolsbox-registros' ) );
			}
			return array(
				'redirect' => add_query_arg(
					array(
						'page'            => 'toolsbox-registros-export',
						'tbreg_selection' => $token,
					),
					admin_url( 'admin.php' )
				),
			);
		}
		if ( ! $this->entries->supportsTransactions() ) {
			return new \WP_Error( 'tbreg_storage', __( 'Las tablas deben usar InnoDB para modificar inscripciones.', 'toolsbox-registros' ) );
		}
		if ( 'delete' === $selection['operation'] && '1' !== ( $input['tbreg_confirm_delete'] ?? '' ) ) {
			return new \WP_Error( 'tbreg_confirm_delete', __( 'Confirma la eliminación de la selección.', 'toolsbox-registros' ), $selection );
		}
		$changed   = 0;
		$unchanged = 0;
		$errors    = array();
		foreach ( $selection['ids'] as $id ) {
			$result = 'delete' === $selection['operation'] ? $this->entries->delete( $id ) : $this->entries->updateStatus( $id, $selection['operation'] );
			if ( is_wp_error( $result ) ) {
				$errors[] = '#' . $id . ': ' . $result->get_error_message();
			} elseif ( $result ) {
				++$changed;
			} else {
				++$unchanged;
			}
		}
		return array(
			'changed'   => $changed,
			'unchanged' => $unchanged,
			'errors'    => $errors,
			'filters'   => $selection['filters'],
		);
	}
	/**
	 * POST autenticado con confirmación de borrado y redirección local.
	 *
	 * @return void
	 */
	public function handle_action(): void {
		Menu::require_access( 'tbreg_view_entries' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Compara el método con POST; no se guarda ni se muestra.
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'La acción requiere POST.', 'toolsbox-registros' ), '', array( 'response' => 405 ) );
		}
		check_admin_referer( 'tbreg_entries_action', 'tbreg_nonce' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- perform_action valida estructuras, nonce, capacidad, selección y filtros.
		$input  = wp_unslash( $_POST );
		$result = $this->perform_action( $input );
		if ( is_wp_error( $result ) && 'tbreg_confirm_delete' === $result->get_error_code() ) {
			$this->confirm_delete( $result->get_error_data() );
			return;
		}
		if ( ! is_wp_error( $result ) && isset( $result['redirect'] ) ) {
			wp_safe_redirect( $result['redirect'], 303 );
			exit;
		}
		/* translators: 1: registros actualizados; 2: sin cambios; 3: errores. */
		$message = is_wp_error( $result ) ? $result->get_error_message() : sprintf( __( 'Actualizadas: %1$d. Sin cambios: %2$d. Errores: %3$d.', 'toolsbox-registros' ), $result['changed'], $result['unchanged'], count( $result['errors'] ) );
		if ( ! is_wp_error( $result ) && array() !== $result['errors'] ) {
			$message .= ' ' . implode( ' ', $result['errors'] );
		}
		$filters = EntriesFilters::parse( $input );
		$url     = EntriesFilters::list_url( is_wp_error( $filters ) ? array() : $filters );
		$token   = Notices::put( $message, is_wp_error( $result ) || ! empty( $result['errors'] ) );
		wp_safe_redirect( add_query_arg( 'tbreg_notice', $token, $url ), 303 );
		exit;
	}
	/**
	 * Confirma los IDs concretos antes de una eliminación definitiva.
	 *
	 * @param array<string,mixed> $selection Selección ya autorizada.
	 * @return void
	 */
	private function confirm_delete( array $selection ): void {
		ob_start();
		?>
		<h1><?php esc_html_e( 'Eliminar inscripciones', 'toolsbox-registros' ); ?></h1>
		<p><?php /* translators: %d: cantidad de inscripciones seleccionadas. */ echo esc_html( sprintf( __( 'Se eliminarán definitivamente %d inscripciones y sus valores. Esta acción libera los cupos que ocupaban.', 'toolsbox-registros' ), count( $selection['ids'] ) ) ); ?></p>
		<p><?php echo esc_html( '#' . implode( ', #', $selection['ids'] ) ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="tbreg_entries_action"><input type="hidden" name="operation" value="delete"><input type="hidden" name="tbreg_confirm_delete" value="1">
		<?php
		wp_nonce_field( 'tbreg_entries_action', 'tbreg_nonce' );
		self::filter_inputs( $selection['filters'] );
		foreach ( $selection['ids'] as $id ) {
			echo '<input type="hidden" name="tbreg_ids[]" value="' . esc_attr( (string) $id ) . '">';
		} submit_button( __( 'Eliminar definitivamente', 'toolsbox-registros' ), 'delete', '', false );
		?>
		<a href="<?php echo esc_url( EntriesFilters::list_url( $selection['filters'] ) ); ?>"><?php esc_html_e( 'Cancelar', 'toolsbox-registros' ); ?></a></form>
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML construido arriba con escape por contexto y nonce de WordPress.
		wp_die( ob_get_clean(), esc_html__( 'Confirmar eliminación', 'toolsbox-registros' ), array( 'response' => 200 ) );
	}
	/**
	 * Recupera selección privada para enlazar con el exportador de Fase 5.
	 *
	 * @param string $token Identificador opaco.
	 * @return array<string,mixed>
	 */
	public static function export_selection( string $token ): array {
		if ( ! current_user_can( 'tbreg_export_entries' ) || ! preg_match( '/^[a-f0-9]{32}$/D', $token ) ) {
			return array();
		}
		$value = TransientStore::get( 'tbreg_selection_' . get_current_user_id() . '_' . $token );
		return is_array( $value ) ? $value : array();
	}
}
