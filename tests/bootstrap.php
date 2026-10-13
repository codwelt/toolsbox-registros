<?php
/**
 * Bootstrap de pruebas sobre un WordPress de desarrollo.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
	exit;
}

$tbreg_wp_load = getenv( 'TBREG_WP_LOAD' ) ?: dirname( __DIR__, 4 ) . '/wp-load.php';
if ( ! is_file( $tbreg_wp_load ) ) {
	throw new RuntimeException( 'Configura TBREG_WP_LOAD con la ruta al wp-load.php de desarrollo.' );
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( $tbreg_wp_load ) . '/' );
}
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
define( 'WP_ADMIN', true );
define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true );
// Aísla el proceso de pruebas de los autoloaders de otros plugins y del tema.
// Estos filtros solo viven en memoria y no cambian las opciones del sitio.
foreach ( array( 'pre_option_active_plugins' => array(), 'pre_site_option_active_sitewide_plugins' => array(), 'pre_option_template' => 'tbreg-test-theme', 'pre_option_stylesheet' => 'tbreg-test-theme' ) as $tbreg_hook => $tbreg_value ) {
	$GLOBALS['wp_filter'][ $tbreg_hook ][10][] = array(
		'function' => static fn (): mixed => $tbreg_value,
		'accepted_args' => 0,
	);
}
$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/wp-admin/';
ob_start();
require_once $tbreg_wp_load;
ob_end_clean();
require_once dirname( __DIR__ ) . '/toolsbox-registros.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

/**
 * Datos de ejemplo para probar round-trips y validación.
 *
 * @return array<string,mixed>
 */
function tbreg_test_form_data(): array {
	return array(
		'title'  => 'Seminario Bogotá',
		'fields' => array(
			array( 'key' => 'f_name', 'type' => 'text', 'label' => 'Nombre', 'role' => 'name' ),
			array( 'key' => 'f_email', 'type' => 'email', 'label' => 'Correo', 'role' => 'email' ),
			array( 'key' => 'f_document', 'type' => 'document', 'label' => 'Documento', 'role' => 'document' ),
			array( 'key' => 'f_city', 'type' => 'select', 'label' => 'Ciudad', 'options' => array( array( 'value' => 'bogota', 'label' => 'Bogotá' ), array( 'value' => 'medellin', 'label' => 'Medellín' ) ) ),
			array( 'key' => 'f_choices', 'type' => 'checkbox', 'label' => 'Intereses', 'options' => array( array( 'value' => 'a', 'label' => 'Diseño' ), array( 'value' => 'b', 'label' => 'Marketing' ) ) ),
		),
	);
}
