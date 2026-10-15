<?php
/**
 * Constructor de formularios y guardado autenticado.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);

namespace Codwelt\ToolsBoxRegistros\Admin;

use Codwelt\ToolsBoxRegistros\Domain\Form;
use Codwelt\ToolsBoxRegistros\Repository\EntryRepository;
use Codwelt\ToolsBoxRegistros\Repository\FormRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Valida las peticiones y renderiza el editor sin paso de compilación.
 */
final class FormEditorPage {
	/**
	 * Repositorio de formularios.
	 *
	 * @var FormRepository
	 */
	private FormRepository $forms;

	/**
	 * Repositorio para el total de inscripciones del encabezado.
	 *
	 * @var EntryRepository
	 */
	private EntryRepository $entries;

	/**
	 * Inicializa el repositorio del editor.
	 *
	 * @param FormRepository|null  $forms Repositorio opcional para pruebas.
	 * @param EntryRepository|null $entries Inscripciones opcionales para pruebas.
	 */
	public function __construct( ?FormRepository $forms = null, ?EntryRepository $entries = null ) {
		$this->forms   = $forms ?? new FormRepository();
		$this->entries = $entries ?? new EntryRepository();
	}

	/**
	 * Valida autorización, nonce y esquema antes de persistir el formulario.
	 *
	 * @param array<string,mixed> $input Entrada ya deslasheada.
	 * @return Form|\WP_Error
	 */
	public function save( array $input ): Form|\WP_Error {
		if ( ! current_user_can( 'tbreg_manage_forms' ) ) {
			return new \WP_Error( 'tbreg_forbidden', __( 'No tienes permiso para administrar formularios.', 'toolsbox-registros' ) );
		}
		if ( ! isset( $input['tbreg_nonce'] ) || ! is_string( $input['tbreg_nonce'] ) || ! wp_verify_nonce( $input['tbreg_nonce'], 'tbreg_save_form' ) ) {
			return new \WP_Error( 'tbreg_invalid_nonce', __( 'La sesión del formulario ha caducado. Recarga la página e inténtalo de nuevo.', 'toolsbox-registros' ) );
		}
		try {
			foreach ( array( 'tbreg_id', 'tbreg_title', 'tbreg_slug', 'tbreg_description', 'tbreg_status', 'tbreg_fields', 'tbreg_revision' ) as $name ) {
				if ( isset( $input[ $name ] ) && ! is_string( $input[ $name ] ) ) {
					return new \WP_Error( 'tbreg_invalid_data', __( 'El formulario contiene un valor no permitido.', 'toolsbox-registros' ) );
				}
			}
			$id = filter_var( $input['tbreg_id'] ?? '0', FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
			if ( false === $id || ( $id > 0 && ! preg_match( '/^[a-f0-9]{64}$/D', $input['tbreg_revision'] ?? '' ) ) ) {
				return new \WP_Error( 'tbreg_invalid_data', __( 'El identificador o la revisión del formulario no es válido. Recarga la página.', 'toolsbox-registros' ) );
			}
			$json = $input['tbreg_fields'] ?? '[]';
			if ( strlen( $json ) > 1048576 ) {
				return new \WP_Error( 'tbreg_too_large', __( 'La definición del formulario es demasiado grande.', 'toolsbox-registros' ) );
			}
			$fields = json_decode( $json, true, 64, JSON_THROW_ON_ERROR );
			if ( ! str_starts_with( ltrim( $json ), '[' ) || ! is_array( $fields ) || array_values( $fields ) !== $fields ) {
				return new \WP_Error( 'tbreg_invalid_data', __( 'Los campos deben ser una lista ordenada.', 'toolsbox-registros' ) );
			}
			$condition_remaps = array();
			// Solo las opciones recién creadas y no editadas generan su valor desde la etiqueta.
			foreach ( $fields as &$field ) {
				if ( ! is_array( $field ) ) {
					return new \WP_Error( 'tbreg_invalid_data', __( 'La definición de un campo no es válida.', 'toolsbox-registros' ) );
				}
				$remap = array();
				if ( isset( $field['options'] ) && is_array( $field['options'] ) ) {
					foreach ( $field['options'] as &$option ) {
						if ( is_array( $option ) && true === ( $option['auto'] ?? false ) && isset( $option['label'], $option['value'] ) && is_string( $option['label'] ) && is_string( $option['value'] ) ) {
							$generated                 = sanitize_title( $option['label'] );
							$remap[ $option['value'] ] = $generated;
							$option['value']           = $generated;
						}
					}
					unset( $option );
				}
				if ( ! empty( $remap ) && isset( $field['key'] ) && is_string( $field['key'] ) ) {
					$condition_remaps[ $field['key'] ] = $remap; }
				$default = $field['default'] ?? '';
				if ( is_scalar( $default ) && isset( $remap[ (string) $default ] ) ) {
					$field['default'] = $remap[ (string) $default ];
				} elseif ( is_array( $default ) ) {
					$field['default'] = array_map( static fn ( mixed $value ): mixed => is_scalar( $value ) ? ( $remap[ (string) $value ] ?? $value ) : $value, $default );
				}
				if ( 'consent' === ( $field['type'] ?? '' ) ) {
					$field['required'] = true;
				}
			}
			unset( $field );
			foreach ( $fields as &$field ) {
				foreach ( $field['options'] ?? array() as $index => $option ) {
					foreach ( $option['when'] ?? array() as $parent => $allowed ) {
						if ( isset( $condition_remaps[ $parent ] ) && is_array( $allowed ) ) {
							$field['options'][ $index ]['when'][ $parent ] = array_map( static fn ( mixed $value ): mixed => is_string( $value ) ? ( $condition_remaps[ $parent ][ $value ] ?? $value ) : $value, $allowed ); }
					}
				}
			}
			unset( $field );
			$form = Form::fromArray(
				array(
					'id'          => $id,
					'title'       => $input['tbreg_title'] ?? '',
					'slug'        => $input['tbreg_slug'] ?? '',
					'description' => $input['tbreg_description'] ?? '',
					'status'      => $input['tbreg_status'] ?? 'draft',
					'fields'      => $fields,
					'settings'    => $this->settings_input( $input['tbreg_settings'] ?? array(), $input['tbreg_after_submit'] ?? null ),
				)
			);
			return $this->forms->save( $form, $id > 0 ? $input['tbreg_revision'] : null );
		} catch ( \JsonException $exception ) {
			return new \WP_Error( 'tbreg_invalid_json', __( 'El esquema JSON está mal formado. El formulario anterior se ha conservado.', 'toolsbox-registros' ) );
		} catch ( \InvalidArgumentException $exception ) {
			return new \WP_Error( 'tbreg_invalid_data', $exception->getMessage() );
		} catch ( \RuntimeException $exception ) {
			return new \WP_Error( 'tbreg_db_error', __( 'No se pudo guardar el formulario. Inténtalo de nuevo.', 'toolsbox-registros' ) );
		}
	}

	/**
	 * Convierte exclusivamente controles HTML conocidos a los tipos del esquema.
	 *
	 * @param mixed $raw Ajustes recibidos.
	 * @param mixed $mode Acción de interfaz, sin persistir en el esquema.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException Si un control contiene datos inválidos.
	 */
	private function settings_input( mixed $raw, mixed $mode = null ): array {
		if ( ! is_array( $raw ) ) {
			throw new \InvalidArgumentException( esc_html__( 'La configuración no es válida.', 'toolsbox-registros' ) );
		}
		if ( null !== $mode ) {
			if ( ! in_array( $mode, array( 'message', 'redirect' ), true ) ) {
				throw new \InvalidArgumentException( esc_html__( 'La acción después de inscribirse no es válida.', 'toolsbox-registros' ) );
			}
			if ( 'message' === $mode ) {
				$raw['redirect_url'] = '';
			} elseif ( ! is_string( $raw['redirect_url'] ?? null ) || '' === esc_url_raw( trim( $raw['redirect_url'] ) ) ) {
				throw new \InvalidArgumentException( esc_html__( 'Escribe la URL de la página de redirección.', 'toolsbox-registros' ) );
			}
		}
		foreach ( array( 'event', 'notify_admin', 'confirm_attendee' ) as $name ) {
			if ( isset( $raw[ $name ] ) && ! is_array( $raw[ $name ] ) ) {
				throw new \InvalidArgumentException( esc_html__( 'La configuración del evento o las notificaciones no es válida.', 'toolsbox-registros' ) );
			}
		}
		foreach ( array( 'waitlist', 'require_login', 'pdf_enabled' ) as $name ) {
			$value = $raw[ $name ] ?? '0';
			if ( ! in_array( $value, array( '0', '1', false, true ), true ) ) {
				throw new \InvalidArgumentException( esc_html__( 'Una opción de configuración no es válida.', 'toolsbox-registros' ) );
			}
			$raw[ $name ] = '1' === $value || true === $value;
		}
		$capacity = filter_var(
			$raw['capacity'] ?? '0',
			FILTER_VALIDATE_INT,
			array(
				'options' => array(
					'min_range' => 0,
					'max_range' => 4294967295,
				),
			)
		);
		if ( false === $capacity ) {
			throw new \InvalidArgumentException( esc_html__( 'El cupo debe ser un entero no negativo.', 'toolsbox-registros' ) );
		}
		$raw['capacity'] = $capacity;
		foreach ( array( 'notify_admin', 'confirm_attendee' ) as $name ) {
			$value = $raw[ $name ]['enabled'] ?? '0';
			if ( ! in_array( $value, array( '0', '1', false, true ), true ) ) {
				throw new \InvalidArgumentException( esc_html__( 'La notificación no es válida.', 'toolsbox-registros' ) );
			}
			$raw[ $name ]['enabled'] = '1' === $value || true === $value;
		}
		foreach ( array( 'starts_at', 'ends_at' ) as $name ) {
			$value = $raw['event'][ $name ] ?? '';
			if ( ! is_string( $value ) ) {
				throw new \InvalidArgumentException( esc_html__( 'La fecha del evento no es válida.', 'toolsbox-registros' ) );
			}
			if ( '' !== $value ) {
				$value = str_replace( 'T', ' ', $value );
				$value = 16 === strlen( $value ) ? $value . ':00' : $value;
				$date  = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, wp_timezone() );
				if ( false === $date || $date->format( 'Y-m-d H:i:s' ) !== $value ) {
					throw new \InvalidArgumentException( esc_html__( 'La fecha del evento no es válida.', 'toolsbox-registros' ) );
				}
			}
			$raw['event'][ $name ] = $value;
		}
		if ( '' !== $raw['event']['starts_at'] && '' !== $raw['event']['ends_at'] && $raw['event']['starts_at'] > $raw['event']['ends_at'] ) {
			throw new \InvalidArgumentException( esc_html__( 'El fin del evento no puede preceder al inicio.', 'toolsbox-registros' ) );
		}
		$recipients = $raw['notify_admin']['to'] ?? '';
		if ( ! is_string( $recipients ) ) {
			throw new \InvalidArgumentException( esc_html__( 'Los destinatarios no son válidos.', 'toolsbox-registros' ) );
		}
		if ( '' !== trim( $recipients ) ) {
			foreach ( explode( ',', $recipients ) as $recipient ) {
				if ( ! is_email( trim( $recipient ) ) ) {
					throw new \InvalidArgumentException( esc_html__( 'Revisa los correos de los destinatarios.', 'toolsbox-registros' ) );
				}
			}
		}
		return $raw;
	}

	/**
	 * Atiende el POST autenticado y redirige a una pantalla propia.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		Menu::require_access( 'tbreg_manage_forms' );
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			wp_die( esc_html__( 'El guardado requiere una petición POST.', 'toolsbox-registros' ), '', array( 'response' => 405 ) );
		}
		check_admin_referer( 'tbreg_save_form', 'tbreg_nonce' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- save() valida estructura, tipos y cadenas antes de persistir.
		$input  = wp_unslash( $_POST );
		$result = $this->save( $input );
		$id     = is_wp_error( $result ) ? ( isset( $input['tbreg_id'] ) && is_string( $input['tbreg_id'] ) ? absint( $input['tbreg_id'] ) : 0 ) : $result->id();
		$token  = Notices::put( is_wp_error( $result ) ? $result->get_error_message() : __( 'Formulario guardado.', 'toolsbox-registros' ), is_wp_error( $result ) );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => 'toolsbox-registros-edit',
					'id'           => $id,
					'tbreg_notice' => $token,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Obtiene el estado autorizado para render y assets del editor.
	 *
	 * @return array<string,mixed>
	 */
	public function context(): array {
		Menu::require_access( 'tbreg_manage_forms' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Selector de lectura; ninguna escritura.
		$id   = isset( $_GET['id'] ) && is_scalar( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$form = $id > 0 ? $this->forms->find( $id ) : null;
		if ( $id > 0 && null === $form ) {
			wp_die( esc_html__( 'El formulario no existe.', 'toolsbox-registros' ), '', array( 'response' => 404 ) );
		}
		$data = null !== $form ? $form->toArray() : Form::fromArray( array( 'title' => __( 'Nuevo formulario', 'toolsbox-registros' ) ) )->toArray();
		if ( null === $form ) {
			$data['title'] = '';
		}
		return array(
			'data'     => $data,
			'revision' => null !== $form ? $form->revision() : '',
			'used'     => $id > 0 ? $this->forms->usedFieldKeys( $id ) : array(),
		);
	}

	/**
	 * Renderiza las pestañas y los controles de configuración.
	 *
	 * @return void
	 */
	public function render(): void {
		$context  = $this->context();
		$data     = $context['data'];
		$settings = $data['settings'];
		$links    = array();
		foreach ( array( 'entries', 'export' ) as $page ) {
			$links[ $page ] = add_query_arg(
				array(
					'page'    => 'toolsbox-registros-' . $page,
					'form_id' => $data['id'],
				),
				admin_url( 'admin.php' )
			);
		}
		?>
		<div class="wrap tbreg-admin tbreg-form-editor">
			<div class="tbreg-editor-heading">
				<h1><?php echo esc_html( $data['id'] > 0 ? __( 'Editar formulario', 'toolsbox-registros' ) : __( 'Crear formulario', 'toolsbox-registros' ) ); ?></h1>
				<span id="tbreg-form-status" class="tbreg-status-badge" data-status="<?php echo esc_attr( $data['status'] ); ?>"><?php echo esc_html( FormsListTable::form_statuses()[ $data['status'] ] ); ?></span>
				<?php if ( $data['id'] > 0 ) : ?>
					<nav class="tbreg-editor-links" aria-label="<?php esc_attr_e( 'Inscripciones del formulario', 'toolsbox-registros' ); ?>">
						<?php if ( current_user_can( 'tbreg_view_entries' ) ) : ?>
							<a href="<?php echo esc_url( $links['entries'] ); ?>"><?php echo esc_html( sprintf( /* translators: %s: Total de inscripciones del formulario. */ __( 'Ver inscripciones (%s)', 'toolsbox-registros' ), number_format_i18n( $this->entries->count( array( 'form_id' => $data['id'] ) ) ) ) ); ?></a>
						<?php endif; ?>
						<?php if ( current_user_can( 'tbreg_export_entries' ) ) : ?>
							<a href="<?php echo esc_url( $links['export'] ); ?>"><?php esc_html_e( 'Exportar', 'toolsbox-registros' ); ?></a>
						<?php endif; ?>
					</nav>
				<?php endif; ?>
			</div>
			<hr class="wp-header-end">
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=toolsbox-registros' ) ); ?>">&larr; <?php esc_html_e( 'Volver a formularios', 'toolsbox-registros' ); ?></a></p>
			<?php Notices::render(); ?>
			<noscript><div class="notice notice-warning"><p><?php esc_html_e( 'Activa JavaScript para agregar y ordenar campos. Puedes editar los datos y la configuración del formulario existente.', 'toolsbox-registros' ); ?></p></div></noscript>
			<div id="tbreg-builder-error" class="notice notice-error" role="alert" hidden><p></p></div>
			<form id="tbreg-editor" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="tbreg_save_form">
				<input type="hidden" name="tbreg_id" value="<?php echo esc_attr( (string) $data['id'] ); ?>">
				<input type="hidden" name="tbreg_revision" value="<?php echo esc_attr( $context['revision'] ); ?>">
				<input type="hidden" id="tbreg-fields-json" name="tbreg_fields" value="<?php echo esc_attr( (string) wp_json_encode( $data['fields'] ) ); ?>">
				<?php wp_nonce_field( 'tbreg_save_form', 'tbreg_nonce' ); ?>
				<div class="tbreg-editor-layout">
				<?php $this->publish_panel( $data, $links['entries'] ); ?>
				<div class="tbreg-editor-main">
				<label class="tbreg-title-label" for="tbreg-title"><?php esc_html_e( 'Nombre del formulario', 'toolsbox-registros' ); ?></label>
				<input id="tbreg-title" name="tbreg_title" type="text" class="large-text tbreg-title" maxlength="200" value="<?php echo esc_attr( $data['title'] ); ?>" required>
				<div class="tbreg-embed-info">
					<?php if ( $data['id'] > 0 ) : ?>
						<div class="tbreg-shortcode-row">
							<label for="tbreg-shortcode"><?php esc_html_e( 'Shortcode', 'toolsbox-registros' ); ?></label>
							<input type="text" id="tbreg-shortcode" class="tbreg-shortcode" readonly value="<?php echo esc_attr( '[toolsbox_registro id="' . $data['id'] . '"]' ); ?>">
							<button type="button" class="button" data-tbreg-copy-target="tbreg-shortcode" aria-describedby="tbreg-copy-status"><?php esc_html_e( 'Copiar', 'toolsbox-registros' ); ?></button>
							<span id="tbreg-copy-status" role="status" aria-live="polite" aria-atomic="true"></span>
						</div>
					<?php else : ?>
						<p><?php esc_html_e( 'Guarda el formulario para obtener su shortcode.', 'toolsbox-registros' ); ?></p>
					<?php endif; ?>
					<details class="tbreg-slug-editor">
						<summary><?php esc_html_e( 'Slug:', 'toolsbox-registros' ); ?> <span id="tbreg-slug-summary"><?php echo esc_html( '' !== $data['slug'] ? $data['slug'] : __( 'Se genera al guardar', 'toolsbox-registros' ) ); ?></span> <span class="tbreg-slug-edit-label"><?php esc_html_e( 'Editar', 'toolsbox-registros' ); ?></span></summary>
						<label for="tbreg-slug"><?php esc_html_e( 'Slug', 'toolsbox-registros' ); ?></label>
						<input id="tbreg-slug" type="text" name="tbreg_slug" class="regular-text" value="<?php echo esc_attr( $data['slug'] ); ?>" aria-describedby="tbreg-slug-help">
						<p id="tbreg-slug-help" class="description"><?php esc_html_e( 'Se guarda con el formulario. Déjalo vacío para generarlo a partir del nombre.', 'toolsbox-registros' ); ?></p>
					</details>
					<p class="description"><?php esc_html_e( 'También puedes usar el bloque «ToolsBox Registros» o el widget de Elementor «ToolsBox — Formulario de inscripción». Publica el formulario para recibir inscripciones.', 'toolsbox-registros' ); ?></p>
				</div>
				<div class="tbreg-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Editor del formulario', 'toolsbox-registros' ); ?>">
					<?php
					foreach ( array(
						'fields'        => __( 'Campos', 'toolsbox-registros' ),
						'settings'      => __( 'Configuración', 'toolsbox-registros' ),
						'notifications' => __( 'Notificaciones', 'toolsbox-registros' ),
					) as $key => $label ) :
						?>
						<button type="button" role="tab" id="tbreg-tab-<?php echo esc_attr( $key ); ?>" aria-controls="tbreg-panel-<?php echo esc_attr( $key ); ?>" aria-selected="<?php echo 'fields' === $key ? 'true' : 'false'; ?>" tabindex="<?php echo 'fields' === $key ? '0' : '-1'; ?>" data-tab="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></button>
					<?php endforeach; ?>
				</div>
				<section id="tbreg-panel-fields" role="tabpanel" aria-labelledby="tbreg-tab-fields" class="tbreg-panel">
					<div class="tbreg-canvas">
						<div class="tbreg-canvas-toolbar">
							<h2><?php esc_html_e( 'Campos', 'toolsbox-registros' ); ?> (<span id="tbreg-fields-count">0</span>)</h2>
							<details class="tbreg-templates" data-tbreg-ui>
							<summary><?php esc_html_e( 'Plantillas', 'toolsbox-registros' ); ?></summary>
							<label class="screen-reader-text" for="tbreg-template"><?php esc_html_e( 'Elegir plantilla', 'toolsbox-registros' ); ?></label>
							<select id="tbreg-template">
							<?php
							foreach ( Templates::all() as $name => $template ) :
								?>
								<option value="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $template['label'] ); ?></option><?php endforeach; ?></select>
							<button type="button" class="button" id="tbreg-apply-template"><?php esc_html_e( 'Usar plantilla', 'toolsbox-registros' ); ?></button>
							</details>
							<button type="button" class="button" id="tbreg-expand-all" aria-controls="tbreg-fields"><?php esc_html_e( 'Expandir todo', 'toolsbox-registros' ); ?></button>
						</div>
						<p id="tbreg-field-status" role="status" aria-live="polite" aria-atomic="true"></p>
							<p class="description"><?php esc_html_e( 'Arrastra el asa de un campo para ordenarlo o usa las flechas para moverlo con el teclado.', 'toolsbox-registros' ); ?></p>
							<div id="tbreg-fields-empty" class="tbreg-empty">
								<h3><?php esc_html_e( 'Empieza con una plantilla', 'toolsbox-registros' ); ?></h3>
								<div class="tbreg-template-cards">
								<?php foreach ( Templates::all() as $name => $template ) : ?>
									<button type="button" class="button" data-tbreg-template="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $template['label'] ); ?></button>
								<?php endforeach; ?>
								</div>
								<p><?php esc_html_e( 'O agrega tu primer campo con el botón de abajo.', 'toolsbox-registros' ); ?></p>
							</div>
							<ul id="tbreg-fields" aria-label="<?php esc_attr_e( 'Campos activos', 'toolsbox-registros' ); ?>"></ul>
							<div id="tbreg-inserter-end">
								<button type="button" class="button" id="tbreg-add-field" aria-controls="tbreg-inserter" aria-expanded="false"><?php esc_html_e( '+ Agregar campo', 'toolsbox-registros' ); ?></button>
								<?php $this->inserter(); ?>
							</div>
							<section id="tbreg-archived" hidden><h2><?php esc_html_e( 'Campos archivados', 'toolsbox-registros' ); ?></h2><p class="description"><?php esc_html_e( 'Sus valores anteriores se conservan para las inscripciones y la exportación.', 'toolsbox-registros' ); ?></p><ul></ul></section>
					</div>
				</section>
				<?php
				$this->settings_panel( $data );
				$this->notifications_panel( $settings );
				?>
				</div>
				</div>
				<div class="tbreg-mobile-savebar"><button type="submit" class="button button-primary" data-tbreg-save><?php esc_html_e( 'Guardar formulario', 'toolsbox-registros' ); ?></button><span data-tbreg-save-status aria-hidden="true"></span></div>
			</form>
		</div>
		<?php
	}

	/**
	 * Agrupa los ajustes existentes sin cambiar el esquema guardado.
	 *
	 * @param array<string,mixed> $data Formulario.
	 * @return void
	 */
	private function settings_panel( array $data ): void {
		$settings = $data['settings'];
		?>
		<section id="tbreg-panel-settings" role="tabpanel" aria-labelledby="tbreg-tab-settings" class="tbreg-panel" hidden>
			<section class="tbreg-settings-card postbox" aria-labelledby="tbreg-event-title">
				<h2 id="tbreg-event-title"><?php esc_html_e( 'Información del evento', 'toolsbox-registros' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Describe la actividad a la que se inscribirán las personas.', 'toolsbox-registros' ); ?></p>
				<div class="tbreg-settings-grid">
				<?php
				$this->control( 'tbreg_description', __( 'Descripción', 'toolsbox-registros' ), $data['description'], 'textarea' );
				$this->control( 'tbreg_settings[event][name]', __( 'Nombre del evento', 'toolsbox-registros' ), $settings['event']['name'] );
				$this->control( 'tbreg_settings[event][location]', __( 'Lugar o enlace del evento', 'toolsbox-registros' ), $settings['event']['location'] );
				$this->select_control(
					'tbreg_settings[event][modality]',
					__( 'Modalidad', 'toolsbox-registros' ),
					$settings['event']['modality'],
					array(
						'presencial' => __( 'Presencial', 'toolsbox-registros' ),
						'virtual'    => __( 'Virtual', 'toolsbox-registros' ),
						'hibrido'    => __( 'Híbrido', 'toolsbox-registros' ),
					)
				);
				$this->control( 'tbreg_settings[event][starts_at]', __( 'Inicio del evento', 'toolsbox-registros' ), $settings['event']['starts_at'], 'datetime-local' );
				$this->control( 'tbreg_settings[event][ends_at]', __( 'Fin del evento', 'toolsbox-registros' ), $settings['event']['ends_at'], 'datetime-local' );
				?>
				</div>
			</section>
			<section id="tbreg-registration-settings" class="tbreg-settings-card postbox" aria-labelledby="tbreg-registration-title">
				<h2 id="tbreg-registration-title"><?php esc_html_e( 'Inscripciones', 'toolsbox-registros' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Define cuándo se reciben inscripciones y cómo se controlan los cupos.', 'toolsbox-registros' ); ?></p>
				<div class="tbreg-settings-grid">
					<div>
					<?php $this->control( 'tbreg_settings[capacity]', __( 'Cupo máximo', 'toolsbox-registros' ), (string) $settings['capacity'], 'number', 'tbreg-capacity' ); ?>
					<p id="tbreg-capacity-help" class="description"><?php esc_html_e( '0 = ilimitado.', 'toolsbox-registros' ); ?></p>
					</div>
					<div id="tbreg-waitlist-options" data-tbreg-dependent="waitlist">
					<?php $this->checkbox_control( 'tbreg_settings[waitlist]', __( 'Permitir lista de espera cuando se complete el cupo', 'toolsbox-registros' ), $settings['waitlist'] ); ?>
					</div>
					<?php
					$this->control( 'tbreg_settings[opens_at]', __( 'Apertura de inscripciones', 'toolsbox-registros' ), $settings['opens_at'], 'datetime-local', 'tbreg-opens-at' );
					$this->control( 'tbreg_settings[closes_at]', __( 'Cierre de inscripciones', 'toolsbox-registros' ), $settings['closes_at'], 'datetime-local' );
					$this->select_control( 'tbreg_settings[default_status]', __( 'Estado inicial', 'toolsbox-registros' ), $settings['default_status'], FormsListTable::entry_statuses() );
					?>
					<div>
					<?php
					$this->select_control(
						'tbreg_settings[prevent_duplicates]',
						__( 'Control de duplicados', 'toolsbox-registros' ),
						$settings['prevent_duplicates'],
						array(
							'none'     => __( 'Sin control', 'toolsbox-registros' ),
							'email'    => __( 'Por correo principal', 'toolsbox-registros' ),
							'document' => __( 'Por documento principal', 'toolsbox-registros' ),
						)
					);
					?>
					<p id="tbreg-duplicate-email-warning" class="tbreg-warning" role="status" hidden><?php esc_html_e( 'Para controlar duplicados por correo, asigna el rol Correo principal a un campo activo en Campos → Avanzado.', 'toolsbox-registros' ); ?></p>
					</div>
					<?php $this->checkbox_control( 'tbreg_settings[require_login]', __( 'Requerir inicio de sesión para inscribirse', 'toolsbox-registros' ), $settings['require_login'] ); ?>
				</div>
				<p class="description"><?php echo esc_html( sprintf( /* translators: %s: Zona horaria del sitio. */ __( 'Las fechas de este formulario se configuran en la zona horaria del sitio: %s.', 'toolsbox-registros' ), wp_timezone_string() ) ); ?>
				<?php if ( in_array( wp_timezone_string(), array( 'UTC', '+00:00', 'Etc/UTC', 'GMT', 'Etc/GMT' ), true ) && current_user_can( 'manage_options' ) ) : ?>
					<a href="<?php echo esc_url( admin_url( 'options-general.php#timezone_string' ) ); ?>"><?php esc_html_e( 'Configurar zona horaria', 'toolsbox-registros' ); ?></a>
				<?php endif; ?></p>
			</section>
			<section class="tbreg-settings-card postbox" aria-labelledby="tbreg-after-title">
				<h2 id="tbreg-after-title"><?php esc_html_e( 'Después de inscribirse', 'toolsbox-registros' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Elige qué verá la persona al completar su inscripción.', 'toolsbox-registros' ); ?></p>
				<fieldset class="tbreg-after-mode"><legend class="screen-reader-text"><?php esc_html_e( 'Acción después de inscribirse', 'toolsbox-registros' ); ?></legend>
					<label><input type="radio" name="tbreg_after_submit" value="message" aria-controls="tbreg-success-options" <?php checked( '' === $settings['redirect_url'] ); ?>> <?php esc_html_e( 'Mostrar mensaje', 'toolsbox-registros' ); ?></label>
					<label><input type="radio" name="tbreg_after_submit" value="redirect" aria-controls="tbreg-redirect-options" <?php checked( '' !== $settings['redirect_url'] ); ?>> <?php esc_html_e( 'Redirigir a una página', 'toolsbox-registros' ); ?></label>
				</fieldset>
				<div id="tbreg-success-options" data-tbreg-dependent="message"><?php $this->control( 'tbreg_settings[success_message]', __( 'Mensaje de éxito', 'toolsbox-registros' ), $settings['success_message'], 'textarea' ); ?></div>
				<div id="tbreg-redirect-options" data-tbreg-dependent="redirect"><?php $this->control( 'tbreg_settings[redirect_url]', __( 'Redirección después de inscribirse', 'toolsbox-registros' ), $settings['redirect_url'], 'url' ); ?></div>
				<p id="tbreg-redirect-help" class="description" <?php echo '' === $settings['redirect_url'] ? 'hidden' : ''; ?>><?php echo esc_html( sprintf( /* translators: %s: Dirección de redirección que se eliminará al guardar. */ __( 'Si guardas con «Mostrar mensaje», se quitará la redirección a %s.', 'toolsbox-registros' ), $settings['redirect_url'] ) ); ?></p>
				<?php $this->control( 'tbreg_settings[submit_label]', __( 'Texto del botón de envío', 'toolsbox-registros' ), $settings['submit_label'] ); ?>
			</section>
			<section class="tbreg-settings-card postbox" aria-labelledby="tbreg-pdf-title">
				<h2 id="tbreg-pdf-title"><?php esc_html_e( 'Comprobante en PDF', 'toolsbox-registros' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Ofrece un comprobante descargable al completar la inscripción.', 'toolsbox-registros' ); ?></p>
				<?php $this->checkbox_control( 'tbreg_settings[pdf_enabled]', __( 'Ofrecer PDF al inscrito', 'toolsbox-registros' ), $settings['pdf_enabled'], 'tbreg-pdf-options' ); ?>
				<div id="tbreg-pdf-options" data-tbreg-dependent="pdf">
					<?php $this->control( 'tbreg_settings[receipt_text]', __( 'Texto de la segunda página del PDF', 'toolsbox-registros' ), $settings['receipt_text'], 'textarea' ); ?>
					<p class="description"><?php esc_html_e( 'Con el PDF activado se muestra el comprobante con su enlace de descarga, en lugar de redirigir automáticamente.', 'toolsbox-registros' ); ?></p>
				</div>
			</section>
			<section class="tbreg-settings-card postbox" aria-labelledby="tbreg-privacy-title">
				<h2 id="tbreg-privacy-title"><?php esc_html_e( 'Privacidad y seguridad', 'toolsbox-registros' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Configura la autorización de datos y la verificación adicional contra envíos automáticos.', 'toolsbox-registros' ); ?></p>
				<?php
				$this->control( 'tbreg_settings[consent_text]', __( 'Texto de autorización de tratamiento de datos', 'toolsbox-registros' ), $settings['consent_text'], 'textarea' );
				$this->select_control(
					'tbreg_settings[captcha]',
					__( 'Verificación antispam adicional', 'toolsbox-registros' ),
					$settings['captcha'],
					array(
						'none'      => __( 'Sin captcha adicional', 'toolsbox-registros' ),
						'turnstile' => __( 'Cloudflare Turnstile', 'toolsbox-registros' ),
						'recaptcha' => __( 'Google reCAPTCHA v2', 'toolsbox-registros' ),
					)
				);
				?>
			</section>
		</section>
		<?php
	}

	/**
	 * Configuración de correos existente, pendiente del servicio de envío.
	 *
	 * @param array<string,mixed> $settings Ajustes guardados.
	 * @return void
	 */
	private function notifications_panel( array $settings ): void {
		?>
		<section id="tbreg-panel-notifications" role="tabpanel" aria-labelledby="tbreg-tab-notifications" class="tbreg-panel" hidden>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'El envío de correos llega en la próxima versión; la configuración ya se guarda.', 'toolsbox-registros' ); ?></p></div>
			<section class="tbreg-settings-card postbox" aria-labelledby="tbreg-notify-admin-title">
				<h2 id="tbreg-notify-admin-title"><?php esc_html_e( 'Aviso al administrador', 'toolsbox-registros' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Prepara los destinatarios y el asunto del aviso de una nueva inscripción.', 'toolsbox-registros' ); ?></p>
				<?php $this->checkbox_control( 'tbreg_settings[notify_admin][enabled]', __( 'Avisar al administrador', 'toolsbox-registros' ), $settings['notify_admin']['enabled'], 'tbreg-notify-admin-options' ); ?>
				<div id="tbreg-notify-admin-options" data-tbreg-dependent="notify_admin">
				<?php
				$this->control( 'tbreg_settings[notify_admin][to]', __( 'Destinatarios (correos separados por coma)', 'toolsbox-registros' ), $settings['notify_admin']['to'] );
				$this->merge_control( 'tbreg_settings[notify_admin][subject]', __( 'Asunto para el administrador', 'toolsbox-registros' ), $settings['notify_admin']['subject'], 'text', 'tbreg-admin-subject' );
				?>
				</div>
			</section>
			<section class="tbreg-settings-card postbox" aria-labelledby="tbreg-confirm-attendee-title">
				<h2 id="tbreg-confirm-attendee-title"><?php esc_html_e( 'Confirmación al inscrito', 'toolsbox-registros' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Prepara el correo que recibirá la persona inscrita.', 'toolsbox-registros' ); ?></p>
				<?php $this->checkbox_control( 'tbreg_settings[confirm_attendee][enabled]', __( 'Confirmar al inscrito por correo', 'toolsbox-registros' ), $settings['confirm_attendee']['enabled'], 'tbreg-confirm-attendee-options' ); ?>
				<div id="tbreg-confirm-attendee-options" data-tbreg-dependent="confirm_attendee">
				<?php
				$this->merge_control( 'tbreg_settings[confirm_attendee][subject]', __( 'Asunto para el inscrito', 'toolsbox-registros' ), $settings['confirm_attendee']['subject'], 'text', 'tbreg-attendee-subject' );
				$this->merge_control( 'tbreg_settings[confirm_attendee][body]', __( 'Mensaje para el inscrito', 'toolsbox-registros' ), $settings['confirm_attendee']['body'], 'textarea', 'tbreg-attendee-body' );
				?>
				</div>
			</section>
			<p id="tbreg-merge-status" role="status" aria-live="polite" aria-atomic="true"></p>
			<div id="tbreg-merge-picker" class="tbreg-merge-picker" role="dialog" aria-labelledby="tbreg-merge-title" data-tbreg-ui hidden>
				<div class="tbreg-inserter-heading"><h3 id="tbreg-merge-title"><?php esc_html_e( 'Insertar dato', 'toolsbox-registros' ); ?></h3><button type="button" class="button" id="tbreg-merge-close"><?php esc_html_e( 'Cerrar', 'toolsbox-registros' ); ?></button></div>
				<label for="tbreg-merge-search"><?php esc_html_e( 'Buscar dato por nombre', 'toolsbox-registros' ); ?></label>
				<input type="search" id="tbreg-merge-search" autocomplete="off">
				<div id="tbreg-merge-choices"></div>
				<p id="tbreg-merge-empty" role="status" hidden><?php esc_html_e( 'No se encontraron datos.', 'toolsbox-registros' ); ?></p>
			</div>
		</section>
		<?php
	}

	/**
	 * Asunto o mensaje con selector de datos por etiqueta.
	 *
	 * @param string $name Nombre del control.
	 * @param string $label Etiqueta visible.
	 * @param string $value Texto guardado.
	 * @param string $type Tipo de control.
	 * @param string $id Identificador único.
	 * @return void
	 */
	private function merge_control( string $name, string $label, string $value, string $type, string $id ): void {
		echo '<div class="tbreg-merge-control">';
		$this->control( $name, $label, $value, $type, $id );
		echo '<button type="button" class="button" data-tbreg-merge-target="' . esc_attr( $id ) . '" aria-haspopup="dialog" aria-controls="tbreg-merge-picker" aria-expanded="false" hidden>' . esc_html__( 'Insertar dato ▾', 'toolsbox-registros' ) . '<span class="screen-reader-text">: ' . esc_html( $label ) . '</span></button></div>';
	}

	/**
	 * Catálogo único que se abre en la posición de inserción elegida.
	 *
	 * @return void
	 */
	private function inserter(): void {
		$groups = array(
			__( 'Texto y contacto', 'toolsbox-registros' ) => array( 'text', 'textarea', 'email', 'tel', 'document', 'url' ),
			__( 'Selección', 'toolsbox-registros' )        => array( 'select', 'radio', 'checkbox' ),
			__( 'Fecha y número', 'toolsbox-registros' )   => array( 'date', 'time', 'number' ),
			__( 'Especiales', 'toolsbox-registros' )       => array( 'photo', 'consent', 'hidden' ),
			__( 'Diseño', 'toolsbox-registros' )           => array( 'heading' ),
		);
		$types  = Templates::types();
		?>
		<section id="tbreg-inserter" class="tbreg-inserter" role="dialog" aria-labelledby="tbreg-inserter-title" data-tbreg-ui hidden>
			<div class="tbreg-inserter-heading"><h3 id="tbreg-inserter-title"><?php esc_html_e( 'Agregar un campo', 'toolsbox-registros' ); ?></h3><button type="button" class="button" id="tbreg-close-inserter"><?php esc_html_e( 'Cerrar', 'toolsbox-registros' ); ?></button></div>
			<label for="tbreg-inserter-search"><?php esc_html_e( 'Buscar tipo de campo', 'toolsbox-registros' ); ?></label>
			<input type="search" id="tbreg-inserter-search" autocomplete="off">
			<p id="tbreg-inserter-empty" role="status" hidden><?php esc_html_e( 'No se encontraron tipos de campo.', 'toolsbox-registros' ); ?></p>
			<div class="tbreg-inserter-groups">
			<?php foreach ( $groups as $label => $members ) : ?>
				<div class="tbreg-inserter-group"><h4><?php echo esc_html( $label ); ?></h4><div class="tbreg-inserter-types">
				<?php foreach ( $members as $type ) : ?>
					<button type="button" class="button tbreg-add-field" data-type="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $types[ $type ] ); ?></button>
				<?php endforeach; ?>
				</div></div>
			<?php endforeach; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Panel compartido por todas las pestañas, con controles POST únicos.
	 *
	 * @param array<string,mixed> $data Formulario guardado.
	 * @param string              $entries_url Listado filtrado autorizado.
	 * @return void
	 */
	private function publish_panel( array $data, string $entries_url ): void {
		$settings = $data['settings'];
		$statuses = FormsListTable::form_statuses();
		if ( 'trash' !== $data['status'] ) {
			unset( $statuses['trash'] );
		}
		$updated = '' !== $data['updated_at'] ? strtotime( $data['updated_at'] . ' UTC' ) : false;
		$status  = $updated ? sprintf( /* translators: %s: Tiempo transcurrido desde el último guardado. */ __( 'Guardado hace %s', 'toolsbox-registros' ), human_time_diff( $updated ) ) : __( 'Sin guardar', 'toolsbox-registros' );
		$preview = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'tbreg_preview_form',
					'id'     => $data['id'],
				),
				admin_url( 'admin-post.php' )
			),
			'tbreg_preview_form_' . $data['id']
		);
		?>
		<aside class="tbreg-publish postbox" aria-labelledby="tbreg-publish-title">
			<h2 id="tbreg-publish-title"><?php esc_html_e( 'Publicar', 'toolsbox-registros' ); ?></h2>
			<?php $this->select_control( 'tbreg_status', __( 'Estado del formulario', 'toolsbox-registros' ), $data['status'], $statuses ); ?>
			<p id="tbreg-email-warning" class="tbreg-warning" role="status" hidden><?php esc_html_e( 'No hay un campo con el rol Correo principal. Puedes publicar, pero no se podrá identificar el correo del inscrito.', 'toolsbox-registros' ); ?></p>
			<details class="tbreg-publish-details" open>
				<summary><?php esc_html_e( 'Cupos, fechas y vista previa', 'toolsbox-registros' ); ?></summary>
				<p><strong><?php esc_html_e( 'Inscritos / cupo:', 'toolsbox-registros' ); ?></strong>
				<?php if ( $data['id'] > 0 && current_user_can( 'tbreg_view_entries' ) ) : ?>
					<a href="<?php echo esc_url( $entries_url ); ?>"><?php echo esc_html( number_format_i18n( $data['entries_count'] ) ); ?></a>
				<?php else : ?>
					<?php echo esc_html( number_format_i18n( $data['entries_count'] ) ); ?>
				<?php endif; ?>
				/ <span id="tbreg-capacity-summary"><?php echo esc_html( $settings['capacity'] > 0 ? number_format_i18n( $settings['capacity'] ) : '∞' ); ?></span></p>
				<progress id="tbreg-capacity-progress" aria-label="<?php esc_attr_e( 'Ocupación del cupo', 'toolsbox-registros' ); ?>" data-count="<?php echo esc_attr( (string) $data['entries_count'] ); ?>" max="<?php echo esc_attr( (string) max( 1, $settings['capacity'] ) ); ?>" value="<?php echo esc_attr( (string) min( $data['entries_count'], max( 1, $settings['capacity'] ) ) ); ?>" <?php echo $settings['capacity'] > 0 ? '' : 'hidden'; ?>></progress>
				<p class="description"><?php esc_html_e( 'El contador incluye las inscripciones que ocupan cupo.', 'toolsbox-registros' ); ?></p>
				<p><strong><?php esc_html_e( 'Abre:', 'toolsbox-registros' ); ?></strong> <span id="tbreg-opens-summary"><?php echo esc_html( '' !== $settings['opens_at'] ? substr( str_replace( 'T', ' ', $settings['opens_at'] ), 0, 16 ) : __( 'Sin fecha', 'toolsbox-registros' ) ); ?></span><br>
				<strong><?php esc_html_e( 'Cierra:', 'toolsbox-registros' ); ?></strong> <span id="tbreg-closes-summary"><?php echo esc_html( '' !== $settings['closes_at'] ? substr( str_replace( 'T', ' ', $settings['closes_at'] ), 0, 16 ) : __( 'Sin fecha', 'toolsbox-registros' ) ); ?></span> <a href="#tbreg-opens-at" data-tbreg-settings-link><?php esc_html_e( 'Cambiar fechas', 'toolsbox-registros' ); ?></a></p>
				<p class="description"><?php echo esc_html( sprintf( /* translators: %s: Zona horaria del sitio. */ __( 'Hora del sitio: %s.', 'toolsbox-registros' ), wp_timezone_string() ) ); ?>
				<?php if ( in_array( wp_timezone_string(), array( 'UTC', '+00:00', 'Etc/UTC', 'GMT', 'Etc/GMT' ), true ) && current_user_can( 'manage_options' ) ) : ?>
					<a href="<?php echo esc_url( admin_url( 'options-general.php#timezone_string' ) ); ?>"><?php esc_html_e( 'Configurar zona horaria', 'toolsbox-registros' ); ?></a>
				<?php endif; ?></p>
				<?php if ( $data['id'] > 0 ) : ?>
					<a class="button" id="tbreg-preview-link" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( $preview ); ?>"><?php esc_html_e( 'Vista previa', 'toolsbox-registros' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(abre una pestaña nueva)', 'toolsbox-registros' ); ?></span></a>
					<p class="description"><?php esc_html_e( 'Muestra la última versión guardada, sin enviar inscripciones.', 'toolsbox-registros' ); ?></p>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'Guarda el formulario para ver la vista previa.', 'toolsbox-registros' ); ?></p>
				<?php endif; ?>
			</details>
			<p id="tbreg-builder-status" role="status" aria-live="polite" aria-atomic="true"><?php echo esc_html( $status ); ?></p>
			<button type="submit" class="button button-primary button-large" data-tbreg-save><?php esc_html_e( 'Guardar formulario', 'toolsbox-registros' ); ?></button>
			<span class="tbreg-save-shortcut" aria-hidden="true">Ctrl / ⌘ + S</span>
		</aside>
		<?php
	}

	/**
	 * Renderiza un control de texto con etiqueta asociada.
	 *
	 * @param string $name Nombre interno.
	 * @param string $label Etiqueta visible.
	 * @param string $value Valor guardado.
	 * @param string $type Tipo de control.
	 * @param string $id Identificador opcional para enlaces y foco.
	 * @return void
	 */
	private function control( string $name, string $label, string $value, string $type = 'text', string $id = '' ): void {
		echo '<label class="tbreg-control"><span>' . esc_html( $label ) . '</span>';
		if ( 'textarea' === $type ) {
			echo '<textarea name="' . esc_attr( $name ) . '"' . ( '' !== $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . ' rows="4">' . esc_textarea( $value ) . '</textarea>';
		} else {
			$value = 'datetime-local' === $type ? str_replace( ' ', 'T', $value ) : $value;
			echo '<input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . ( '' !== $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . ( 'number' === $type ? ' min="0" step="1"' : '' ) . '>';
		}
		echo '</label>';
	}

	/**
	 * Renderiza una lista de selección etiquetada.
	 *
	 * @param string               $name Nombre interno.
	 * @param string               $label Etiqueta visible.
	 * @param string               $value Valor guardado.
	 * @param array<string,string> $options Opciones del control.
	 * @return void
	 */
	private function select_control( string $name, string $label, string $value, array $options ): void {
		echo '<label class="tbreg-control"><span>' . esc_html( $label ) . '</span><select name="' . esc_attr( $name ) . '">';
		foreach ( $options as $key => $text ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $key, $value, false ) . '>' . esc_html( $text ) . '</option>';
		}
		echo '</select></label>';
	}

	/**
	 * Renderiza una casilla de configuración.
	 *
	 * @param string $name Nombre interno.
	 * @param string $label Etiqueta visible.
	 * @param bool   $value Estado guardado.
	 * @param string $controls Panel dependiente.
	 * @return void
	 */
	private function checkbox_control( string $name, string $label, bool $value, string $controls = '' ): void {
		echo '<label class="tbreg-control tbreg-checkbox tbreg-switch"><input type="checkbox" role="switch" name="' . esc_attr( $name ) . '" value="1"' . ( '' !== $controls ? ' aria-controls="' . esc_attr( $controls ) . '"' : '' ) . ' ' . checked( $value, true, false ) . '><span>' . esc_html( $label ) . '</span></label>';
	}
}
