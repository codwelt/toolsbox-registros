=== ToolsBox Registros ===
Contributors: codwelt
Tags: forms, registration, events, export, excel
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Base de ToolsBox Registros para formularios de inscripción y exportación a Excel. Las funcionalidades se incorporarán por fases.

== Description ==

ToolsBox Registros permitirá crear formularios de inscripción para clases, eventos y seminarios, administrar los inscritos y exportar los registros a Excel o CSV.

La versión de desarrollo 0.1.0 incluye la estructura base y la capa de datos: tablas propias para formularios, inscripciones y valores, capacidades de administración, migraciones y repositorios. El constructor de formularios, el shortcode, el bloque y la exportación todavía están pendientes.

No necesita dependencias de Composer para funcionar. Las herramientas declaradas en composer.json son exclusivamente para desarrollo.

Más herramientas de Codwelt: https://codwelt.com/toolsbox-herramientas-digitales-gratis/

== Installation ==

1. Sube la carpeta toolsbox-registros a wp-content/plugins/.
2. Activa ToolsBox Registros desde el menú Plugins de WordPress.
3. Esta versión inicial no añade pantallas de administración ni formularios públicos.

== Frequently Asked Questions ==

= ¿Puedo crear formularios con esta versión? =

Todavía no. La versión 0.1.0 establece la base del plugin; las siguientes fases añadirán las funcionalidades.

= ¿La desactivación borra datos? =

No. La desactivación conserva las tablas y los registros existentes.

== Changelog ==

= 0.1.0 =

* Estructura inicial con autoloader propio, arranque y hooks de activación y desactivación.
* Configuración de herramientas de desarrollo y documentación inicial.
* Capa de datos con tres tablas InnoDB, migraciones y capacidades propias.
* Validación de esquemas, conservación de campos históricos y repositorios transaccionales con tests unitarios y de integración.
