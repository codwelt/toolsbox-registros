<?php
/**
 * Consulta histórica y edición protegida de una inscripción.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Admin;

use Codwelt\ToolsBoxRegistros\Domain\Field;
use Codwelt\ToolsBoxRegistros\Domain\FieldTypeRegistry;
use Codwelt\ToolsBoxRegistros\Repository\EntryRepository;
use Codwelt\ToolsBoxRegistros\Repository\FormRepository;
use Codwelt\ToolsBoxRegistros\Services\TransientStore;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Conserva consentimiento y campos históricos; detecta ediciones simultáneas. */
final class EntryDetailPage {
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
	 * Inicializa repositorios.
	 *
	 * @param EntryRepository|null $entries Inscripciones opcionales.
	 * @param FormRepository|null  $forms Formularios opcionales.
	 */
	public function __construct( ?EntryRepository $entries = null, ?FormRepository $forms = null ) {
		$this->entries = $entries ?? new EntryRepository();
		$this->forms   = $forms ?? new FormRepository();
	}
	/**
	 * Valida permisos, nonce, revisión y estructura antes de editar.
	 *
	 * @param array<string,mixed> $input Entrada sin slashes.
	 * @return bool|\WP_Error
	 */
	public function save( array $input ): bool|\WP_Error {
		if ( ! current_user_can( 'tbreg_view_entries' ) || ! current_user_can( 'tbreg_edit_entries' ) ) {
			return new \WP_Error( 'tbreg_forbidden', __( 'No tienes permiso para editar inscripciones.', 'toolsbox-registros' ) );
		}
		$id = filter_var( $input['tbreg_id'] ?? null, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
		if ( false === $id || ! is_string( $input['tbreg_nonce'] ?? null ) || ! wp_verify_nonce( $input['tbreg_nonce'], 'tbreg_save_entry_' . $id ) ) {
			return new \WP_Error( 'tbreg_invalid_nonce', __( 'La sesión ha caducado o la inscripción no es válida.', 'toolsbox-registros' ) );
		}
		foreach ( array( 'tbreg_revision', 'tbreg_form_revision' ) as $key ) {
			if ( ! is_string( $input[ $key ] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $input[ $key ] ) ) {
				return new \WP_Error( 'tbreg_conflict', __( 'Recarga la inscripción antes de editarla.', 'toolsbox-registros' ) );
			}
		}
		if ( ! is_array( $input['tbreg_values'] ?? null ) || ! is_string( $input['tbreg_status'] ?? null ) || strlen( (string) wp_json_encode( $input['tbreg_values'] ) ) > 1048576 ) {
			return new \WP_Error( 'tbreg_invalid_data', __( 'Los valores o el estado no son válidos.', 'toolsbox-registros' ) );
		}
		$entry = $this->entries->find( $id );
		$form  = null === $entry ? null : $this->forms->find( (int) $entry['form_id'] );
		if ( null === $form ) {
			return new \WP_Error( 'tbreg_not_found', __( 'La inscripción o su formulario ya no existe.', 'toolsbox-registros' ) );
		}
		if ( ! $this->entries->supportsTransactions() ) {
			return new \WP_Error( 'tbreg_storage', __( 'Las tablas deben usar InnoDB para editar inscripciones.', 'toolsbox-registros' ) );
		}
		$values = $input['tbreg_values'];
		foreach ( $form->fields() as $field ) {
			if ( $field->storesValue() && 'checkbox' === $field->get( 'type' ) && ! array_key_exists( $field->key(), $values ) ) {
				$values[ $field->key() ] = array();
			}
		}
		return $this->entries->updateValues( $id, $values, $input['tbreg_revision'], $input['tbreg_form_revision'], $input['tbreg_status'] );
	}
	/**
	 * POST autenticado y PRG con errores privados de una sola lectura.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		Menu::require_access( 'tbreg_view_entries' );
		Menu::require_access( 'tbreg_edit_entries' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Compara el método con POST; no se guarda ni se muestra.
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'La edición requiere POST.', 'toolsbox-registros' ), '', array( 'response' => 405 ) );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- save() valida nonce específico, capacidades, revisión, tamaño y valores.
		$input  = wp_unslash( $_POST );
		$result = $this->save( $input );
		$id     = isset( $input['tbreg_id'] ) && is_scalar( $input['tbreg_id'] ) ? absint( $input['tbreg_id'] ) : 0;
		$args   = array(
			'page' => 'toolsbox-registros-entry',
			'id'   => $id,
		);
		if ( is_wp_error( $result ) ) {
			$payload = array(
				'message' => $result->get_error_message(),
				'errors'  => $result->get_error_data()['fields'] ?? array(),
				'values'  => array(),
			);
			$entry   = $this->entries->find( $id );
			$form    = null === $entry ? null : $this->forms->find( (int) $entry['form_id'] );
			if ( null !== $form && 'tbreg_validation' === $result->get_error_code() ) {
				foreach ( $form->fields() as $field ) {
					$value = $input['tbreg_values'][ $field->key() ] ?? null;
					if ( ! $field->storesValue() || in_array( $field->get( 'type' ), array( 'hidden', 'consent' ), true ) ) {
						continue;
					}
					if ( is_scalar( $value ) ) {
						$sanitized                          = ( new FieldTypeRegistry() )->get( $field->get( 'type' ) )->sanitize( $value, $field );
						$payload['values'][ $field->key() ] = is_array( $sanitized ) ? array_map( static fn ( string $item ): string => mb_substr( $item, 0, 5000 ), $sanitized ) : mb_substr( $sanitized, 0, 5000 );
					} elseif ( 'checkbox' === $field->get( 'type' ) && is_array( $value ) && count( $value ) <= 500 && count( array_filter( $value, 'is_scalar' ) ) === count( $value ) ) {
						$payload['values'][ $field->key() ] = ( new FieldTypeRegistry() )->get( 'checkbox' )->sanitize( $value, $field );
					}
				}
			}
			$token = bin2hex( random_bytes( 16 ) );
			TransientStore::set( 'tbreg_entry_error_' . get_current_user_id() . '_' . $token, $payload, 300 );
			$args['tbreg_error'] = $token;
			$args['edit']        = 1;
		} else {
			$args['tbreg_notice'] = Notices::put( __( 'Inscripción actualizada.', 'toolsbox-registros' ) );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ), 303 );
		exit;
	}
	/**
	 * Muestra metadatos y campos conservados o controles de edición.
	 *
	 * @return void
	 */
	public function render(): void {
		Menu::require_access( 'tbreg_view_entries' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- ID y modo de lectura; la edición se autoriza en POST.
		$id      = isset( $_GET['id'] ) && is_scalar( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$editing = isset( $_GET['edit'] ) && is_string( $_GET['edit'] ) && '1' === sanitize_key( wp_unslash( $_GET['edit'] ) ) && current_user_can( 'tbreg_edit_entries' );
		$token   = isset( $_GET['tbreg_error'] ) && is_string( $_GET['tbreg_error'] ) ? sanitize_key( $_GET['tbreg_error'] ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$entry = $this->entries->find( $id );
		$form  = null === $entry ? null : $this->forms->find( (int) $entry['form_id'] );
		if ( null === $entry || null === $form ) {
			wp_die( esc_html__( 'La inscripción o su formulario no existe.', 'toolsbox-registros' ), '', array( 'response' => 404 ) );
		}
		$values   = $this->entries->values( $id );
		$registry = new FieldTypeRegistry();
		$feedback = array();
		if ( $editing && preg_match( '/^[a-f0-9]{32}$/D', $token ) ) {
			$key      = 'tbreg_entry_error_' . get_current_user_id() . '_' . $token;
			$feedback = TransientStore::get( $key );
			$feedback = is_array( $feedback ) ? $feedback : array();
			TransientStore::delete( $key );
		}
		$metadata = array(
			__( 'Formulario', 'toolsbox-registros' )       => $form->get( 'title' ),
			__( 'Estado', 'toolsbox-registros' )           => FormsListTable::entry_statuses()[ $entry['status'] ],
			__( 'Fecha de inscripción', 'toolsbox-registros' ) => EntriesFilters::display_date( $entry['created_at'] ),
			__( 'Última actualización', 'toolsbox-registros' ) => EntriesFilters::display_date( $entry['updated_at'] ),
			__( 'Página de origen', 'toolsbox-registros' ) => $entry['source_url'],
			__( 'Navegador', 'toolsbox-registros' )        => $entry['user_agent'],
			__( 'Huella de IP', 'toolsbox-registros' )     => $entry['ip_hash'],
		);
		$user     = empty( $entry['user_id'] ) ? false : get_userdata( (int) $entry['user_id'] );
		$metadata[ __( 'Usuario', 'toolsbox-registros' ) ] = $user ? $user->display_name . ' (#' . $entry['user_id'] . ')' : ( empty( $entry['user_id'] ) ? __( 'Visitante', 'toolsbox-registros' ) : '#' . $entry['user_id'] );
		?>
		<div class="wrap tbreg-admin"><h1><?php /* translators: %d: ID de la inscripción. */ echo esc_html( sprintf( __( 'Inscripción #%d', 'toolsbox-registros' ), $id ) ); ?></h1>
		<p><a href="<?php echo esc_url( EntriesFilters::list_url( array( 'form_id' => $form->id() ) ) ); ?>"><?php esc_html_e( 'Volver a inscripciones', 'toolsbox-registros' ); ?></a></p>
		<?php
		Notices::render();
		if ( ! empty( $feedback['message'] ) ) {
			echo '<div class="notice notice-error" role="alert"><p>' . esc_html( $feedback['message'] ) . '</p></div>';}
		?>
		<dl class="tbreg-entry-metadata">
		<?php
		foreach ( $metadata as $label => $value ) :
			?>
			<dt><?php echo esc_html( $label ); ?></dt><dd><?php echo esc_html( (string) $value ); ?></dd><?php endforeach; ?></dl>
		<?php
		if ( ! $editing && current_user_can( 'tbreg_edit_entries' ) ) :
			?>
			<p><a class="button button-primary" href="
			<?php
			echo esc_url(
				add_query_arg(
					array(
						'page' => 'toolsbox-registros-entry',
						'id'   => $id,
						'edit' => 1,
					),
					admin_url( 'admin.php' )
				)
			);
			?>
			"><?php esc_html_e( 'Editar datos y estado', 'toolsbox-registros' ); ?></a></p><?php endif; ?>
		<?php if ( $editing ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tbreg-entry-editor tbreg-registration" novalidate>
		<input type="hidden" name="action" value="tbreg_save_entry"><input type="hidden" name="tbreg_id" value="<?php echo esc_attr( (string) $id ); ?>"><input type="hidden" name="tbreg_revision" value="<?php echo esc_attr( EntryRepository::revision( $entry, $values ) ); ?>"><input type="hidden" name="tbreg_form_revision" value="<?php echo esc_attr( $form->revision() ); ?>">
			<?php wp_nonce_field( 'tbreg_save_entry_' . $id, 'tbreg_nonce' ); ?>
		<label for="tbreg-detail-status"><?php esc_html_e( 'Estado', 'toolsbox-registros' ); ?></label><select name="tbreg_status" id="tbreg-detail-status">
			<?php
			foreach ( FormsListTable::entry_statuses() as $key => $label ) :
				?>
			<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $entry['status'], $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select>
		<p><?php esc_html_e( 'Los campos eliminados, el consentimiento y los valores ocultos se conservan sin editar. Los datos históricos que no cambies se mantienen.', 'toolsbox-registros' ); ?></p>
		<?php endif; ?>
		<div class="tbreg-entry-values<?php echo $editing ? ' tbreg-fields' : ''; ?>">
		<?php
		$focused = false;
		foreach ( $form->fields() as $field ) {
			if ( 'heading' === $field->get( 'type' ) ) {
				continue;
			}
			$value = $values[ $field->key() ] ?? '';
			if ( $editing && $field->storesValue() && ! in_array( $field->get( 'type' ), array( 'hidden', 'consent' ), true ) ) {
				$value            = $feedback['values'][ $field->key() ] ?? $value;
				$error            = $feedback['errors'][ $field->key() ] ?? '';
				$data             = $field->toArray();
				$data['required'] = false;
				// Un valor histórico fuera del catálogo debe verse y conservarse si no cambia.
				if ( $registry->get( $field->get( 'type' ) )->supportsOptions() ) {
					foreach ( is_array( $values[ $field->key() ] ?? '' ) ? $values[ $field->key() ] : array( $values[ $field->key() ] ?? '' ) as $old ) {
						if ( '' !== (string) $old && ! in_array( $old, array_column( $data['options'], 'value' ), true ) ) {
												$data['options'][] = array(
													'value' => (string) $old,
													'label' => (string) $old . __( ' (valor anterior)', 'toolsbox-registros' ),
												);
						}
					}
				}
				// Los controles del navegador no deben impedir guardar datos antiguos sin cambios.
				$data['validation'] = array();
				$data['default']    = '';
				$html               = $registry->get( $field->get( 'type' ) )->render(
					Field::fromArray( $data ),
					$value,
					array(
						'id'        => 'tbreg-entry-' . $id . '-' . $field->key(),
						'error'     => $error,
						'autofocus' => ! $focused && '' !== $error,
					)
				);
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- El FieldType genera HTML escapado por contexto.
				echo $html;
				if ( '' !== $error ) {
					$focused = true;
				}
			} else {
				$label = $field->get( 'label' ) . ( $field->get( 'archived' ) ? __( ' (eliminado)', 'toolsbox-registros' ) : '' );
				$text  = 'consent' === $field->get( 'type' ) ? ( '1' === (string) $value ? __( 'Sí', 'toolsbox-registros' ) : __( 'No registrado', 'toolsbox-registros' ) ) : $registry->get( $field->get( 'type' ) )->toExport( $value, $field );
				echo '<div class="tbreg-entry-value"><strong>' . esc_html( $label ) . '</strong><p>' . nl2br( esc_html( $text ) ) . '</p></div>';
			}
		}
		// Conserva también valores huérfanos sin permitir su modificación.
		$known = array_map( static fn ( Field $field ): string => $field->key(), $form->fields() );
		foreach ( $values as $key => $value ) {
			if ( ! in_array( $key, $known, true ) ) {
				echo '<div class="tbreg-entry-value"><strong>' . esc_html( $key ) . '</strong><p>' . esc_html( is_array( $value ) ? implode( ', ', $value ) : $value ) . '</p></div>';
			}
		}
		?>
		</div>
		<?php
		if ( $editing ) {
			submit_button( __( 'Guardar inscripción', 'toolsbox-registros' ) );
			echo '</form>';
		}
		?>
		</div>
		<?php
	}
}
