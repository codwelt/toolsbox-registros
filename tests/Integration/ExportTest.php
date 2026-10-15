<?php
/** Exportación completa sobre MySQL aislado. @package ToolsBoxRegistros */
declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Tests\Integration;
use Codwelt\ToolsBoxRegistros\Domain\Form;
use Codwelt\ToolsBoxRegistros\Admin\EntriesPage;
use Codwelt\ToolsBoxRegistros\Admin\ExportPage;
use Codwelt\ToolsBoxRegistros\Admin\SettingsPage;
use Codwelt\ToolsBoxRegistros\Admin\Menu;
use Codwelt\ToolsBoxRegistros\Export\EntriesExporter;
use Codwelt\ToolsBoxRegistros\Export\XlsxWriter;
use Codwelt\ToolsBoxRegistros\Install\Installer;
use Codwelt\ToolsBoxRegistros\Repository\FormRepository;
use Codwelt\ToolsBoxRegistros\Repository\EntryRepository;
use Codwelt\ToolsBoxRegistros\Services\TransientStore;
use PHPUnit\Framework\TestCase;
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class ExportTest extends TestCase {
 private static \wpdb $db;
 private FormRepository $forms;
 private EntryRepository $entries;
 private ExportPage $page;
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
		$this->forms = new FormRepository( self::$db ); $this->entries = new EntryRepository( self::$db ); $this->page = new ExportPage( $this->forms, $this->entries );
		$this->previous_user = get_current_user_id(); $users = get_users( array( 'number' => 1, 'fields' => 'ID' ) ); wp_set_current_user( (int) $users[0] );
		$this->caps = array_fill_keys( Installer::CAPABILITIES, true );
		$this->cap_filter = function ( array $caps ): array { return array_merge( $caps, $this->caps ); }; add_filter( 'user_has_cap', $this->cap_filter );
		set_current_screen( 'toolsbox-registros_page_toolsbox-registros-export' );
	}
	protected function tearDown(): void { remove_filter( 'user_has_cap', $this->cap_filter ); wp_set_current_user( $this->previous_user ); $_GET = array(); $_POST = array(); $_REQUEST = array(); }
	private function form(): Form {
		$data = tbreg_test_form_data(); $data['status'] = 'published'; $data['title'] = 'Seminario Bogotá 😀';
		$data['fields'][] = array( 'key' => 'f_number', 'type' => 'number', 'label' => 'Importe' );
		$data['fields'][] = array( 'key' => 'f_heading', 'type' => 'heading', 'label' => 'Datos personales' );
		$form = $this->forms->save( Form::fromArray( $data ) ); $this->assertInstanceOf( Form::class, $form ); return $form;
	}
	private function entry( Form $form, int $index = 1, string $status = 'confirmed' ): int {
		$id = $this->entries->create( $form, array( 'f_name' => 'Ana Muñoz 😀 ' . $index, 'f_email' => 'qa' . $index . '@example.test', 'f_document' => '00012345678901234567', 'f_city' => 'bogota', 'f_choices' => array( 'a', 'b' ), 'f_number' => '12.5' ), array( 'status' => $status ) ); $this->assertIsInt( $id ); return $id;
	}
	private function input( Form $form, array $extra = array() ): array { return array_merge( array( 'form_id' => (string) $form->id(), 'format' => 'csv', 'tbreg_nonce' => wp_create_nonce( 'tbreg_export_entries' ) ), $extra ); }
	private function csv( array $file, string $delimiter = ';' ): array {
		$this->assertIsArray( $file ); $handle = fopen( $file['path'], 'rb' ); $this->assertSame( "\xEF\xBB\xBF", fread( $handle, 3 ) ); $rows = array(); while ( false !== ( $row = fgetcsv( $handle, 0, $delimiter, '"', '' ) ) ) $rows[] = $row; fclose( $handle ); wp_delete_file( $file['path'] ); return $rows;
	}
	public function test_export_retains_history_labels_order_local_dates_and_text_precision(): void {
		$timezone = static fn (): string => 'America/Bogota'; add_filter( 'pre_option_timezone_string', $timezone );
		try {
			$form = $this->form(); $id = $this->entry( $form ); $this->entry( $form, 2, 'attended' );
			self::$db->update( self::$db->prefix . 'tbreg_entries', array( 'created_at' => '2026-10-13 05:00:00' ), array( 'id' => $id ) );
			self::$db->update( self::$db->prefix . 'tbreg_entry_values', array( 'value' => '=HYPERLINK("https://example.test","texto")' ), array( 'entry_id' => $id, 'field_key' => 'f_name' ) );
			$data = $form->toArray(); $data['fields'][3]['archived'] = true; $form = $this->forms->save( Form::fromArray( $data ) );
			$file = $this->page->build_file( $this->input( $form ) ); $this->assertSame( 2, $file['rows'] );
			$directory = getenv( 'TBREG_EXPORT_FIXTURE_DIR' ); if ( $directory && is_dir( $directory ) ) copy( $file['path'], $directory . '/inscritos.csv' );
			$rows = $this->csv( $file ); $this->assertSame( array( 'ID', 'Fecha de inscripción', 'Estado', 'Nombre', 'Correo', 'Documento', 'Ciudad (eliminado)', 'Intereses', 'Importe' ), $rows[0] );
			$this->assertSame( '2026-10-13 00:00:00', $rows[1][1] ); $this->assertSame( 'Confirmado', $rows[1][2] ); $this->assertSame( "'=HYPERLINK(\"https://example.test\",\"texto\")", $rows[1][3] ); $this->assertSame( '00012345678901234567', $rows[1][5] ); $this->assertSame( 'Bogotá', $rows[1][6] ); $this->assertSame( 'Diseño, Marketing', $rows[1][7] ); $this->assertSame( 'Asistió', $rows[2][2] );
			$file = $this->page->build_file( $this->input( $form, array( 'format' => 'xlsx' ) ) ); $this->assertSame( 2, $file['rows'] ); $this->assertSame( 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $file['mime'] );
			if ( $directory && is_dir( $directory ) ) copy( $file['path'], $directory . '/inscritos.xlsx' );
			$zip = new \ZipArchive(); $this->assertTrue( $zip->open( $file['path'] ) ); $doc = new \DOMDocument(); $this->assertTrue( $doc->loadXML( $zip->getFromName( 'xl/worksheets/sheet1.xml' ), LIBXML_NONET ) ); $zip->close(); $xpath = new \DOMXPath( $doc ); $xpath->registerNamespace( 's', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );
			$this->assertSame( '=HYPERLINK("https://example.test","texto")', $xpath->evaluate( 'string(//s:c[@r="D2"]/s:is/s:t)' ) ); $this->assertSame( 'inlineStr', $xpath->evaluate( 'string(//s:c[@r="F2"]/@t)' ) ); $this->assertSame( 0.0, $xpath->evaluate( 'count(//s:f)' ) ); wp_delete_file( $file['path'] );
			$_GET = array( 'form_id' => (string) $form->id() ); ob_start(); $this->page->render(); $html = ob_get_clean(); $this->assertStringContainsString( 'method="post"', $html ); $this->assertStringContainsString( 'name="tbreg_nonce"', $html );
			if ( $directory && is_dir( $directory ) ) file_put_contents( $directory . '/export.html', $html );
		} finally { remove_filter( 'pre_option_timezone_string', $timezone ); }
	}
	public function test_filters_apply_in_utc_to_the_same_local_day_and_form_only(): void {
		$timezone = static fn (): string => 'America/Bogota'; add_filter( 'pre_option_timezone_string', $timezone );
		try {
			$form = $this->form(); $other = $this->form(); $this->entry( $other );
			foreach ( array( '2026-10-13 04:59:59', '2026-10-13 05:00:00', '2026-10-14 04:59:59', '2026-10-14 05:00:00' ) as $i => $date ) { $id = $this->entry( $form, $i + 1, 2 === $i ? 'attended' : 'confirmed' ); self::$db->update( self::$db->prefix . 'tbreg_entries', array( 'created_at' => $date ), array( 'id' => $id ) ); }
			$input = $this->input( $form, array( 'date_from' => '2026-10-13', 'date_to' => '2026-10-13', 'delimiter' => ',' ) ); $file = $this->page->build_file( $input ); $this->assertSame( 2, $file['rows'] ); $this->assertCount( 3, $this->csv( $file, ',' ) );
			$input['status'] = 'attended'; $file = $this->page->build_file( $input ); $this->assertSame( 1, $file['rows'] ); $this->assertSame( 'Asistió', $this->csv( $file, ',' )[1][2] );
			$input['s'] = 'no existe'; $file = $this->page->build_file( $input ); $this->assertSame( 0, $file['rows'] ); $this->assertCount( 1, $this->csv( $file, ',' ) );
		} finally { remove_filter( 'pre_option_timezone_string', $timezone ); }
	}
	public function test_permissions_nonce_invalid_format_and_dates_are_rejected_before_generating(): void {
		$form = $this->form(); $input = $this->input( $form ); $this->caps['tbreg_export_entries'] = false; $this->assertSame( 'tbreg_forbidden', $this->page->build_file( $input )->get_error_code() ); $this->caps['tbreg_export_entries'] = true;
		$this->assertSame( 'tbreg_invalid_nonce', $this->page->build_file( array_merge( $input, array( 'tbreg_nonce' => 'invalid' ) ) )->get_error_code() );
		foreach ( array( array( 'format' => 'html' ), array( 'delimiter' => '|' ), array( 'format' => array( 'csv' ) ), array( 'form_id' => array( 1 ) ), array( 'date_from' => '2026-02-30' ), array( 'date_from' => '2026-10-14', 'date_to' => '2026-10-13' ), array( 'form_id' => '99999999' ) ) as $bad ) $this->assertInstanceOf( \WP_Error::class, $this->page->build_file( array_merge( $input, $bad ) ) );
		$this->caps['tbreg_manage_forms'] = false; $this->caps['tbreg_view_entries'] = false; $file = $this->page->build_file( $input ); $this->assertSame( 0, $file['rows'] ); $this->csv( $file );
	}
	public function test_selected_export_is_user_bound_revalidated_and_never_falls_back_to_all(): void {
		$form = $this->form(); $one = $this->entry( $form ); $two = $this->entry( $form, 2 ); $other = $this->form(); $outside = $this->entry( $other, 3 );
		$selection = ( new EntriesPage( $this->entries, $this->forms ) )->perform_action( array( 'operation' => 'export', 'tbreg_ids' => array( (string) $two ), 'form_id' => (string) $form->id(), 'tbreg_nonce' => wp_create_nonce( 'tbreg_entries_action' ) ) );
		parse_str( (string) wp_parse_url( $selection['redirect'], PHP_URL_QUERY ), $args ); $token = $args['tbreg_selection']; $key = 'tbreg_selection_' . get_current_user_id() . '_' . $token;
		try {
			$input = $this->input( $form, array( 'tbreg_selection' => $token ) ); $file = $this->page->build_file( $input ); $this->assertSame( 1, $file['rows'] ); $this->assertSame( (string) $two, $this->csv( $file )[1][0] );
			$this->assertSame( 'tbreg_invalid_selection', $this->page->build_file( array_merge( $input, array( 'form_id' => (string) $other->id() ) ) )->get_error_code() );
			$user = get_current_user_id(); wp_set_current_user( 0 ); $this->assertSame( 'tbreg_invalid_selection', $this->page->context( $input )->get_error_code() ); wp_set_current_user( $user );
			TransientStore::set( $key, array( 'form_id' => $form->id(), 'ids' => array( $outside ) ), 600 ); $this->assertSame( 'tbreg_invalid_selection', $this->page->build_file( $input )->get_error_code() );
			TransientStore::set( $key, array( 'form_id' => $form->id(), 'ids' => array( $two ) ), 600 ); $this->entries->delete( $two ); $this->assertSame( 'tbreg_invalid_selection', $this->page->build_file( $input )->get_error_code() );
			TransientStore::delete( $key ); $this->assertSame( 'tbreg_invalid_selection', $this->page->build_file( $input )->get_error_code() ); $_GET = array( 'tbreg_selection' => $token ); ob_start(); $this->page->render(); $html = ob_get_clean(); $this->assertStringContainsString( 'disabled="disabled"', $html ); $this->assertStringContainsString( 'ha caducado', $html );
			$this->assertNotNull( $this->entries->find( $one ) );
		} finally { TransientStore::delete( $key ); }
	}
	public function test_hooks_reorder_add_columns_and_keep_custom_formula_text_safe(): void {
		$form = $this->form(); $this->entry( $form ); $columns = static fn ( array $columns ): array => array( 'custom' => array( 'label' => '=Personalizada', 'type' => 'text' ), 'field_f_city' => $columns['field_f_city'] ); $row = static function ( array $row ): array { $row['custom'] = '=1+1'; return $row; };
		add_filter( 'tbreg_export_columns', $columns ); add_filter( 'tbreg_export_row', $row );
		try { $rows = $this->csv( $this->page->build_file( $this->input( $form ) ) ); $this->assertSame( array( "'=Personalizada", 'Ciudad' ), $rows[0] ); $this->assertSame( array( "'=1+1", 'Bogotá' ), $rows[1] ); } finally { remove_filter( 'tbreg_export_columns', $columns ); remove_filter( 'tbreg_export_row', $row ); }
		$before = glob( get_temp_dir() . 'tbreg-*.tmp' ); $bad = static fn (): array => array( 'id' => array( 'no escalar' ) ); add_filter( 'tbreg_export_row', $bad );
		try { $this->assertSame( 'tbreg_export_failed', $this->page->build_file( $this->input( $form, array( 'format' => 'xlsx' ) ) )->get_error_code() ); $this->assertSame( $before, glob( get_temp_dir() . 'tbreg-*.tmp' ) ); } finally { remove_filter( 'tbreg_export_row', $bad ); }
	}
	public function test_without_zip_archive_only_csv_is_offered_and_settings_explain_it(): void {
		$disable = static fn (): bool => false; add_filter( 'tbreg_xlsx_available', $disable );
		try {
			$form = $this->form(); $this->assertFalse( XlsxWriter::available() ); $context = $this->page->context( array( 'form_id' => (string) $form->id() ) ); $this->assertSame( 'csv', $context['format'] );
			$_GET = array( 'form_id' => (string) $form->id() ); ob_start(); $this->page->render(); $html = ob_get_clean(); $this->assertStringNotContainsString( 'value="xlsx"', $html ); $this->assertStringContainsString( 'value="csv"', $html ); $this->assertStringContainsString( 'ZipArchive', $html );
			ob_start(); ( new SettingsPage() )->render(); $html = ob_get_clean(); $this->assertStringContainsString( 'ZipArchive', $html );
			$this->assertSame( 'tbreg_zip_unavailable', $this->page->build_file( $this->input( $form, array( 'format' => 'xlsx' ) ) )->get_error_code() ); $this->assertCount( 1, $this->csv( $this->page->build_file( $this->input( $form ) ) ) );
		} finally { remove_filter( 'tbreg_xlsx_available', $disable ); }
	}
	public function test_export_keyset_excludes_new_entries_and_rejects_invalid_internal_ids(): void {
		$form = $this->form(); $one = $this->entry( $form ); $two = $this->entry( $form, 2 ); $iterator = $this->entries->iterateForExport( $form->id(), array(), 1, $form ); $this->assertSame( (string) $one, $iterator->current()['id'] ); $this->entry( $form, 3 ); $iterator->next(); $this->assertSame( (string) $two, $iterator->current()['id'] ); $iterator->next(); $this->assertFalse( $iterator->valid() );
		foreach ( array( array(), array( 0 ), array( '1 OR 1=1' ), array( '1' ), array_fill( 0, 201, 1 ) ) as $ids ) { try { iterator_to_array( $this->entries->iterateForExport( $form->id(), array( 'entry_ids' => $ids ) ) ); $this->fail( 'IDs inválidos admitidos.' ); } catch ( \InvalidArgumentException $exception ) { $this->assertNotEmpty( $exception->getMessage() ); } }
	}
	public function test_ten_thousand_entries_export_under_a_hard_64_megabyte_limit(): void {
		$form = $this->form(); $seed = $this->entry( $form );
		$table = '`' . self::$db->prefix . 'tbreg_entries`'; $values = '`' . self::$db->prefix . 'tbreg_entry_values`';
		// Duplica exclusivamente la inscripción sintética en las tablas de esta clase.
		$columns = 'form_id,status,user_id,email,document,ip_hash,user_agent,source_url,created_at,updated_at'; $count = 1;
		while ( $count < 10000 ) { $batch = min( $count, 10000 - $count ); $inserted = self::$db->query( self::$db->prepare( 'INSERT INTO ' . $table . ' (' . $columns . ') SELECT ' . $columns . ' FROM ' . $table . ' WHERE form_id=%d LIMIT %d', $form->id(), $batch ) ); $this->assertSame( $batch, $inserted ); $count += $batch; }
		$this->assertSame( 9999 * 6, self::$db->query( self::$db->prepare( 'INSERT INTO ' . $values . ' (entry_id,field_key,value) SELECT e.id,v.field_key,v.value FROM ' . $table . ' e JOIN ' . $values . ' v ON v.entry_id=%d WHERE e.form_id=%d AND e.id<>%d', $seed, $form->id(), $seed ) ) );
		$process = proc_open( array( PHP_BINARY, '-d', 'memory_limit=64M', dirname( __DIR__ ) . '/export-benchmark.php', self::$db->prefix, (string) $form->id() ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes ); $this->assertIsResource( $process ); $stdout = stream_get_contents( $pipes[1] ); $stderr = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); $this->assertSame( 0, proc_close( $process ), $stderr ); $this->assertSame( '', $stderr );
		$results = json_decode( $stdout, true, 512, JSON_THROW_ON_ERROR ); $this->assertCount( 2, $results ); foreach ( $results as $result ) { $this->assertSame( 10000, $result['entries'] ); $this->assertSame( '64M', $result['memory_limit'] ); $this->assertLessThanOrEqual( 64 * 1024 * 1024, $result['peak_bytes'] ); $this->assertGreaterThan( 1000, $result['file_bytes'] ); }
		$directory = getenv( 'TBREG_EXPORT_FIXTURE_DIR' ); if ( $directory && is_dir( $directory ) ) file_put_contents( $directory . '/benchmark.json', wp_json_encode( $results, JSON_PRETTY_PRINT ) );
	}
}
