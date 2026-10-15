<?php
/** Ausencia de Elementor sin dependencia fatal ni assets propios en otras páginas. @package ToolsBoxRegistros */
declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Tests\Unit;
use Codwelt\ToolsBoxRegistros\Plugin;
use Codwelt\ToolsBoxRegistros\Elementor\Integration;
use PHPUnit\Framework\TestCase;
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ElementorOptionalTest extends TestCase {
	public function test_boot_does_not_load_elementor_widget_or_enqueue_its_assets(): void {
		$this->assertFalse( class_exists( '\Elementor\Widget_Base', false ) );
		( new Plugin() )->boot();
		$integration = new Integration(); $integration->register();
		$this->assertNotFalse( has_action( 'elementor/widgets/register', array( $integration, 'register_widget' ) ) );
		$this->assertFalse( class_exists( '\Codwelt\ToolsBoxRegistros\Elementor\FormWidget', false ) );
		$this->assertFalse( wp_script_is( 'tbreg-elementor', 'enqueued' ) );
		remove_action( 'elementor/widgets/register', array( $integration, 'register_widget' ) );
	}
}
