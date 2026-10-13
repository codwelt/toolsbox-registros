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

La versión de desarrollo 0.1.0 incluye la estructura base, la capa de datos y el constructor de formularios del administrador: 15 tipos de campo, opciones ordenables, plantillas y configuración por formulario. También incluye el shortcode y el bloque público, validación en el servidor, cupos, lista de espera, fechas de inscripción, prevención de duplicados y antispam. Incluye además gestión de inscritos: filtros, columnas por formulario, estados, detalle y edición protegida. Los correos, las herramientas de privacidad y la descarga XLSX/CSV se incorporarán en las siguientes fases.

No necesita dependencias de Composer para funcionar. Las herramientas declaradas en composer.json son exclusivamente para desarrollo.

Más herramientas de Codwelt: https://codwelt.com/toolsbox-herramientas-digitales-gratis/

== Installation ==

1. Sube la carpeta toolsbox-registros a wp-content/plugins/.
2. Activa ToolsBox Registros desde el menú Plugins de WordPress.
3. Abre ToolsBox Registros → Formularios → Crear formulario. Agrega campos o usa una plantilla, configura las opciones y guarda.
4. Publica el formulario y pega su shortcode [toolsbox_registro id="X"] en una página, o inserta el bloque ToolsBox Registros y selecciona el formulario. También puedes usar [toolsbox_registro slug="mi-formulario"].
5. Si activas un captcha, configura sus claves en ToolsBox Registros → Ajustes y selecciona el proveedor en la configuración del formulario.
6. Abre Inscripciones para filtrar por formulario, estado, fechas o búsqueda. Al elegir un formulario, usa Opciones de pantalla para mostrar sus campos históricos o cambiar las columnas y la cantidad por página.
7. Abre una inscripción para consultar el detalle o editar sus datos y estado. Las acciones en lote permiten confirmar, marcar asistencia, cancelar y eliminar con confirmación.

== Frequently Asked Questions ==

= ¿Puedo crear formularios con esta versión? =

Sí. Los formularios publicados reciben inscripciones mediante el shortcode o el bloque, con o sin JavaScript. El captcha opcional requiere JavaScript. Puedes consultar y administrar las inscripciones desde ToolsBox Registros → Inscripciones.

= ¿Cómo funcionan las fechas de los filtros? =

Desde y Hasta seleccionan días completos en la zona horaria del sitio. El plugin convierte sus límites a UTC para consultar la base de datos y muestra las fechas de inscripción en la hora del sitio.

= ¿Qué ocurre con los datos antiguos al editar? =

Los campos eliminados se conservan y pueden mostrarse desde Opciones de pantalla. La edición no modifica campos archivados, consentimiento ni valores ocultos. Los valores antiguos sin cambios se conservan; los datos que cambias se validan contra las reglas actuales. Si otro usuario cambia la inscripción o el formulario mientras editas, debes recargar el detalle.

= ¿Puedo descargar la selección de inscritos? =

Esta fase permite preparar una selección privada por formulario con Exportar seleccionados. La descarga XLSX/CSV se añadirá en la siguiente fase de desarrollo.

= ¿Cómo funciona el antispam? =

Incluye un campo trampa, un tiempo mínimo de llenado de 3 segundos, un token firmado de 12 horas y un límite de 5 intentos por IP en 10 minutos. No guarda la IP en texto plano. Puedes añadir Cloudflare Turnstile o reCAPTCHA v2 configurando ambas claves en Ajustes; el secreto nunca se muestra en el formulario público.

= ¿Puedo usar caché de página? =

Con JavaScript, el formulario renueva su token desde un endpoint que no se cachea. Para el envío sin JavaScript, configura la duración de la caché por debajo de 12 horas. Excluye las páginas de usuarios autenticados y las URLs con tbreg_result; esos resultados privados se consumen una sola vez y el plugin envía cabeceras para evitar su caché. La disponibilidad y el cupo se vuelven a comprobar al enviar.

= ¿Puedo modificar las plantillas? =

Sí. Copia la plantilla de templates/ dentro de toolsbox-registros/ en tu tema. Se conserva la misma ruta relativa, por ejemplo toolsbox-registros/fields/text.php.

= ¿La desactivación borra datos? =

No. La desactivación conserva las tablas y los registros existentes.

== Changelog ==

= 0.1.0 =

* Estructura inicial con autoloader propio, arranque y hooks de activación y desactivación.
* Configuración de herramientas de desarrollo y documentación inicial.
* Capa de datos con tres tablas InnoDB, migraciones y capacidades propias.
* Validación de esquemas, conservación de campos históricos y repositorios transaccionales con tests unitarios y de integración.
* Constructor del administrador sin build: 15 tipos, campos y opciones ordenables, tres plantillas rápidas y configuración por formulario.
* Listado paginado, búsqueda, estados, copiar shortcode, duplicar, cerrar y papelera con restauración.
* Guardado con nonce y capacidad, avisos, protección del historial y detección de ediciones simultáneas.
* Formulario público con shortcode, bloque dinámico de Gutenberg, estilos bajo demanda y plantillas sustituibles desde el tema.
* Envío REST con JavaScript y POST con redirección, errores accesibles y conservación temporal privada de valores.
* Validación por tipo, cupos transaccionales, fechas del sitio, lista de espera, duplicados y protección antispam con captcha opcional.
* Constructor con controles por tipo, predeterminados basados en opciones y ajustes responsive.
* Gestión de inscritos con filtros de formulario, estado, fechas locales y búsqueda, paginación y columnas dinámicas configurables.
* Detalle histórico y edición con nonce, capacidades, validación, revisión para evitar cambios simultáneos y actualización transaccional de datos/estado.
* Acciones masivas de confirmación, asistencia, cancelación y borrado confirmado, con contador de cupos coherente.
* Selección privada por formulario preparada para el exportador de la siguiente fase.
