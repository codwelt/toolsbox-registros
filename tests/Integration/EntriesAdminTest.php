<?php
/**
 * Fase 4: permisos, historial, UTC, acciones y rendimiento con MySQL.
 *
 * @package ToolsBoxRegistros
 */
declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Tests\Integration;
use Codwelt\ToolsBoxRegistros\Domain\Form;
use Codwelt\ToolsBoxRegistros\Admin\EntriesFilters;
use Codwelt\ToolsBoxRegistros\Admin\EntriesListTable;
use Codwelt\ToolsBoxRegistros\Admin\EntriesPage;
use Codwelt\ToolsBoxRegistros\Admin\EntryDetailPage;
use Codwelt\ToolsBoxRegistros\Admin\Menu;
use Codwelt\ToolsBoxRegistros\Install\Installer;
use Codwelt\ToolsBoxRegistros\Repository\FormRepository;
use Codwelt\ToolsBoxRegistros\Repository\EntryRepository;
use Codwelt\ToolsBoxRegistros\Services\TransientStore;
use PHPUnit\Framework\TestCase;
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class EntriesAdminTest extends TestCase {
	private static \wpdb $db;
	private FormRepository $forms;
	private EntryRepository $entries;
	private EntriesPage $page;
	private EntryDetailPage $detail;
	private int $previous_user;
	private \Closure $cap_filter;
	private array $caps = array();
	public static function setUpBeforeClass(): void {
		if ( '1' !== getenv( 'TBREG_RUN_INTEGRATION' ) ) self::markTestSkipped( 'Activa TBREG_RUN_INTEGRATION=1.' );
		if ( ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) throw new \RuntimeException( 'Se requiere WordPress local/development.' );
		self::$db = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ); self::$db->set_prefix( 'tbregtest_' . bin2hex( random_bytes( 6 ) ) . '_' );
		global $wpdb; $original = $wpdb; $wpdb = self::$db;
		try { dbDelta( Installer::schema( self::$db ) ); } finally { $wpdb = $original; }
		register_shutdown_function( array( self::class, 'cleanup' ) );
	}
	public static function cleanup(): void {
		if ( ! isset( self::$db ) || ! preg_match( '/^tbregtest_[a-f0-9]{12}_$/D', self::$db->prefix ) ) return;
		foreach ( array( 'entry_values', 'entries', 'forms' ) as $suffix ) self::$db->query( 'DROP TABLE IF EXISTS `' . self::$db->prefix . 'tbreg_' . $suffix . '`' );
	}
	public static function tearDownAfterClass(): void { self::cleanup(); }
	protected function setUp(): void {
		foreach ( array( 'entry_values', 'entries', 'forms' ) as $suffix ) self::$db->query( 'DELETE FROM `' . self::$db->prefix . 'tbreg_' . $suffix . '`' );
		$this->forms = new FormRepository( self::$db ); $this->entries = new EntryRepository( self::$db ); $this->page = new EntriesPage( $this->entries, $this->forms ); $this->detail = new EntryDetailPage( $this->entries, $this->forms );
		$this->previous_user = get_current_user_id(); $users = get_users( array( 'number' => 1, 'fields' => 'ID' ) ); wp_set_current_user( (int) $users[0] );
		$this->caps = array_fill_keys( Installer::CAPABILITIES, true );
		$this->cap_filter = function ( array $caps ): array { return array_merge( $caps, $this->caps ); }; add_filter( 'user_has_cap', $this->cap_filter );
		set_current_screen( 'toolsbox-registros_page_toolsbox-registros-entries' );
	}
	protected function tearDown(): void { remove_filter( 'user_has_cap', $this->cap_filter ); wp_set_current_user( $this->previous_user ); $_GET = array(); $_POST = array(); $_REQUEST = array(); }
	private function form( array $settings = array() ): Form {
		$data = tbreg_test_form_data(); $data['settings'] = $settings; $data['status'] = 'published';
		$data['fields'][] = array( 'key' => 'f_consent', 'type' => 'consent', 'label' => 'Consentimiento' );
		$data['fields'][] = array( 'key' => 'f_hidden', 'type' => 'hidden', 'label' => 'Origen', 'default' => 'web' );
		$data['fields'][] = array( 'key' => 'f_heading', 'type' => 'heading', 'label' => 'Título' );
		$form = $this->forms->save( Form::fromArray( $data ) ); $this->assertInstanceOf( Form::class, $form ); return $form;
	}
	private function entry( Form $form, int $i = 1, string $status = 'confirmed' ): int {
		$id = $this->entries->create( $form, array( 'f_name' => 'Ana Muñoz 😀 ' . $i, 'f_email' => 'persona' . $i . '@example.test', 'f_document' => 'CC' . $i, 'f_city' => 'bogota', 'f_choices' => array( 'a', 'b' ), 'f_consent' => '1', 'f_hidden' => 'web' ), array( 'status' => $status, 'source_url' => home_url( '/evento/' ), 'user_agent' => 'Prueba ToolsBox' ) ); $this->assertIsInt( $id ); return $id;
	}
	private function action( array $ids, string $operation, int $form_id = 0 ): array { return array( 'operation' => $operation, 'tbreg_ids' => array_map( 'strval', $ids ), 'form_id' => (string) $form_id, 'tbreg_nonce' => wp_create_nonce( 'tbreg_entries_action' ) ); }
	private function edit( int $id ): array {
		$entry = $this->entries->find( $id ); $form = $this->forms->find( (int) $entry['form_id'] ); $values = $this->entries->values( $id );
		return array( 'tbreg_id' => (string) $id, 'tbreg_nonce' => wp_create_nonce( 'tbreg_save_entry_' . $id ), 'tbreg_revision' => EntryRepository::revision( $entry, $values ), 'tbreg_form_revision' => $form->revision(), 'tbreg_values' => $values, 'tbreg_status' => $entry['status'] );
	}
	public function test_filters_convert_colombian_days_and_reject_bad_ranges(): void {
		$timezone = static fn (): string => 'America/Bogota'; add_filter( 'pre_option_timezone_string', $timezone );
		try {
			$filters = EntriesFilters::parse( array( 'date_from' => '2026-10-13', 'date_to' => '2026-10-13' ) );
			$this->assertSame( '2026-10-13 05:00:00', $filters['date_from'] ); $this->assertSame( '2026-10-14 04:59:59', $filters['date_to'] );
			$form = $this->form(); $ids = array(); for ( $i = 1; $i <= 4; ++$i ) $ids[] = $this->entry( $form, $i );
			foreach ( array( '2026-10-13 04:59:59', '2026-10-13 05:00:00', '2026-10-14 04:59:59', '2026-10-14 05:00:00' ) as $index => $date ) self::$db->update( self::$db->prefix . 'tbreg_entries', array( 'created_at' => $date ), array( 'id' => $ids[ $index ] ) );
			$this->assertSame( 2, $this->entries->paginate( $filters )['total'] );
			$this->assertInstanceOf( \WP_Error::class, EntriesFilters::parse( array( 'date_from' => '2026-02-30' ) ) ); $this->assertInstanceOf( \WP_Error::class, EntriesFilters::parse( array( 'date_from' => '2026-10-14', 'date_to' => '2026-10-13' ) ) );
			$this->assertInstanceOf( \WP_Error::class, EntriesFilters::parse( array( 'form_id' => array( 1 ) ) ) ); $this->assertInstanceOf( \WP_Error::class, EntriesFilters::parse( array( 'status' => 'evil' ) ) );
		} finally { remove_filter( 'pre_option_timezone_string', $timezone ); }
	}
	public function test_global_and_dynamic_columns_use_labels_and_escape_output(): void {
		$form = $this->form(); $id = $this->entry( $form ); $table = new EntriesListTable( $this->entries, $this->forms, array() ); $table->prepare_items();
		$this->assertArrayHasKey( 'name', $table->get_columns() ); $this->assertSame( 'Ana Muñoz 😀 1', $table->column_default( $table->items[0], 'name' ) );
		$filtered = new EntriesListTable( $this->entries, $this->forms, array( 'form_id' => $form->id() ) ); $filtered->prepare_items();
		$this->assertSame( 'Bogotá', $filtered->column_default( $filtered->items[0], 'field_f_city' ) ); $this->assertSame( 'Diseño, Marketing', $filtered->column_default( $filtered->items[0], 'field_f_choices' ) );
		$this->assertArrayNotHasKey( 'field_f_heading', $filtered->get_columns() ); $this->assertContains( 'field_f_hidden', $filtered->default_hidden() );
		$data = $form->toArray(); $data['fields'][3]['archived'] = true; $this->forms->save( Form::fromArray( $data ) );
		$table = new EntriesListTable( $this->entries, $this->forms, array( 'form_id' => $form->id() ) ); $this->assertStringContainsString( '(eliminado)', $table->get_columns()['field_f_city'] );
		self::$db->update( self::$db->prefix . 'tbreg_entry_values', array( 'value' => '<script>alert(1)</script>' ), array( 'entry_id' => $id, 'field_key' => 'f_name' ) ); $table->prepare_items();
		$this->assertStringNotContainsString( '<script>', $table->column_default( $table->items[0], 'field_f_name' ) );
	}
	public function test_search_status_pagination_and_ordering_are_prepared(): void {
		$form = $this->form(); for ( $i = 1; $i <= 4; ++$i ) $this->entry( $form, $i, 4 === $i ? 'cancelled' : 'confirmed' );
		foreach ( array( 'persona2@example.test', 'CC2', 'Ana Muñoz 😀 2' ) as $search ) $this->assertSame( 1, $this->entries->paginate( array( 'search' => $search ) )['total'] );
		$this->assertSame( 3, $this->entries->paginate( array( 'status' => 'confirmed' ) )['total'] );
		$result = $this->entries->paginate( array( 'per_page' => 2, 'page' => 2, 'orderby' => 'email', 'order' => 'ASC' ) ); $this->assertCount( 2, $result['items'] ); $this->assertSame( 'persona3@example.test', $result['items'][0]['email'] );
		$this->assertSame( 0, $this->entries->paginate( array( 'search' => "%' OR 1=1 --" ) )['total'] ); $this->assertSame( 4, $this->entries->paginate( array( 'orderby' => 'id; DROP TABLE x', 'order' => 'evil' ) )['total'] );
	}
	public function test_bulk_changes_and_deletion_keep_capacity_and_values_in_sync(): void {
		$form = $this->form( array( 'capacity' => 2 ) ); $one = $this->entry( $form ); $two = $this->entry( $form, 2 ); $waiting = $this->entry( $form, 3, 'waitlist' );
		$result = $this->page->perform_action( $this->action( array( $one, $two ), 'attended', $form->id() ) ); $this->assertSame( 2, $result['changed'] ); $this->assertSame( 2, $this->forms->find( $form->id() )->get( 'entries_count' ) );
		$result = $this->page->perform_action( $this->action( array( $waiting ), 'confirmed', $form->id() ) ); $this->assertCount( 1, $result['errors'] ); $this->assertSame( 'waitlist', $this->entries->find( $waiting )['status'] );
		$this->page->perform_action( $this->action( array( $one ), 'cancelled', $form->id() ) ); $this->page->perform_action( $this->action( array( $waiting ), 'confirmed', $form->id() ) ); $this->assertSame( 2, $this->forms->find( $form->id() )->get( 'entries_count' ) );
		$input = $this->action( array( $two ), 'delete', $form->id() ); $this->assertSame( 'tbreg_confirm_delete', $this->page->perform_action( $input )->get_error_code() ); $this->assertNotNull( $this->entries->find( $two ) );
		$input['tbreg_confirm_delete'] = '1'; $result = $this->page->perform_action( $input ); $this->assertSame( 1, $result['changed'] ); $this->assertNull( $this->entries->find( $two ) ); $this->assertSame( array(), $this->entries->values( $two ) ); $this->assertSame( 1, $this->forms->find( $form->id() )->get( 'entries_count' ) );
		$this->assertSame( 0, (int) self::$db->get_var( self::$db->prepare( 'SELECT COUNT(*) FROM `' . self::$db->prefix . 'tbreg_entry_values` WHERE entry_id=%d', $two ) ) );
	}
	public function test_actions_reject_missing_cap_nonce_invalid_ids_and_cross_form_selection(): void {
		$form = $this->form(); $one = $this->entry( $form ); $other = $this->form(); $two = $this->entry( $other, 2 ); $input = $this->action( array( $one ), 'cancelled', $form->id() );
		$this->caps['tbreg_edit_entries'] = false; $this->assertSame( 'tbreg_forbidden', $this->page->perform_action( $input )->get_error_code() ); $this->caps['tbreg_edit_entries'] = true;
		$bad = $input; $bad['tbreg_nonce'] = 'invalid'; $this->assertSame( 'tbreg_invalid_nonce', $this->page->perform_action( $bad )->get_error_code() );
		$bad = $input; $bad['tbreg_ids'][] = (string) $two; $this->assertSame( 'tbreg_invalid_selection', $this->page->perform_action( $bad )->get_error_code() );
		$bad = $input; $bad['tbreg_ids'] = array( array( $one ) ); $this->assertSame( 'tbreg_invalid_selection', $this->page->perform_action( $bad )->get_error_code() ); $this->assertSame( 'confirmed', $this->entries->find( $one )['status'] );
		$this->caps['tbreg_view_entries'] = false; $this->assertSame( 'tbreg_forbidden', $this->page->perform_action( $input )->get_error_code() );
	}
	public function test_export_selection_is_cap_nonce_form_and_user_bound(): void {
		$form = $this->form(); $id = $this->entry( $form ); $input = $this->action( array( $id ), 'export', $form->id() );
		$this->caps['tbreg_export_entries'] = false; $this->assertSame( 'tbreg_forbidden', $this->page->perform_action( $input )->get_error_code() ); $this->caps['tbreg_export_entries'] = true;
		$result = $this->page->perform_action( $input ); parse_str( (string) wp_parse_url( $result['redirect'], PHP_URL_QUERY ), $args ); $token = $args['tbreg_selection'];
		$this->assertSame( array( 'form_id' => $form->id(), 'ids' => array( $id ) ), EntriesPage::export_selection( $token ) ); $user = get_current_user_id(); wp_set_current_user( 0 ); $this->assertSame( array(), EntriesPage::export_selection( $token ) ); wp_set_current_user( $user );
		TransientStore::delete( 'tbreg_selection_' . $user . '_' . $token ); $input['form_id'] = '0'; $this->assertSame( 'tbreg_invalid_selection', $this->page->perform_action( $input )->get_error_code() );
	}
	public function test_edit_sanitizes_and_preserves_archived_consent_hidden_and_unmodified_old_catalog(): void {
		$form = $this->form(); $id = $this->entry( $form ); $data = $form->toArray(); $data['fields'][2]['archived'] = true; $data['fields'][3]['options'] = array( array( 'value' => 'cali', 'label' => 'Cali' ) ); $this->forms->save( Form::fromArray( $data ) );
		$input = $this->edit( $id ); $input['tbreg_values']['f_name'] = '<b>Beatriz 😀</b>'; $input['tbreg_values']['f_email'] = 'BEA@example.test'; $input['tbreg_values']['f_document'] = 'changed'; $input['tbreg_values']['f_consent'] = '0'; $input['tbreg_values']['f_hidden'] = 'tampered'; $input['tbreg_values']['unknown'] = 'ignored'; $input['tbreg_status'] = 'attended';
		$this->assertTrue( $this->detail->save( $input ) ); $values = $this->entries->values( $id ); $this->assertSame( 'Beatriz 😀', $values['f_name'] ); $this->assertSame( 'bea@example.test', $this->entries->find( $id )['email'] ); $this->assertSame( 'CC1', $values['f_document'] ); $this->assertSame( '1', $values['f_consent'] ); $this->assertSame( 'web', $values['f_hidden'] ); $this->assertSame( 'bogota', $values['f_city'] ); $this->assertArrayNotHasKey( 'unknown', $values );
		$this->assertSame( 1, $this->forms->find( $form->id() )->get( 'entries_count' ) );
	}
	public function test_edit_validation_duplicate_and_capacity_failure_roll_back_every_change(): void {
		$form = $this->form( array( 'capacity' => 2, 'prevent_duplicates' => 'email' ) ); $one = $this->entry( $form ); $two = $this->entry( $form, 2 ); $waiting = $this->entry( $form, 3, 'waitlist' );
		$input = $this->edit( $one ); $input['tbreg_values']['f_email'] = 'bad'; $this->assertSame( 'tbreg_validation', $this->detail->save( $input )->get_error_code() );
		$input = $this->edit( $one ); $input['tbreg_values']['f_name'] = 'Cambio que no debe guardarse'; $input['tbreg_values']['f_email'] = 'persona2@example.test'; $this->assertSame( 'tbreg_invalid_data', $this->detail->save( $input )->get_error_code() ); $this->assertSame( 'Ana Muñoz 😀 1', $this->entries->values( $one )['f_name'] );
		$input = $this->edit( $waiting ); $input['tbreg_values']['f_name'] = 'Cambio sin cupo'; $input['tbreg_status'] = 'confirmed'; $this->assertInstanceOf( \WP_Error::class, $this->detail->save( $input ) ); $this->assertSame( 'Ana Muñoz 😀 3', $this->entries->values( $waiting )['f_name'] );
		$this->assertSame( 'persona1@example.test', $this->entries->find( $one )['email'] ); $this->assertSame( 2, $this->forms->find( $form->id() )->get( 'entries_count' ) );
	}
	public function test_edit_nonce_and_revisions_prevent_overwriting_other_changes(): void {
		$form = $this->form(); $id = $this->entry( $form ); $input = $this->edit( $id ); $input['tbreg_values']['f_name'] = 'Otra persona';
		$bad = $input; $bad['tbreg_nonce'] = 'bad'; $this->assertSame( 'tbreg_invalid_nonce', $this->detail->save( $bad )->get_error_code() );
		$this->caps['tbreg_edit_entries'] = false; $this->assertSame( 'tbreg_forbidden', $this->detail->save( $input )->get_error_code() ); $this->caps['tbreg_edit_entries'] = true;
		$this->entries->updateStatus( $id, 'attended' ); $this->assertSame( 'tbreg_conflict', $this->detail->save( $input )->get_error_code() );
		$input = $this->edit( $id ); $data = $form->toArray(); $data['fields'][0]['label'] = 'Nombre actualizado'; $this->forms->save( Form::fromArray( $data ) ); $this->assertSame( 'tbreg_conflict', $this->detail->save( $input )->get_error_code() );
		$this->assertSame( 'Ana Muñoz 😀 1', $this->entries->values( $id )['f_name'] );
	}
	public function test_read_only_users_have_no_mutation_controls_but_can_see_details(): void {
		$form = $this->form(); $id = $this->entry( $form ); $this->caps['tbreg_edit_entries'] = false; $this->caps['tbreg_export_entries'] = false;
		$table = new EntriesListTable( $this->entries, $this->forms, array() ); $this->assertArrayNotHasKey( 'cb', $table->get_columns() );
		$_GET = array( 'id' => (string) $id, 'edit' => '1' ); ob_start(); $this->detail->render(); $html = ob_get_clean(); $this->assertStringContainsString( 'Ana Muñoz', $html ); $this->assertStringNotContainsString( 'tbreg_save_entry', $html ); $this->assertStringNotContainsString( 'Editar datos y estado', $html );
		$this->assertSame( 100, EntriesPage::screen_option( false, 'tbreg_entries_per_page', 999 ) ); $this->assertFalse( EntriesPage::screen_option( false, 'other_option', 20 ) );
	}
	public function test_invalid_edit_prg_preserves_values_focus_privately_and_once(): void {
		$form = $this->form(); $id = $this->entry( $form ); $input = $this->edit( $id ); $input['tbreg_values']['f_name'] = 'Nombre corregido 😀'; $input['tbreg_values']['f_email'] = 'correo-invalido'; $input['tbreg_values']['f_choices'] = 'outside'; $_POST = wp_slash( $input );
		$old_method = $_SERVER['REQUEST_METHOD'] ?? null; $_SERVER['REQUEST_METHOD'] = 'POST';
		$redirect = static function ( string $url, int $status ): string { throw new \RuntimeException( $status . '|' . $url ); }; add_filter( 'wp_redirect', $redirect, 10, 2 );
		try {
			try { $this->detail->handle_save(); $this->fail( 'Falta PRG.' ); } catch ( \RuntimeException $exception ) {
				list( $status, $url ) = explode( '|', $exception->getMessage(), 2 ); $this->assertSame( '303', $status ); $this->assertStringNotContainsString( 'Nombre', $url ); $this->assertStringNotContainsString( 'correo-invalido', $url );
				parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $_GET ); $this->assertFalse( TransientStore::get( 'tbreg_entry_error_0_' . $_GET['tbreg_error'] ) );
				ob_start(); $this->detail->render(); $html = ob_get_clean(); $this->assertStringContainsString( 'value="Nombre corregido 😀"', $html ); $this->assertStringContainsString( 'aria-invalid="true"', $html ); $this->assertStringContainsString( 'autofocus', $html ); $this->assertStringNotContainsString( 'outside (valor anterior)', $html );
				$this->assertFalse( TransientStore::get( 'tbreg_entry_error_' . get_current_user_id() . '_' . $_GET['tbreg_error'] ) ); $this->assertSame( 'Ana Muñoz 😀 1', $this->entries->values( $id )['f_name'] );
			}
		} finally { remove_filter( 'wp_redirect', $redirect, 10 ); if ( null === $old_method ) unset( $_SERVER['REQUEST_METHOD'] ); else $_SERVER['REQUEST_METHOD'] = $old_method; }
	}
	public function test_screen_preferences_hide_selected_columns_without_discarding_values(): void {
		$form = $this->form(); $this->entry( $form ); $_GET = array( 'form_id' => (string) $form->id() ); $this->page->load();
		$filter = static fn (): array => array( 'field_f_city' ); $hook = 'get_user_option_managetoolsbox-registros_page_toolsbox-registros-entriescolumnshidden'; add_filter( $hook, $filter );
		try {
			$table = new EntriesListTable( $this->entries, $this->forms ); $table->prepare_items(); ob_start(); $table->display(); $html = ob_get_clean();
			$this->assertMatchesRegularExpression( '/column-field_f_city[^"\n]*hidden/', $html ); $this->assertStringContainsString( 'Bogotá', $html );
			$this->assertSame( 4, count( array_filter( array_keys( $table->get_columns() ), static fn ( string $key ): bool => str_starts_with( $key, 'field_' ) && ! in_array( $key, $table->default_hidden(), true ) ) ) );
		} finally { remove_filter( $hook, $filter ); }
	}
	public function test_detail_shows_history_metadata_and_editor_uses_a_post_with_nonce(): void {
		$form = $this->form(); $id = $this->entry( $form ); $data = $form->toArray(); $data['fields'][2]['archived'] = true; $this->forms->save( Form::fromArray( $data ) ); $_GET = array( 'id' => (string) $id );
		ob_start(); $this->detail->render(); $html = ob_get_clean(); $this->assertStringContainsString( 'Documento (eliminado)', $html ); $this->assertStringContainsString( 'CC1', $html ); $this->assertStringContainsString( 'Prueba ToolsBox', $html ); $this->assertStringContainsString( 'Página de origen', $html );
		$_GET['edit'] = '1'; ob_start(); $this->detail->render(); $html = ob_get_clean(); $this->assertStringContainsString( 'name="tbreg_nonce"', $html ); $this->assertStringContainsString( 'name="tbreg_form_revision"', $html ); $this->assertStringNotContainsString( 'name="tbreg_values[f_document]"', $html ); $this->assertStringNotContainsString( 'name="tbreg_values[f_consent]"', $html );
		$directory = getenv( 'TBREG_ENTRIES_FIXTURE_DIR' ); if ( false !== $directory && is_dir( $directory ) ) file_put_contents( $directory . '/detail.html', $html );
	}
	public function test_thousand_entries_list_renders_in_under_one_second_without_n_plus_one(): void {
		$form = $this->form();
		for ( $i = 1; $i <= 1000; ++$i ) {
			$id = $this->entries->create( $form, array( 'f_name' => 'Inscrito Bogotá 😀 ' . $i, 'f_email' => 'benchmark' . $i . '@example.test', 'f_city' => 'bogota', 'f_choices' => array( 'a', 'b' ) ) ); if ( ! is_int( $id ) ) throw new \RuntimeException( 'No se pudo crear el fixture de rendimiento.' );
		}
		$_GET = array( 'form_id' => (string) $form->id(), 'page' => 'toolsbox-registros-entries' );
		$before = self::$db->num_queries; $start = microtime( true ); $this->page->load(); ob_start(); $this->page->render(); $html = ob_get_clean(); $elapsed = microtime( true ) - $start; $queries = self::$db->num_queries - $before;
		$this->assertLessThan( 1.0, $elapsed, 'Tiempo listado: ' . $elapsed ); $this->assertLessThanOrEqual( 7, $queries, 'Consultas de tablas propias: ' . $queries ); $this->assertStringContainsString( number_format_i18n( 1000 ), $html ); $this->assertStringContainsString( 'Bogotá', $html );
		$directory = getenv( 'TBREG_ENTRIES_FIXTURE_DIR' ); if ( false !== $directory && is_dir( $directory ) ) { file_put_contents( $directory . '/entries.html', $html ); file_put_contents( $directory . '/benchmark.json', wp_json_encode( array( 'entries' => 1000, 'seconds' => $elapsed, 'queries' => $queries ) ) ); }
	}
}
