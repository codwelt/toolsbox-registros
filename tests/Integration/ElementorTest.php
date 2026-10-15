<?php
/** Integración con las APIs reales de Elementor, aislada del resto de pruebas. @package ToolsBoxRegistros */
declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Tests\Integration;

use Codwelt\ToolsBoxRegistros\Domain\Form;
use Codwelt\ToolsBoxRegistros\Admin\LanguageTemplate;
use Codwelt\ToolsBoxRegistros\Elementor\FormWidget;
use Codwelt\ToolsBoxRegistros\Elementor\Integration;
use Codwelt\ToolsBoxRegistros\Frontend\FormRenderer;
use Codwelt\ToolsBoxRegistros\Install\Installer;
use Codwelt\ToolsBoxRegistros\Repository\FormRepository;
use PHPUnit\Framework\TestCase;
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ElementorTest extends TestCase {
	private \wpdb $db;
	private \wpdb $original_db;
	private FormRepository $forms;
	private int $previous_user;
	private \Closure $caps;
	private array $posts = array();
	protected function setUp(): void {
		if ( '1' !== getenv( 'TBREG_RUN_INTEGRATION' ) ) self::markTestSkipped( 'Activa TBREG_RUN_INTEGRATION=1.' );
		if ( ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) throw new \RuntimeException( 'Se requiere WordPress local/development.' );
		$path = WP_PLUGIN_DIR . '/elementor/elementor.php';
		if ( ! is_file( $path ) ) self::markTestSkipped( 'Instala Elementor gratuito para verificar esta integración opcional.' );
		require_once $path;
		\Elementor\Plugin::$instance->init();
		global $wpdb;
		$this->original_db = $wpdb;
		$this->db = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		// Mantiene las tablas WordPress reales; cambia solo el prefijo que usan los repositorios del plugin.
		$this->db->set_prefix( $wpdb->prefix );
		$this->db->prefix = 'tbregtest_' . bin2hex( random_bytes( 6 ) ) . '_';
		$wpdb = $this->db;
		dbDelta( Installer::schema( $this->db ) );
		register_shutdown_function( array( $this, 'cleanup' ) );
		$this->forms = new FormRepository();
		$this->previous_user = get_current_user_id();
		$users = get_users( array( 'number' => 1, 'fields' => 'ID' ) );
		wp_set_current_user( (int) $users[0] );
		$this->caps = static fn ( array $caps ): array => array_merge( $caps, array_fill_keys( Installer::CAPABILITIES, true ), array( 'edit_posts' => true ) );
		add_filter( 'user_has_cap', $this->caps );
		( new Integration() )->register();
	}
	public function cleanup(): void {
		if ( ! isset( $this->db ) || ! preg_match( '/^tbregtest_[a-f0-9]{12}_$/D', $this->db->prefix ) ) return;
		foreach ( $this->posts as $id ) wp_delete_post( $id, true );
		$this->posts = array();
		foreach ( array( 'entry_values', 'entries', 'forms' ) as $suffix ) $this->db->query( 'DROP TABLE IF EXISTS `' . $this->db->prefix . 'tbreg_' . $suffix . '`' );
	}
	protected function tearDown(): void {
		$this->cleanup();
		remove_filter( 'user_has_cap', $this->caps );
		wp_set_current_user( $this->previous_user );
		$GLOBALS['wpdb'] = $this->original_db;
	}
	private function form( string $status = 'published' ): Form {
		$template = LanguageTemplate::get();
		$form = $this->forms->save( Form::fromArray( array( 'title' => 'Idiomas Elementor QA', 'status' => $status, 'fields' => $template['fields'], 'settings' => $template['settings'] ) ) );
		$this->assertInstanceOf( Form::class, $form );
		return $form;
	}
	private function widget( array $settings, string $id = 'a1b2c3d' ): FormWidget {
		return new FormWidget( array( 'id' => $id, 'elType' => 'widget', 'widgetType' => 'tbreg_form', 'settings' => $settings, 'elements' => array() ), array() );
	}
	private function html( FormWidget $widget ): string {
		ob_start(); $widget->render_content(); return (string) ob_get_clean();
	}
	public function test_registration_assets_and_optional_loading(): void {
		$manager = \Elementor\Plugin::$instance->widgets_manager;
		$this->assertInstanceOf( FormWidget::class, $manager->get_widget_types( 'tbreg_form' ) );
		$widget = $this->widget( array() );
		$this->assertSame( array( 'tbreg-form' ), $widget->get_style_depends() );
		$this->assertSame( array( 'tbreg-elementor' ), $widget->get_script_depends() );
		$this->assertContains( 'elementor-frontend', wp_scripts()->registered['tbreg-elementor']->deps );
		$this->assertContains( 'tbreg-form', wp_scripts()->registered['tbreg-elementor']->deps );
		$method = new \ReflectionMethod( FormWidget::class, 'is_dynamic_content' ); $method->setAccessible( true );
		$this->assertTrue( $method->invoke( $widget ) );
	}
	public function test_field_styles_are_private_to_instance_and_do_not_change_schema(): void {
		$form = $this->form(); $revision = $form->revision();
		$settings = array( 'form_id' => (string) $form->id(), 'field_styles' => array(
			array( '_id' => 'name123', 'field_key' => $form->id() . ':f_name', 'control_color' => '#005566' ),
			array( '_id' => 'photo12', 'field_key' => $form->id() . ':f_photo' ),
			array( '_id' => 'wrong12', 'field_key' => ( $form->id() + 1 ) . ':f_name' ),
			array( '_id' => 'bad" onmouseover="alert(1)', 'field_key' => $form->id() . ':f_name' ),
			array( '_id' => 'missing', 'field_key' => $form->id() . ':f_missing' ),
		) );
		$html = $this->html( $this->widget( $settings ) );
		$this->assertStringContainsString( 'elementor-repeater-item-name123', $html );
		$this->assertStringContainsString( 'elementor-repeater-item-photo12', $html );
		foreach ( array( 'wrong12', 'missing', 'onmouseover' ) as $bad ) $this->assertStringNotContainsString( $bad, $html );
		$plain = $this->html( $this->widget( array( 'form_id' => $form->id() ), 'd4e5f6a' ) );
		$this->assertStringNotContainsString( 'elementor-repeater-item-name123', $plain );
		$this->assertSame( $revision, $this->forms->find( $form->id() )->revision() );
		preg_match( '/<section[^>]*id="([^"]+)"/', $html, $first ); preg_match( '/<section[^>]*id="([^"]+)"/', $plain, $second );
		$this->assertNotSame( $first[1], $second[1] );
		$this->assertStringContainsString( 'data-preview="false"', $html );
		$this->assertStringContainsString( 'toolsbox-registros/v1/forms/', $html );
	}
	public function test_drafts_permissions_closed_and_deleted_forms(): void {
		$form = $this->form( 'draft' );
		$this->assertSame( '', $this->html( $this->widget( array( 'form_id' => $form->id() ) ) ) );
		\Elementor\Plugin::$instance->editor->set_edit_mode( true );
		$html = $this->html( $this->widget( array( 'form_id' => $form->id() ) ) );
		$this->assertStringContainsString( 'data-preview="true"', $html );
		$this->assertMatchesRegularExpression( '/<button type="submit"[^>]*disabled/', $html );
		remove_filter( 'user_has_cap', $this->caps ); wp_set_current_user( 0 );
		\Elementor\Plugin::$instance->controls_manager->delete_stack( new FormWidget() );
		$widget = $this->widget( array( 'form_id' => $form->id() ) );
		$this->assertArrayNotHasKey( $form->id(), $widget->get_controls( 'form_id' )['options'] );
		$this->assertSame( '', $this->html( $widget ) );
		\Elementor\Plugin::$instance->editor->set_edit_mode( false );
		$closed = $this->form( 'closed' );
		$this->assertStringContainsString( 'tbreg-registration', $this->html( $this->widget( array( 'form_id' => $closed->id() ) ) ) );
		$this->assertStringNotContainsString( '<form ', $this->html( $this->widget( array( 'form_id' => $closed->id() ) ) ) );
		$this->assertSame( '', $this->html( $this->widget( array( 'form_id' => 99999999 ) ) ) );
	}
	public function test_page_editor_can_preview_public_forms_without_sending_or_seeing_drafts(): void {
		$public = $this->form(); $draft = $this->form( 'draft' );
		\Elementor\Plugin::$instance->editor->set_edit_mode( true );
		$permissions = static fn ( array $caps ): array => array_merge( $caps, array( 'tbreg_manage_forms' => false, 'edit_posts' => true ) );
		add_filter( 'user_has_cap', $permissions, 20 );
		try {
			$html = $this->html( $this->widget( array( 'form_id' => $public->id() ) ) );
			$this->assertStringContainsString( 'data-preview="true"', $html );
			$this->assertMatchesRegularExpression( '/<button type="submit"[^>]*disabled/', $html );
			$this->assertStringNotContainsString( '<form ', $this->html( $this->widget( array( 'form_id' => $draft->id() ) ) ) );
		} finally { remove_filter( 'user_has_cap', $permissions, 20 ); }
	}
	public function test_elementor_generates_scoped_responsive_and_individual_css(): void {
		$form = $this->form();
		$settings = array( 'form_id' => (string) $form->id(), 'container_background' => '#f2f7fb', 'labels_color' => '#125577', 'input_color' => '#243b53', 'input_background' => '#ffffff', 'input_radius' => array( 'unit' => 'px', 'size' => 12 ), 'input_typography_typography' => 'custom', 'input_typography_font_size' => array( 'unit' => 'px', 'size' => 17 ), 'container_padding' => array( 'unit' => 'px', 'top' => '32', 'right' => '32', 'bottom' => '32', 'left' => '32', 'isLinked' => true ), 'container_padding_mobile' => array( 'unit' => 'px', 'top' => '12', 'right' => '12', 'bottom' => '12', 'left' => '12', 'isLinked' => true ), 'button_background' => '#125577', 'button_color' => '#ffffff', 'button_hover_background' => '#0c3b55', 'field_styles' => array( array( '_id' => 'name123', 'label' => 'Nombre', 'field_key' => $form->id() . ':f_name', 'control_background' => '', 'background' => '#fff2cc', 'width' => '3', 'width_mobile' => '6', 'label_color' => '#7c4a00' ) ) );
		$plain = array( 'form_id' => (string) $form->id(), 'container_background' => '#fff5f5', 'button_background' => '#982735', 'show_title' => '' );
		$data = array( array( 'id' => 'a1b2c3d', 'elType' => 'widget', 'widgetType' => 'tbreg_form', 'settings' => $settings, 'elements' => array() ), array( 'id' => 'd4e5f6a', 'elType' => 'widget', 'widgetType' => 'tbreg_form', 'settings' => $plain, 'elements' => array() ) );
		$post = wp_insert_post( array( 'post_title' => 'ToolsBox QA Elementor temporal', 'post_type' => 'page', 'post_status' => 'draft' ) );
		$this->posts[] = $post;
		update_post_meta( $post, '_elementor_edit_mode', 'builder' ); update_post_meta( $post, '_elementor_template_type', 'wp-page' ); update_post_meta( $post, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
		$css = ( new \Elementor\Core\Files\CSS\Post( $post ) )->get_content();
		foreach ( array( '.elementor-element-a1b2c3d', '.elementor-element-d4e5f6a', '#f2f7fb', '#fff5f5', '#fff2cc', '.elementor-repeater-item-name123', 'grid-column:span 3', 'grid-column:span 6', '@media', 'padding:12px', 'border-radius:12px', 'display:none', 'font-size:17px' ) as $expected ) $this->assertStringContainsString( $expected, $css );
		$this->assertStringNotContainsString( '{{', $css );
		$directory = getenv( 'TBREG_ELEMENTOR_FIXTURE_DIR' );
		if ( $directory && is_dir( $directory ) ) {
			$html = '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;background:#eef1f5;font-family:Arial,sans-serif;padding:24px}.elementor-' . $post . '{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:24px}@media(max-width:767px){body{padding:8px}.elementor-' . $post . '{grid-template-columns:1fr}}</style><style>' . file_get_contents( TBREG_PATH . 'assets/public/css/form.css' ) . $css . '</style></head><body><main class="elementor-' . $post . '">';
			foreach ( $data as $element ) $html .= '<div class="elementor-element elementor-element-' . $element['id'] . '">' . $this->html( $this->widget( $element['settings'], $element['id'] ) ) . '</div>';
			$html .= '</main><script>' . file_get_contents( TBREG_PATH . 'assets/public/js/form.js' ) . '</script></body></html>';
			file_put_contents( $directory . '/widgets.html', $html ); file_put_contents( $directory . '/styles.css', $css );
			file_put_contents( $directory . '/settings.json', wp_json_encode( array( 'settings' => $settings, 'controls' => $this->widget( $settings )->get_controls() ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
		}
	}
}
