<?php
/**
 * Pruebas del esquema de formularios y campos.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);

namespace Codwelt\ToolsBoxRegistros\Tests\Unit;

use Codwelt\ToolsBoxRegistros\Domain\Field;
use Codwelt\ToolsBoxRegistros\Domain\Form;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FormTest extends TestCase {
	public function test_normalizes_and_round_trips_arrays_and_database_json(): void {
		$form = Form::fromArray( tbreg_test_form_data() );
		$this->assertSame( 'seminario-bogota', $form->get( 'slug' ) );
		$this->assertSame( 'Bogotá', $form->fields()[3]->get( 'options' )[0]['label'] );
		$this->assertSame( 'confirmed', $form->settings()['default_status'] );
		$data = $form->toArray();
		$data['id'] = '12';
		$data['fields'] = wp_json_encode( $data['fields'] );
		$data['settings'] = wp_json_encode( $data['settings'] );
		$restored = Form::fromArray( $data );
		$this->assertSame( 12, $restored->id() );
		$this->assertSame( $form->fields()[0]->toArray(), $restored->fields()[0]->toArray() );
		$this->assertSame( $form->settings(), $restored->settings() );
	}

	public function test_every_v1_type_can_be_defined(): void {
		foreach ( Field::TYPES as $type ) {
			$data = array( 'key' => 'f_' . $type, 'type' => $type, 'label' => $type );
			if ( in_array( $type, array( 'select', 'radio', 'checkbox' ), true ) ) {
				$data['options'] = array( array( 'value' => 'yes', 'label' => 'Sí' ) );
			}
			$field = Field::fromArray( $data );
			$this->assertSame( $type, $field->get( 'type' ) );
			$this->assertSame( 'heading' !== $type, $field->storesValue() );
		}
	}

	public function test_archived_roles_do_not_conflict_with_active_replacements(): void {
		$data = tbreg_test_form_data();
		$data['fields'][1]['archived'] = true;
		$data['fields'][] = array( 'key' => 'f_new_email', 'type' => 'email', 'label' => 'Nuevo correo', 'role' => 'email' );
		$form = Form::fromArray( $data );
		$this->assertFalse( $form->fields()[1]->storesValue() );
		$this->assertCount( 6, $form->fields() );
	}

	public function test_normalizes_local_registration_dates(): void {
		$data = tbreg_test_form_data();
		$data['settings'] = array( 'opens_at' => '2026-10-13T08:00', 'closes_at' => '2026-10-14T20:00' );
		$this->assertSame( '2026-10-13 08:00:00', Form::fromArray( $data )->settings()['opens_at'] );
	}

	public function test_long_unicode_titles_generate_valid_slugs_within_database_limit(): void {
		$data = tbreg_test_form_data();
		$data['title'] = str_repeat( '漢', 100 );
		$slug = Form::fromArray( $data )->get( 'slug' );
		$this->assertLessThanOrEqual( 200, strlen( $slug ) );
		$this->assertSame( 1, preg_match( '//u', rawurldecode( $slug ) ) );
		$this->assertSame( $slug, sanitize_title( $slug ) );
	}

	/** @dataProvider invalidSchemas */
	public function test_rejects_invalid_schemas( string $case ): void {
		$data = tbreg_test_form_data();
		switch ( $case ) {
			case 'duplicate_key': $data['fields'][1]['key'] = 'f_name'; break;
			case 'bad_key': $data['fields'][0]['key'] = '../../file'; break;
			case 'unknown_type': $data['fields'][0]['type'] = 'script'; break;
			case 'empty_label': $data['fields'][0]['label'] = '<b></b>'; break;
			case 'empty_options': $data['fields'][3]['options'] = array(); break;
			case 'duplicate_options': $data['fields'][3]['options'][1]['value'] = 'bogota'; break;
			case 'invalid_default': $data['fields'][3]['default'] = 'cali'; break;
			case 'malformed_json': $data['fields'] = '{"broken":'; break;
			case 'scalar_json': $data['fields'] = 'null'; break;
			case 'unordered_fields': $data['fields'] = array( 'named' => $data['fields'][0] ); break;
			case 'invalid_status': $data['status'] = 'evil'; break;
			case 'negative_capacity': $data['settings'] = array( 'capacity' => -1 ); break;
			case 'string_boolean': $data['fields'][0]['required'] = 'false'; break;
			case 'duplicate_role': $data['fields'][0]['type'] = 'email'; $data['fields'][0]['role'] = 'email'; break;
			case 'bad_range': $data['fields'][0]['type'] = 'number'; $data['fields'][0]['validation'] = array( 'min' => 5, 'max' => 2 ); break;
			case 'invalid_length': $data['fields'][0]['validation'] = array( 'maxlength' => 1.5 ); break;
			case 'impossible_date': $data['settings'] = array( 'opens_at' => '2026-02-30T08:00' ); break;
			case 'reversed_dates': $data['settings'] = array( 'opens_at' => '2026-10-14T08:00', 'closes_at' => '2026-10-13T08:00' ); break;
			case 'negative_id': $data['id'] = -1; break;
			case 'bad_created_date': $data['created_at'] = '2026-13-01 00:00:00'; break;
			case 'object_value': $data['fields'][0]['default'] = new \stdClass(); break;
		}
		$this->expectException( \InvalidArgumentException::class );
		Form::fromArray( $data );
	}

	public function invalidSchemas(): array {
		return array_map( static fn ( string $case ): array => array( $case ), array( 'duplicate_key', 'bad_key', 'unknown_type', 'empty_label', 'empty_options', 'duplicate_options', 'invalid_default', 'malformed_json', 'scalar_json', 'unordered_fields', 'invalid_status', 'negative_capacity', 'string_boolean', 'duplicate_role', 'bad_range', 'invalid_length', 'impossible_date', 'reversed_dates', 'negative_id', 'bad_created_date', 'object_value' ) );
	}
}
