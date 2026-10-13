<?php
/**
 * Tipo nativo de campo.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);
namespace Codwelt\ToolsBoxRegistros\Domain\FieldTypes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Reglas y plantilla del tipo checkbox. */
final class CheckboxField extends AbstractFieldType {
	public const TYPE = 'checkbox';
}
