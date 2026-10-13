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
/** Reglas y plantilla del tipo document. */
final class DocumentField extends AbstractFieldType {
	public const TYPE = 'document';
}
