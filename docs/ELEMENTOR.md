# Diseñar formularios con Elementor

Disponible desde ToolsBox Registros **0.3.0**, con Elementor gratuito **3.5 o posterior**, sujeto a los requisitos propios de Elementor. Se verificó con Elementor 4.2.4 y WordPress 7.1.

1. Crea el formulario en **ToolsBox Registros → Formularios**. Allí defines campos, opciones dependientes, consentimiento, cupos, fechas y PDF.
2. Abre una página con Elementor y busca **ToolsBox — Formulario de inscripción**. Arrastra el widget a un contenedor.
3. En **Contenido → Formulario de inscripción**, selecciona el formulario. Puedes mostrar u ocultar su título y descripción.
4. En **Estilo**, ajusta contenedor, títulos, etiquetas, campos de entrada, opciones y consentimiento, ayudas, botones, fotografía y mensajes. Los controles incluyen colores, tipografías, bordes, sombras, espaciado y medidas responsive.
5. En **Estilo → Campos individuales**, agrega un ajuste, ponle un nombre y busca el campo del formulario seleccionado. Puedes personalizar etiqueta, control, ayuda y color de selección, además de configurar ancho de 100%, 50% o 33% por dispositivo. Los campos de otro formulario no reciben ese ajuste.
6. Publica el formulario desde ToolsBox Registros y guarda la página cuando esté lista. Un formulario en borrador no recibe inscripciones ni aparece al visitante.

El formulario «Inscripción de idiomas» existente tiene ID **5**. Puedes elegirlo y diseñarlo como borrador si tienes permiso para administrar formularios. Los editores de páginas pueden diseñar formularios públicos; los borradores requieren `tbreg_manage_forms`.

## Cómo se conservan datos y estilos

Los campos y sus reglas se administran en ToolsBox Registros. Elementor modifica la presentación de cada widget. Cambiar colores o anchos no altera claves, opciones, inscripciones ni columnas de Excel. Puedes usar el mismo formulario en varias páginas con diseños distintos; todas las instancias guardan en el mismo formulario.

Los estilos individuales siguen la clave inmutable del campo. Si eliminas o archivas ese campo, su estilo deja de mostrarse. Al cambiar el formulario del widget, los ajustes del formulario anterior no se aplican al nuevo. Si creas o renombras campos mientras Elementor está abierto, vuelve a abrir el editor para actualizar sus selectores.

La vista previa del editor permite probar las opciones dependientes y desactiva el envío, la renovación de tokens y la cámara. En la página pública funcionan el envío habitual, la foto, las reglas y el PDF. Los estilos de Elementor afectan el formulario web; el PDF conserva su diseño propio.

El widget utiliza HTML dinámico para respetar disponibilidad y resultados privados. La configuración de caché de página y los límites de los tokens descritos en `readme.txt` siguen aplicando. No requiere Elementor Pro, Composer ni compilación de JavaScript. Si Elementor está desactivado, puedes usar el shortcode o Gutenberg.

## Verificación

- Pruebas sobre las APIs reales de Elementor: registro, dependencias, caché de widget desactivada, permisos, borradores, formularios cerrados, claves y clases verificadas, estilos generales/individuales, tipografía y CSS responsive.
- Montaje dinámico idempotente: dos widgets y reemplazo de nodos; un solo envío tras varios montajes y enlace al PDF. Vista previa sin solicitudes ni cámara.
- Chrome: dos instancias del mismo formulario con estilos independientes y selecciones independientes. A 375 px: sin desbordes, relleno de 12 px y campo configurado de medio ancho a ancho completo.
- Evidencia: [escritorio](qa/elementor/widgets-escritorio.png), [móvil](qa/elementor/widgets-375.png) e [informe](qa/elementor/verificacion.json). La revisión visual utiliza el HTML del widget y el CSS generado por Elementor; las pruebas PHP verifican sus controles y registro reales.

Desde la carpeta del plugin:

```bash
TBREG_RUN_INTEGRATION=1 vendor/bin/phpunit
vendor/bin/phpcs
```

Para las pruebas DOM, genera los fixtures de las fases anteriores según `PLAN.md` y ejecuta con la instalación externa de jsdom:

```bash
TBREG_RUN_INTEGRATION=1 \
TBREG_EDITOR_FIXTURE_DIR=/tmp/tbreg-editor-fixtures \
TBREG_FRONTEND_FIXTURE_DIR=/tmp/tbreg-frontend-fixtures \
TBREG_ENTRIES_FIXTURE_DIR=/tmp/tbreg-entries-fixtures \
TBREG_EXPORT_FIXTURE_DIR=/tmp/tbreg-export-fixtures \
TBREG_LANGUAGE_FIXTURE_DIR=/tmp/tbreg-language-fixtures \
TBREG_JSDOM_PATH=/tmp/tbreg-js-tests/node_modules/jsdom \
node --test tests/JavaScript/*.test.cjs
```

Referencias oficiales: [selectores y estilos por widget](https://developers.elementor.com/docs/editor-controls/selectors), [dependencias de scripts](https://developers.elementor.com/docs/scripts-styles/widget-scripts) y [hooks JavaScript](https://developers.elementor.com/docs/hooks/js).
