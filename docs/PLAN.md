# ToolsBox Registros — Plan de implementación

> Documento de trabajo para la construcción del plugin por fases (agente: Codex).
> Leer junto con [`../AGENTS.md`](../AGENTS.md), que define las convenciones obligatorias.

- **Plugin:** ToolsBox Registros
- **Ruta:** `wp-content/plugins/toolsbox-registros`
- **Plugin URI:** https://codwelt.com/toolsbox-herramientas-digitales-gratis/
- **Autor:** Codwelt SAS — https://codwelt.com/
- **Requisitos:** WordPress ≥ 6.0 (probado en 7.1), PHP ≥ 8.0
- **Licencia:** GPLv2 o posterior (el `LICENSE` actual es MIT, que es compatible; en la cabecera del plugin y en `readme.txt` se declara GPLv2+ para cumplir con WordPress.org)

---

## 1. Objetivo

Plugin que permite crear **formularios dinámicos de inscripción** desde el panel de administración para clases, eventos, seminarios, talleres, webinars, etc. Debe permitir:

1. Crear N formularios, cada uno con sus propios campos.
2. Agregar, ordenar, editar y eliminar campos según la necesidad, y configurar los **valores posibles** de los campos de selección.
3. Publicar el formulario en cualquier página (shortcode y bloque de Gutenberg).
4. Guardar las inscripciones y consultarlas desde el admin.
5. **Descargar en Excel (.xlsx)** el listado de inscritos **por formulario** (con CSV como alternativa).

### Fuera de alcance (v1)
Pagos, lógica condicional entre campos, subida de archivos, check-in con QR, integración con CRM/Mailchimp. Quedan como backlog (sección 9).

---

## 2. Decisiones de arquitectura

| Tema | Decisión | Motivo |
|---|---|---|
| Dependencias | **Ninguna vía Composer en runtime.** Autoloader PSR-4 propio, igual que `toolsbox-por-codwelt`. | Instalación por zip sin `vendor/`, lista para WordPress.org. |
| Exportación Excel | **Escritor XLSX nativo** (`ZipArchive` + SpreadsheetML mínimo) escrito en el plugin. Respaldo en **CSV UTF-8 con BOM** si `ZipArchive` no existe. | PhpSpreadsheet pesa ~10 MB y es excesivo para una sola hoja de datos. |
| Constructor de formularios (UI) | **JS vanilla + `jquery-ui-sortable`** (incluido en el núcleo de WP). Sin paso de build. | Codex puede generarlo y probarlo sin toolchain Node. El bloque de Gutenberg se registra con `block.json` + un script sin build (`wp.blocks`, `wp.element`, `wp.serverSideRender`). |
| Almacenamiento de formularios | Tabla propia `tbreg_forms`; la definición de campos se guarda como **JSON** en la columna `fields`. | El constructor guarda el esquema completo de forma atómica; simple de versionar. |
| Almacenamiento de inscripciones | Tablas propias `tbreg_entries` + `tbreg_entry_values` (una fila por campo). | Permite filtrar, buscar duplicados (email/documento) y exportar en bloques sin cargar todo en memoria. |
| Integridad histórica | Cada campo tiene un `key` **inmutable**. Los campos eliminados con inscripciones previas se marcan `archived: true` (no se borran) para que el export siga mostrando esas columnas. | Editar el formulario después de abrir inscripciones no debe corromper los datos. |
| Envío del formulario | POST a `admin-post.php` (funciona sin JS) + mejora progresiva con `fetch` hacia un endpoint REST `toolsbox-registros/v1/forms/{id}/submit`. | Accesible y compatible con caché de página. |
| Seguridad | Nonces, capacidades propias, `$wpdb->prepare` siempre, sanitización por tipo de campo, escape en salida, honeypot + límite de envíos por IP. Turnstile/reCAPTCHA opcional. | — |

---

## 3. Estructura de archivos objetivo

```
toolsbox-registros/
├── toolsbox-registros.php          # Cabecera, constantes, autoloader, bootstrap
├── uninstall.php                   # Borra tablas/opciones solo si el usuario lo eligió
├── readme.txt                      # Formato WordPress.org
├── LICENSE
├── AGENTS.md                       # Convenciones para el agente
├── composer.json                   # Solo dev (phpunit, phpcs/WPCS); sin dependencias de runtime
├── composer.lock                   # Versiones reproducibles de herramientas de desarrollo
├── phpcs.xml.dist
├── phpunit.xml.dist
├── .gitignore                      # Excluye vendor/ y caché local de PHPUnit
├── docs/
│   └── PLAN.md                     # Este documento
├── languages/
│   └── toolsbox-registros.pot
├── assets/
│   ├── admin/
│   │   ├── css/builder.css
│   │   ├── css/admin.css
│   │   └── js/builder.js
│   └── public/
│       ├── css/form.css
│       └── js/form.js
├── blocks/
│   └── registro-form/
│       ├── block.json
│       └── index.js
├── templates/                      # Sobrescribibles desde el tema: /toolsbox-registros/*.php
│   ├── form.php
│   ├── fields/{text,email,tel,number,textarea,select,radio,checkbox,date,time,url,consent,heading,hidden}.php
│   └── messages/{closed,full,success}.php
├── src/
│   ├── Plugin.php                  # Orquesta y registra los servicios (hooks)
│   ├── helpers.php
│   ├── Install/
│   │   ├── Installer.php           # Activación, dbDelta, capacidades
│   │   └── Migrations.php          # Versionado de esquema (opción tbreg_db_version)
│   ├── Domain/
│   │   ├── Form.php                # Entidad (value object) del formulario
│   │   ├── Field.php               # Entidad de campo
│   │   ├── FieldTypes/             # Una clase por tipo: sanitize(), validate(), render(), export()
│   │   │   ├── FieldTypeInterface.php
│   │   │   ├── AbstractFieldType.php
│   │   │   ├── TextField.php … ConsentField.php
│   │   └── FieldTypeRegistry.php   # Registro de tipos, extensible vía filtro
│   ├── Repository/
│   │   ├── Repository.php          # Consultas preparadas y transacciones compartidas
│   │   ├── FormRepository.php
│   │   └── EntryRepository.php
│   ├── Admin/
│   │   ├── Menu.php
│   │   ├── FormsListTable.php      # WP_List_Table
│   │   ├── FormEditorPage.php      # Pantalla del constructor
│   │   ├── EntriesListTable.php
│   │   ├── EntryDetailPage.php
│   │   ├── SettingsPage.php
│   │   └── Notices.php
│   ├── Frontend/
│   │   ├── Shortcode.php
│   │   ├── Block.php
│   │   ├── FormRenderer.php
│   │   └── SubmissionHandler.php   # admin-post + REST
│   ├── Rest/
│   │   └── SubmitController.php
│   ├── Services/
│   │   ├── Validator.php
│   │   ├── RegistrationRules.php   # Cupos, fechas, duplicados
│   │   ├── Mailer.php              # Notificaciones admin/inscrito
│   │   ├── SpamGuard.php           # Honeypot, rate-limit, captcha
│   │   └── Privacy.php             # Exportador/borrador de datos personales de WP
│   └── Export/
│       ├── ExporterInterface.php
│       ├── XlsxWriter.php
│       ├── CsvWriter.php
│       └── EntriesExporter.php
└── tests/
    ├── bootstrap.php
    ├── Unit/
    └── Integration/
```

---

## 4. Modelo de datos

Prefijo de tablas: `{$wpdb->prefix}tbreg_`. Se crean con `dbDelta()` en la activación y en `plugins_loaded` si `tbreg_db_version` es menor que la versión del código.

### 4.1 `tbreg_forms`
| Columna | Tipo | Notas |
|---|---|---|
| id | BIGINT UNSIGNED PK AI | |
| title | VARCHAR(200) | Nombre interno/visible |
| slug | VARCHAR(200) UNIQUE | |
| description | TEXT | Texto introductorio opcional |
| status | VARCHAR(20) | `draft` \| `published` \| `closed` \| `trash` |
| fields | LONGTEXT | JSON con la lista ordenada de campos (ver 4.4) |
| settings | LONGTEXT | JSON con la configuración (ver 4.5) |
| entries_count | INT UNSIGNED DEFAULT 0 | Cache para cupos y listados |
| created_by | BIGINT UNSIGNED | |
| created_at / updated_at | DATETIME (UTC) | |

### 4.2 `tbreg_entries`
| Columna | Tipo | Notas |
|---|---|---|
| id | BIGINT UNSIGNED PK AI | |
| form_id | BIGINT UNSIGNED, INDEX | |
| status | VARCHAR(20), INDEX | `pending` \| `confirmed` \| `attended` \| `cancelled` \| `waitlist` |
| user_id | BIGINT UNSIGNED NULL | Si el inscrito estaba logueado |
| email | VARCHAR(190) NULL, INDEX(form_id, email) | Copia desnormalizada del campo marcado como "email principal" |
| document | VARCHAR(60) NULL, INDEX(form_id, document) | Copia del campo marcado como "documento" (para evitar duplicados) |
| ip_hash | CHAR(64) | `hash_hmac('sha256', ip, wp_salt())`; no se guarda la IP en claro |
| user_agent | VARCHAR(255) | |
| source_url | VARCHAR(255) | Página donde se envió |
| created_at / updated_at | DATETIME (UTC) | |

### 4.3 `tbreg_entry_values`
| Columna | Tipo | Notas |
|---|---|---|
| id | BIGINT UNSIGNED PK AI | |
| entry_id | BIGINT UNSIGNED, INDEX | |
| field_key | VARCHAR(64) | `key` inmutable del campo |
| value | LONGTEXT | Escalar o JSON (checkbox múltiple → `["a","b"]`) |

INDEX compuesto `(entry_id, field_key)`.

### 4.4 Esquema JSON de un campo
```json
{
  "key": "f_ab12cd",            // generado al crear, inmutable, ^[a-z0-9_]{3,64}$
  "type": "select",             // ver catálogo 5.2
  "label": "Ciudad",
  "placeholder": "",
  "help": "Ciudad de residencia",
  "required": true,
  "default": "",
  "width": "full",              // full | half | third
  "role": null,                 // null | "email" | "document" | "name" (para duplicados, correo de confirmación y export)
  "options": [                  // solo select / radio / checkbox
    { "value": "bogota", "label": "Bogotá" },
    { "value": "medellin", "label": "Medellín" }
  ],
  "validation": { "min": null, "max": null, "minlength": null, "maxlength": 200, "pattern": null },
  "archived": false,
  "css_class": ""
}
```

### 4.5 Esquema JSON de `settings` del formulario
```json
{
  "event": { "name": "", "starts_at": "", "ends_at": "", "location": "", "modality": "presencial|virtual|hibrido" },
  "submit_label": "Inscribirme",
  "success_message": "¡Gracias! Tu inscripción fue registrada.",
  "redirect_url": "",
  "opens_at": "", "closes_at": "",            // ventana de inscripción (zona horaria del sitio)
  "capacity": 0,                             // 0 = ilimitado
  "waitlist": false,                         // al llenarse, registrar como 'waitlist' en vez de cerrar
  "prevent_duplicates": "none|email|document",
  "default_status": "confirmed",
  "require_login": false,
  "notify_admin": { "enabled": true, "to": "", "subject": "" },
  "confirm_attendee": { "enabled": true, "subject": "", "body": "" },  // con merge tags {field:key}, {event_name}, {entry_id}
  "consent_text": "",                        // texto de tratamiento de datos (Ley 1581 de 2012 / GDPR)
  "captcha": "none|turnstile|recaptcha"
}
```

---

## 5. Funcionalidades

### 5.1 Menú de administración
Menú principal **"ToolsBox Registros"** (icono `dashicons-clipboard`):
- **Formularios**: listado, crear, duplicar, cerrar, papelera.
- **Inscripciones**: listado global con filtro por formulario, estado y rango de fechas; botón de exportar.
- **Ajustes**: remitente de correos, captcha (claves), retención de datos, "borrar datos al desinstalar".
- Enlace "Acerca de / Más herramientas" → https://codwelt.com/toolsbox-herramientas-digitales-gratis/

### 5.2 Catálogo de tipos de campo (v1)
`text`, `email`, `tel`, `number`, `textarea`, `select`, `radio`, `checkbox` (uno o varios), `date`, `time`, `url`, `document` (texto con validación alfanumérica para cédula/NIT/pasaporte), `consent` (checkbox obligatorio con texto legal), `heading` (título/separador, no guarda datos), `hidden` (valor fijo o desde parámetro GET, útil para UTM).

Cada tipo implementa `FieldTypeInterface`:
```php
interface FieldTypeInterface {
    public function type(): string;
    public function label(): string;
    public function supportsOptions(): bool;
    public function storesValue(): bool;
    public function sanitize(mixed $raw, Field $field): mixed;
    public function validate(mixed $value, Field $field): ?\WP_Error;
    public function render(Field $field, mixed $value): string;   // usa templates/fields/{type}.php
    public function toExport(mixed $stored, Field $field): string; // valor legible para Excel (labels, no values)
}
```
Los tipos se pueden extender con el filtro `tbreg_field_types`.

### 5.3 Shortcode y bloque
- `[toolsbox_registro id="12"]` (también acepta `slug="..."`).
- Bloque `codwelt/toolsbox-registro` con un selector de formulario en el inspector y vista previa vía `ServerSideRender`.

### 5.4 Reglas de inscripción
Antes de guardar, `RegistrationRules` evalúa en este orden: formulario publicado → ventana abierta → login requerido → cupo disponible (o lista de espera) → duplicado. Cada regla devuelve un `WP_Error` con un mensaje traducible. Para evitar sobrecupo por concurrencia, el chequeo y el `INSERT` usan `SELECT … FOR UPDATE` sobre la fila del formulario dentro de una transacción (si el motor es InnoDB; si no, un lock con `GET_LOCK`).

### 5.5 Exportación
- Desde **Inscripciones** (filtrando por formulario) y desde la acción de fila "Exportar" en **Formularios**.
- Formatos: **XLSX** (por defecto) y **CSV**.
- Columnas: `ID`, `Fecha de inscripción` (zona horaria del sitio), `Estado`, y luego un campo por columna en el orden del formulario, incluidos los campos archivados (con el sufijo "(eliminado)"). Los campos `heading` se omiten. Los checkbox múltiples se unen con `, `. Las opciones se exportan con su **label**.
- Filtros aplicables: estado y rango de fechas.
- Nombre de archivo: `inscritos-{slug}-{Y-m-d_His}.xlsx`.
- Proceso en bloques de 500 inscripciones, escribiendo el XML de la hoja en un archivo temporal (`wp_tempnam`) para no agotar la memoria con miles de filas.
- Seguridad: capacidad `tbreg_export_entries` + nonce. En CSV, las celdas que empiezan con `= + - @ \t \r` se prefijan con `'` (inyección de fórmulas). En XLSX todo se escribe como `inlineStr`, salvo ID y números.

### 5.6 Capacidades
Se asignan a `administrator` al activar (y se pueden dar a `editor` con un filtro):
`tbreg_manage_forms`, `tbreg_view_entries`, `tbreg_edit_entries`, `tbreg_export_entries`.

### 5.7 Hooks públicos (extensibilidad)
- Acciones: `tbreg_before_submit`, `tbreg_entry_created( $entry_id, $form, $values )`, `tbreg_entry_status_changed`, `tbreg_form_saved`.
- Filtros: `tbreg_field_types`, `tbreg_validate_submission`, `tbreg_export_columns`, `tbreg_export_row`, `tbreg_mail_admin_args`, `tbreg_mail_attendee_args`, `tbreg_template_path`.

---

## 6. Plan de acción por fases

> Regla general: **cada fase deja el plugin activable y sin errores fatales**, con sus criterios de aceptación verificados antes de pasar a la siguiente. Al final de cada fase, actualizar la sección "Estado" (sección 8).

### Fase 0 — Andamiaje y convenciones
**Objetivo:** plugin vacío que se activa y se desactiva sin errores.

Tareas:
1. Crear `toolsbox-registros.php` con la cabecera:
   ```
   Plugin Name: ToolsBox Registros
   Plugin URI:  https://codwelt.com/toolsbox-herramientas-digitales-gratis/
   Description: Crea formularios dinámicos de inscripción para clases, eventos y seminarios, y exporta los inscritos a Excel.
   Version:     0.1.0
   Author:      Codwelt SAS
   Author URI:  https://codwelt.com/
   Text Domain: toolsbox-registros
   Domain Path: /languages
   Requires at least: 6.0
   Requires PHP: 8.0
   License:     GPLv2 or later
   ```
2. Constantes `TBREG_VERSION`, `TBREG_FILE`, `TBREG_PATH`, `TBREG_URL`, `TBREG_BASENAME`.
3. Autoloader PSR-4 para `Codwelt\ToolsBoxRegistros\` → `src/` (mismo patrón que `toolsbox-por-codwelt`).
4. `src/Plugin.php` con un `boot()` que registra los servicios en `plugins_loaded`.
5. `register_activation_hook` / `register_deactivation_hook` apuntando a `Install\Installer`.
6. `composer.json` (solo `require-dev`: `phpunit/phpunit`, `yoast/phpunit-polyfills`, `wp-coding-standards/wpcs`, `phpcompatibility/phpcompatibility-wp`) y `phpcs.xml.dist`.
7. `readme.txt` inicial y `languages/` vacío.

Criterios de aceptación:
- [x] El plugin aparece en "Plugins" con los datos correctos y se activa sin warnings con `WP_DEBUG` activo.
- [x] `php -l` pasa en todos los archivos.

Verificación de la fase 0 (2026-10-13):
- Cabecera, constantes, autoloader propio, `Plugin::boot()`, hooks de `Installer`, configuración de desarrollo, `readme.txt` y directorio `languages/` implementados.
- `composer validate --strict --no-check-lock` correcto; las dependencias son exclusivamente de desarrollo, con plataforma PHP 8.0.30 y PHPUnit 9.6.
- Los tres archivos PHP pasan `php -l`. WordPress Coding Standards pasa sin errores ni warnings usando el PHPCS incluido en Plugin Check. La configuración exceptúa la regla de nombres de archivo para mantener los nombres PSR-4 exigidos por el plan.
- Una prueba temporal con las APIs reales del WordPress 7.1 del proyecto y PHP 8.2.29 verificó metadatos, carga sin salida, constantes, autoload de clases anidadas, namespaces ajenos y clases inexistentes, hooks de activación/desactivación (con argumento de red falso y verdadero), arranque idempotente y traducciones registradas en `init`, con `WP_DEBUG` y `E_ALL` sin warnings.
- Se instalaron las dependencias de desarrollo al iniciar la fase 1. El ruleset completo con `PHPCompatibilityWP` pasa sin errores ni warnings.
- El fallo inicial de conexión a la base de datos quedó resuelto en el entorno local. Antes de implementar la fase 1, se verificaron `get_plugins()`, `activate_plugin()` y `deactivate_plugins()` en WordPress 7.1 con `WP_DEBUG=true`: listado correcto, activación, desactivación y reactivación sin warnings del plugin. Se restauró la lista original de plugins activos.
- Se conserva el `LICENSE` MIT existente y las declaraciones GPLv2+ previstas por el plan; la coherencia de los archivos de licencia debe resolverse antes del empaquetado.

### Fase 1 — Capa de datos
**Objetivo:** tablas, entidades y repositorios.

Tareas:
1. `Installer::activate()`: `dbDelta` de las 3 tablas (sección 4), guardar `tbreg_db_version`, añadir las capacidades.
2. `Migrations::maybe_upgrade()` en `plugins_loaded`.
3. Entidades `Form` y `Field` con `fromArray()`/`toArray()` y validación del esquema JSON (keys únicas, tipos válidos y opciones no vacías en select/radio/checkbox).
4. `FormRepository`: `find`, `findBySlug`, `all(args)`, `save`, `duplicate`, `trash`, `delete`, `incrementCount`.
5. `EntryRepository`: `create(form, values, meta)` en transacción, `find`, `paginate(filters)`, `values(entry_id)`, `existsByEmail`, `existsByDocument`, `updateStatus`, `delete`, `iterateForExport(form_id, filters, chunk)` (generator).
6. Todas las consultas con `$wpdb->prepare`.

Criterios de aceptación:
- [x] Al activar se crean las 3 tablas; desactivar y reactivar no duplica ni rompe nada.
- [x] Tests unitarios de `Form::fromArray` (esquema válido e inválido) y de integración de los repositorios pasan.

Verificación de la fase 1 (2026-10-13):
- `Installer` crea las tres tablas con `dbDelta`, motor InnoDB e índices por formulario, correo, documento y fecha. `(entry_id, field_key)` es único para evitar valores repetidos. Comprueba motor y columnas antes de guardar `tbreg_db_version = 1.0.0`.
- Las cuatro capacidades se asignan a `administrator`; el filtro `tbreg_capability_roles` permite incluir otros roles. La activación en red recorre los sitios en bloques y restaura el sitio actual. Esta rama multisitio todavía no se ha probado en una red real.
- `Migrations::maybe_upgrade()` se ejecuta durante el arranque en `plugins_loaded`, actualiza instalaciones pendientes y evita consultas de esquema si la versión ya está actualizada.
- `Form` y `Field` validan JSON, claves, catálogo de tipos, opciones, roles principales, estados y ajustes. Los datos se normalizan y se exponen mediante copias; los slugs Unicode generados respetan el límite de la columna sin cortar caracteres.
- Los repositorios implementan las operaciones previstas. Guardar un formulario conserva creador, fechas y contador; archiva campos retirados con datos y rechaza cambios de tipo. Duplicar crea un borrador sin inscripciones.
- Crear, cambiar estado y eliminar una inscripción bloquea primero la fila del formulario y actualiza valores y contador dentro de una transacción. `pending`, `confirmed` y `attended` ocupan cupo; `cancelled` y `waitlist` no. La integridad de cupos y duplicados ya se verifica sobre la fila actual, aunque la validación pública, las fechas y el acceso siguen siendo responsabilidad de la fase 3.
- Los filtros de fecha de los repositorios reciben UTC; las pantallas deberán convertir las fechas del sitio antes de consultarlos. La exportación usa paginación por ID y un ID máximo fijado al comenzar, con una consulta agrupada de valores por bloque.
- `Repository.php` centraliza consultas preparadas y transacciones. Los valores variables se enlazan con `prepare()` o con `wpdb::insert()`, que lo usa internamente. DDL, `SHOW` de identificadores internos y comandos estáticos de transacción no reciben valores del usuario y no usan `prepare()` sin placeholders, porque WordPress emite un warning. Se evitan `%i` para conservar compatibilidad con WordPress 6.0.
- El ruleset conserva los métodos camelCase definidos por el plan y exceptúa únicamente los avisos de escape de excepciones en dominio/repositorios: se convierten en `WP_Error` y deberán escaparse al renderizar. El escape de salida de las pantallas sigue siendo obligatorio.
- **39 tests / 188 assertions**, incluyendo esquemas manipulados, todos los tipos v1, CRUD, conservación histórica, duplicados, metadatos sin IP en claro, cupos, estados, rollback tras fallar la segunda fila de valores, hooks después del commit, paginación y exportación por bloques. Sintaxis PHP y PHPCS con WPCS/PHPCompatibilityWP correctos.
- Verificación real de activación y migración en WordPress 7.1, PHP 8.2.29 y MySQL 9.0.1, con `WP_DEBUG=true` y sin warnings del plugin. Un formulario temporal y su inscripción sobrevivieron a la reactivación y a una migración pendiente; después se eliminaron. Se restauró el estado original de activación.
- Los tests de integración crean tablas `tbregtest_{aleatorio}_tbreg_*` y las eliminan al terminar, sin vaciar las tablas del plugin. El bootstrap excluye en memoria otros plugins y el tema para evitar conflictos de autoloaders, sin modificar opciones. El fallback de bloqueo para motores no transaccionales sigue pendiente de evaluar en la fase 3; las tablas instaladas y verificadas en esta fase son InnoDB.

Comandos reproducibles desde la carpeta del plugin:
```bash
composer install
vendor/bin/phpcs
TBREG_RUN_INTEGRATION=1 vendor/bin/phpunit --testsuite Unit
TBREG_RUN_INTEGRATION=1 vendor/bin/phpunit
```
`TBREG_WP_LOAD` permite indicar otro `wp-load.php`. Las pruebas de integración requieren un entorno WordPress `local` o `development` y una conexión MySQL con permisos para crear y borrar las tablas temporales.

### Fase 2 — Constructor de formularios (admin)
**Objetivo:** crear y editar formularios con campos dinámicos y sus opciones.

Tareas:
1. `Admin\Menu` con los submenús de 5.1.
2. `FormsListTable` (columnas: título, shortcode con botón copiar, estado, inscritos/cupo, fechas, acciones: editar, duplicar, ver inscritos, exportar, papelera).
3. `FormEditorPage` en dos columnas:
   - **Izquierda:** paleta de tipos de campo (botones "+ Texto", "+ Selección", …).
   - **Centro:** lista ordenable (drag & drop con `jquery-ui-sortable`) de tarjetas de campo, colapsables. Cada tarjeta permite editar label, placeholder, ayuda, obligatorio, ancho, rol (email/documento/nombre), validaciones y, en los tipos con opciones, un **repetidor de opciones** (label + value autogenerado con `sanitize_title`, editable, ordenable, con botón de eliminar).
   - **Pestañas superiores:** "Campos", "Configuración" (evento, cupos, fechas, duplicados, mensajes, redirección), "Notificaciones" y "Publicar" (shortcode y estado).
4. `builder.js` mantiene el estado en memoria y lo serializa como JSON en un `<input type="hidden" name="tbreg_fields">` al guardar. Validación en cliente (labels obligatorios, opciones únicas) y de nuevo en el servidor.
5. Guardado vía `admin-post.php?action=tbreg_save_form` con nonce y capacidad `tbreg_manage_forms`.
6. Si el formulario ya tiene inscripciones: al eliminar un campo se archiva (`archived: true`) con un aviso; no se permite cambiar el `type` de un campo con datos (solo label/opciones). Cambiar el `value` de una opción con datos muestra una advertencia.
7. Botón **"Plantillas rápidas"**: crea un formulario precargado (Evento básico: nombre, email, teléfono, documento, ciudad, consentimiento; Clase/curso; Webinar).

Criterios de aceptación:
- [x] Se puede crear un formulario con todos los tipos de campo, reordenarlos, configurar opciones y guardar; al recargar se conserva todo.
- [x] Un esquema manipulado a mano (tipo inexistente, key duplicada) es rechazado en el servidor con un aviso.
- [x] Usuarios sin `tbreg_manage_forms` no acceden.

Verificación de la fase 2 (2026-10-13):
- Menú ToolsBox Registros, listado `WP_List_Table` con búsqueda, filtro de estado y paginación; shortcode copiable, duplicación, cierre, papelera y restauración como borrador. Las acciones de fila verifican capacidad y nonce específico del formulario y la operación.
- Constructor con los 15 tipos, tarjetas colapsables, ordenación de campos y opciones con `jquery-ui-sortable`, controles para ordenar con teclado, etiquetas, valores, ayuda, ancho, rol y validación. Pestañas Campos, Configuración, Notificaciones y Publicar; plantillas Evento básico, Clase/curso y Webinar. JS vanilla sin compilación; assets solo en pantallas propias.
- El servidor aplica `sanitize_title()` a valores autogenerados de opciones nuevas y ajusta sus defaults. Los valores existentes o editados manualmente permanecen estables al cambiar la etiqueta. El guardado POST valida nonce, capacidad, JSON, tipos y ajustes, fechas del evento y destinatarios. Los avisos son transients por usuario, escapados y de un solo uso.
- Los campos con valores tienen el tipo bloqueado y se archivan al retirarlos. El servidor conserva el historial incluso si se manipula el JSON. Se advierte al editar opciones con datos. Una revisión del contenido editable evita sobrescribir cambios de otro administrador; las nuevas inscripciones no invalidan esa revisión.
- **`TBREG_RUN_INTEGRATION=1 vendor/bin/phpunit`: 50 tests / 311 assertions**, sin saltos. Incluye todos los tipos y el orden de sus opciones, configuración, claves/tipos/JSON manipulados, autorización, nonces, revisiones, archivo histórico, acciones de fila, plantillas, paginación, HTML escapado, avisos, carga de assets y el handler admin-post (método, nonce, deslasheado y redirección).
- **8 pruebas DOM de JavaScript** con jsdom de desarrollo y los archivos reales de jQuery UI incluidos en WordPress: creación de los 15 tipos, ordenación, serialización/recarga, valores automáticos y manuales, teclado, archivo histórico, plantillas, opciones repetidas, foco y contenido HTML tratado como texto. El JSON producido por estas pruebas también pasó por el guardado PHP y MySQL y se recargó conservando tipos, orden y opciones.
- Sintaxis correcta en 17 archivos PHP; PHPCS con WPCS/PHPCompatibilityWP sin errores ni warnings. El ruleset declara las cuatro capacidades ya registradas por el instalador.
- Activación, desactivación, reactivación, arranque, render del editor/listado y guardado verificados con APIs reales en WordPress 7.1, PHP 8.2.29 y MySQL 9.0.1, con `WP_DEBUG=true`, sin warnings del plugin y conservando datos. Se eliminaron los registros temporales y se restauró la lista de plugins activos.
- La revisión visual en navegador queda pendiente: Computer Use no dispone de navegador en este entorno. Las pruebas DOM verifican comportamiento y estructura; no sustituyen la comprobación visual del arrastre, el foco y el diseño responsive en un navegador real.
- Las rutas de Inscripciones, Exportar y Ajustes globales muestran mensajes de disponibilidad futura. Se pueden guardar los ajustes de notificaciones y obtener el shortcode, pero el envío, render público y exportación se implementarán en sus fases correspondientes. Se mantienen las notas UTC y autoload de la revisión de fase 1; el instalador no se ha modificado.

Pruebas reproducibles del constructor (solo desarrollo; jsdom no es dependencia del plugin):
```bash
mkdir -p /tmp/tbreg-editor-fixtures
TBREG_RUN_INTEGRATION=1 TBREG_EDITOR_FIXTURE_DIR=/tmp/tbreg-editor-fixtures vendor/bin/phpunit
# Usa una instalación de jsdom de desarrollo existente o instálalo fuera del plugin:
npm install --prefix /tmp/tbreg-js-tests --no-save jsdom@24.1.3
TBREG_RUN_INTEGRATION=1 TBREG_EDITOR_FIXTURE_DIR=/tmp/tbreg-editor-fixtures TBREG_JSDOM_PATH=/tmp/tbreg-js-tests/node_modules/jsdom node --test tests/JavaScript/builder.test.cjs
TBREG_RUN_INTEGRATION=1 TBREG_EDITOR_DOM_FIELDS=/tmp/tbreg-editor-fixtures/dom-fields.json vendor/bin/phpunit
```

#### Correcciones de la revisión visual (Fase 2) — resueltas antes del cierre de la Fase 3

Resueltas el 2026-10-13: controles según tipo con limpieza en cliente/servidor, predeterminados mediante selector o casillas que siguen el catálogo, límites nativos de fecha/hora, paleta y pestañas sin desbordes, acciones compactas y separación del predeterminado. Se añadieron dos pruebas DOM y pruebas PHP de normalización/límites. Se repitió la revisión en Chrome a **1300 px y 375 px** con el HTML del editor generado por PHP y los estilos de WordPress: `scrollWidth === innerWidth` en ambos tamaños. Capturas: [escritorio](qa/fase-3/constructor-1300.jpg) y [móvil](qa/fase-3/constructor-375.jpg). Las observaciones originales se conservan debajo.
Revisión hecha con capturas en Chrome headless del editor real (estilos del admin de WP + `builder.js`), a 1300 px y 375 px.

1. **Ajustes según el tipo de campo.** Hoy todas las tarjetas muestran los mismos controles. Mostrar solo los que aplican:

   | Tipo | Placeholder | Mín./Máx. | Long. mín./máx. | Patrón | Obligatorio | Valor predeterminado | Rol |
   |---|---|---|---|---|---|---|---|
   | text, tel, document, textarea | ✔ | — | ✔ | ✔ (no en textarea) | ✔ | texto | name/document según tipo |
   | email, url | ✔ | — | ✔ | — | ✔ | texto | email solo en `email` |
   | number | ✔ | ✔ | — | — | ✔ | número | — |
   | date, time | — | ✔ (como fecha/hora) | — | — | ✔ | fecha/hora | — |
   | select, radio | — | — | — | — | ✔ | **`<select>` con las opciones** | — |
   | checkbox | — | — | — | — | ✔ | **casillas con las opciones** | — |
   | consent | — | — | — | — | siempre ✔ (deshabilitado) | — | — |
   | heading | — | — | — | — | — | — | — (solo etiqueta, ayuda y clases CSS) |
   | hidden | — | — | — | — | — | texto o `{get:param}` | — |

   Al cambiar el tipo, limpiar los valores que ya no aplican antes de serializar. El servidor (`Field::fromArray`) debe ignorar o rechazar esas claves para el tipo correspondiente.
2. **Valor predeterminado de listas.** En select, radio y checkbox hoy se escribe el *value* a mano (en checkbox, separado por comas). Reemplazarlo por un selector que se alimente de las opciones actuales y se actualice al agregar, renombrar o eliminar opciones.
3. **Paleta en móvil (≤ 600 px).** Los textos largos ("+ Casillas de selección", "+ Correo electrónico", "+ Título o separador") se salen del borde del botón. Permitir el salto de línea (`white-space: normal; height: auto; line-height: 1.3; padding-block: 6px`) o pasar a una sola columna por debajo de 400 px.
4. **Pestañas en móvil.** A 375 px las pestañas desbordan unos 5 px ("Publicar" queda cortada). Usar `flex-wrap: wrap` u `overflow-x: auto` en `.tbreg-tabs`.
5. **Botones Subir/Bajar/Eliminar de las opciones.** En escritorio ocupan una fila completa bajo cada opción. Ponerlos en línea a la derecha de "Valor guardado" (botones de icono con `aria-label`) para que la lista de opciones sea compacta.
6. **"Agregar opción" y "Valor predeterminado"** quedan pegados; añadir separación (`margin-top: 16px`).

Criterio de aceptación: repetir las capturas a 1300 px y 375 px sin desbordes horizontales, y agregar pruebas DOM de los puntos 1 y 2.

### Fase 3 — Render público y envío
**Objetivo:** el formulario funciona en el frontend y guarda inscripciones.

Decisiones de implementación de fase 3:
- El instalador exige InnoDB para las tres tablas. Si se cambia su motor externamente, el envío público se bloqueará: `GET_LOCK` puede serializar cupos en MyISAM, pero no permite revertir atómicamente una inscripción y sus valores si falla una escritura. Se conserva la garantía de rollback en lugar de habilitar ese respaldo parcial.
- `FieldTypeInterface::render()` admite un tercer contexto opcional para los IDs únicos, los errores accesibles y el texto de consentimiento; las llamadas con dos argumentos siguen funcionando. `hidden` añade `query_param` opcional al esquema para mapear explícitamente UTM u otro parámetro GET, sin modificar la clave inmutable del campo.
- Los transients del límite de intentos y los resultados PRG usan el backend de opciones de WordPress, incluso si hay caché externa: un drop-in residual de LiteSpeed en este entorno trataba el frontend como caché externa sin persistencia y el admin como almacenamiento en BD. Se normaliza solo el almacenamiento del plugin, sin modificar el drop-in ni la configuración del sitio; las opciones son temporales y no se precargan. Se limpian al consumirlas o mediante el mantenimiento diario `wp_scheduled_delete`, también con caché externa, sin crear otra tarea cron.

Tareas:
1. `FormRenderer` + `templates/` (sobrescribibles desde `{tema}/toolsbox-registros/`). HTML semántico y accesible: `<label for>`, `aria-required`, `aria-describedby` para la ayuda y los errores, grid responsive según `width`.
2. `Shortcode` y `Block` (registrado con `block.json`, editor sin build).
3. Assets públicos cargados **solo** en páginas donde se renderiza el formulario.
4. `SubmissionHandler`:
   - Verifica el nonce (usando un token propio con un TTL de 12 h si hay caché de página: campo `tbreg_ts` firmado con HMAC en lugar de depender solo del nonce).
   - `SpamGuard`: honeypot, tiempo mínimo de llenado (3 s), rate limit por `ip_hash` (5 envíos / 10 min con transients) y captcha opcional.
   - Sanitiza y valida campo por campo con su `FieldType`; ignora claves no definidas en el esquema y los campos archivados.
   - Aplica `RegistrationRules` (5.4).
   - Guarda con `EntryRepository::create` y dispara `tbreg_entry_created`.
   - Sin JS: redirección PRG con mensaje de éxito o con errores conservando los valores (transient de un solo uso).
   - Con JS: `form.js` envía por `fetch` al endpoint REST, muestra errores inline y el mensaje de éxito sin recargar.
5. Mensajes de "inscripciones cerradas", "cupo lleno" y "aún no abre", con plantillas propias.

Criterios de aceptación:
- [x] Inscripción exitosa con y sin JavaScript.
- [x] Los campos obligatorios, el formato de email, las opciones fuera del catálogo y el min/max se rechazan en el servidor aunque se salte la validación del navegador.
- [x] Con cupo = 2, la tercera inscripción se bloquea (o entra en lista de espera si está activada).
- [x] La prevención de duplicados por email/documento funciona.
- [x] Pasa una revisión básica de accesibilidad (navegación con teclado, labels y foco al primer error).

Verificación del 2026-10-13:
- `TBREG_RUN_INTEGRATION=1`: **103 pruebas PHP / 788 aserciones**, sin saltos. Integración con MySQL real en tablas de prefijo aleatorio que se eliminan al terminar. Incluye catálogos, formatos, rangos numéricos y de fecha/hora, roles compatibles, consentimiento sin selección inicial, duplicados, cupos/lista de espera, login, borradores, fechas locales, cambios de esquema/estado entre validación y escritura, API REST y permisos.
- **18 pruebas JavaScript DOM** (10 constructor + 8 frontend), con `TBREG_RUN_INTEGRATION=1`, fixtures PHP reales y jQuery UI de WordPress. Verifican envío, arrays, errores/foco, teclado del constructor, doble envío, fallo de red, mensajes escapados e IDs/labels/referencias accesibles en varias instancias.
- Prueba HTTP real en WordPress local: POST inválido → 303 con valores privados y mensaje de un solo uso; éxito REST → 201; email duplicado → 409; éxito POST sin JS; cupo 2 rechaza la tercera inscripción → 409; listado REST anónimo → 401. Los datos temporales de prueba se eliminaron al finalizar.
- Chrome conectado: inscripción por teclado con JavaScript, validación nativa y email `a@b` aceptado por el navegador pero rechazado por el servidor, foco en el primer error y mensaje de éxito sin recarga. [Éxito](qa/fase-3/envio-exitoso.jpg), [formulario móvil](qa/fase-3/formulario-375.jpg) y [error del servidor](qa/fase-3/error-servidor-375.jpg). Sin desbordes a 375 px; constructor revisado también a 1300 px.
- WPCS/PHPCompatibility sin errores en 65 archivos; sintaxis válida en 70 archivos PHP y 3 JavaScript. Callbacks reales de activación, desactivación y reactivación ejecutados con `WP_DEBUG`: formulario y cuatro inscripciones HTTP/Chrome conservados, sin avisos del plugin. No se cambió la configuración del sitio.
- Se verifica el rechazo seguro si una tabla deja de usar InnoDB; no se habilita un respaldo MyISAM con escrituras parciales. Los contadores antispam están serializados con `GET_LOCK`. Pruebas de regresión cubren el drop-in de caché no persistente del entorno y la limpieza de temporales vencidos, conservando los vigentes y los de otros componentes, sin modificar ese drop-in.
- Turnstile/reCAPTCHA v2: configuración protegida por capacidad y nonce, secretos fuera del HTML y respuestas simuladas del proveedor (incluido hostname incorrecto). La comprobación con claves reales queda para QA de fase 8. Referencias: [Turnstile](https://developers.cloudflare.com/turnstile/get-started/server-side-validation/) y [reCAPTCHA](https://developers.google.com/recaptcha/docs/verify).
- Sin dependencias de runtime ni compilación JS; el instalador no cambió y se conservan las notas pendientes de Fase 1 sobre UTC y autoload.

### Fase 4 — Gestión de inscripciones (admin)
**Objetivo:** consultar y administrar los inscritos.

Decisiones de implementación de Fase 4:
- La acción «Exportar seleccionados» prepara una selección protegida por nonce, capacidad y formulario. La descarga XLSX/CSV se implementa en Fase 5, junto con los escritores; en esta fase la pantalla muestra la selección preparada.
- Las fechas del filtro son días de la zona horaria del sitio, convertidos a límites UTC inclusivos con `get_gmt_from_date()`.
- La edición cambia solo los campos activos modificados y valida esos cambios contra el esquema actual. Los campos archivados, el consentimiento y los ocultos permanecen de solo lectura; los valores antiguos sin cambios se conservan aunque haya cambiado el catálogo o la obligatoriedad. Una revisión detecta cambios simultáneos en inscripción o esquema.


Tareas:
1. `EntriesListTable`: filtro por formulario (obligatorio para ver columnas dinámicas; sin filtro muestra columnas genéricas: formulario, nombre, email, fecha, estado), por estado y por rango de fechas, y búsqueda por email/documento/nombre. Paginación.
2. Al filtrar por formulario, las 3–4 primeras columnas visibles salen de los campos del formulario (configurable con "Opciones de pantalla").
3. Acciones masivas: cambiar estado (confirmado, asistió, cancelado), eliminar, exportar seleccionados.
4. `EntryDetailPage`: muestra todos los valores, los metadatos (fecha, página de origen, usuario) y el cambio de estado; permite editar valores (capacidad `tbreg_edit_entries`).
5. Mantener `entries_count` sincronizado al crear, eliminar o cancelar.

Criterios de aceptación:
- [x] Con 1.000 inscripciones de prueba el listado carga en menos de 1 s (índices correctos).
- [x] Los cambios de estado y eliminaciones actualizan el contador de cupos.

Verificación del 2026-10-13:
- `TBREG_RUN_INTEGRATION=1`: **117 pruebas PHP / 925 aserciones**, sin saltos. Incluye permisos de consulta/edición/exportación, nonces, IDs y pertenencia a formulario, cambios masivos con errores parciales comunicados, borrado confirmado, cupos, rollback, duplicados, historial y revisiones de inscripción/esquema.
- **20 pruebas DOM JavaScript** (10 constructor + 8 frontend + 2 administración), con integración activa y fixtures de PHP. Verifican IDs únicos, labels/referencias accesibles, separación entre filtros GET y acciones POST, un único botón de expansión por fila y campos históricos de solo lectura.
- Con **1.000 inscripciones**, la carga y el render de la pantalla del plugin tardaron **0.003165 s** y usaron **6 consultas** a sus tablas, incluida la lista de formularios. Se mide `EntriesPage::load()` + `render()` con MySQL local y WordPress ya arrancado. [Medición](qa/fase-4/benchmark.json). Formularios y valores se cargan por lote, sin consultas por fila.
- Las fechas elegidas en `America/Bogota` se convierten a UTC: el día `2026-10-13` corresponde a `2026-10-13 05:00:00` hasta `2026-10-14 04:59:59`. Se prueban ambos límites y los registros justo fuera del intervalo. El instalador y la nota pendiente de autoload permanecen sin cambios.
- HTTP local sobre tablas ficticias aisladas: POST + PRG para confirmar/cancelar, nonce inválido → 403, GET de acciones → 405, revisión y confirmación de borrado, detalle borrado → 404, selección privada para exportar y contador final correcto. Activación/desactivación/reactivación reales con `WP_DEBUG`: sin avisos del plugin y datos existentes conservados. Las tablas de QA se eliminan al finalizar.
- Chrome conectado: [listado de escritorio](qa/fase-4/listado-1300.jpg), [móvil](qa/fase-4/listado-375.jpg), [Opciones de pantalla](qa/fase-4/opciones-pantalla.jpg), [detalle](qa/fase-4/detalle-1300.jpg), [edición exitosa](qa/fase-4/edicion-exitosa.jpg) y [error accesible](qa/fase-4/error-edicion.jpg). Listado y detalle sin desbordes a 375 px, expansión nativa de filas, nombres visibles en móvil, columna archivada configurable y foco en el primer error del servidor. Las preferencias de columnas nativas se verifican también en integración.
- WPCS/PHPCompatibility sin errores en 69 archivos; sintaxis válida en 75 PHP, 3 scripts JS y 3 pruebas CJS. No hay dependencias nuevas de runtime, migraciones ni cambios en `wp-config.php`.
- La selección de exportación caduca a los diez minutos y está ligada al usuario; los errores de edición se guardan cinco minutos y se consumen una vez. Ambos usan el almacenamiento temporal coherente de Fase 3 y su limpieza diaria. Los escritores y la descarga se mantienen en Fase 5.

### Fase 5 — Exportación a Excel
**Objetivo:** descarga del registro de inscritos por formulario.

Tareas:
1. `XlsxWriter` (sin dependencias):
   - Genera `[Content_Types].xml`, `_rels/.rels`, `docProps/core.xml`, `xl/workbook.xml`, `xl/_rels/workbook.xml.rels`, `xl/styles.xml` (encabezado en negrita con fondo y formato de fecha) y `xl/worksheets/sheet1.xml`.
   - API en streaming: `open($path)`, `writeHeader(array)`, `writeRow(array)`, `close()`; las filas se escriben directo a un archivo temporal y luego se empaquetan con `ZipArchive`.
   - Escapa XML (`htmlspecialchars` con `ENT_XML1`), elimina caracteres de control no válidos en XML 1.0 y limita la celda a 32.767 caracteres.
   - Primera fila congelada (`<pane>`), autofiltro en el encabezado y anchos de columna aproximados.
   - Nombre de la hoja = título del formulario saneado (máx. 31 caracteres, sin `[]:*?/\`).
2. `CsvWriter`: UTF-8 con BOM, separador configurable (`,` o `;` — por defecto `;` porque Excel en es-CO lo espera) y protección contra inyección de fórmulas.
3. `EntriesExporter`: arma las columnas (5.5), itera con `iterateForExport`, aplica los filtros `tbreg_export_columns` / `tbreg_export_row` y envía las cabeceras de descarga (`Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`, `Content-Disposition`, `nocache_headers()`).
4. Pantalla o modal de exportación: formulario, formato, estado y rango de fechas.
5. Si `ZipArchive` no está disponible: ocultar XLSX, ofrecer CSV y mostrar un aviso en Ajustes.

Criterios de aceptación:
- [ ] El `.xlsx` abre sin advertencias de reparación en Excel (Windows/Mac), LibreOffice y Google Sheets.
- [ ] Las tildes, la ñ y los emojis se ven correctamente en XLSX y CSV.
- [ ] Exportar 10.000 inscripciones no supera los 64 MB de memoria.
- [ ] Un valor `=HYPERLINK(...)` se exporta como texto, no como fórmula.
- [ ] Las columnas de campos archivados aparecen con el sufijo "(eliminado)".

### Fase 6 — Notificaciones
**Objetivo:** correos al administrador y al inscrito.

Tareas:
1. `Mailer` con `wp_mail` en HTML y una plantilla simple; merge tags `{field:KEY}`, `{all_fields}`, `{event_name}`, `{event_date}`, `{event_location}`, `{entry_id}`, `{site_name}`.
2. Correo al admin (destinatarios separados por coma) y confirmación al inscrito (usa el campo con `role = email`).
3. Correo opcional al cambiar el estado (p. ej. de lista de espera a confirmado).
4. Remitente configurable en Ajustes (`From` nombre/email) y `Reply-To` = email del inscrito en el correo al admin.
5. Envío diferido con `wp_schedule_single_event` para no ralentizar el envío del formulario (con respaldo síncrono si WP-Cron está deshabilitado).

Criterios de aceptación:
- [ ] Los merge tags se reemplazan y escapan correctamente; un `{field:x}` inexistente se reemplaza por vacío.
- [ ] Probado con un SMTP de pruebas (Mailpit/MailHog vía docker-compose).

### Fase 7 — Privacidad, i18n, desinstalación y endurecimiento
Tareas:
1. `Privacy`: registrar `wp_privacy_personal_data_exporters` y `wp_privacy_personal_data_erasers` (búsqueda por la columna `email`) y añadir texto sugerido a la política de privacidad (`wp_add_privacy_policy_content`).
2. Ajuste de **retención**: borrar las inscripciones de más de N meses (cron diario), desactivado por defecto.
3. Campo `consent` con el texto legal configurable (referencia a la Ley 1581 de 2012 de habeas data en Colombia) y registro de la fecha de aceptación.
4. `uninstall.php`: si la opción "borrar datos al desinstalar" está activa, eliminar tablas, opciones, capacidades y transients; si no, no tocar nada. Compatible con multisitio (recorrer los sitios).
5. Generar `languages/toolsbox-registros.pot` (`wp i18n make-pot`). Todo el texto en español con funciones `__()`/`esc_html__()` y text domain `toolsbox-registros`; preparar `en_US` como segundo idioma.
6. Revisión de seguridad: todas las salidas escapadas, todas las entradas saneadas, `current_user_can` + nonce en cada acción admin, sin `extract()`, sin `eval`, sin consultas sin `prepare`.

Criterios de aceptación:
- [ ] `phpcs --standard=WordPress` sin errores.
- [ ] El plugin **Plugin Check** (ya instalado en este WordPress) no reporta errores.
- [ ] Desinstalar con la opción activa deja la base de datos limpia.

### Fase 8 — QA, documentación y release
Tareas:
1. Tests: unitarios (FieldTypes, Validator, RegistrationRules, XlsxWriter → abrir el zip y validar el XML) e integración (repositorios, envío completo, exportación).
2. Matriz de pruebas manuales: PHP 8.0/8.2/8.3, WP 6.0/latest, temas Twenty Twenty-Four/Five y Elementor (shortcode en un widget).
3. `readme.txt` completo (descripción, FAQ, capturas, changelog) y enlace a https://codwelt.com/toolsbox-herramientas-digitales-gratis/.
4. Script de empaquetado (zip sin `tests/`, `docs/`, `composer.*`, `phpcs.xml.dist`, `AGENTS.md`).
5. Etiquetar `v1.0.0`.

Criterios de aceptación:
- [ ] Todos los tests pasan.
- [ ] Instalación limpia desde el zip en un WordPress nuevo: crear formulario → publicar → inscribirse → exportar a Excel, sin errores en `debug.log`.

---

## 7. Flujo de verificación end-to-end (smoke test)
1. Activar el plugin.
2. Crear "Seminario de Marketing Digital" desde la plantilla "Evento básico"; añadir el campo `select` "¿Cómo te enteraste?" con las opciones Instagram / Facebook / Referido / Otro; cupo 3; duplicados por documento.
3. Insertar `[toolsbox_registro id="X"]` en una página.
4. Registrar 3 personas (una con JS desactivado); intentar una 4.ª (bloqueada) y un duplicado (bloqueado).
5. Marcar una como "Asistió".
6. Eliminar el campo "¿Cómo te enteraste?" (queda archivado).
7. Exportar a XLSX y CSV → verificar las columnas, los labels de las opciones, la columna "(eliminado)" y las tildes.

---

## 8. Estado
| Fase | Estado | Notas |
|---|---|---|
| 0 Andamiaje | ✅ Completada | Sintaxis, WPCS, metadatos y callbacks verificados. Activación, desactivación y reactivación probadas contra la BD local (MySQL de `/usr/local/mysql`, puerto 3306) sin warnings con `WP_DEBUG`. |
| 1 Datos | ✅ Completada | Tres tablas InnoDB, capacidades, migraciones, entidades y repositorios. 39 tests / 188 assertions; activación y conservación de datos verificadas. |
| 2 Constructor | ✅ Completada | Ajustes de la revisión visual resueltos; controles por tipo, predeterminados de listas y diseño responsive verificados en Chrome a 1300/375 px. 10 pruebas DOM del constructor; capturas en `docs/qa/fase-3/`. |
| 3 Frontend/envío | ✅ Completada | Shortcode/bloque, 15 tipos, POST/REST, validación, cupos, fechas, duplicados y antispam. 103 PHP / 788 aserciones con integración activa + 18 DOM; HTTP real, teclado/foco y responsive en Chrome; activación/reactivación sin avisos. Captcha real pendiente de QA; requiere InnoDB. |
| 4 Inscripciones | ✅ Completada | Filtros locales→UTC, columnas y opciones de pantalla, detalle/edición con historial y revisiones, acciones masivas y cupos. 117 PHP / 925 aserciones con integración activa + 20 DOM; 1.000 inscritos en 0.003165 s con 6 consultas; HTTP, Chrome y reactivación verificados. Selección de exportación lista; descarga en Fase 5. |
| 5 Exportación | ⬜ Pendiente | |
| 6 Notificaciones | ⬜ Pendiente | |
| 7 Privacidad/hardening | ⬜ Pendiente | |
| 8 QA/release | ⬜ Pendiente | |

### Notas de revisión (Fase 1)
- `EntryRepository` filtra `date_from`/`date_to` en **UTC**. Las pantallas de las fases 4 y 5 reciben fechas en la zona horaria del sitio y deben convertirlas con `get_gmt_from_date()` antes de llamar al repositorio.
- `tbreg_db_version` se guarda con `autoload = false`, pero `Migrations::maybe_upgrade()` la lee en cada petición, lo que suma una consulta extra por página. Cambiarla a autoload `true` cuando se toque el instalador.

## 9. Backlog (post v1)
- Lógica condicional (mostrar campo X si Y = Z).
- Campo de subida de archivos.
- Check-in con código QR enviado en el correo de confirmación + escáner en el admin.
- Exportar a Google Sheets / webhook genérico.
- Integración con pagos (WooCommerce / Wompi / Mercado Pago).
- Recordatorios automáticos antes del evento.
- Agregar el `.ics` (calendario) al correo de confirmación.
- Integración como módulo dentro de `toolsbox-por-codwelt`.
