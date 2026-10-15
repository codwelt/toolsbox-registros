<?php
/**
 * Configuración y descarga de exportaciones protegidas por nonce y capacidad.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Admin;

use Codwelt\ToolsBoxRegistros\Repository\FormRepository;
use Codwelt\ToolsBoxRegistros\Repository\EntryRepository;
use Codwelt\ToolsBoxRegistros\Export\EntriesExporter;
use Codwelt\ToolsBoxRegistros\Export\XlsxWriter;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Los IDs seleccionados se obtienen del token privado, nunca del formulario. */
final class ExportPage {
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
	 * Generador.
	 *
	 * @var EntriesExporter
	 */
	private EntriesExporter $exporter;
	/**
	 * Inicializa servicios.
	 *
	 * @param FormRepository|null  $forms Formularios opcionales.
	 * @param EntryRepository|null $entries Inscripciones opcionales.
	 */
	public function __construct( ?FormRepository $forms = null, ?EntryRepository $entries = null ) {
		$this->forms    = $forms ?? new FormRepository();
		$this->entries  = $entries ?? new EntryRepository();
		$this->exporter = new EntriesExporter( $this->entries );
	}
	/**
	 * Valida opciones y revalida la selección ligada al usuario actual.
	 *
	 * @param array<string,mixed> $input Datos sin slashes.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function context( array $input ): array|\WP_Error {
		if ( ! current_user_can( 'tbreg_export_entries' ) ) {
			return new \WP_Error( 'tbreg_forbidden', __( 'No tienes permiso para exportar inscripciones.', 'toolsbox-registros' ) );
		}
		foreach ( array( 'format', 'delimiter', 'tbreg_selection' ) as $key ) {
			if ( isset( $input[ $key ] ) && ! is_string( $input[ $key ] ) ) {
				return new \WP_Error( 'tbreg_invalid_data', __( 'Las opciones de exportación no son válidas.', 'toolsbox-registros' ) );
			}
		}
		$token = $input['tbreg_selection'] ?? '';
		$ids   = null;
		if ( '' !== $token ) {
			$selection = EntriesPage::export_selection( $token );
			if ( empty( $selection['form_id'] ) || empty( $selection['ids'] ) || ! is_array( $selection['ids'] ) || count( $selection['ids'] ) > 200 ) {
				return new \WP_Error( 'tbreg_invalid_selection', __( 'La selección ha caducado o no está disponible. Vuelve al listado para seleccionar inscripciones.', 'toolsbox-registros' ) );
			}
			$input['form_id'] = $input['form_id'] ?? $selection['form_id'];
			$ids              = $selection['ids'];
		}
		$filters = EntriesFilters::parse( $input );
		if ( is_wp_error( $filters ) ) {
			return $filters;
		}
		if ( null !== $ids && ( $filters['form_id'] ?? 0 ) !== $selection['form_id'] ) {
			return new \WP_Error( 'tbreg_invalid_selection', __( 'La selección pertenece a otro formulario.', 'toolsbox-registros' ) );
		}
		$form = isset( $filters['form_id'] ) ? $this->forms->find( $filters['form_id'] ) : null;
		if ( isset( $filters['form_id'] ) && null === $form ) {
			return new \WP_Error( 'tbreg_not_found', __( 'El formulario seleccionado ya no existe.', 'toolsbox-registros' ) );
		}
		if ( null !== $ids ) {
			foreach ( $ids as $id ) {
				if ( ! is_int( $id ) || $id < 1 ) {
					return new \WP_Error( 'tbreg_invalid_selection', __( 'La selección no es válida.', 'toolsbox-registros' ) );
				}
				$entry = $this->entries->find( $id );
				if ( null === $entry || (int) $entry['form_id'] !== $form->id() ) {
					return new \WP_Error( 'tbreg_invalid_selection', __( 'Una inscripción de la selección fue eliminada o pertenece a otro formulario.', 'toolsbox-registros' ) );
				}
			}
		}
		$format    = $input['format'] ?? ( XlsxWriter::available() ? 'xlsx' : 'csv' );
		$delimiter = $input['delimiter'] ?? ';';
		if ( ! in_array( $format, array( 'xlsx', 'csv' ), true ) || ! in_array( $delimiter, array( ';', ',' ), true ) ) {
			return new \WP_Error( 'tbreg_invalid_data', __( 'Selecciona un formato y un separador válidos.', 'toolsbox-registros' ) );
		}
		if ( 'xlsx' === $format && ! XlsxWriter::available() ) {
			return new \WP_Error( 'tbreg_zip_unavailable', __( 'Excel no está disponible en este servidor. Selecciona CSV.', 'toolsbox-registros' ) );
		}
		return array(
			'form'      => $form,
			'filters'   => $filters,
			'ids'       => $ids,
			'token'     => $token,
			'format'    => $format,
			'delimiter' => $delimiter,
		);
	}
	/**
	 * Prepara un archivo descargable tras comprobar nonce y autorización.
	 *
	 * @param array<string,mixed> $input Datos de POST.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function build_file( array $input ): array|\WP_Error {
		if ( ! current_user_can( 'tbreg_export_entries' ) ) {
			return new \WP_Error( 'tbreg_forbidden', __( 'No tienes permiso para exportar inscripciones.', 'toolsbox-registros' ) );
		}
		if ( ! is_string( $input['tbreg_nonce'] ?? null ) || ! wp_verify_nonce( $input['tbreg_nonce'], 'tbreg_export_entries' ) ) {
			return new \WP_Error( 'tbreg_invalid_nonce', __( 'La sesión ha caducado. Recarga la pantalla de exportación.', 'toolsbox-registros' ) );
		}
		$context = $this->context( $input );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		if ( null === $context['form'] ) {
			return new \WP_Error( 'tbreg_invalid_data', __( 'Selecciona un formulario para descargar sus inscripciones.', 'toolsbox-registros' ) );
		}
		try {
			return $this->exporter->build( $context['form'], $context['format'], $context['filters'], $context['ids'], $context['delimiter'] );
		} catch ( \Throwable $exception ) {
			return new \WP_Error( 'tbreg_export_failed', __( 'No se pudo generar el archivo. Comprueba el almacenamiento del servidor y vuelve a intentarlo.', 'toolsbox-registros' ) );
		}
	}
	/**
	 * Descarga solo por POST autenticado; los errores vuelven a la pantalla.
	 *
	 * @return void
	 */
	public function handle_download(): void {
		Menu::require_access( 'tbreg_export_entries' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Comparación estricta del método, sin almacenarlo ni mostrarlo.
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'La descarga requiere POST.', 'toolsbox-registros' ), '', array( 'response' => 405 ) );
		}
		check_admin_referer( 'tbreg_export_entries', 'tbreg_nonce' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- build_file valida permiso, nonce, formato, filtros y selección.
		$input = wp_unslash( $_POST );
		$file  = $this->build_file( $input );
		if ( is_wp_error( $file ) ) {
			$args = array(
				'page'         => 'toolsbox-registros-export',
				'tbreg_notice' => Notices::put( $file->get_error_message(), true ),
			);
			if ( isset( $input['form_id'] ) && is_scalar( $input['form_id'] ) ) {
				$args['form_id'] = absint( $input['form_id'] );
			}
			if ( is_string( $input['tbreg_selection'] ?? null ) && preg_match( '/^[a-f0-9]{32}$/D', $input['tbreg_selection'] ) ) {
				$args['tbreg_selection'] = $input['tbreg_selection'];
			}
			wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ), 303 );
			exit;
		}
		try {
			$this->exporter->send( $file );
		} catch ( \RuntimeException $exception ) {
			if ( ! headers_sent() ) {
				wp_die( esc_html__( 'No se pudo iniciar la descarga.', 'toolsbox-registros' ), '', array( 'response' => 500 ) );
			}
		}
			exit;
	}
	/**
	 * Configura formato, filtros locales y selección antes de descargar.
	 *
	 * @return void
	 */
	public function render(): void {
		Menu::require_access( 'tbreg_export_entries' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Contexto de lectura; el POST de descarga valida su nonce.
		$context = $this->context( wp_unslash( $_GET ) );
		$error   = is_wp_error( $context ) ? $context : null;
		if ( null !== $error ) {
			$context = array(
				'form'      => null,
				'filters'   => array(),
				'ids'       => null,
				'token'     => '',
				'format'    => XlsxWriter::available() ? 'xlsx' : 'csv',
				'delimiter' => ';',
			);
		}
		$filters = $context['filters'];
		?>
		<div class="wrap tbreg-admin"><h1><?php esc_html_e( 'Exportar inscripciones', 'toolsbox-registros' ); ?></h1>
		<?php
		Notices::render();
		if ( null !== $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $error->get_error_message() ) . '</p></div>';}
		?>
		<?php
		if ( ! XlsxWriter::available() ) :
			?>
			<div class="notice notice-warning"><p><?php esc_html_e( 'El servidor no dispone de ZipArchive. Puedes descargar los registros en CSV.', 'toolsbox-registros' ); ?></p></div><?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tbreg-export-form"><input type="hidden" name="action" value="tbreg_export_entries"><?php wp_nonce_field( 'tbreg_export_entries', 'tbreg_nonce' ); ?>
		<?php if ( null !== $context['ids'] ) : ?>
		<p><?php /* translators: %d: cantidad de inscripciones seleccionadas. */ echo esc_html( sprintf( __( 'Solo se descargarán las %d inscripciones seleccionadas que cumplan los filtros.', 'toolsbox-registros' ), count( $context['ids'] ) ) ); ?></p>
		<input type="hidden" name="tbreg_selection" value="<?php echo esc_attr( $context['token'] ); ?>"><input type="hidden" name="form_id" value="<?php echo esc_attr( (string) $context['form']->id() ); ?>">
		<p><strong><?php echo esc_html( $context['form']->get( 'title' ) ); ?></strong></p>
		<a href="
			<?php
			echo esc_url(
				add_query_arg(
					array(
						'page'    => 'toolsbox-registros-export',
						'form_id' => $context['form']->id(),
					),
					admin_url( 'admin.php' )
				)
			);
			?>
					"><?php esc_html_e( 'Exportar el formulario completo', 'toolsbox-registros' ); ?></a>
		<?php else : ?>
		<label class="tbreg-control" for="tbreg-export-form"><span><?php esc_html_e( 'Formulario', 'toolsbox-registros' ); ?></span><select id="tbreg-export-form" name="form_id" required><option value=""><?php esc_html_e( 'Selecciona un formulario', 'toolsbox-registros' ); ?></option>
			<?php
			foreach ( $this->forms->summaries() as $form ) :
				?>
				<option value="<?php echo esc_attr( (string) $form['id'] ); ?>" <?php selected( $filters['form_id'] ?? 0, $form['id'] ); ?>><?php echo esc_html( $form['title'] . ( 'trash' === $form['status'] ? __( ' (papelera)', 'toolsbox-registros' ) : '' ) ); ?></option><?php endforeach; ?></select></label>
		<?php endif; ?>
		<label class="tbreg-control" for="tbreg-export-format"><span><?php esc_html_e( 'Formato', 'toolsbox-registros' ); ?></span><select id="tbreg-export-format" name="format">
		<?php
		if ( XlsxWriter::available() ) :
			?>
			<option value="xlsx" <?php selected( $context['format'], 'xlsx' ); ?>><?php esc_html_e( 'Excel (.xlsx)', 'toolsbox-registros' ); ?></option><?php endif; ?><option value="csv" <?php selected( $context['format'], 'csv' ); ?>><?php esc_html_e( 'CSV UTF-8', 'toolsbox-registros' ); ?></option></select></label>
		<label class="tbreg-control" for="tbreg-export-delimiter"><span><?php esc_html_e( 'Separador CSV', 'toolsbox-registros' ); ?></span><select id="tbreg-export-delimiter" name="delimiter"><option value=";" <?php selected( $context['delimiter'], ';' ); ?>><?php esc_html_e( 'Punto y coma (;)', 'toolsbox-registros' ); ?></option><option value="," <?php selected( $context['delimiter'], ',' ); ?>><?php esc_html_e( 'Coma (,)', 'toolsbox-registros' ); ?></option></select><small><?php esc_html_e( 'Se aplica solo a CSV.', 'toolsbox-registros' ); ?></small></label>
		<label class="tbreg-control" for="tbreg-export-status"><span><?php esc_html_e( 'Estado', 'toolsbox-registros' ); ?></span><select id="tbreg-export-status" name="status"><option value=""><?php esc_html_e( 'Todos los estados', 'toolsbox-registros' ); ?></option>
		<?php
		foreach ( FormsListTable::entry_statuses() as $key => $label ) :
			?>
			<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['status'] ?? '', $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
		<?php
		foreach ( array(
			'date_from' => __( 'Desde', 'toolsbox-registros' ),
			'date_to'   => __( 'Hasta', 'toolsbox-registros' ),
		) as $key => $label ) :
			?>
				<label class="tbreg-control" for="tbreg-export-<?php echo esc_attr( $key ); ?>"><span><?php echo esc_html( $label ); ?></span><input type="date" id="tbreg-export-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $filters[ 'local_' . $key ] ?? '' ); ?>"></label><?php endforeach; ?>
		<label class="tbreg-control" for="tbreg-export-search"><span><?php esc_html_e( 'Búsqueda (opcional)', 'toolsbox-registros' ); ?></span><input type="search" id="tbreg-export-search" name="s" maxlength="190" value="<?php echo esc_attr( $filters['search'] ?? '' ); ?>"></label>
		<p><?php /* translators: %s: zona horaria configurada. */ echo esc_html( sprintf( __( 'Las fechas elegidas y la fecha de inscripción del archivo usan la zona del sitio: %s.', 'toolsbox-registros' ), wp_timezone_string() ) ); ?></p>
		<p><?php esc_html_e( 'Los campos eliminados se incluyen y las opciones se descargan con su texto visible.', 'toolsbox-registros' ); ?></p>
		<?php submit_button( __( 'Descargar inscripciones', 'toolsbox-registros' ), 'primary', 'tbreg_download', true, null !== $error ? array( 'disabled' => 'disabled' ) : array() ); ?>
		</form></div>
		<?php
	}
}
