<?php
/**
 * Plugin Name: ToolsBox Registros
 * Plugin URI:  https://codwelt.com/toolsbox-herramientas-digitales-gratis/
 * Description: Crea formularios dinámicos de inscripción para clases, eventos y seminarios, y exporta los inscritos a Excel.
 * Version:     0.3.1
 * Author:      Codwelt SAS
 * Author URI:  https://codwelt.com/
 * Text Domain: toolsbox-registros
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);

use Codwelt\ToolsBoxRegistros\Install\Installer;
use Codwelt\ToolsBoxRegistros\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TBREG_VERSION', '0.3.1' );
define( 'TBREG_FILE', __FILE__ );
define( 'TBREG_PATH', plugin_dir_path( __FILE__ ) );
define( 'TBREG_URL', plugin_dir_url( __FILE__ ) );
define( 'TBREG_BASENAME', plugin_basename( __FILE__ ) );

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'Codwelt\\ToolsBoxRegistros\\';
		if ( strncmp( $class_name, $prefix, strlen( $prefix ) ) !== 0 ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = TBREG_PATH . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( TBREG_FILE, array( Installer::class, 'activate' ) );
register_deactivation_hook( TBREG_FILE, array( Installer::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( new Plugin(), 'boot' ) );
