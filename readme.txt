=== ToolsBox Registros ===
Contributors: codwelt
Tags: forms, registration, events, export, excel
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.4.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Base de ToolsBox Registros para formularios de inscripción y exportación a Excel. Las funcionalidades se incorporarán por fases.

== Description ==

ToolsBox Registros permite crear formularios de inscripción para clases, eventos y seminarios, administrar los inscritos y exportar los registros a Excel o CSV.

La versión de desarrollo 0.3.0 incluye la estructura base, la capa de datos y el constructor de formularios del administrador: 16 tipos de campo, opciones ordenables, plantillas y configuración por formulario. También incluye el shortcode y el bloque público, validación en el servidor, cupos, lista de espera, fechas de inscripción, prevención de duplicados y antispam. Incluye además gestión de inscritos: filtros, columnas por formulario, estados, detalle y edición protegida. Permite exportar por formulario o selección a Excel (.xlsx) y CSV. Incluye una plantilla de inscripción de idiomas con opciones dependientes, fotografía privada desde archivo o cámara y PDF para el cliente. Añade un widget para Elementor gratuito con selector de formulario, estilos generales, ajustes individuales y diseño responsive por instancia. Los correos y las herramientas generales de privacidad se incorporarán en las siguientes fases.

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

8. Abre Exportar, elige formulario, formato y filtros, y descarga. Para una selección, usa Exportar seleccionados desde Inscripciones.

9. Para inscripciones de idiomas, crea un formulario y aplica Inscripción de idiomas (ejemplo editable). Ajusta cursos, niveles, subniveles, modalidad, días y horarios desde los campos. Cada opción permite configurar Disponible cuando usando otros campos y sus valores.
10. En Configuración puedes habilitar el PDF y editar el texto de su segunda página. La plantilla lo activa. El cliente recibe el enlace después de guardar su inscripción; el administrador lo descarga desde el detalle.

11. En Elementor busca ToolsBox — Formulario de inscripción, arrástralo a la página y selecciona el formulario en Contenido. En Estilo ajusta contenedor, etiquetas, campos, botones, foto y mensajes. En Campos individuales agrega un ajuste, elige el campo del formulario y configura sus estilos o ancho por dispositivo. El mismo formulario puede tener diseños diferentes en varios widgets.

== Frequently Asked Questions ==

= ¿Necesito Elementor Pro? =

No. El widget funciona con Elementor gratuito 3.5 o posterior, sujeto a los requisitos de WordPress/PHP de Elementor. La integración se carga únicamente cuando Elementor está activo; el shortcode y el bloque siguen disponibles. Crea campos y reglas en ToolsBox Registros y modifica su presentación en Elementor. Los campos del selector se actualizan al volver a abrir el editor.

= ¿Puedo diseñar un borrador sin enviar inscripciones? =

Sí, si tienes permiso para administrar formularios. La vista previa del editor desactiva envío y cámara. Los editores de páginas pueden diseñar formularios públicos; no pueden acceder a borradores sin permiso. Publica el formulario desde ToolsBox Registros antes de mostrarlo en una página pública.


= ¿Puedo crear formularios con esta versión? =

Sí. Los formularios publicados reciben inscripciones mediante el shortcode o el bloque, con o sin JavaScript. El captcha opcional requiere JavaScript. Puedes consultar y administrar las inscripciones desde ToolsBox Registros → Inscripciones.

= ¿Cómo funcionan las fechas de los filtros? =

Desde y Hasta seleccionan días completos en la zona horaria del sitio. El plugin convierte sus límites a UTC para consultar la base de datos y muestra las fechas de inscripción en la hora del sitio.

= ¿Qué ocurre con los datos antiguos al editar? =

Los campos eliminados se conservan y pueden mostrarse desde Opciones de pantalla. La edición no modifica campos archivados, consentimiento ni valores ocultos. Los valores antiguos sin cambios se conservan; los datos que cambias se validan contra las reglas actuales. Si otro usuario cambia la inscripción o el formulario mientras editas, debes recargar el detalle.

= ¿Puedo descargar la selección de inscritos? =

Sí. Selecciona un formulario en Inscripciones, marca las filas y elige Exportar seleccionados. La selección es privada y caduca a los diez minutos. En Exportar puedes elegir XLSX o CSV, estado, fechas y búsqueda antes de descargar. También puedes exportar el formulario completo desde el menú Exportar.

= ¿Qué incluye el archivo exportado? =

ID, fecha de inscripción en la hora del sitio, estado y todos los campos en su orden, incluidos los eliminados con el sufijo (eliminado). Las listas usan el texto visible y las selecciones múltiples se separan con coma. Documentos y teléfonos son texto en XLSX para conservar ceros iniciales y números largos. XLSX conserva como texto los valores que parecen fórmulas; CSV los protege con un apóstrofo. El separador CSV predeterminado es punto y coma; puedes elegir coma.

= ¿Qué necesita la exportación Excel? =

La extensión PHP ZipArchive. Si no está disponible, el plugin muestra un aviso en Ajustes y ofrece CSV. No necesita PhpSpreadsheet ni otras librerías externas.

= ¿Cómo se configuran los niveles y horarios relacionados? =

En una opción de lista, radio o casilla, agrega condiciones en Disponible cuando. Elige el campo del que depende y marca sus opciones permitidas. Las condiciones entre campos se cumplen todas; dentro de un campo basta una de las opciones marcadas. El formulario muestra las opciones válidas y borra selecciones que dejan de estar disponibles. El servidor rechaza combinaciones incorrectas aunque se manipule el envío. Los horarios de la plantilla son ejemplos; revísalos antes de publicar.

= ¿Cómo se adjunta la fotografía? =

Usa el tipo Fotografía (archivo o cámara). Admite JPEG, PNG y WebP, hasta 5 MiB o el límite menor que imponga PHP. Necesita GD para reducir y recomprimir la imagen; la foto se guarda en la inscripción y no tiene una URL pública. El admin puede verla en el detalle y el PDF la incluye; Excel muestra Foto adjunta. La cámara requiere HTTPS, permiso del navegador y un dispositivo compatible. Puedes usar un archivo si no está disponible. Tras errores con envío sin JavaScript hay que adjuntar de nuevo la foto.

= ¿Cómo obtiene el cliente su PDF? =

Habilita Ofrecer PDF al cliente al completar la inscripción. Después de guardar aparece Descargar PDF de mi inscripción. El enlace dura 24 horas y funciona en el navegador que realizó la inscripción. El admin lo descarga con su sesión y permisos desde el detalle. La plantilla produce dos páginas con datos, foto y espacios de firma; datos extensos generan páginas adicionales. El texto de la segunda página es editable. Habilitar el PDF conserva la pantalla del resultado para ofrecer la descarga antes de cualquier redirección configurada.

= ¿Cómo funciona el antispam? =

Incluye un campo trampa, un tiempo mínimo de llenado de 3 segundos, un token firmado de 12 horas y un límite de 5 intentos por IP en 10 minutos. No guarda la IP en texto plano. Puedes añadir Cloudflare Turnstile o reCAPTCHA v2 configurando ambas claves en Ajustes; el secreto nunca se muestra en el formulario público.

= ¿Puedo usar caché de página? =

Con JavaScript, el formulario renueva su token desde un endpoint que no se cachea. Para el envío sin JavaScript, configura la duración de la caché por debajo de 12 horas. Excluye las páginas de usuarios autenticados y las URLs con tbreg_result; esos resultados privados se consumen una sola vez y el plugin envía cabeceras para evitar su caché. La disponibilidad y el cupo se vuelven a comprobar al enviar.

= ¿Puedo modificar las plantillas? =

Sí. Copia la plantilla de templates/ dentro de toolsbox-registros/ en tu tema. Se conserva la misma ruta relativa, por ejemplo toolsbox-registros/fields/text.php.

= ¿La desactivación borra datos? =

No. La desactivación conserva las tablas y los registros existentes.

== Changelog ==

= 0.4.0 =

* Nuevo: diseño «Formato de inscripción y matrícula» para el PDF, configurable por formulario: colores, logo, título, subtítulo, opciones con casillas, recuadro de foto, espacio de uso de la institución, condiciones por secciones, firmas y pie de página.
* Nuevo: descarga del formato en blanco desde Configuración → Comprobante en PDF.

= 0.3.9 =

* Corregido: el detalle de una inscripción mostraba «Lo siento, no tienes permitido el acceso a esta página» incluso a administradores. La página se oculta del menú después de la verificación de acceso de WordPress y el menú marca Inscripciones mientras se ve un detalle.

= 0.3.7 =

* Revisión responsive y de accesibilidad del editor completo, con pruebas de contraste AA.
* Siempre se distingue como interruptor excluyente; la matriz muestra Ninguna cuando no hay otras condiciones.
* Aviso de eliminación de redirección solo cuando corresponde y con su dirección visible.
* Conteo de aserciones PHP reproducible con y sin fixtures del DOM.

= 0.3.6 =

* Configuración agrupada por tema, con interruptores y controles dependientes que conservan sus valores.
* Tarjetas de notificaciones con selector de datos por nombre de campo e inserción en el cursor.
* Selección de mensaje o redirección, avisos de correo principal y zona horaria en Inscripciones.
* El envío de correos continúa pendiente; esta versión guarda su configuración.

= 0.3.5 =

* Tarjeta de campo con secciones General, Opciones, Validación y Avanzado.
* Opciones compactas con valores editables, pegado múltiple, dependencias por campo y matriz.
* Menú de campo de 32 px, sin borde permanente y separado de la flecha.

= 0.3.4 =

* Inserción de campos en cualquier posición con catálogo agrupado, buscador y navegación por teclado.
* Tarjetas con resúmenes, menú de acciones y duplicación con claves nuevas.
* Separadores visuales, plantillas en el lienzo y controles para expandir o contraer todos los campos.
* Enlaces separados para fechas y zona horaria; Guardar inferior solo cuando el botón principal sale de pantalla.

= 0.3.3 =

* Panel Publicar en todas las pestañas, fijo en escritorio y con guardado inferior en móvil.
* Resumen de cupos y fechas, aviso de correo principal y atajo Ctrl/Cmd+S.
* Indicador de cambios sin guardar y vista previa privada de la última versión guardada, sin envío ni cámara.

= 0.3.2 =

* Shortcode bajo el nombre del formulario con copia accesible y slug editable en línea.
* Encabezado con estado y enlaces autorizados a inscripciones y exportación.
* Corrige la copia del shortcode en el editor y conserva la alternativa manual.

= 0.3.1 =

* Corrige las casillas de Configuración y Notificaciones en el editor de formularios.
* Elimina un comentario técnico que se mostraba junto a la zona horaria.

= 0.3.0 =

* Widget opcional para Elementor gratuito con selector de formularios.
* Controles nativos de color, tipografía, borde, sombra, espaciado y medidas responsive.
* Estilos individuales y anchos por campo sin modificar las reglas ni el esquema del formulario.
* Estilos por instancia, vista previa sin envío y montaje dinámico idempotente.
* HTML dinámico sin caché de widget para respetar cupos, fechas y resultados privados.


= 0.2.0 =

* Plantilla de idiomas con curso, nivel, subnivel, modalidad, días y horarios relacionados y editables.
* Validación cruzada de opciones y revalidación transaccional; relaciones conservadas al regenerar claves.
* Fotografías privadas con recomprensión, límites de imagen y captura desde cámara con mejora progresiva.
* PDF nativo con fuente Unicode incluida, foto, texto editable, firma y enlace privado para el cliente.
* Versión de assets actualizada para evitar caché del editor y formulario anteriores.

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
* Exportación XLSX nativa y CSV UTF-8 con BOM por formulario o selección, filtros locales, columnas históricas y opciones visibles.
* Descargas con nonce y capacidad, temporales privados, protección de fórmulas y generación por bloques sin dependencias externas.

