<?php
/** Proceso aislado de exportación con límite de memoria. @package ToolsBoxRegistros */
declare(strict_types=1);
if ( PHP_SAPI !== 'cli' || '1' !== getenv( 'TBREG_RUN_INTEGRATION' ) ) { exit( 1 ); }
require __DIR__ . '/bootstrap.php';
if ( ! defined( 'ABSPATH' ) ) { exit; }
$tbreg_prefix = $argv[1] ?? '';
$tbreg_form_id = filter_var( $argv[2] ?? '', FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
if ( ! preg_match( '/^tbregtest_[a-f0-9]{12}_$/D', $tbreg_prefix ) || false === $tbreg_form_id || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) { exit( 2 ); }
$tbreg_db = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ); $tbreg_db->set_prefix( $tbreg_prefix );
$tbreg_form = ( new Codwelt\ToolsBoxRegistros\Repository\FormRepository( $tbreg_db ) )->find( $tbreg_form_id );
if ( null === $tbreg_form ) { exit( 3 ); }
$tbreg_exporter = new Codwelt\ToolsBoxRegistros\Export\EntriesExporter( new Codwelt\ToolsBoxRegistros\Repository\EntryRepository( $tbreg_db ) );
$tbreg_results = array();
foreach ( array( 'xlsx', 'csv' ) as $tbreg_format ) {
	$tbreg_queries = $tbreg_db->num_queries; $tbreg_start = microtime( true );
	$tbreg_file = $tbreg_exporter->build( $tbreg_form, $tbreg_format );
	$tbreg_results[] = array( 'format' => $tbreg_format, 'entries' => $tbreg_file['rows'], 'seconds' => microtime( true ) - $tbreg_start, 'queries' => $tbreg_db->num_queries - $tbreg_queries, 'peak_bytes' => memory_get_peak_usage( true ), 'memory_limit' => ini_get( 'memory_limit' ), 'file_bytes' => filesize( $tbreg_file['path'] ) );
	wp_delete_file( $tbreg_file['path'] );
}
echo wp_json_encode( $tbreg_results );
