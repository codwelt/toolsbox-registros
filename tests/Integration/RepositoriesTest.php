<?php
/**
 * Integración con MySQL en tablas de prueba de prefijo aleatorio.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);

namespace Codwelt\ToolsBoxRegistros\Tests\Integration;

use Codwelt\ToolsBoxRegistros\Domain\Form;
use Codwelt\ToolsBoxRegistros\Domain\Field;
use Codwelt\ToolsBoxRegistros\Admin\FormEditorPage;
use Codwelt\ToolsBoxRegistros\Admin\FormPreviewPage;
use Codwelt\ToolsBoxRegistros\Admin\FormsListTable;
use Codwelt\ToolsBoxRegistros\Admin\Menu;
use Codwelt\ToolsBoxRegistros\Admin\Templates;
use Codwelt\ToolsBoxRegistros\Admin\Notices;
use Codwelt\ToolsBoxRegistros\Install\Installer;
use Codwelt\ToolsBoxRegistros\Repository\EntryRepository;
use Codwelt\ToolsBoxRegistros\Repository\FormRepository;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RepositoriesTest extends TestCase {
	private static \wpdb $db;
	private FormRepository $forms;
	private EntryRepository $entries;
	private int $previous_user;
	private \Closure $cap_filter;

	public static function setUpBeforeClass(): void {
		if ( '1' !== getenv( 'TBREG_RUN_INTEGRATION' ) ) {
			self::markTestSkipped( 'Activa TBREG_RUN_INTEGRATION=1 para probar MySQL.' );
		}
		if ( ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
			throw new \RuntimeException( 'Las pruebas de integración requieren un WordPress local o de desarrollo.' );
		}
		self::$db = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		self::$db->set_prefix( 'tbregtest_' . bin2hex( random_bytes( 6 ) ) . '_' );
		self::install_schema();
		register_shutdown_function( array( self::class, 'cleanup' ) );
	}

	private static function install_schema(): void {
		global $wpdb;
		$original = $wpdb;
		$wpdb = self::$db;
		try {
			dbDelta( Installer::schema( self::$db ) );
		} finally {
			$wpdb = $original;
		}
	}

	public static function cleanup(): void {
		if ( ! isset( self::$db ) || ! preg_match( '/^tbregtest_[a-f0-9]{12}_$/D', self::$db->prefix ) ) {
			return;
		}
		foreach ( array( 'entry_values', 'entries', 'forms' ) as $suffix ) {
			self::$db->query( 'DROP TABLE IF EXISTS `' . self::$db->prefix . 'tbreg_' . $suffix . '`' );
		}
	}

	protected function setUp(): void {
		$this->previous_user = get_current_user_id();
		$users = get_users( array( 'number' => 1, 'fields' => 'ID' ) );
		if ( empty( $users ) ) {
			throw new \RuntimeException( 'La integración requiere un usuario WordPress local.' );
		}
		wp_set_current_user( (int) $users[0] );
		$this->cap_filter = static function ( array $caps ): array {
			if ( 0 === get_current_user_id() ) return $caps;
			foreach ( array( 'tbreg_manage_forms', 'tbreg_view_entries', 'tbreg_edit_entries', 'tbreg_export_entries' ) as $cap ) {
				$caps[ $cap ] = true;
			}
			return $caps;
		};
		add_filter( 'user_has_cap', $this->cap_filter );
		foreach ( array( 'entry_values', 'entries', 'forms' ) as $suffix ) {
			self::$db->query( 'DELETE FROM `' . self::$db->prefix . 'tbreg_' . $suffix . '`' );
		}
		$this->forms = new FormRepository( self::$db );
		$this->entries = new EntryRepository( self::$db );
	}

	protected function tearDown(): void {
		remove_filter( 'user_has_cap', $this->cap_filter );
		wp_set_current_user( $this->previous_user );
		$_GET = array();
		$_REQUEST = array();
	}

	private function editor_input( Form $form ): array {
		return array(
			'tbreg_nonce' => wp_create_nonce( 'tbreg_save_form' ),
			'tbreg_id' => (string) $form->id(),
			'tbreg_revision' => $form->revision(),
			'tbreg_title' => $form->get( 'title' ),
			'tbreg_slug' => $form->get( 'slug' ),
			'tbreg_description' => $form->get( 'description' ),
			'tbreg_status' => $form->get( 'status' ),
			'tbreg_fields' => wp_json_encode( $form->toArray()['fields'] ),
			'tbreg_settings' => $form->settings(),
		);
	}

	private function conditional_settings(): array {
		return array(
			'capacity' => 0, 'waitlist' => true, 'pdf_enabled' => false,
			'receipt_text' => 'Segunda página guardada', 'success_message' => 'Mensaje guardado',
			'redirect_url' => 'https://example.test/gracias',
			'notify_admin' => array( 'enabled' => false, 'to' => 'qa@example.test', 'subject' => 'Alta {event_name}' ),
			'confirm_attendee' => array( 'enabled' => false, 'subject' => 'Gracias {entry_id}', 'body' => '<p>Hola {field:f_name}</p>' ),
		);
	}

	public function test_editor_inactive_settings_and_after_submit_action(): void {
		$form = $this->form( $this->conditional_settings() );
		$input = $this->editor_input( $form ); $input['tbreg_after_submit'] = 'redirect';
		$editor = new FormEditorPage( $this->forms ); $saved = $editor->save( $input );
		$this->assertInstanceOf( Form::class, $saved );
		$this->assertSame( $form->settings(), $saved->settings() );
		$_GET['id'] = (string) $saved->id(); ob_start(); $editor->render(); $html = ob_get_clean();
		$this->assertSame( 7, substr_count( $html, 'class="tbreg-settings-card postbox"' ) );
		$this->assertSame( 3, substr_count( $html, 'data-tbreg-merge-target=' ) );
		$this->assertStringContainsString( 'El envío de correos llega en la próxima versión', $html );
		$this->assertStringNotContainsString( 'notify_admin][body]', $html );
		$directory = getenv( 'TBREG_EDITOR_FIXTURE_DIR' );
		if ( $directory && is_dir( $directory ) ) {
			file_put_contents( $directory . '/editor-settings.html', $html );
			file_put_contents( $directory . '/settings-fields.json', wp_json_encode( $saved->toArray()['fields'] ) );
		}
		$input = $this->editor_input( $saved ); $input['tbreg_after_submit'] = 'message';
		$saved = $editor->save( $input ); $this->assertInstanceOf( Form::class, $saved );
		$this->assertSame( '', $saved->settings()['redirect_url'] );
		$expected = $form->settings(); $expected['redirect_url'] = '';
		$this->assertSame( $expected, $saved->settings() );
		$input = $this->editor_input( $saved ); $input['tbreg_after_submit'] = 'redirect';
		$this->assertInstanceOf( \WP_Error::class, $editor->save( $input ) );
		foreach ( array( 'invalid', array( 'redirect' ) ) as $mode ) {
			$input['tbreg_after_submit'] = $mode;
			$this->assertInstanceOf( \WP_Error::class, $editor->save( $input ) );
		}
		$this->assertSame( $expected, $this->forms->find( $saved->id() )->settings() );
	}

	public function test_editor_notification_tokens_from_dom_persist_while_disabled(): void {
		$form = $this->form( $this->conditional_settings() ); $input = $this->editor_input( $form );
		$directory = getenv( 'TBREG_EDITOR_FIXTURE_DIR' );
		if ( $directory && is_file( $directory . '/dom-settings-post.json' ) ) {
			$dom = json_decode( file_get_contents( $directory . '/dom-settings-post.json' ), true, 512, JSON_THROW_ON_ERROR );
			$input['tbreg_settings'] = $dom['settings']; $input['tbreg_after_submit'] = $dom['mode'];
		} else {
			$input['tbreg_after_submit'] = 'redirect';
			$input['tbreg_settings']['notify_admin']['subject'] = 'Alta {event_name}{field:f_name}';
			$input['tbreg_settings']['confirm_attendee']['body'] = '<p>Hola {field:f_name}</p>{all_fields}';
		}
		$saved = ( new FormEditorPage( $this->forms ) )->save( $input );
		$this->assertInstanceOf( Form::class, $saved );
		$expected = $form->settings();
		$expected['notify_admin']['subject'] .= '{field:f_name}';
		$expected['confirm_attendee']['body'] .= '{all_fields}';
		$this->assertSame( $expected, $saved->settings() );
		$this->assertSame( $expected, $this->forms->find( $saved->id() )->settings() );
		$this->assertArrayNotHasKey( 'tbreg_after_submit', $saved->settings() );
	}

	public function test_editor_conditional_options_roundtrip_preserves_canonical_json(): void {
		$template = \Codwelt\ToolsBoxRegistros\Admin\LanguageTemplate::get();
		$form = $this->forms->save( Form::fromArray( array( 'title' => 'Idiomas QA', 'fields' => $template['fields'], 'settings' => $template['settings'] ) ) );
		$this->assertInstanceOf( Form::class, $form );
		$original = $form->toArray()['fields'];
		$directory = getenv( 'TBREG_EDITOR_FIXTURE_DIR' );
		if ( $directory && is_dir( $directory ) ) file_put_contents( $directory . '/canonical-fields.json', wp_json_encode( $original ) );
		$input = $this->editor_input( $form );
		if ( $directory && is_file( $directory . '/dom-roundtrip.json' ) ) {
			$input['tbreg_fields'] = file_get_contents( $directory . '/dom-roundtrip.json' );
		}
		$this->assertSame( $original, json_decode( $input['tbreg_fields'], true, 512, JSON_THROW_ON_ERROR ) );
		$saved = ( new FormEditorPage( $this->forms ) )->save( $input );
		$this->assertInstanceOf( Form::class, $saved );
		$this->assertSame( wp_json_encode( $original ), wp_json_encode( $saved->toArray()['fields'] ) );
		$this->assertSame( $form->settings(), $saved->settings() );
		$this->assertSame( $original, $this->forms->find( $saved->id() )->toArray()['fields'] );
	}

	public function test_editor_saves_all_types_order_options_and_settings(): void {
		$fields = array();
		foreach ( array_reverse( Field::TYPES ) as $type ) {
			$field = array( 'key' => 'f_' . $type, 'type' => $type, 'label' => 'Campo ' . $type );
			if ( in_array( $type, array( 'select', 'radio', 'checkbox' ), true ) ) {
				$field['options'] = array( array( 'label' => 'Medellín', 'value' => 'medellin' ), array( 'label' => 'Bogotá', 'value' => 'bogota' ) );
			}
			$fields[] = $field;
		}
		$form = Form::fromArray( array( 'title' => 'Los quince tipos 😀', 'fields' => $fields ) );
		$input = $this->editor_input( $form );
		$dom_fields = getenv( 'TBREG_EDITOR_DOM_FIELDS' );
		if ( false !== $dom_fields && is_file( $dom_fields ) ) {
			$input['tbreg_fields'] = file_get_contents( $dom_fields );
		}
		$input['tbreg_settings']['capacity'] = '25';
		$input['tbreg_settings']['waitlist'] = '1';
		$input['tbreg_settings']['opens_at'] = '2026-11-01T09:00';
		$input['tbreg_settings']['closes_at'] = '2026-11-03T15:00';
		$input['tbreg_settings']['event']['starts_at'] = '2026-11-04T09:00';
		$input['tbreg_settings']['notify_admin']['to'] = 'uno@example.test, dos@example.test';
		$saved = ( new FormEditorPage( $this->forms ) )->save( $input );
		$this->assertInstanceOf( Form::class, $saved );
		$loaded = $this->forms->find( $saved->id() );
		$submitted = json_decode( $input['tbreg_fields'], true, 64, JSON_THROW_ON_ERROR );
		$this->assertSame( array_column( $submitted, 'type' ), array_map( static fn ( Field $field ): string => $field->get( 'type' ), $loaded->fields() ) );
		$this->assertSame( array_column( $submitted, 'key' ), array_column( $loaded->toArray()['fields'], 'key' ) );
		$this->assertEqualsCanonicalizing( Field::TYPES, array_column( $submitted, 'type' ) );
		$this->assertSame( array( 'medellin', 'bogota' ), array_column( array_values( array_filter( $loaded->toArray()['fields'], static fn ( array $field ): bool => 'checkbox' === $field['type'] ) )[0]['options'], 'value' ) );
		$this->assertSame( 25, $loaded->settings()['capacity'] );
		$this->assertTrue( $loaded->settings()['waitlist'] );
		$this->assertSame( '2026-11-01 09:00:00', $loaded->settings()['opens_at'] );
		$this->assertSame( '2026-11-04 09:00:00', $loaded->settings()['event']['starts_at'] );
		$this->assertTrue( array_values( array_filter( $loaded->toArray()['fields'], static fn ( array $field ): bool => 'consent' === $field['type'] ) )[0]['required'] );
	}

	public function test_editor_generates_option_values_on_server_and_remaps_defaults(): void {
		$form = Form::fromArray( array( 'title' => 'Opciones', 'fields' => array( array( 'key' => 'f_city', 'type' => 'select', 'label' => 'Ciudad', 'options' => array( array( 'label' => 'Bogotá & Norte', 'value' => 'preview' ) ), 'default' => 'preview' ) ) ) );
		$input = $this->editor_input( $form );
		$fields = $form->toArray()['fields'];
		$fields[0]['options'][0]['auto'] = true;
		$input['tbreg_fields'] = wp_json_encode( $fields );
		$saved = ( new FormEditorPage( $this->forms ) )->save( $input );
		$this->assertInstanceOf( Form::class, $saved );
		$this->assertSame( sanitize_title( 'Bogotá & Norte' ), $saved->toArray()['fields'][0]['options'][0]['value'] );
		$this->assertSame( $saved->toArray()['fields'][0]['options'][0]['value'], $saved->toArray()['fields'][0]['default'] );
		$this->assertArrayNotHasKey( 'auto', $saved->toArray()['fields'][0]['options'][0] );
	}

	public function test_editor_rejects_tampering_without_changing_saved_form(): void {
		$form = $this->form();
		$input = $this->editor_input( $form );
		$fields = $form->toArray()['fields'];
		$cases = array( '{broken', '{}', '[null]' );
		$bad = $fields; $bad[0]['type'] = 'exec'; $cases[] = wp_json_encode( $bad );
		$bad = $fields; $bad[1]['key'] = $bad[0]['key']; $cases[] = wp_json_encode( $bad );
		$bad = $fields; $bad[3]['options'][1]['value'] = 'bogota'; $cases[] = wp_json_encode( $bad );
		$editor = new FormEditorPage( $this->forms );
		foreach ( $cases as $json ) {
			$input['tbreg_fields'] = $json;
			$this->assertInstanceOf( \WP_Error::class, $editor->save( $input ) );
			$this->assertSame( $form->toArray(), $this->forms->find( $form->id() )->toArray() );
		}
		$input = $this->editor_input( $form );
		$input['tbreg_settings']['waitlist'] = array( '1' );
		$this->assertInstanceOf( \WP_Error::class, $editor->save( $input ) );
		$input = $this->editor_input( $form );
		$input['tbreg_settings']['event']['starts_at'] = '2026-02-30T12:00';
		$this->assertInstanceOf( \WP_Error::class, $editor->save( $input ) );
		$input = $this->editor_input( $form );
		$input['tbreg_settings']['notify_admin']['to'] = 'bad-address';
		$this->assertInstanceOf( \WP_Error::class, $editor->save( $input ) );
	}

	public function test_editor_requires_nonce_and_capability_and_page_access(): void {
		$form = $this->form();
		$input = $this->editor_input( $form );
		$input['tbreg_nonce'] = 'invalid';
		$editor = new FormEditorPage( $this->forms );
		$this->assertSame( 'tbreg_invalid_nonce', $editor->save( $input )->get_error_code() );
		$input = $this->editor_input( $form );
		wp_set_current_user( 0 );
		$this->assertSame( 'tbreg_forbidden', $editor->save( $input )->get_error_code() );
		$this->assertSame( 'tbreg_forbidden', ( new Menu( $this->forms ) )->perform_action( 'trash', $form->id(), '' )->get_error_code() );
		$handler = static fn () => static function ( $message, $title, $args ): void { throw new \RuntimeException( (string) $args['response'] ); };
		add_filter( 'wp_die_handler', $handler );
		try {
			foreach ( array( array( $editor, 'context' ), array( new Menu( $this->forms ), 'render_forms' ), array( new Menu( $this->forms ), 'render_entries' ), array( new Menu( $this->forms ), 'render_export' ) ) as $callback ) {
				try { $callback(); $this->fail( 'Acceso no autorizado.' ); } catch ( \RuntimeException $exception ) { $this->assertSame( '403', $exception->getMessage() ); }
			}
		} finally {
			remove_filter( 'wp_die_handler', $handler );
		}
	}

	public function test_editor_revision_rejects_stale_edits_but_allows_counter_updates(): void {
		$form = $this->form();
		$input = $this->editor_input( $form );
		$this->entry( $form );
		$editor = new FormEditorPage( $this->forms );
		$input['tbreg_title'] = 'Nuevo nombre';
		$this->assertInstanceOf( Form::class, $editor->save( $input ) );
		$input['tbreg_title'] = 'Edición antigua';
		$this->assertInstanceOf( \WP_Error::class, $editor->save( $input ) );
		$this->assertSame( 'Nuevo nombre', $this->forms->find( $form->id() )->get( 'title' ) );
		$this->assertSame( 1, $this->forms->find( $form->id() )->get( 'entries_count' ) );
	}

	public function test_admin_post_handler_checks_method_and_saves_unslashed_input(): void {
		$form = $this->form();
		$editor = new FormEditorPage( $this->forms );
		$previous_method = $_SERVER['REQUEST_METHOD'] ?? null;
		$handler = static fn () => static function ( $message, $title, $args ): void { throw new \RuntimeException( (string) $args['response'] ); };
		add_filter( 'wp_die_handler', $handler );
		$redirect = static function ( string $location ): string { throw new \RuntimeException( $location ); };
		add_filter( 'wp_redirect', $redirect );
		try {
			$_SERVER['REQUEST_METHOD'] = 'GET';
			try { $editor->handle_save(); $this->fail( 'Guardado por GET.' ); } catch ( \RuntimeException $exception ) { $this->assertSame( '405', $exception->getMessage() ); }
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$_REQUEST['tbreg_nonce'] = 'invalid';
			try { $editor->handle_save(); $this->fail( 'Nonce inválido aceptado.' ); } catch ( \RuntimeException $exception ) { $this->assertSame( '403', $exception->getMessage() ); }
			$input = $this->editor_input( $form ); $input['tbreg_title'] = 'Curso "Diseño" de O\'Connor';
			$_POST = wp_slash( $input ); $_REQUEST = $_POST;
			try {
				$editor->handle_save(); $this->fail( 'Falta la redirección PRG.' );
			} catch ( \RuntimeException $exception ) {
				$query = array(); parse_str( (string) wp_parse_url( $exception->getMessage(), PHP_URL_QUERY ), $query );
				$this->assertSame( 'toolsbox-registros-edit', $query['page'] );
				$this->assertSame( (string) $form->id(), $query['id'] );
				$this->assertSame( $input['tbreg_title'], $this->forms->find( $form->id() )->get( 'title' ) );
				$this->assertNotEmpty( $query['tbreg_notice'] );
				$_GET['tbreg_notice'] = $query['tbreg_notice']; ob_start(); Notices::render(); $notice = ob_get_clean();
				$this->assertStringContainsString( 'Formulario guardado', $notice );
			}
		} finally {
			remove_filter( 'wp_redirect', $redirect ); remove_filter( 'wp_die_handler', $handler );
			$_POST = array(); $_REQUEST = array();
			if ( null === $previous_method ) unset( $_SERVER['REQUEST_METHOD'] ); else $_SERVER['REQUEST_METHOD'] = $previous_method;
		}
	}

	public function test_editor_archives_used_fields_and_rejects_type_changes(): void {
		$form = $this->form();
		$this->entry( $form );
		$this->assertContains( 'f_city', $this->forms->usedFieldKeys( $form->id() ) );
		$editor = new FormEditorPage( $this->forms );
		$input = $this->editor_input( $form );
		$fields = $form->toArray()['fields'];
		$fields[3]['type'] = 'radio'; $input['tbreg_fields'] = wp_json_encode( $fields );
		$this->assertInstanceOf( \WP_Error::class, $editor->save( $input ) );
		array_splice( $fields, 3, 1 ); $input['tbreg_fields'] = wp_json_encode( $fields );
		$saved = $editor->save( $input );
		$this->assertInstanceOf( Form::class, $saved );
		$this->assertTrue( $saved->toArray()['fields'][4]['archived'] );
		$this->assertSame( 'f_city', $saved->toArray()['fields'][4]['key'] );
	}

	public function test_form_actions_require_specific_nonces_and_preserve_entries(): void {
		$form = $this->form(); $id = $this->entry( $form );
		$menu = new Menu( $this->forms );
		$this->assertInstanceOf( \WP_Error::class, $menu->perform_action( 'delete', $form->id(), wp_create_nonce( 'tbreg_form_action_delete_' . $form->id() ) ) );
		$this->assertInstanceOf( \WP_Error::class, $menu->perform_action( 'trash', $form->id(), wp_create_nonce( 'tbreg_form_action_close_' . $form->id() ) ) );
		foreach ( array( 'close' => 'closed', 'trash' => 'trash', 'restore' => 'draft' ) as $action => $status ) {
			$result = $menu->perform_action( $action, $form->id(), wp_create_nonce( 'tbreg_form_action_' . $action . '_' . $form->id() ) );
			$this->assertInstanceOf( Form::class, $result );
			$this->assertSame( $status, $result->get( 'status' ) );
			$this->assertNotNull( $this->entries->find( $id ) );
		}
		$copy = $menu->perform_action( 'duplicate', $form->id(), wp_create_nonce( 'tbreg_form_action_duplicate_' . $form->id() ) );
		$this->assertInstanceOf( Form::class, $copy );
		$this->assertSame( 0, $copy->get( 'entries_count' ) );
	}

	public function test_templates_are_valid_have_unique_keys_and_copy_as_drafts(): void {
		foreach ( Templates::all() as $name => $template ) {
			$form = Form::fromArray( array( 'title' => $template['label'], 'fields' => $template['fields'], 'settings' => $template['settings'] ?? array() ) );
			$saved = ( new FormEditorPage( $this->forms ) )->save( $this->editor_input( $form ) );
			$this->assertInstanceOf( Form::class, $saved );
			$this->assertSame( 'draft', $saved->get( 'status' ) );
			$this->assertSame( count( $template['fields'] ), count( array_unique( array_column( $template['fields'], 'key' ) ) ) );
		}
		$this->assertSame( count( Templates::all() ), $this->forms->count() );
		$this->assertSame( 1, $this->forms->count( array( 'search' => 'Webinar' ) ) );
	}

	public function test_notices_are_escaped_user_scoped_and_consumed_once(): void {
		$token = Notices::put( '<script>alert(1)</script>', true );
		$_GET['tbreg_notice'] = $token;
		ob_start(); Notices::render(); $output = ob_get_clean();
		$this->assertStringContainsString( '&lt;script&gt;', $output );
		$this->assertStringNotContainsString( '<script>', $output );
		ob_start(); Notices::render(); $this->assertSame( '', ob_get_clean() );
		$token = Notices::put( 'Privado' ); $_GET['tbreg_notice'] = $token;
		$user = get_current_user_id(); wp_set_current_user( 0 );
		ob_start(); Notices::render(); $this->assertSame( '', ob_get_clean() );
		wp_set_current_user( $user );
		ob_start(); Notices::render(); $this->assertStringContainsString( 'Privado', ob_get_clean() );
	}

	public function test_admin_html_assets_and_list_pagination(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		set_current_screen( 'toplevel_page_toolsbox-registros' );
		for ( $i = 0; $i < 21; ++$i ) $this->form();
		$table = new FormsListTable( $this->forms ); $table->prepare_items();
		$this->assertCount( 20, $table->items );
		$_GET['paged'] = '2'; $_REQUEST['paged'] = '2'; $table->prepare_items(); $this->assertCount( 1, $table->items );
		$_GET = array();
		$_REQUEST = array();
		$menu = new Menu( $this->forms ); $menu->register(); $menu->menu();
		$this->assertNotFalse( has_action( 'admin_post_tbreg_save_form' ) );
		$this->assertNotFalse( has_action( 'admin_post_tbreg_form_action' ) );
		wp_dequeue_script( 'tbreg-builder' ); wp_dequeue_style( 'tbreg-admin' );
		$menu->assets( 'dashboard' );
		$this->assertFalse( wp_script_is( 'tbreg-builder', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'tbreg-admin', 'enqueued' ) );
		$menu->assets( 'toolsbox-registros_page_toolsbox-registros-edit' );
		$this->assertTrue( wp_script_is( 'tbreg-builder', 'enqueued' ) );
		$this->assertSame( array( 'jquery-ui-sortable' ), wp_scripts()->registered['tbreg-builder']->deps );
		ob_start(); ( new FormEditorPage( $this->forms ) )->render(); $html = ob_get_clean();
		$this->assertStringContainsString( 'name="tbreg_nonce"', $html );
		$this->assertStringContainsString( 'name="tbreg_fields"', $html );
		$this->assertSame( count( Field::TYPES ), substr_count( $html, 'class="button tbreg-add-field"' ) );
		$this->assertSame( 3, substr_count( $html, 'role="tabpanel"' ) );
		$this->assertSame( 1, substr_count( $html, 'name="tbreg_status"' ) );
		$this->assertStringNotContainsString( 'tbreg-panel-publish', $html );
		$this->assertStringContainsString( 'tbreg-publish postbox', $html );
		$this->assertStringContainsString( 'Guarda el formulario para obtener su shortcode.', $html );
		$this->assertStringNotContainsString( 'data-tbreg-copy-target', $html );
		$directory = getenv( 'TBREG_EDITOR_FIXTURE_DIR' );
		if ( false !== $directory && is_dir( $directory ) ) {
			file_put_contents( $directory . '/editor.html', $html );
			file_put_contents( $directory . '/builder-config.js', wp_scripts()->get_data( 'tbreg-builder', 'data' ) );
		}
		ob_start(); $menu->render_forms(); $output = ob_get_clean();
		$this->assertStringContainsString( 'tbreg_form_action', $output );
		$this->assertStringContainsString( '[toolsbox_registro', $output );
		if ( $directory && is_dir( $directory ) ) file_put_contents( $directory . '/forms.html', $output );
	}

	public function test_editor_header_counts_all_entries_and_respects_link_capabilities(): void {
		$form = $this->form();
		$this->entry( $form );
		$this->entry( $form, 'cancelada@example.test', array(), array( 'status' => 'cancelled' ) );
		$this->entry( $form, 'espera@example.test', array(), array( 'status' => 'waitlist' ) );
		$_GET = array( 'id' => (string) $form->id() );
		$editor = new FormEditorPage( $this->forms, $this->entries );
		foreach ( array( 'draft' => 'Borrador', 'published' => 'Publicado', 'closed' => 'Cerrado' ) as $status => $label ) {
			$data = $this->forms->find( $form->id() )->toArray();
			$data['status'] = $status;
			$this->forms->save( Form::fromArray( $data ) );
			ob_start(); $editor->render(); $html = ob_get_clean();
			$this->assertStringContainsString( 'data-status="' . $status . '">' . $label, $html );
		}
		$this->assertSame( 1, $this->forms->find( $form->id() )->get( 'entries_count' ) );
		$this->assertSame( 3, $this->entries->count( array( 'form_id' => $form->id() ) ) );
		$this->assertSame( 1, $this->entries->count( array( 'form_id' => $form->id(), 'status' => 'waitlist' ) ) );
		$this->assertStringContainsString( 'Ver inscripciones (3)', $html );
		$this->assertStringContainsString( 'page=toolsbox-registros-entries&#038;form_id=' . $form->id(), $html );
		$this->assertStringContainsString( 'page=toolsbox-registros-export&#038;form_id=' . $form->id(), $html );
		$this->assertSame( 1, substr_count( $html, 'name="tbreg_slug"' ) );
		$this->assertSame( 1, substr_count( $html, 'id="tbreg-shortcode"' ) );
		$this->assertLessThan( strpos( $html, 'role="tablist"' ), strpos( $html, 'id="tbreg-shortcode"' ) );
		$this->assertStringContainsString( '[toolsbox_registro id=&quot;' . $form->id() . '&quot;]', $html );
		$directory = getenv( 'TBREG_EDITOR_FIXTURE_DIR' );
		if ( $directory && is_dir( $directory ) ) file_put_contents( $directory . '/editor-saved.html', $html );
		$deny = static function ( array $caps ): array { $caps['tbreg_view_entries'] = false; $caps['tbreg_export_entries'] = false; return $caps; };
		add_filter( 'user_has_cap', $deny, 99 );
		try {
			ob_start(); $editor->render(); $restricted = ob_get_clean();
			$this->assertStringNotContainsString( 'Ver inscripciones', $restricted );
			$this->assertStringNotContainsString( '>Exportar</a>', $restricted );
			$this->assertStringContainsString( 'id="tbreg-shortcode"', $restricted );
		} finally {
			remove_filter( 'user_has_cap', $deny, 99 );
		}
	}

	public function test_inserter_catalog_and_timezone_link_respect_site_settings_and_capability(): void {
		$editor = new FormEditorPage( $this->forms, $this->entries );
		$utc = static fn (): string => 'UTC'; add_filter( 'pre_option_timezone_string', $utc );
		$allow = static function ( array $caps ): array { $caps['manage_options'] = true; return $caps; }; add_filter( 'user_has_cap', $allow, 99 );
		try {
			ob_start(); $editor->render(); $html = ob_get_clean();
			$this->assertStringNotContainsString( 'tbreg-palette', $html );
			$this->assertSame( 5, substr_count( $html, 'class="tbreg-inserter-group"' ) );
			preg_match_all( '/data-type="([a-z]+)"/', $html, $matches );
			$this->assertEqualsCanonicalizing( Field::TYPES, $matches[1] );
			$this->assertSame( count( Field::TYPES ), count( array_unique( $matches[1] ) ) );
			$this->assertStringContainsString( 'options-general.php#timezone_string', $html );
			$this->assertStringContainsString( 'href="#tbreg-opens-at" data-tbreg-settings-link', $html );
			remove_filter( 'user_has_cap', $allow, 99 );
			$deny = static function ( array $caps ): array { $caps['manage_options'] = false; return $caps; }; add_filter( 'user_has_cap', $deny, 99 );
			try {
				ob_start(); $editor->render(); $restricted = ob_get_clean();
				$this->assertStringNotContainsString( 'options-general.php#timezone_string', $restricted );
			} finally { remove_filter( 'user_has_cap', $deny, 99 ); }
		} finally { remove_filter( 'pre_option_timezone_string', $utc ); remove_filter( 'user_has_cap', $allow, 99 ); }
		$bogota = static fn (): string => 'America/Bogota'; add_filter( 'pre_option_timezone_string', $bogota );
		try {
			ob_start(); $editor->render(); $html = ob_get_clean();
			$this->assertStringNotContainsString( 'options-general.php#timezone_string', $html );
		} finally { remove_filter( 'pre_option_timezone_string', $bogota ); }
	}

	public function test_preview_requires_capability_matching_nonce_get_and_existing_form(): void {
		$form = $this->form(); $other = $this->form();
		$page = new FormPreviewPage( $this->forms );
		$valid = array( 'id' => (string) $form->id(), '_wpnonce' => wp_create_nonce( 'tbreg_preview_form_' . $form->id() ) );
		foreach ( array( array(), array( 'id' => array( 1 ), '_wpnonce' => 'bad' ), array_merge( $valid, array( '_wpnonce' => 'bad' ) ), array_merge( $valid, array( 'id' => (string) $other->id() ) ) ) as $request ) {
			$result = $page->document( $request ); $this->assertInstanceOf( \WP_Error::class, $result ); $this->assertSame( 403, $result->get_error_data()['status'] );
		}
		$this->assertSame( 405, $page->document( $valid, 'POST' )->get_error_data()['status'] );
		$user = get_current_user_id(); wp_set_current_user( 0 );
		$this->assertSame( 403, $page->document( $valid )->get_error_data()['status'] ); wp_set_current_user( $user );
		$this->forms->trash( $form->id() );
		$this->assertSame( 404, $page->document( $valid )->get_error_data()['status'] );
		$id = $other->id() + 100;
		$this->assertSame( 404, $page->document( array( 'id' => (string) $id, '_wpnonce' => wp_create_nonce( 'tbreg_preview_form_' . $id ) ) )->get_error_data()['status'] );
	}

	public function test_preview_renders_saved_draft_and_closed_forms_without_submission_credentials(): void {
		$form = $this->form( array( 'capacity' => 2, 'opens_at' => '2030-10-01 08:00:00' ) );
		$this->entry( $form );
		$page = new FormPreviewPage( $this->forms );
		$request = array( 'id' => (string) $form->id(), '_wpnonce' => wp_create_nonce( 'tbreg_preview_form_' . $form->id() ), 'tbreg_title' => 'Título sin guardar ignorado' );
		foreach ( array( 'draft', 'closed' ) as $status ) {
			$data = $this->forms->find( $form->id() )->toArray(); $data['status'] = $status;
			$this->forms->save( Form::fromArray( $data ) );
			$html = $page->document( $request );
			$this->assertIsString( $html );
			$this->assertStringContainsString( 'data-preview="true"', $html );
			$this->assertStringContainsString( '<button type="submit"  disabled=', $html );
			$this->assertStringContainsString( 'Seminario Bogotá', $html );
			$this->assertStringNotContainsString( 'Título sin guardar ignorado', $html );
			$this->assertStringNotContainsString( 'name="tbreg_token"', $html );
			$this->assertStringNotContainsString( 'name="tbreg_ts"', $html );
		}
		$this->assertSame( 1, $this->entries->count( array( 'form_id' => $form->id() ) ) );
	}

	public static function tearDownAfterClass(): void {
		self::cleanup();
	}

	private function form( array $settings = array() ): Form {
		$data = tbreg_test_form_data();
		$data['settings'] = $settings;
		$form = $this->forms->save( Form::fromArray( $data ) );
		$this->assertInstanceOf( Form::class, $form );
		return $form;
	}

	private function entry( Form $form, string $email = 'ana@example.test', array $extra = array(), array $meta = array() ): int {
		$id = $this->entries->create( $form, array_merge( array( 'f_name' => 'Ana Muñoz 😀', 'f_email' => $email, 'f_document' => 'CC123', 'f_city' => 'bogota', 'f_choices' => array( 'a', 'b' ) ), $extra ), $meta );
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );
		return $id;
	}

	public function test_schema_is_innodb_indexed_and_idempotent(): void {
		$form = $this->form();
		$id = $this->entry( $form );
		self::install_schema();
		foreach ( array( 'forms', 'entries', 'entry_values' ) as $suffix ) {
			$table = self::$db->prefix . 'tbreg_' . $suffix;
			$status = self::$db->get_row( self::$db->prepare( 'SHOW TABLE STATUS LIKE %s', self::$db->esc_like( $table ) ), ARRAY_A );
			$this->assertSame( 'InnoDB', $status['Engine'] );
			$this->assertGreaterThanOrEqual( 2, count( self::$db->get_results( 'SHOW INDEX FROM `' . $table . '`' ) ) );
		}
		$this->assertSame( 'Ana Muñoz 😀', $this->entries->values( $id )['f_name'] );
		$this->assertSame( 1, $this->forms->find( $form->id() )->get( 'entries_count' ) );
	}

	public function test_form_crud_and_duplicate_do_not_copy_entries(): void {
		$form = $this->form();
		$id = $this->entry( $form );
		$this->assertSame( $form->id(), $this->forms->findBySlug( $form->get( 'slug' ) )->id() );
		$this->assertCount( 1, $this->forms->all( array( 'search' => 'Bogotá' ) ) );
		$copy = $this->forms->duplicate( $form->id() );
		$this->assertInstanceOf( Form::class, $copy );
		$this->assertSame( 'draft', $copy->get( 'status' ) );
		$this->assertSame( 0, $copy->get( 'entries_count' ) );
		$this->assertCount( 0, $this->entries->paginate( array( 'form_id' => $copy->id() ) )['items'] );
		$this->assertNotSame( $form->get( 'slug' ), $copy->get( 'slug' ) );
		$this->assertTrue( $this->forms->trash( $form->id() ) );
		$this->assertCount( 1, $this->forms->all() );
		$this->assertNotNull( $this->entries->find( $id ) );
		$this->assertTrue( $this->forms->delete( $form->id() ) );
		$this->assertNull( $this->entries->find( $id ) );
		$this->assertSame( array(), $this->entries->values( $id ) );
	}

	public function test_fields_with_values_are_archived_and_cannot_change_type(): void {
		$form = $this->form();
		$id = $this->entry( $form );
		$data = $form->toArray();
		$data['fields'][3]['type'] = 'radio';
		$this->assertInstanceOf( \WP_Error::class, $this->forms->save( Form::fromArray( $data ) ) );
		$data = $form->toArray();
		unset( $data['fields'][3] );
		$data['fields'] = array_values( $data['fields'] );
		$data['entries_count'] = 999;
		$saved = $this->forms->save( Form::fromArray( $data ) );
		$this->assertInstanceOf( Form::class, $saved );
		$this->assertSame( 1, $saved->get( 'entries_count' ) );
		$city = array_values( array_filter( $saved->fields(), static fn ( $field ): bool => $field->key() === 'f_city' ) )[0];
		$this->assertTrue( $city->get( 'archived' ) );
		$this->assertSame( 'Bogotá', $city->get( 'options' )[0]['label'] );
		$this->assertSame( 'bogota', $this->entries->values( $id )['f_city'] );
		$this->assertInstanceOf( \WP_Error::class, $this->entries->create( $form, array( 'f_city' => 'bogota' ) ) );
	}

	public function test_entry_round_trip_duplicates_and_privacy_metadata(): void {
		$form = $this->form( array( 'prevent_duplicates' => 'email' ) );
		$id = $this->entry( $form, 'ANA@example.test', array( 'f_name' => '["texto literal"]' ), array( 'ip' => '203.0.113.15', 'source_url' => 'https://example.test/evento', 'user_agent' => '<b>Navegador</b>' ) );
		$entry = $this->entries->find( $id );
		$this->assertSame( 'ana@example.test', $entry['email'] );
		$this->assertSame( hash_hmac( 'sha256', '203.0.113.15', wp_salt() ), $entry['ip_hash'] );
		$this->assertSame( 'Navegador', $entry['user_agent'] );
		$this->assertNull( $entry['user_id'] );
		$this->assertSame( array( 'a', 'b' ), $this->entries->values( $id )['f_choices'] );
		$this->assertSame( '["texto literal"]', $this->entries->values( $id )['f_name'] );
		$this->assertTrue( $this->entries->existsByEmail( $form->id(), 'ANA@example.test' ) );
		$this->assertTrue( $this->entries->existsByDocument( $form->id(), 'CC123' ) );
		$this->assertInstanceOf( \WP_Error::class, $this->entries->create( $form, array( 'f_email' => 'ana@example.test' ) ) );
		$this->assertTrue( $this->entries->updateStatus( $id, 'cancelled' ) );
		$this->assertFalse( $this->entries->existsByEmail( $form->id(), 'ana@example.test' ) );
		$this->entry( $form );
		$this->assertInstanceOf( \WP_Error::class, $this->entries->updateStatus( $id, 'waitlist' ) );
	}

	public function test_capacity_status_changes_and_delete_keep_counters_consistent(): void {
		$form = $this->form( array( 'capacity' => 1, 'waitlist' => true ) );
		$first = $this->entry( $form );
		$second = $this->entry( $form, 'b@example.test' );
		$this->assertSame( 'waitlist', $this->entries->find( $second )['status'] );
		$this->assertInstanceOf( \WP_Error::class, $this->entries->updateStatus( $second, 'confirmed' ) );
		$this->assertTrue( $this->entries->updateStatus( $first, 'attended' ) );
		$this->assertSame( 1, $this->forms->find( $form->id() )->get( 'entries_count' ) );
		$this->assertTrue( $this->entries->updateStatus( $first, 'cancelled' ) );
		$this->assertTrue( $this->entries->updateStatus( $second, 'confirmed' ) );
		$this->assertTrue( $this->entries->delete( $second ) );
		$this->assertSame( 0, $this->forms->find( $form->id() )->get( 'entries_count' ) );
		$this->assertTrue( $this->entries->delete( $first ) );
		$this->assertFalse( $this->entries->delete( $first ) );
		$this->assertSame( 0, $this->forms->find( $form->id() )->get( 'entries_count' ) );
	}

	public function test_document_duplicates_and_missing_primary_values_are_rejected(): void {
		$form = $this->form( array( 'prevent_duplicates' => 'document' ) );
		$this->entry( $form );
		$this->assertInstanceOf( \WP_Error::class, $this->entries->create( $form, array( 'f_document' => 'CC123', 'f_email' => 'other@example.test' ) ) );
		$this->assertInstanceOf( \WP_Error::class, $this->entries->create( $form, array( 'f_email' => 'other@example.test' ) ) );
		$this->assertSame( 1, $this->entries->paginate()['total'] );
	}

	public function test_slugs_are_unique_and_count_does_not_underflow(): void {
		$first = $this->form();
		$second = $this->form();
		$this->assertSame( $first->get( 'slug' ) . '-2', $second->get( 'slug' ) );
		$this->forms->incrementCount( $first->id(), -10 );
		$this->assertSame( 0, $this->forms->find( $first->id() )->get( 'entries_count' ) );
		$this->forms->incrementCount( $first->id(), 2 );
		$this->assertSame( 2, $this->forms->find( $first->id() )->get( 'entries_count' ) );
	}

	public function test_created_hook_runs_only_after_commit_with_normalized_values(): void {
		$form = $this->form();
		$calls = array();
		$callback = static function ( $id, $saved_form, $values ) use ( &$calls ): void {
			$calls[] = array( $id, $saved_form, $values );
		};
		add_action( 'tbreg_entry_created', $callback, 10, 3 );
		try {
			$db = new FailingValuesDatabase( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
			$db->set_prefix( self::$db->prefix );
			( new EntryRepository( $db ) )->create( $form, array( 'f_name' => 'Ana', 'f_city' => 'bogota' ) );
			$this->assertSame( array(), $calls );
			$db->close();
			$id = $this->entry( $form, 'hook@example.test', array( 'f_name' => '<b>Ana</b>' ) );
			$this->assertCount( 1, $calls );
			$this->assertSame( $id, $calls[0][0] );
			$this->assertSame( 1, $calls[0][1]->get( 'entries_count' ) );
			$this->assertSame( 'Ana', $calls[0][2]['f_name'] );
		} finally {
			remove_action( 'tbreg_entry_created', $callback, 10 );
		}
	}

	public function test_export_has_an_id_ceiling_and_invalid_date_is_rejected(): void {
		$form = $this->form();
		$first = $this->entry( $form );
		$this->entry( $form, 'second@example.test' );
		$stream = $this->entries->iterateForExport( $form->id(), array(), 1 );
		$stream->rewind();
		$this->assertSame( $first, (int) $stream->current()['id'] );
		$this->entry( $form, 'late@example.test' );
		$rows = array();
		while ( $stream->valid() ) {
			$rows[] = $stream->current();
			$stream->next();
		}
		$this->assertCount( 2, $rows );
		$this->expectException( \InvalidArgumentException::class );
		$this->entries->paginate( array( 'date_from' => '2026-02-30' ) );
	}

	public function test_full_form_rejects_new_entry_without_waitlist(): void {
		$form = $this->form( array( 'capacity' => 1 ) );
		$this->entry( $form );
		$this->assertInstanceOf( \WP_Error::class, $this->entries->create( $form, array( 'f_name' => 'Otra persona' ) ) );
		$this->assertSame( 1, $this->entries->paginate()['total'] );
	}

	public function test_invalid_values_never_create_partial_entries(): void {
		$form = $this->form();
		foreach ( array( array( 'unknown' => 'x' ), array( 'f_city' => 'unknown' ), array( 'f_name' => array( 'nested' ) ), array( 'f_choices' => array( array( 'nested' ) ) ) ) as $values ) {
			$this->assertInstanceOf( \WP_Error::class, $this->entries->create( $form, $values ) );
		}
		$this->assertSame( 0, $this->entries->paginate()['total'] );
		$this->assertSame( 0, $this->forms->find( $form->id() )->get( 'entries_count' ) );
	}

	public function test_database_failure_rolls_back_entry_and_first_value(): void {
		$form = $this->form();
		$db = new FailingValuesDatabase( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$db->set_prefix( self::$db->prefix );
		$repository = new EntryRepository( $db );
		$this->assertInstanceOf( \WP_Error::class, $repository->create( $form, array( 'f_name' => 'Ana', 'f_email' => 'a@example.test' ) ) );
		$this->assertSame( 0, $this->entries->paginate()['total'] );
		$this->assertSame( '0', (string) self::$db->get_var( 'SELECT COUNT(*) FROM `' . self::$db->prefix . 'tbreg_entry_values`' ) );
		$this->assertSame( 0, $this->forms->find( $form->id() )->get( 'entries_count' ) );
		$db->close();
	}

	public function test_filters_pagination_and_streaming_do_not_skip_deleted_ids(): void {
		$form = $this->form();
		$ids = array();
		for ( $i = 0; $i < 7; ++$i ) {
			$ids[] = $this->entry( $form, 'person' . $i . '@example.test', array( 'f_name' => 'Nombre ' . $i ) );
		}
		$this->entries->delete( $ids[2] );
		$this->entries->updateStatus( $ids[4], 'cancelled' );
		$this->assertSame( 5, $this->entries->paginate( array( 'form_id' => $form->id(), 'status' => 'confirmed' ) )['total'] );
		$this->assertSame( 1, $this->entries->paginate( array( 'search' => 'Nombre 6' ) )['total'] );
		$this->assertSame( 0, $this->entries->paginate( array( 'search' => "' OR 1=1 --" ) )['total'] );
		$this->assertSame( 0, $this->entries->paginate( array( 'date_to' => '2000-01-01' ) )['total'] );
		$page = $this->entries->paginate( array( 'form_id' => $form->id(), 'per_page' => 2, 'page' => 2 ) );
		$this->assertSame( 3, $page['pages'] );
		$this->assertCount( 2, $page['items'] );
		$exported = iterator_to_array( $this->entries->iterateForExport( $form->id(), array( 'status' => 'confirmed' ), 2 ), false );
		$this->assertCount( 5, $exported );
		$this->assertSame( array( $ids[0], $ids[1], $ids[3], $ids[5], $ids[6] ), array_map( 'intval', array_column( $exported, 'id' ) ) );
		$this->assertSame( 'Nombre 6', $exported[4]['values']['f_name'] );
		$this->assertSame( array( 'a', 'b' ), $exported[4]['values']['f_choices'] );
	}
}

/** Simula el fallo de la segunda fila de valores en una conexión MySQL real. */
final class FailingValuesDatabase extends \wpdb {
	private int $value_inserts = 0;

	public function query( $query ) {
		if ( str_starts_with( $query, 'INSERT INTO `' . $this->prefix . 'tbreg_entry_values`' ) && ++$this->value_inserts === 2 ) {
			$this->last_error = 'Simulated database failure';
			return false;
		}
		return parent::query( $query );
	}
}
