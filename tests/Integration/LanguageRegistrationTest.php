<?php
/** Inscripción de idiomas: relaciones, foto, constancia y exportación. @package ToolsBoxRegistros */
declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Tests\Integration;
use Codwelt\ToolsBoxRegistros\Domain\Form;
use Codwelt\ToolsBoxRegistros\Domain\Field;
use Codwelt\ToolsBoxRegistros\Admin\LanguageTemplate;
use Codwelt\ToolsBoxRegistros\Admin\Templates;
use Codwelt\ToolsBoxRegistros\Admin\FormEditorPage;
use Codwelt\ToolsBoxRegistros\Admin\EntryDetailPage;
use Codwelt\ToolsBoxRegistros\Frontend\Receipt;
use Codwelt\ToolsBoxRegistros\Frontend\FormRenderer;
use Codwelt\ToolsBoxRegistros\Frontend\SubmissionHandler;
use Codwelt\ToolsBoxRegistros\Export\RegistrationPdf;
use Codwelt\ToolsBoxRegistros\Export\EntriesExporter;
use Codwelt\ToolsBoxRegistros\Install\Installer;
use Codwelt\ToolsBoxRegistros\Repository\FormRepository;
use Codwelt\ToolsBoxRegistros\Repository\EntryRepository;
use Codwelt\ToolsBoxRegistros\Services\TransientStore;
use Codwelt\ToolsBoxRegistros\Services\SpamGuard;
use Codwelt\ToolsBoxRegistros\Services\Photo;
use Codwelt\ToolsBoxRegistros\Services\Validator;
use PHPUnit\Framework\TestCase;
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class LanguageRegistrationTest extends TestCase {
 private static \wpdb $db;
 private FormRepository $forms;
 private EntryRepository $entries;
 private SubmissionHandler $handler;
 private Receipt $receipt;
 private int $previous_user;
 private \Closure $cap_filter;
 private array $caps = array();
 private array $tokens = array();
 private array $ips = array();
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
		$this->forms = new FormRepository( self::$db ); $this->entries = new EntryRepository( self::$db ); $this->handler = new SubmissionHandler( $this->forms, $this->entries ); $this->receipt = new Receipt( $this->forms, $this->entries );
		$this->previous_user = get_current_user_id(); $users = get_users( array( 'number' => 1, 'fields' => 'ID' ) ); wp_set_current_user( (int) $users[0] );
		$this->caps = array_fill_keys( Installer::CAPABILITIES, true );
		$this->cap_filter = function ( array $caps ): array { return array_merge( $caps, $this->caps ); }; add_filter( 'user_has_cap', $this->cap_filter );
		set_current_screen( 'toolsbox-registros_page_toolsbox-registros-export' );
	}
	protected function tearDown(): void { foreach ( $this->tokens as $key ) TransientStore::delete( $key ); foreach ( $this->ips as $ip ) TransientStore::delete( 'tbreg_rate_' . hash_hmac( 'sha256', (string) inet_pton( $ip ), wp_salt() ) ); unset( $_COOKIE['tbreg_receipt_owner'] ); remove_filter( 'user_has_cap', $this->cap_filter ); wp_set_current_user( $this->previous_user ); $_GET = array(); $_POST = array(); $_REQUEST = array(); }
	private function form(): Form { $template = LanguageTemplate::get(); $form = $this->forms->save( Form::fromArray( array( 'title' => 'Inscripción de idiomas - Demo', 'status' => 'published', 'fields' => $template['fields'], 'settings' => $template['settings'] ) ) ); $this->assertInstanceOf( Form::class, $form ); return $form; }
	private function photo(): string { $image = imagecreatetruecolor( 240, 300 ); imagefill( $image, 0, 0, imagecolorallocate( $image, 224, 237, 245 ) ); $ink = imagecolorallocate( $image, 35, 91, 125 ); imagefilledellipse( $image, 120, 95, 75, 75, $ink ); imagefilledellipse( $image, 120, 235, 170, 180, $ink ); imagestring( $image, 3, 56, 279, 'FOTO DE PRUEBA', imagecolorallocate( $image, 255, 255, 255 ) ); ob_start(); imagepng( $image ); $bytes = ob_get_clean(); imagedestroy( $image ); return 'data:image/png;base64,' . base64_encode( $bytes ); }
	private function values( string $course = 'japones' ): array { return array( 'f_course' => $course, 'f_level' => 'basico', 'f_module' => 'basico_1', 'f_mode' => 'virtual', 'f_days' => 'lunes_miercoles', 'f_schedule' => 'coreano' === $course ? 'virtual_19_21' : 'virtual_18_20', 'f_name' => 'Ana Muñoz 😀', 'f_birth' => '1990-05-15', 'f_document' => '000123456789', 'f_mobile' => '+57 300 123 4567', 'f_email' => 'qa@example.test', 'f_consent' => '1', 'f_photo' => $this->photo() ); }
	private function submit( Form $form, array $values ): array|\WP_Error {
		$ip = '2001:db8::' . bin2hex( random_bytes( 2 ) ); $this->ips[] = $ip;
		$result = $this->handler->submit( $form->id(), array_merge( ( new SpamGuard() )->issue( $form->id(), time() - 4 ), array( 'tbreg_values' => $values ) ), array( 'ip' => $ip ) );
		if ( is_array( $result ) && ! empty( $result['pdf_url'] ) ) { parse_str( wp_parse_url( $result['pdf_url'], PHP_URL_QUERY ), $query ); $this->tokens[] = 'tbreg_receipt_' . hash( 'sha256', $_COOKIE['tbreg_receipt_owner'] . '|' . $query['token'] ); }
		return $result;
	}
	public function test_each_language_saves_photo_generates_private_pdf_and_exports_labels(): void {
		$form = $this->form(); $directory = getenv( 'TBREG_LANGUAGE_FIXTURE_DIR' );
		foreach ( array( 'japones', 'mandarin', 'coreano' ) as $course ) {
			$result = $this->submit( $form, $this->values( $course ) ); $this->assertIsArray( $result ); $this->assertTrue( $result['success'] ); $entry = $this->entries->find( $result['entry_id'] ); $values = $this->entries->values( $result['entry_id'] ); $this->assertSame( $course, $values['f_course'] ); $this->assertStringStartsWith( 'data:image/jpeg;base64,', $values['f_photo'] ); $this->assertNotEmpty( Photo::jpeg( $values['f_photo'] ) );
			parse_str( wp_parse_url( $result['pdf_url'], PHP_URL_QUERY ), $query ); $context = $this->receipt->resolve( $query ); $this->assertIsArray( $context );
			$pdf = ( new RegistrationPdf() )->build( $context['form'], $context['entry'], $context['values'] ); $this->assertStringStartsWith( '%PDF-1.4', $pdf ); $this->assertStringContainsString( '/ToUnicode', $pdf ); $this->assertStringContainsString( '/Count 2', $pdf ); $this->assertStringContainsString( '/Subtype /Image', $pdf );
			if ( 'japones' === $course ) {
				$_GET = array( 'id' => (string) $result['entry_id'] ); ob_start(); ( new EntryDetailPage( $this->entries, $this->forms ) )->render(); $html = ob_get_clean();
				$this->assertStringContainsString( 'tbreg-photo-preview', $html ); $this->assertStringContainsString( 'tbreg_receipt', $html );
				if ( $directory && is_dir( $directory ) ) { file_put_contents( $directory . '/inscripcion.pdf', $pdf ); file_put_contents( $directory . '/foto-prueba.jpg', Photo::jpeg( $values['f_photo'] ) ); file_put_contents( $directory . '/detail.html', $html ); }
			}
		}
		$file = ( new EntriesExporter( $this->entries ) )->build( $form, 'csv' ); $content = file_get_contents( $file['path'] ); wp_delete_file( $file['path'] ); $this->assertStringContainsString( 'Japonés', $content ); $this->assertStringContainsString( 'Mandarín', $content ); $this->assertStringContainsString( 'Coreano', $content ); $this->assertStringContainsString( 'Básico 1', $content ); $this->assertStringContainsString( 'Foto adjunta', $content ); $this->assertStringNotContainsString( 'base64', $content );
		if ( $directory && is_dir( $directory ) ) { file_put_contents( $directory . '/inscritos.csv', $content ); $file = ( new EntriesExporter( $this->entries ) )->build( $form, 'xlsx' ); copy( $file['path'], $directory . '/inscritos.xlsx' ); wp_delete_file( $file['path'] ); file_put_contents( $directory . '/form.html', ( new FormRenderer() )->render( $form ) ); }
	}
	public function test_invalid_combinations_are_rejected_without_partial_entries(): void {
		$form = $this->form();
		foreach ( array( array( 'f_module' => 'intermedio_1' ), array( 'f_schedule' => 'virtual_19_21' ), array( 'f_mode' => 'presencial' ), array( 'f_course' => 'inventado' ), array( 'f_photo' => 'data:image/svg+xml;base64,' . base64_encode( '<svg/>' ) ) ) as $bad ) { $result = $this->submit( $form, array_merge( $this->values(), $bad ) ); $this->assertInstanceOf( \WP_Error::class, $result ); $this->assertSame( 0, $this->entries->paginate()['total'] ); }
		$result = $this->submit( $form, array_merge( $this->values(), array( 'f_level' => 'intermedio', 'f_module' => 'intermedio_1' ) ) ); $this->assertIsArray( $result );
	}
	public function test_server_checks_relationships_again_after_a_schema_change(): void {
		$form = $this->form(); $change = function () use ( $form ): void { $data = $form->toArray(); $data['fields'][2]['options'][0]['when'] = array( 'f_level' => array( 'avanzado' ) ); $this->forms->save( Form::fromArray( $data ) ); };
		add_action( 'tbreg_before_submit', $change ); try { $result = $this->submit( $form, $this->values() ); $this->assertSame( 'tbreg_validation', $result->get_error_code() ); $this->assertSame( 0, $this->entries->paginate()['total'] ); } finally { remove_action( 'tbreg_before_submit', $change ); }
	}
	public function test_receipt_cannot_be_opened_with_another_cookie_bad_nonce_or_deleted_entry(): void {
		$form = $this->form(); $result = $this->submit( $form, $this->values() ); parse_str( wp_parse_url( $result['pdf_url'], PHP_URL_QUERY ), $query ); $owner = $_COOKIE['tbreg_receipt_owner']; $_COOKIE['tbreg_receipt_owner'] = str_repeat( '0', 32 ); $this->assertInstanceOf( \WP_Error::class, $this->receipt->resolve( $query ) ); $_COOKIE['tbreg_receipt_owner'] = $owner;
		$this->assertIsArray( $this->receipt->resolve( $query ) ); $query['token'] = array( 'bad' ); $this->assertInstanceOf( \WP_Error::class, $this->receipt->resolve( $query ) );
		$admin = array( 'entry_id' => $result['entry_id'], '_wpnonce' => wp_create_nonce( 'tbreg_receipt_' . $result['entry_id'] ) ); $this->assertIsArray( $this->receipt->resolve( $admin ) ); $this->caps['tbreg_view_entries'] = false; $this->assertInstanceOf( \WP_Error::class, $this->receipt->resolve( $admin ) ); $this->caps['tbreg_view_entries'] = true; $admin['_wpnonce'] = 'bad'; $this->assertInstanceOf( \WP_Error::class, $this->receipt->resolve( $admin ) );
		$key = $this->tokens[0]; TransientStore::delete( $key ); parse_str( wp_parse_url( $result['pdf_url'], PHP_URL_QUERY ), $query ); $this->assertInstanceOf( \WP_Error::class, $this->receipt->resolve( $query ) );
		$result = $this->submit( $form, $this->values() ); parse_str( wp_parse_url( $result['pdf_url'], PHP_URL_QUERY ), $query ); $this->entries->delete( $result['entry_id'] ); $this->assertInstanceOf( \WP_Error::class, $this->receipt->resolve( $query ) ); $this->assertSame( array(), $this->entries->values( $result['entry_id'] ) );
	}
	public function test_edit_rejects_incompatible_descendants_and_preserves_photo(): void {
		$form = $this->form(); $result = $this->submit( $form, $this->values() ); $stored = $this->entries->values( $result['entry_id'] ); $validator = new Validator();
		$error = $validator->validate_edit( $form, array( 'f_level' => 'intermedio' ), $stored ); $this->assertArrayHasKey( 'f_module', $error->get_error_data()['fields'] );
		$changed = $validator->validate_edit( $form, array( 'f_level' => 'intermedio', 'f_module' => 'intermedio_1', 'f_photo' => 'tampered' ), $stored ); $this->assertIsArray( $changed ); $this->assertArrayNotHasKey( 'f_photo', $changed );
	}
	public function test_templates_remap_references_and_invalid_schema_cycles_are_rejected(): void {
		$template = Templates::all()['languages']; $form = Form::fromArray( array( 'title' => 'Idioma', 'fields' => $template['fields'], 'settings' => $template['settings'] ) ); $this->assertTrue( $form->settings()['pdf_enabled'] ); $keys = array_column( $form->toArray()['fields'], 'key' );
		foreach ( $form->fields() as $field ) { foreach ( $field->get( 'options' ) as $option ) { foreach ( array_keys( $option['when'] ?? array() ) as $parent ) $this->assertContains( $parent, $keys ); } }
		$data = LanguageTemplate::get()['fields']; $data[0]['options'][0]['when'] = array( 'f_level' => array( 'basico' ) ); $this->expectException( \InvalidArgumentException::class ); Form::fromArray( array( 'title' => 'Ciclo', 'fields' => $data ) );
	}
	public function test_photo_rejects_disguised_files_huge_dimensions_and_local_paths(): void {
		$this->assertInstanceOf( \WP_Error::class, Photo::normalize( '/etc/passwd' ) ); $this->assertInstanceOf( \WP_Error::class, Photo::normalize( 'https://example.test/photo.jpg' ) ); $this->assertInstanceOf( \WP_Error::class, Photo::normalize( 'data:image/jpeg;base64,' . base64_encode( '<?php echo 1;' ) ) );
		$this->assertInstanceOf( \WP_Error::class, Photo::normalize( str_replace( 'image/png', 'image/jpeg', $this->photo() ) ) ); $this->assertSame( '', Photo::jpeg( 'corrupto' ) );
		$image = imagecreatetruecolor( 4100, 1 ); ob_start(); imagepng( $image ); $bytes = ob_get_clean(); imagedestroy( $image ); $this->assertInstanceOf( \WP_Error::class, Photo::normalize( 'data:image/png;base64,' . base64_encode( $bytes ) ) );
		$form = $this->form(); $this->assertInstanceOf( \WP_Error::class, Photo::uploads( $form, array(), array( 'tbreg_photos' => array( 'error' => array( 'f_photo' => UPLOAD_ERR_OK ), 'tmp_name' => array( 'f_photo' => __FILE__ ) ) ) ) );
	}
	public function test_editor_remaps_auto_generated_option_values_in_conditions(): void {
		$fields = array( array( 'key' => 'f_parent', 'type' => 'select', 'label' => 'Curso', 'options' => array( array( 'label' => 'Japonés', 'value' => 'preview', 'auto' => true ) ) ), array( 'key' => 'f_child', 'type' => 'select', 'label' => 'Módulo', 'options' => array( array( 'value' => 'uno', 'label' => 'Uno', 'when' => array( 'f_parent' => array( 'preview' ) ) ) ) ) );
		$input = array( 'tbreg_nonce' => wp_create_nonce( 'tbreg_save_form' ), 'tbreg_title' => 'Auto', 'tbreg_fields' => wp_json_encode( $fields ), 'tbreg_settings' => array() );
		$saved = ( new FormEditorPage( $this->forms ) )->save( $input ); $this->assertInstanceOf( Form::class, $saved ); $this->assertSame( array( 'japones' ), $saved->fields()[1]->get( 'options' )[0]['when']['f_parent'] );
	}
	public function test_long_pdf_paginates_without_losing_the_end_of_a_value(): void {
		$form = $this->form(); $data = $form->toArray(); $data['fields'][] = array( 'key' => 'f_long', 'type' => 'textarea', 'label' => 'Comentarios' ); $form = Form::fromArray( $data );
		$result = $this->submit( $this->forms->find( $form->id() ), $this->values() ); $values = $this->entries->values( $result['entry_id'] ); $values['f_long'] = str_repeat( 'Texto largo con tildes Bogotá. ', 170 ) . 'FINAL_UNICO';
		$pdf = ( new RegistrationPdf() )->build( $form, $this->entries->find( $result['entry_id'] ), $values ); $this->assertDoesNotMatchRegularExpression( '~/Count 2\b~', $pdf ); $this->assertStringContainsString( '/ToUnicode', $pdf );
		$directory = getenv( 'TBREG_LANGUAGE_FIXTURE_DIR' ); if ( $directory && is_dir( $directory ) ) file_put_contents( $directory . '/inscripcion-larga.pdf', $pdf );
	}
}
