<?php
/**
 * Envío público en tablas aisladas con MySQL real.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Tests\Integration;
use Codwelt\ToolsBoxRegistros\Domain\Field;
use Codwelt\ToolsBoxRegistros\Domain\Form;
use Codwelt\ToolsBoxRegistros\Install\Installer;
use Codwelt\ToolsBoxRegistros\Repository\FormRepository;
use Codwelt\ToolsBoxRegistros\Repository\EntryRepository;
use Codwelt\ToolsBoxRegistros\Frontend\SubmissionHandler;
use Codwelt\ToolsBoxRegistros\Frontend\FormRenderer;
use Codwelt\ToolsBoxRegistros\Frontend\Feedback;
use Codwelt\ToolsBoxRegistros\Frontend\Shortcode;
use Codwelt\ToolsBoxRegistros\Frontend\Block;
use Codwelt\ToolsBoxRegistros\Frontend\Template;
use Codwelt\ToolsBoxRegistros\Rest\SubmitController;
use Codwelt\ToolsBoxRegistros\Services\SpamGuard;
use Codwelt\ToolsBoxRegistros\Services\TransientStore;
use Codwelt\ToolsBoxRegistros\Admin\SettingsPage;
use PHPUnit\Framework\TestCase;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class SubmissionTest extends TestCase {
	private static \wpdb $db;
	private FormRepository $forms;
	private EntryRepository $entries;
	private SubmissionHandler $handler;
	private SpamGuard $spam;
	private array $ips = array();
	private int $previous_user;
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
		$this->forms = new FormRepository( self::$db ); $this->entries = new EntryRepository( self::$db ); $this->spam = new SpamGuard( self::$db );
		$this->handler = new SubmissionHandler( $this->forms, $this->entries, $this->spam );
		$this->previous_user = get_current_user_id(); wp_set_current_user( 0 ); $this->ips = array();
	}
	protected function tearDown(): void {
		foreach ( $this->ips as $ip ) TransientStore::delete( 'tbreg_rate_' . hash_hmac( 'sha256', (string) inet_pton( $ip ), wp_salt() ) );
		wp_set_current_user( $this->previous_user ); $_GET = array(); $_POST = array(); $_REQUEST = array();
	}
	private function form( array $settings = array() ): Form {
		$fields = array();
		foreach ( Field::TYPES as $type ) {
			$data = array( 'key' => 'f_' . $type, 'type' => $type, 'label' => 'Campo ' . $type, 'help' => 'Ayuda ' . $type, 'required' => ! in_array( $type, array( 'heading', 'hidden' ), true ) );
			if ( in_array( $type, array( 'select', 'radio', 'checkbox' ), true ) ) $data['options'] = array( array( 'value' => 'a', 'label' => 'Bogotá' ), array( 'value' => 'b', 'label' => 'Medellín' ) );
			if ( in_array( $type, array( 'email', 'document' ), true ) ) $data['role'] = $type;
			if ( 'text' === $type ) { $data['role'] = 'name'; $data['width'] = 'half'; }
			if ( 'number' === $type ) $data['validation'] = array( 'min' => 1, 'max' => 5 );
			if ( 'hidden' === $type ) $data['default'] = 'web';
			$fields[] = $data;
		}
		$result = $this->forms->save( Form::fromArray( array( 'title' => 'Inscripción pública', 'status' => 'published', 'fields' => $fields, 'settings' => $settings ) ) );
		$this->assertInstanceOf( Form::class, $result ); return $result;
	}
	private function input( Form $form ): array {
		return array_merge( $this->spam->issue( $form->id(), time() - 4 ), array( 'tbreg_form_id' => (string) $form->id(), 'tbreg_website' => '', 'tbreg_return' => home_url( '/evento/' ), 'tbreg_values' => array(
			'f_text' => 'Ana Muñoz 😀', 'f_email' => 'ANA@example.test', 'f_tel' => '+57 3001234567', 'f_number' => '2.5', 'f_textarea' => "Una línea\nOtra línea", 'f_select' => 'a', 'f_radio' => 'b', 'f_checkbox' => array( 'a', 'b' ), 'f_date' => '2026-11-01', 'f_time' => '13:30', 'f_url' => 'https://example.test/', 'f_document' => 'CC123', 'f_consent' => '1', 'f_hidden' => 'tampered', 'f_heading' => 'ignored', 'unknown' => 'ignored',
		) ) );
	}
	private function transport(): array {
		$ip = '2001:db8::' . bin2hex( random_bytes( 2 ) ); $this->ips[] = $ip; return array( 'ip' => $ip, 'user_agent' => 'Tests ToolsBox' );
	}
	public function test_feedback_survives_a_nonpersistent_external_cache_dropin(): void {
		$external = wp_using_ext_object_cache();
		wp_using_ext_object_cache( true );
		try {
			$token = Feedback::store( 123, array( 'message' => 'Resultado privado' ) );
			$this->assertTrue( wp_using_ext_object_cache() );
			wp_cache_flush();
			$this->assertSame( array( 'message' => 'Resultado privado' ), Feedback::consume( 123, $token ) );
			$this->assertTrue( wp_using_ext_object_cache() );
			$this->assertSame( array(), Feedback::consume( 123, $token ) );
		} finally { wp_using_ext_object_cache( $external ); }
	}
	public function test_cleanup_deletes_only_expired_security_transients_with_external_cache(): void {
		$suffix = bin2hex( random_bytes( 8 ) ); $expired = 'tbreg_feedback_' . $suffix; $active = 'tbreg_rate_' . $suffix; $other = 'tbreg_other_' . $suffix;
		$external = wp_using_ext_object_cache(); wp_using_ext_object_cache( true );
		try {
			TransientStore::set( $expired, 'privado', 300 ); TransientStore::set( $active, 'vigente', 300 ); TransientStore::set( $other, 'otro componente', 300 );
			update_option( '_transient_timeout_' . $expired, time() - 1, false ); update_option( '_transient_timeout_' . $other, time() - 1, false );
			TransientStore::cleanup();
			$this->assertFalse( get_option( '_transient_' . $expired ) ); $this->assertFalse( get_option( '_transient_timeout_' . $expired ) );
			$this->assertSame( 'vigente', TransientStore::get( $active ) ); $this->assertSame( 'otro componente', get_option( '_transient_' . $other ) ); $this->assertTrue( wp_using_ext_object_cache() );
		} finally { foreach ( array( $expired, $active, $other ) as $key ) TransientStore::delete( $key ); wp_using_ext_object_cache( $external ); }
	}
	public function test_rate_limit_survives_nonpersistent_external_cache_across_requests(): void {
		$form = $this->form(); $input = $this->input( $form ); $transport = $this->transport();
		$external = wp_using_ext_object_cache(); wp_using_ext_object_cache( true );
		try {
			for ( $i = 0; $i < 5; ++$i ) {
				$this->assertNull( $this->spam->check( $form, $input, $transport['ip'] ) );
				wp_cache_flush();
			}
			$this->assertSame( 'tbreg_rate_limit', $this->spam->check( $form, $input, $transport['ip'] )->get_error_code() );
			$this->assertTrue( wp_using_ext_object_cache() );
		} finally { wp_using_ext_object_cache( $external ); }
	}
	public function test_public_submission_persists_all_types_and_ignores_client_metadata(): void {
		$form = $this->form(); $input = $this->input( $form ); $input['status'] = 'cancelled'; $input['user_id'] = 123; $input['ip'] = '1.2.3.4';
		$transport = $this->transport(); $result = $this->handler->submit( $form->id(), $input, $transport );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertTrue( $result['success'] ); $entry = $this->entries->find( $result['entry_id'] ); $values = $this->entries->values( $result['entry_id'] );
		$this->assertSame( 'confirmed', $entry['status'] ); $this->assertNull( $entry['user_id'] );
		$this->assertSame( 'ana@example.test', $entry['email'] ); $this->assertSame( 'Ana Muñoz 😀', $values['f_text'] );
		$this->assertSame( array( 'a', 'b' ), $values['f_checkbox'] ); $this->assertSame( 'web', $values['f_hidden'] );
		$this->assertArrayNotHasKey( 'unknown', $values ); $this->assertArrayNotHasKey( 'f_heading', $values );
		$this->assertSame( hash_hmac( 'sha256', $transport['ip'], wp_salt() ), $entry['ip_hash'] );
		$this->assertSame( 1, $this->forms->find( $form->id() )->get( 'entries_count' ) );
	}
	public function test_required_formats_options_and_ranges_cannot_be_bypassed(): void {
		$form = $this->form();
		foreach ( array( 'f_text' => '', 'f_email' => 'invalid', 'f_select' => 'evil', 'f_number' => '99', 'f_consent' => '0', 'f_checkbox' => array( array( 'a' ) ) ) as $key => $bad ) {
			$input = $this->input( $form ); $input['tbreg_values'][ $key ] = $bad;
			$result = $this->handler->submit( $form->id(), $input, $this->transport() );
			$this->assertSame( 'tbreg_validation', $result->get_error_code() ); $this->assertArrayHasKey( $key, $result->get_error_data()['fields'] );
		}
		$this->assertSame( 0, $this->entries->paginate( array( 'form_id' => $form->id() ) )['total'] );
	}
	public function test_capacity_two_blocks_third_and_waitlist_keeps_count(): void {
		$form = $this->form( array( 'capacity' => 2 ) );
		for ( $i = 0; $i < 2; ++$i ) $this->assertIsArray( $this->handler->submit( $form->id(), $this->input( $form ), $this->transport() ) );
		$this->assertSame( 'tbreg_full', $this->handler->submit( $form->id(), $this->input( $form ), $this->transport() )->get_error_code() );
		$data = $this->forms->find( $form->id() )->toArray(); $data['settings']['waitlist'] = true; $form = $this->forms->save( Form::fromArray( $data ) );
		$result = $this->handler->submit( $form->id(), $this->input( $form ), $this->transport() );
		$this->assertSame( 'waitlist', $result['status'] ); $this->assertStringContainsString( 'lista de espera', $result['message'] );
		$this->assertSame( 2, $this->forms->find( $form->id() )->get( 'entries_count' ) );
	}
	public function test_email_and_document_duplicates_are_checked_against_current_form(): void {
		foreach ( array( 'email', 'document' ) as $control ) {
			$form = $this->form( array( 'prevent_duplicates' => $control ) );
			$this->assertIsArray( $this->handler->submit( $form->id(), $this->input( $form ), $this->transport() ) );
			$result = $this->handler->submit( $form->id(), $this->input( $form ), $this->transport() );
			$this->assertInstanceOf( \WP_Error::class, $result ); $this->assertStringContainsString( 'Ya existe', $result->get_error_message() );
			$this->assertSame( 1, $this->entries->paginate( array( 'form_id' => $form->id() ) )['total'] );
		}
	}
	public function test_login_closed_dates_and_drafts_are_not_public(): void {
		$form = $this->form( array( 'require_login' => true ) );
		$this->assertSame( 'tbreg_login', $this->handler->submit( $form->id(), $this->input( $form ), $this->transport() )->get_error_code() );
		foreach ( array( 'draft', 'closed', 'trash' ) as $status ) {
			$data = $form->toArray(); $data['status'] = $status; $current = $this->forms->save( Form::fromArray( $data ) );
			$this->assertInstanceOf( \WP_Error::class, $this->handler->submit( $form->id(), $this->input( $current ), $this->transport() ) );
		}
		$form = $this->form( array( 'opens_at' => '2099-01-01 00:00:00' ) );
		$this->assertSame( 'tbreg_not_open', $this->handler->submit( $form->id(), $this->input( $form ), $this->transport() )->get_error_code() );
		$form = $this->form( array( 'closes_at' => '2000-01-01 00:00:00' ) );
		$this->assertSame( 'tbreg_closed', $this->handler->submit( $form->id(), $this->input( $form ), $this->transport() )->get_error_code() );
	}
	public function test_locked_guard_handles_close_schema_edit_and_capacity_races(): void {
		$form = $this->form( array( 'capacity' => 1 ) );
		$callback = function ( Form $current ): void { $data = $current->toArray(); $data['status'] = 'closed'; $this->forms->save( Form::fromArray( $data ) ); };
		add_action( 'tbreg_before_submit', $callback );
		try { $this->assertSame( 'tbreg_closed', $this->handler->submit( $form->id(), $this->input( $form ), $this->transport() )->get_error_code() ); }
		finally { remove_action( 'tbreg_before_submit', $callback ); }
		$form = $this->form( array( 'capacity' => 1 ) );
		$callback = function ( Form $current ): void { $this->entries->create( $current, array() ); };
		add_action( 'tbreg_before_submit', $callback );
		try { $this->assertSame( 'tbreg_full', $this->handler->submit( $form->id(), $this->input( $form ), $this->transport() )->get_error_code() ); }
		finally { remove_action( 'tbreg_before_submit', $callback ); }
		$form = $this->form();
		$callback = function ( Form $current ): void { $data = $current->toArray(); $data['fields'][] = array( 'key' => 'f_new', 'type' => 'text', 'label' => 'Nuevo', 'required' => true ); $this->forms->save( Form::fromArray( $data ) ); };
		add_action( 'tbreg_before_submit', $callback );
		try { $result = $this->handler->submit( $form->id(), $this->input( $form ), $this->transport() ); $this->assertArrayHasKey( 'f_new', $result->get_error_data()['fields'] ); }
		finally { remove_action( 'tbreg_before_submit', $callback ); }
	}
	public function test_antispam_rate_limit_honeypot_and_missing_transport(): void {
		$form = $this->form(); $transport = $this->transport();
		for ( $i = 0; $i < 5; ++$i ) $this->assertNull( $this->spam->check( $form, $this->input( $form ), $transport['ip'] ) );
		$this->assertSame( 'tbreg_rate_limit', $this->spam->check( $form, $this->input( $form ), $transport['ip'] )->get_error_code() );
		$input = $this->input( $form ); $input['tbreg_website'] = 'https://spam.test';
		$this->assertSame( 'tbreg_spam', $this->handler->submit( $form->id(), $input, $this->transport() )->get_error_code() );
		$this->assertSame( 'tbreg_transport', $this->handler->submit( $form->id(), $this->input( $form ), array() )->get_error_code() );
	}
	public function test_renderer_shortcodes_unique_ids_fields_labels_and_assets(): void {
		$form = $this->form(); $renderer = new FormRenderer();
		wp_dequeue_style( 'tbreg-form' ); wp_dequeue_script( 'tbreg-form' );
		FormRenderer::register_assets(); $this->assertFalse( wp_style_is( 'tbreg-form', 'enqueued' ) );
		$html = $renderer->render( $form ) . $renderer->render( $form );
		$this->assertTrue( wp_style_is( 'tbreg-form', 'enqueued' ) ); $this->assertTrue( wp_script_is( 'tbreg-form', 'enqueued' ) );
		$dom = new \DOMDocument(); @$dom->loadHTML( '<html><body>' . $html . '</body></html>' ); $xpath = new \DOMXPath( $dom ); $ids = array();
		foreach ( $xpath->query( '//*[@id]' ) as $node ) { $this->assertNotContains( $node->getAttribute( 'id' ), $ids ); $ids[] = $node->getAttribute( 'id' ); }
		foreach ( $xpath->query( '//label[@for]' ) as $label ) $this->assertContains( $label->getAttribute( 'for' ), $ids );
		foreach ( $xpath->query( '//*[@aria-describedby]' ) as $node ) foreach ( explode( ' ', $node->getAttribute( 'aria-describedby' ) ) as $id ) $this->assertContains( $id, $ids );
		$shortcode = new Shortcode( $this->forms ); $shortcode->register();
		$this->assertStringContainsString( 'Inscripción pública', do_shortcode( '[toolsbox_registro id="' . $form->id() . '"]' ) );
		$this->assertStringContainsString( 'tbreg-form', $shortcode->render( array( 'slug' => $form->get( 'slug' ) ) ) );
		$data = $form->toArray(); $data['status'] = 'draft'; $draft = $this->forms->save( Form::fromArray( $data ) ); $this->assertSame( '', $renderer->render( $draft ) );
		$directory = getenv( 'TBREG_FRONTEND_FIXTURE_DIR' );
		if ( false !== $directory && is_dir( $directory ) ) file_put_contents( $directory . '/form.html', $html );
	}
	public function test_no_js_post_redirect_errors_preserve_values_privately_and_once(): void {
		$form = $this->form(); $input = $this->input( $form ); $input['tbreg_values']['f_email'] = 'bad';
		$input['tbreg_values']['f_text'] = '<b>Ana</b>'; $_POST = wp_slash( $input );
		$transport = $this->transport(); $old_ip = $_SERVER['REMOTE_ADDR'] ?? null; $old_method = $_SERVER['REQUEST_METHOD'] ?? null;
		$_SERVER['REMOTE_ADDR'] = $transport['ip']; $_SERVER['REQUEST_METHOD'] = 'POST';
		$filter = static function ( string $location, int $status ): string { throw new \RuntimeException( $status . '|' . $location ); }; add_filter( 'wp_redirect', $filter, 10, 2 );
		try {
			try { $this->handler->handle_post(); $this->fail( 'Falta PRG.' ); } catch ( \RuntimeException $exception ) {
				list( $status, $location ) = explode( '|', $exception->getMessage(), 2 ); $this->assertSame( '303', $status ); $this->assertStringNotContainsString( 'Ana', $location );
				parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $_GET );
				$owner = $_COOKIE['tbreg_feedback_owner']; $_COOKIE['tbreg_feedback_owner'] = str_repeat( 'b', 32 );
				$this->assertSame( array(), Feedback::consume( $form->id(), $_GET['tbreg_result'] ) ); $_COOKIE['tbreg_feedback_owner'] = $owner;
				$html = ( new FormRenderer() )->render( $form ); $this->assertStringContainsString( 'value="Ana"', $html ); $this->assertStringContainsString( 'aria-invalid="true"', $html ); $this->assertStringContainsString( 'autofocus', $html );
				$this->assertSame( array(), Feedback::consume( $form->id(), $_GET['tbreg_result'] ) );
			}
		} finally { remove_filter( 'wp_redirect', $filter, 10 ); if ( null === $old_ip ) unset( $_SERVER['REMOTE_ADDR'] ); else $_SERVER['REMOTE_ADDR'] = $old_ip; if ( null === $old_method ) unset( $_SERVER['REQUEST_METHOD'] ); else $_SERVER['REQUEST_METHOD'] = $old_method; }
	}
	public function test_no_js_success_redirect_and_same_origin_only(): void {
		$form = $this->form(); $_POST = wp_slash( $this->input( $form ) ); $transport = $this->transport();
		$old_ip = $_SERVER['REMOTE_ADDR'] ?? null; $old_method = $_SERVER['REQUEST_METHOD'] ?? null; $_SERVER['REMOTE_ADDR'] = $transport['ip']; $_SERVER['REQUEST_METHOD'] = 'POST';
		$filter = static function ( string $location ): string { throw new \RuntimeException( $location ); }; add_filter( 'wp_redirect', $filter );
		try {
			try { $this->handler->handle_post(); $this->fail( 'Falta PRG.' ); } catch ( \RuntimeException $exception ) {
				parse_str( (string) wp_parse_url( $exception->getMessage(), PHP_URL_QUERY ), $_GET ); $html = ( new FormRenderer() )->render( $form );
				$this->assertStringContainsString( 'Tu inscripción fue registrada', $html ); $this->assertStringNotContainsString( '<form', $html );
			}
		} finally { remove_filter( 'wp_redirect', $filter ); if ( null === $old_ip ) unset( $_SERVER['REMOTE_ADDR'] ); else $_SERVER['REMOTE_ADDR'] = $old_ip; if ( null === $old_method ) unset( $_SERVER['REQUEST_METHOD'] ); else $_SERVER['REQUEST_METHOD'] = $old_method; }
		$this->assertSame( home_url( '/' ), SubmissionHandler::safe_url( 'https://evil.test/phishing' ) );
		$this->assertSame( home_url( '/' ), SubmissionHandler::safe_url( '//evil.test/' ) );
		$this->assertSame( home_url( '/gracias/' ), SubmissionHandler::safe_url( home_url( '/gracias/' ) ) );
	}
	public function test_rest_dispatch_submit_token_and_editor_list_permissions(): void {
		$form = $this->form(); $controller = new SubmitController( $this->handler, $this->forms ); $server = rest_get_server(); $controller->register();
		$request = new \WP_REST_Request( 'GET', '/toolsbox-registros/v1/forms' ); $this->assertSame( 401, $server->dispatch( $request )->get_status() );
		$request = new \WP_REST_Request( 'GET', '/toolsbox-registros/v1/forms/' . $form->id() . '/token' ); $response = $server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() ); $this->assertSame( 'no-store, private', $response->get_headers()['Cache-Control'] );
		$request = new \WP_REST_Request( 'POST', '/toolsbox-registros/v1/forms/' . $form->id() . '/submit' ); $request->set_body_params( $this->input( $form ) );
		$old_ip = $_SERVER['REMOTE_ADDR'] ?? null; $_SERVER['REMOTE_ADDR'] = $this->transport()['ip'];
		try { $response = $server->dispatch( $request ); $this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) ); $this->assertTrue( $response->get_data()['success'] ); }
		finally { if ( null === $old_ip ) unset( $_SERVER['REMOTE_ADDR'] ); else $_SERVER['REMOTE_ADDR'] = $old_ip; }
		$users = get_users( array( 'number' => 1, 'fields' => 'ID' ) ); wp_set_current_user( (int) $users[0] );
		$filter = static function ( array $caps ): array { $caps['tbreg_manage_forms'] = true; return $caps; }; add_filter( 'user_has_cap', $filter );
		try { $response = $server->dispatch( new \WP_REST_Request( 'GET', '/toolsbox-registros/v1/forms' ) ); $this->assertSame( 200, $response->get_status() ); $this->assertCount( 1, $response->get_data()['items'] ); }
		finally { remove_filter( 'user_has_cap', $filter ); }
	}
	public function test_block_metadata_and_template_override(): void {
		FormRenderer::register_assets(); ( new Block() )->register(); $type = \WP_Block_Type_Registry::get_instance()->get_registered( 'codwelt/toolsbox-registro' );
		$this->assertNotNull( $type ); $this->assertSame( 'integer', $type->attributes['formId']['type'] );
		$this->assertNotEmpty( $type->editor_script_handles ); $this->assertEmpty( $type->style_handles );
		$form = $this->form(); global $wpdb; $original = $wpdb; $wpdb = self::$db;
		try {
			$markup = '<!-- wp:codwelt/toolsbox-registro {"formId":' . $form->id() . '} /-->';
			$this->assertStringContainsString( '<form', do_blocks( $markup ) );
			$data = $form->toArray(); $data['status'] = 'draft'; $this->forms->save( Form::fromArray( $data ) );
			$this->assertSame( '', do_blocks( $markup ) );
		} finally { $wpdb = $original; }
		$path = tempnam( sys_get_temp_dir(), 'tbreg-template-' ); file_put_contents( $path, '<?php if (!defined("ABSPATH")) exit; echo esc_html($tbreg_context["message"]);' );
		$filter = static fn (): string => $path; add_filter( 'tbreg_template_path', $filter );
		try { $this->assertSame( '&lt;script&gt;', Template::render( 'messages/success.php', array( 'message' => '<script>' ) ) ); $this->assertSame( '', Template::render( '../wp-config.php', array() ) ); }
		finally { remove_filter( 'tbreg_template_path', $filter ); unlink( $path ); }
	}
	public function test_captcha_http_responses_hostname_and_unconfigured_fail_closed(): void {
		foreach ( array( 'turnstile', 'recaptcha' ) as $provider ) {
			$form = $this->form( array( 'captcha' => $provider ) ); $input = $this->input( $form ); $input[ 'turnstile' === $provider ? 'cf-turnstile-response' : 'g-recaptcha-response' ] = 'mock-token';
			$this->assertSame( 'tbreg_captcha', $this->handler->submit( $form->id(), $input, $this->transport() )->get_error_code() );
			$config = static fn (): array => array( 'site_key' => 'mock-site', 'secret_key' => 'mock-secret' ); add_filter( 'tbreg_captcha_config', $config );
			$hostname = wp_parse_url( home_url(), PHP_URL_HOST );
			$http = static fn (): array => array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'success' => true, 'hostname' => $hostname ) ) ); add_filter( 'pre_http_request', $http );
			try { $this->assertIsArray( $this->handler->submit( $form->id(), $input, $this->transport() ) ); }
			finally { remove_filter( 'pre_http_request', $http ); }
			$http = static fn (): array => array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'success' => true, 'hostname' => 'evil.test' ) ) ); add_filter( 'pre_http_request', $http );
			try { $this->assertSame( 'tbreg_captcha', $this->handler->submit( $form->id(), $input, $this->transport() )->get_error_code() ); }
			finally { remove_filter( 'pre_http_request', $http ); remove_filter( 'tbreg_captcha_config', $config ); }
		}
	}
	public function test_non_transactional_storage_is_rejected_without_partial_entry(): void {
		$form = $this->form(); $table = '`' . self::$db->prefix . 'tbreg_entry_values`'; self::$db->query( 'ALTER TABLE ' . $table . ' ENGINE=MyISAM' );
		try { $this->assertSame( 'tbreg_storage', $this->handler->submit( $form->id(), $this->input( $form ), $this->transport() )->get_error_code() ); $this->assertSame( 0, $this->entries->paginate()['total'] ); }
		finally { self::$db->query( 'ALTER TABLE ' . $table . ' ENGINE=InnoDB' ); }
	}
	public function test_captcha_settings_require_cap_nonce_and_do_not_render_secret(): void {
		$settings = new SettingsPage(); $this->assertInstanceOf( \WP_Error::class, $settings->save( array() ) );
		$users = get_users( array( 'number' => 1, 'fields' => 'ID' ) ); wp_set_current_user( (int) $users[0] );
		$filter = static function ( array $caps ): array { $caps['tbreg_manage_forms'] = true; return $caps; }; add_filter( 'user_has_cap', $filter );
		$old = array(); foreach ( array( 'turnstile', 'recaptcha' ) as $provider ) $old[ $provider ] = get_option( 'tbreg_captcha_' . $provider );
		try {
			$this->assertInstanceOf( \WP_Error::class, $settings->save( array( 'tbreg_nonce' => 'invalid' ) ) );
			$input = array( 'tbreg_nonce' => wp_create_nonce( 'tbreg_save_captcha' ), 'tbreg_captcha' => array( 'turnstile' => array( 'site_key' => 'mock-site', 'secret_key' => 'tbreg-test-private' ) ) );
			$this->assertTrue( $settings->save( $input ) ); $input['tbreg_captcha']['turnstile']['secret_key'] = ''; $this->assertTrue( $settings->save( $input ) );
			$this->assertSame( 'tbreg-test-private', SpamGuard::captcha_config( 'turnstile' )['secret_key'] );
			ob_start(); $settings->render(); $html = ob_get_clean(); $this->assertStringNotContainsString( 'tbreg-test-private', $html );
		} finally { remove_filter( 'user_has_cap', $filter ); foreach ( $old as $provider => $value ) { if ( false === $value ) delete_option( 'tbreg_captcha_' . $provider ); else update_option( 'tbreg_captcha_' . $provider, $value, false ); } }
	}
}
