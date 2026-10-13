<?php
/**
 * Reglas del servidor independientes del almacenamiento.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Tests\Unit;
use Codwelt\ToolsBoxRegistros\Domain\Field;
use Codwelt\ToolsBoxRegistros\Domain\Form;
use Codwelt\ToolsBoxRegistros\Domain\FieldTypeRegistry;
use Codwelt\ToolsBoxRegistros\Domain\FieldTypes\AbstractFieldType;
use Codwelt\ToolsBoxRegistros\Services\Validator;
use Codwelt\ToolsBoxRegistros\Services\RegistrationRules;
use Codwelt\ToolsBoxRegistros\Services\SpamGuard;
use PHPUnit\Framework\TestCase;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ValidatorTest extends TestCase {
	private function schema( string $type, array $extra = array() ): Form {
		$field = array_merge( array( 'key' => 'f_test', 'type' => $type, 'label' => 'Prueba', 'required' => true ), $extra );
		if ( in_array( $type, array( 'select', 'radio', 'checkbox' ), true ) ) $field['options'] = array( array( 'value' => 'a', 'label' => 'Bogotá' ), array( 'value' => 'b', 'label' => 'Medellín' ) );
		return Form::fromArray( array( 'id' => 12, 'title' => 'Prueba', 'status' => 'published', 'fields' => array( $field ) ) );
	}
	/** @dataProvider invalidValues */
	public function test_rejects_missing_bad_formats_nested_values_and_limits( string $type, mixed $value, array $extra ): void {
		$result = ( new Validator() )->validate( $this->schema( $type, $extra ), array( 'f_test' => $value ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 422, $result->get_error_data()['status'] );
		$this->assertArrayHasKey( 'f_test', $result->get_error_data()['fields'] );
	}
	public function invalidValues(): array {
		return array(
			array( 'text', '', array() ), array( 'email', 'a b@example.test', array() ), array( 'email', 'broken', array() ),
			array( 'email', array( 'a@example.test' ), array() ), array( 'text', array( 'nested' => array( 'x' ) ), array() ),
			array( 'number', 'NaN', array() ), array( 'number', '1e9999', array() ), array( 'number', '0', array( 'validation' => array( 'min' => 1 ) ) ), array( 'number', '6', array( 'validation' => array( 'max' => 5 ) ) ),
			array( 'date', '2026-02-30', array() ), array( 'time', '25:61', array() ), array( 'url', 'javascript:alert(1)', array() ),
			array( 'document', '<script>x</script>', array() ), array( 'document', 'CC/123', array() ), array( 'document', str_repeat( 'a', 61 ), array() ),
			array( 'select', 'outside', array() ), array( 'radio', 'outside', array() ), array( 'checkbox', array( 'a', 'outside' ), array() ),
			array( 'checkbox', array( array( 'a' ) ), array() ), array( 'checkbox', array(), array() ), array( 'consent', 'yes', array() ), array( 'consent', '0', array() ),
			array( 'date', '2026-01-01', array( 'validation' => array( 'min' => '2026-02-01' ) ) ), array( 'time', '18:00', array( 'validation' => array( 'max' => '17:00' ) ) ),
			array( 'text', 'ññ', array( 'validation' => array( 'minlength' => 3 ) ) ), array( 'textarea', 'abcde', array( 'validation' => array( 'maxlength' => 4 ) ) ),
			array( 'text', 'abc', array( 'validation' => array( 'pattern' => '[0-9]+' ) ) ), array( 'text', '123', array( 'validation' => array( 'pattern' => '[' ) ) ),
		);
	}
	public function test_schema_discards_incompatible_controls_and_keeps_consent_unchecked(): void {
		$field = Field::fromArray( array( 'key' => 'f_test', 'type' => 'heading', 'label' => 'Título', 'placeholder' => array(), 'role' => 'email', 'required' => true, 'default' => array(), 'validation' => array( 'min' => 'bad', 'maxlength' => 'bad', 'pattern' => array() ) ) );
		$this->assertSame( '', $field->get( 'placeholder' ) ); $this->assertNull( $field->get( 'role' ) ); $this->assertFalse( $field->get( 'required' ) ); $this->assertSame( '', $field->get( 'default' ) );
		$this->assertSame( array( 'min' => null, 'max' => null, 'minlength' => null, 'maxlength' => null, 'pattern' => null ), $field->get( 'validation' ) );
		$field = Field::fromArray( array( 'key' => 'f_consent', 'type' => 'consent', 'label' => 'Consentimiento', 'required' => false, 'default' => '1' ) );
		$this->assertTrue( $field->get( 'required' ) ); $this->assertSame( '', $field->get( 'default' ) );
	}
	public function test_time_limits_compare_identical_minutes_with_or_without_seconds(): void {
		$form = $this->schema( 'time', array( 'validation' => array( 'min' => '17:00:00', 'max' => '17:00' ) ) );
		$this->assertSame( array( 'f_test' => '17:00' ), ( new Validator() )->validate( $form, array( 'f_test' => '17:00' ) ) );
		$this->assertSame( array( 'f_test' => '17:00:00' ), ( new Validator() )->validate( $form, array( 'f_test' => '17:00:00' ) ) );
	}
	public function test_sanitizes_ignores_unknown_archived_and_fixed_hidden_values(): void {
		$form = Form::fromArray( array( 'title' => 'Validación', 'fields' => array(
			array( 'key' => 'f_text', 'type' => 'text', 'label' => 'Texto' ),
			array( 'key' => 'f_old', 'type' => 'text', 'label' => 'Archivado', 'archived' => true ),
			array( 'key' => 'f_heading', 'type' => 'heading', 'label' => 'Título' ),
			array( 'key' => 'f_fixed', 'type' => 'hidden', 'label' => 'Origen', 'default' => 'web' ),
		) ) );
		$values = ( new Validator() )->validate( $form, array( 'f_text' => '<b>Muñoz 😀</b>', 'f_old' => 'old', 'f_heading' => 'bad', 'unknown' => 'bad', 'f_fixed' => 'tampered' ) );
		$this->assertSame( array( 'f_text' => 'Muñoz 😀', 'f_fixed' => 'web' ), $values );
		$this->assertSame( array( 'f_test' => '0' ), ( new Validator() )->validate( $this->schema( 'number' ), array( 'f_test' => '0' ) ) );
	}
	public function test_registry_all_native_types_and_export_labels(): void {
		$registry = new FieldTypeRegistry();
		foreach ( Field::TYPES as $type ) {
			$instance = $registry->get( $type );
			$this->assertSame( $type, $instance->type() );
			$this->assertNotEmpty( $instance->label() );
			$this->assertSame( 'heading' !== $type, $instance->storesValue() );
		}
		$field = $this->schema( 'checkbox' )->fields()[0];
		$this->assertSame( 'Bogotá, Medellín', $registry->get( 'checkbox' )->toExport( array( 'a', 'b' ), $field ) );
	}
	public function test_custom_type_filter_can_extend_schema_and_validation(): void {
		$filter = static function ( array $types ): array { $types['custom'] = new class extends AbstractFieldType { public const TYPE = 'custom'; public function label(): string { return 'Personalizado'; } }; return $types; };
		add_filter( 'tbreg_field_types', $filter );
		try { $form = $this->schema( 'custom' ); $this->assertSame( array( 'f_test' => 'Correcto' ), ( new Validator() )->validate( $form, array( 'f_test' => 'Correcto' ) ) ); }
		finally { remove_filter( 'tbreg_field_types', $filter ); }
	}
	public function test_registration_window_uses_site_timezone_and_close_is_exclusive(): void {
		$filter = static fn (): string => 'America/Bogota'; add_filter( 'pre_option_timezone_string', $filter );
		try {
			$form = Form::fromArray( array( 'title' => 'Fechas', 'status' => 'published', 'settings' => array( 'opens_at' => '2026-10-13 09:00:00', 'closes_at' => '2026-10-13 10:00:00' ) ) );
			$rules = new RegistrationRules();
			$this->assertSame( 'tbreg_not_open', $rules->check( $form, strtotime( '2026-10-13 13:59:59 UTC' ) )->get_error_code() );
			$this->assertNull( $rules->check( $form, strtotime( '2026-10-13 14:00:00 UTC' ) ) );
			$this->assertSame( 'tbreg_closed', $rules->check( $form, strtotime( '2026-10-13 15:00:00 UTC' ) )->get_error_code() );
		} finally { remove_filter( 'pre_option_timezone_string', $filter ); }
	}
	public function test_signed_tokens_reject_tampering_wrong_form_expiry_future_and_fast_submit(): void {
		$guard = new SpamGuard(); $now = time(); $token = $guard->issue( 12, $now - 3 );
		$this->assertNull( $guard->token_error( 12, $token, $now ) );
		$this->assertSame( 'tbreg_token', $guard->token_error( 13, $token, $now )->get_error_code() );
		$this->assertSame( 'tbreg_token', $guard->token_error( 12, $guard->issue( 12, $now - 43201 ), $now )->get_error_code() );
		$this->assertSame( 'tbreg_token', $guard->token_error( 12, $guard->issue( 12, $now + 1 ), $now )->get_error_code() );
		$this->assertSame( 'tbreg_too_fast', $guard->token_error( 12, $guard->issue( 12, $now ), $now )->get_error_code() );
		$token['tbreg_ts'] = (string) ( $now - 4 ); $this->assertSame( 'tbreg_token', $guard->token_error( 12, $token, $now )->get_error_code() );
	}
}
