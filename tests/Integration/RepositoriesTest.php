<?php
/**
 * Integración con MySQL en tablas de prueba de prefijo aleatorio.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);

namespace Codwelt\ToolsBoxRegistros\Tests\Integration;

use Codwelt\ToolsBoxRegistros\Domain\Form;
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
		foreach ( array( 'entry_values', 'entries', 'forms' ) as $suffix ) {
			self::$db->query( 'DELETE FROM `' . self::$db->prefix . 'tbreg_' . $suffix . '`' );
		}
		$this->forms = new FormRepository( self::$db );
		$this->entries = new EntryRepository( self::$db );
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
