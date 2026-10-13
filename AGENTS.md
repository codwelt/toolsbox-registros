# AGENTS.md — ToolsBox Registros

Plugin de WordPress para crear formularios dinámicos de inscripción (clases, eventos, seminarios) y exportar los inscritos a Excel.

**El plan completo, el modelo de datos y las fases están en [`docs/PLAN.md`](docs/PLAN.md). Léelo antes de empezar.**

## Cómo trabajar
- Implementa **una fase a la vez**, en orden. No avances sin cumplir los criterios de aceptación de la fase actual.
- Al terminar una fase, actualiza la tabla "Estado" (sección 8 de `docs/PLAN.md`) con notas breves de las decisiones tomadas o las desviaciones del plan.
- El plugin debe seguir activándose sin errores al final de cada fase.
- Si una decisión del plan resulta inviable, documenta el motivo en `docs/PLAN.md` antes de cambiarla.

## Convenciones obligatorias
| Elemento | Valor |
|---|---|
| Namespace PHP | `Codwelt\ToolsBoxRegistros\` → `src/` (autoloader PSR-4 propio, sin `vendor/` en runtime) |
| Prefijo de funciones, opciones, hooks y meta | `tbreg_` |
| Prefijo de constantes | `TBREG_` |
| Tablas | `{$wpdb->prefix}tbreg_forms`, `tbreg_entries`, `tbreg_entry_values` |
| Text domain | `toolsbox-registros` |
| Shortcode | `[toolsbox_registro id="…"]` |
| Bloque | `codwelt/toolsbox-registro` |
| Endpoint REST | `toolsbox-registros/v1` |
| Capacidades | `tbreg_manage_forms`, `tbreg_view_entries`, `tbreg_edit_entries`, `tbreg_export_entries` |
| PHP / WP mínimos | 8.0 / 6.0 |

- `declare(strict_types=1);` y `if (! defined('ABSPATH')) exit;` en cada archivo PHP.
- Estándar de código: WordPress Coding Standards (`phpcs.xml.dist`). Tipado estricto en firmas.
- Seguridad: nonce + `current_user_can()` en cada acción admin; `$wpdb->prepare()` en toda consulta; sanitizar entradas por tipo de campo; escapar toda salida (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`).
- Toda cadena visible es traducible y está escrita en español.
- JS sin paso de build: vanilla JS + dependencias incluidas en WP (`jquery-ui-sortable`, `wp-blocks`, `wp-element`, `wp-components`, `wp-server-side-render`).
- No añadir dependencias de runtime vía Composer (la exportación XLSX usa un escritor propio sobre `ZipArchive`).
- Los `key` de los campos son inmutables una vez creados.

## Verificación
```bash
php -l <archivo>
vendor/bin/phpcs
TBREG_RUN_INTEGRATION=1 vendor/bin/phpunit   # sin la variable, las pruebas de integración se saltan
```
Reporta siempre el resultado **con** las pruebas de integración activas.
También se puede usar el plugin **Plugin Check** instalado en este WordPress (`wp plugin check toolsbox-registros`).
