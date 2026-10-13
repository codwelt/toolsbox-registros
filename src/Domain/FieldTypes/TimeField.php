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
/** Reglas y plantilla del tipo time. */
final class TimeField extends AbstractFieldType {
	public const TYPE = 'time';
}
