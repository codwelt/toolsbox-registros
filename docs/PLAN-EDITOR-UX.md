# Rediseño del editor de formularios — plan de acción

> Pantalla: `wp-admin/admin.php?page=toolsbox-registros-edit&id=5` (formulario «Inscripción de idiomas», 18 campos).
> Análisis del 2026-10-15 con capturas del editor real (HTML de `FormEditorPage::render()`, CSS del admin de WP y `builder.js`) a 1400 px.
> Capturas de partida: [`qa/editor-ux/antes/`](qa/editor-ux/antes/). Leer junto con [`../AGENTS.md`](../AGENTS.md).

## 1. Objetivo

Que el editor sea fácil de entender y de manejar para alguien que no es técnico:

1. **Shortcode visible arriba**, justo debajo del nombre del formulario, listo para copiar.
2. **Opciones de publicación siempre a la mano**, también desde la pestaña Campos, sin tener que ir a otra pestaña.
3. Información **agrupada por tema** y controles que solo aparecen cuando aplican.
4. Campos y opciones **compactos**: ver de un vistazo cómo está armado el formulario.

## 2. Diagnóstico

### 2.1 Errores visibles (corregir primero)
| # | Problema | Dónde | Causa |
|---|---|---|---|
| E1 | Se imprime en pantalla el texto `/* translators: Marcadores numéricos… */` | Pestaña Configuración, al final | Comentario PHP fuera de `<?php ?>` en `FormEditorPage::render()` (justo antes del párrafo de zona horaria) |
| E2 | Las casillas (Ofrecer PDF, Lista de espera, Requerir inicio de sesión, Avisar al administrador, Confirmar al inscrito) se ven como **barras horizontales** de ancho completo con el texto centrado debajo | Configuración y Notificaciones | `.tbreg-control input { width: 100% }` también afecta a los checkbox, y `.tbreg-checkbox` hereda `flex-direction: column` de `.tbreg-control` |

### 2.2 Estructura general
- La pestaña **Publicar** solo tiene 3 cosas (estado, slug y shortcode) y deja la pantalla vacía. El **estado** (Borrador/Publicado), que es lo más importante, no se ve desde ninguna otra pestaña.
- El **shortcode** está escondido en la última pestaña.
- El botón **Guardar** solo está al final de la página. Con 18 campos hay que bajar mucho, y no hay ningún aviso de cambios sin guardar.
- A 1400 px, el contenido ocupa unos 1000 px y la derecha queda vacía.

### 2.3 Pestaña Campos
- La **paleta** tiene 16 botones en una sola columna sin agrupar (y «Fotografía» aparece primero). Ocupa más alto que la lista de campos y obliga a buscar.
- Las **tarjetas cerradas** solo muestran la etiqueta y el tipo. No se sabe si un campo es obligatorio, qué ancho tiene, si es el correo principal o si sus opciones dependen de otro campo.
- No hay agrupación visual: los campos «Título o separador» se ven iguales que los demás, así que el formulario parece una lista plana de 18 elementos.
- No hay forma de **duplicar** un campo.

### 2.4 Tarjeta abierta (ej. «Subnivel / módulo»)
- Las **opciones con condiciones** son el punto más crítico. Cada opción repite: un texto explicativo, el selector «Disponible cuando», la fila de casillas, un botón «Eliminar» y «Agregar condición». **Cada opción ocupa unos 280 px**, así que un campo de 10 opciones mide unos 2.800 px y es imposible ver la relación Nivel → Subnivel de un vistazo.
- El «Identificador permanente» (técnico) es lo primero que se ve. «Clases CSS» aparece al mismo nivel que «Etiqueta».
- Los ajustes no están agrupados (básico, validación, avanzado).

### 2.5 Pestaña Configuración
- Hay 20 controles mezclados en una cuadrícula de dos columnas: datos del evento, cupos, fechas, mensajes, PDF, privacidad y antispam, sin títulos de sección.
- Hay controles que dependen de otros y siempre se muestran: el texto del PDF aunque el PDF esté apagado, la lista de espera con cupo 0 (ilimitado), y redirección y mensaje de éxito al mismo tiempo.
- La zona horaria del sitio es `+00:00`. Conviene sugerir configurarla (p. ej. `America/Bogota`) con un enlace a Ajustes → Generales.

### 2.6 Pestaña Notificaciones
- Mismo problema de las casillas (E2).
- Los asuntos y mensajes siguen visibles aunque la notificación esté apagada.
- Pedir `{field:KEY}` no sirve en la práctica: las claves son `f_0d40ade6df0b2cf2`. Hace falta un selector de etiquetas por nombre de campo.

## 3. Diseño propuesto

### 3.1 Estructura de la pantalla (escritorio ≥ 1100 px)

```
┌──────────────────────────────────────────────────────────────────────────────┐
│ Editar formulario  [● Borrador]          Ver inscripciones (0) · Exportar    │
│ ← Volver a formularios                                                       │
├──────────────────────────────────────────────────────┬───────────────────────┤
│ Nombre del formulario                                │ ┌ PUBLICAR ─────────┐ │
│ [ Inscripción de idiomas                          ]  │ │ Estado [Borrador▾]│ │
│                                                      │ │ Inscritos 0 / ∞   │ │
│ Shortcode  [toolsbox_registro id="5"]  [⧉ Copiar]    │ │ Abre  — sin fecha │ │
│ Slug: inscripcion-de-idiomas  (Editar)               │ │ Cierra — sin fecha│ │
│ También: bloque «ToolsBox Registros» o widget de     │ │ Cambios sin guardar│ │
│ Elementor «ToolsBox — Formulario de inscripción».    │ │ [ Guardar ]  ⌘S   │ │
│                                                      │ └───────────────────┘ │
│ [ Campos (18) ] [ Configuración ] [ Notificaciones ] │ ┌ RESUMEN ──────────┐ │
│ ─────────────────────────────────────────────────── │ │ 18 campos         │ │
│  Campos  ·  [Plantillas ▾]   [Expandir todo]         │ │ 14 obligatorios   │ │
│  ┌──────────────────────────────────────────────┐   │ │ Correo: Correo el.│ │
│  │ ⠿ Curso         Lista · Oblig. · ½      ⋯  ⌄ │   │ │ Documento: Doc... │ │
│  │ ⠿ Nivel         Lista · Oblig. · ½      ⋯  ⌄ │   │ │ PDF: activado     │ │
│  │ ⠿ Subnivel      Lista · Oblig. · ½ · ⇢Nivel  │   │ └───────────────────┘ │
│  │ ── DATOS DEL ESTUDIANTE ──────────────────── │   │                       │
│  │ ⠿ Nombres y apellidos  Texto · Oblig.        │   │  (sidebar fija al     │
│  │ ...                                          │   │   hacer scroll)       │
│  └──────────────────────────────────────────────┘   │                       │
│  [ + Agregar campo ]                                 │                       │
└──────────────────────────────────────────────────────┴───────────────────────┘
```

Decisiones:
- **Barra lateral derecha «Publicar»** visible en **todas** las pestañas (no solo en Campos). Es el patrón que el usuario de WordPress ya conoce del editor de entradas. Se elimina la pestaña Publicar: quedan **Campos · Configuración · Notificaciones**.
- **Shortcode bajo el nombre**, con botón Copiar y el slug editable en línea (patrón «Enlace permanente: … Editar» de WordPress). En formularios nuevos: «Guarda el formulario para obtener el shortcode».
- **La paleta lateral izquierda desaparece** y se reemplaza por un **inserción en línea** (sección 3.3). La pantalla pasa de 3 a 2 columnas.
- Estado como **insignia de color** junto al título: Borrador (gris), Publicado (verde), Cerrado (ámbar).

### 3.2 Barra lateral «Publicar»
- **Estado**: select Borrador / Publicado / Cerrado. Si se elige Publicado y no hay campos con rol correo, mostrar un aviso en línea (no bloqueante).
- **Inscritos**: `entries_count / capacity` con barra de progreso si hay cupo. El número enlaza a Inscripciones filtradas por este formulario.
- **Ventana de inscripción**: resumen de apertura/cierre en hora del sitio y enlace «Cambiar» que lleva a Configuración → Inscripciones.
- **Guardar** (botón primario) + indicador «Cambios sin guardar» / «Guardado hace 2 min».
- **Vista previa**: abre el formulario renderizado en una pestaña nueva (endpoint de vista previa con nonce y solo para `tbreg_manage_forms`; sin enviar datos).
- Atajo **Ctrl/Cmd + S** para guardar y aviso `beforeunload` si hay cambios sin guardar.
- Fija (`position: sticky; top: 46px`) al hacer scroll.
- **Móvil (< 1100 px)**: la tarjeta Publicar pasa arriba de las pestañas, compacta (estado + guardar), y el botón Guardar queda además en una **barra inferior fija**.

### 3.3 Pestaña Campos
1. **Barra superior del lienzo**: título «Campos (18)», menú **Plantillas** (aplica una plantilla con confirmación si ya hay campos) y **Expandir/Contraer todo**.
2. **Inserción de campos**: botón «+ Agregar campo» al final de la lista y un «+» discreto entre tarjetas al pasar el mouse o con el foco. Ambos abren un panel con los tipos **agrupados**:
   - *Texto y contacto*: Texto, Texto largo, Correo, Teléfono, Documento, Dirección web
   - *Selección*: Lista desplegable, Selección única, Casillas
   - *Fecha y número*: Fecha, Hora, Número
   - *Especiales*: Fotografía, Consentimiento, Campo oculto
   - *Diseño*: Título o separador

   Con buscador por nombre. Navegable con teclado (flechas, Enter, Esc). El campo nuevo se inserta en la posición elegida y queda abierto con el foco en «Etiqueta».
3. **Tarjeta cerrada con resumen**: `⠿ Etiqueta` + chips: tipo · **Obligatorio** · ancho (Completo/½/⅓) · rol (✉ Correo principal / 🪪 Documento) · **⇢ Depende de Nivel** · «8 opciones». Menú `⋯` con Duplicar, Mover arriba/abajo y Eliminar.
4. **Los «Título o separador»** se ven como **encabezado de sección** (franja con el texto en mayúsculas pequeñas, sin caja) para que el formulario se lea por bloques.
5. **Estado vacío**: tarjeta con «Empieza con una plantilla» (lista de plantillas como tarjetas) o «Agregar tu primer campo».

### 3.4 Tarjeta abierta
Se organiza en **sub-secciones** dentro de la tarjeta (pestañas internas o acordeones, según quepa mejor):

| Sección | Contenido |
|---|---|
| **General** | Etiqueta, Tipo, Texto de ayuda, Placeholder (si aplica), Obligatorio (como interruptor) |
| **Opciones** *(solo select/radio/checkbox)* | Tabla compacta de opciones + dependencias (ver abajo) + valor predeterminado |
| **Validación** *(si aplica al tipo)* | Mín./Máx., longitud, patrón |
| **Avanzado** | Ancho, Rol principal, Clases CSS, Identificador permanente (solo lectura, con copiar) |

**Tabla de opciones** (una fila por opción, unos 44 px):
```
⠿  Etiqueta            Valor guardado (✎)   Disponible si Nivel…        ⋯
⠿  Básico 1            basico_1             [Básico]                    ⋯
⠿  Intermedio 1        intermedio_1         [Intermedio]                ⋯
⠿  Programa especial   prog_especial        [Avanzado] [Programas esp.] ⋯
[+ Agregar opción]   [Pegar varias…]
```
- El **valor guardado** se muestra como texto y se edita con el lápiz (casi nunca hace falta tocarlo).
- **Pegar varias**: un textarea donde cada línea es una opción (`Etiqueta` o `Etiqueta | valor`), útil para catálogos largos.
- **Dependencias en un solo lugar**: arriba de la tabla, «Las opciones de este campo dependen de: [Nivel ▾]» (una vez por campo, no por opción). Al elegirlo, la columna «Disponible si…» muestra chips con las opciones del campo padre, que se activan o desactivan con un clic.
- **Vista matriz** (botón «Ver como matriz»): filas = opciones de este campo, columnas = opciones del campo padre, casillas en las intersecciones. Permite revisar Nivel × Subnivel completo en una sola pantalla.
- Las condiciones múltiples (más de un campo padre) se mantienen como opción avanzada («+ Otra condición») y se muestran como texto resumido: «Si Nivel = Básico **y** Modalidad = Virtual».
- **Compatibilidad**: el JSON guardado no cambia de forma. Es solo otra manera de editar la estructura actual de `ConditionalOptions`.

### 3.5 Pestaña Configuración → secciones
Tarjetas con título y descripción corta, en este orden:

1. **Información del evento**: Descripción, Nombre del evento, Lugar o enlace, Modalidad, Inicio y Fin.
2. **Inscripciones**: Cupo máximo (con «0 = ilimitado» como ayuda, no en la etiqueta), Lista de espera *(solo si cupo > 0)*, Apertura, Cierre, Estado inicial, Control de duplicados *(avisar si se elige «por correo» y no hay campo con rol correo)*, Requerir inicio de sesión.
3. **Después de inscribirse**: radio «Mostrar mensaje» / «Redirigir a una página», con el campo correspondiente; Texto del botón de envío.
4. **Comprobante en PDF**: interruptor «Ofrecer PDF al inscrito» + Texto de la segunda página *(solo si está activo)*.
5. **Privacidad y seguridad**: Texto de autorización de datos, Verificación antispam.

- Las casillas pasan a ser **interruptores** con el texto a la derecha (una línea).
- La nota de zona horaria va dentro de «Inscripciones», con un enlace a Ajustes → Generales si la zona es UTC.

### 3.6 Pestaña Notificaciones
- Dos tarjetas: **Aviso al administrador** y **Confirmación al inscrito**. Cada una con su interruptor; los campos se muestran solo si está activa.
- Botón **«Insertar dato ▾»** junto a asunto y mensaje: lista los campos por **etiqueta** («Nombres y apellidos») e inserta `{field:f_…}` en la posición del cursor. Incluye también `{event_name}`, `{entry_id}`, `{all_fields}`.
- Mientras no exista la Fase 6, mostrar un aviso informativo en la parte superior («El envío de correos llega en la próxima versión; la configuración ya se guarda»), en lugar de dejarlo en un texto pequeño al final.

### 3.7 Estilo visual
- Paneles en tarjetas blancas (`#fff`, borde `#dcdcde`, radio 4 px) sobre el fondo gris del admin, como el resto de WordPress.
- Usar componentes y colores del admin de WP (`.postbox`, `.button`, `.notice`, variables de `admin-color`), sin inventar una estética nueva.
- Ancho máximo del área de trabajo: 1400 px; barra lateral de 300 px.

## 4. Plan de implementación por fases (para Codex)

> Cada fase deja el editor funcionando. No cambiar el formato JSON de `fields` ni de `settings`; es un rediseño de la interfaz.

### UX-0 — Correcciones inmediatas
- E1: mover el comentario `/* translators */` dentro de un bloque PHP o eliminarlo.
- E2: `.tbreg-checkbox { flex-direction: row; }` y `.tbreg-control input[type="checkbox"], .tbreg-control input[type="radio"] { width: auto; }`.
- **Aceptación:** capturas de Configuración y Notificaciones sin el texto suelto y con casillas normales.

### UX-1 — Encabezado y shortcode
- Insignia de estado junto al título; enlaces «Ver inscripciones (n)» y «Exportar».
- Bloque de shortcode **bajo el campo Nombre**: input de solo lectura + Copiar (con aviso «Copiado» en `aria-live`), slug editable en línea y la mención del bloque y del widget de Elementor.
- **Aceptación:** el shortcode se ve sin cambiar de pestaña; copiar funciona con teclado.

### UX-2 — Barra lateral «Publicar» y guardado
- Layout de 2 columnas (`grid-template-columns: minmax(0,1fr) 300px`), con la barra lateral fija.
- Mover Estado y Guardar a la barra lateral; agregar el resumen de inscritos/cupo y de la ventana de inscripción, y Vista previa.
- Eliminar la pestaña Publicar (el slug pasa al bloque del shortcode).
- Indicador de cambios sin guardar, `beforeunload` y Ctrl/Cmd+S.
- Móvil: tarjeta compacta arriba + barra inferior fija con Guardar.
- **Aceptación:** desde la pestaña Campos se puede cambiar el estado y guardar sin hacer scroll; los avisos de cambios sin guardar funcionan.

### UX-3 — Lista de campos
- Panel de inserción agrupado con buscador (reemplaza la paleta); «+» entre tarjetas.
- Tarjeta cerrada con chips de resumen y menú `⋯` (Duplicar incluido; el duplicado recibe una `key` nueva).
- Separadores visuales para «Título o separador».
- Plantillas en la barra del lienzo y en el estado vacío.
- **Aceptación:** con el formulario 5 se identifican de un vistazo los obligatorios, los anchos y las dependencias; agregar un campo en una posición intermedia funciona con mouse y con teclado.

### UX-4 — Tarjeta abierta y opciones
- Sub-secciones General / Opciones / Validación / Avanzado según el tipo.
- Tabla compacta de opciones, «Pegar varias», selector de campo padre único y chips de «Disponible si…».
- Vista matriz para las dependencias.
- **Aceptación:** el campo «Subnivel / módulo» del formulario 5 cabe en menos de 900 px de alto en escritorio; el JSON guardado es idéntico al de antes del rediseño para la misma configuración (prueba de ida y vuelta).

### UX-5 — Configuración y Notificaciones
- Secciones en tarjetas, interruptores y controles dependientes (sección 3.5).
- Notificaciones en dos tarjetas con «Insertar dato» por etiqueta de campo.
- **Aceptación:** los controles dependientes se muestran u ocultan sin perder su valor guardado.

### UX-6 — Responsive, accesibilidad y pruebas
- Capturas a 1400, 1024, 782 y 375 px de las 3 pestañas, la tarjeta abierta y el panel de inserción, guardadas en `docs/qa/editor-ux/despues/`.
- Teclado: tabs con flechas (ya existe), panel de inserción, menú `⋯`, matriz de dependencias; foco visible; `aria-expanded`, `aria-controls` y anuncios en `aria-live` para guardar, copiar y duplicar.
- Pruebas DOM nuevas: insertar en posición, duplicar, pegar varias opciones, matriz ↔ JSON, controles dependientes, indicador de cambios sin guardar.
- Contraste AA en chips e insignias.
- **Aceptación:** todas las pruebas existentes siguen verdes + las nuevas; sin desbordes horizontales en ninguna captura.

## 5. Fuera de alcance
- Cambiar el formulario público o su diseño (eso es del widget de Elementor y `form.css`).
- Lógica condicional para mostrar u ocultar **campos** completos (está en el backlog del plan general).
- Deshacer/rehacer en el editor.

## 6. Estado de implementación

Se implementa una fase por solicitud, según `AGENTS.md`.

| Fase | Estado | Verificación |
|---|---|---|
| UX-0 | ✅ Completada (2026-10-15), versión 0.3.1 | Configuración y Notificaciones revisadas en Chrome sobre el formulario ID 5, a 1400 y 375 px. Sin comentario visible; casillas nativas cuadradas, con etiquetas a la derecha y sin desbordes horizontales. |
| UX-1 | ✅ Completada (2026-10-15), versión 0.3.2 | Insignia del estado guardado, enlaces por permisos, shortcode bajo Nombre con copia por teclado y slug editable en línea. Chrome a 1400/375 px sin desbordes; 146 PHP / 1.392 aserciones con integración activa y 34 DOM. |
| UX-2 | ✅ Completada (2026-10-15), versión 0.3.3 | Publicar en todas las pestañas, guardado con Ctrl/Cmd+S, indicador de cambios, cupos/fechas y vista previa privada. 148 PHP / 1.426 aserciones con integración activa y 38 DOM; Chrome a 1400/1024/782/375 px. |
| UX-3 | ✅ Completada (2026-10-15), versión 0.3.4 | Catálogo agrupado con buscador e inserción intermedia, resúmenes, menú y duplicación con claves nuevas, separadores y plantillas en el lienzo. 149 PHP / 1.436 aserciones con integración activa + 45 DOM; Chrome a 1400/1024/782/375 px. |
| UX-4 | ✅ Completada (2026-10-15), versión 0.3.5 | Secciones internas, tabla compacta, pegado múltiple, chips y matriz de dependencias. Subnivel: 778 px, filas de 45 px; JSON idéntico al guardar sin cambios. 150 PHP / 1.442 aserciones con integración activa + 52 DOM. |
| UX-5 | ✅ Completada (2026-10-15), versión 0.3.6 | Cinco tarjetas de Configuración y dos de Notificaciones, interruptores, controles dependientes e inserción de datos en el cursor. 152 PHP / 1.461 aserciones con integración activa + 60 DOM; guardado real y Chrome a 1400/1024/782/375 px. |
| UX-6 | ✅ Completada (2026-10-15), versión 0.3.7 | 24 capturas a 1400/1024/782/375 px, teclado y relaciones ARIA verificados, contraste AA y notas de UX-4/UX-5 resueltas. 152 PHP / 1.461 aserciones reproducibles con integración activa; 64 DOM + 1 prueba de contraste. |

Notas de UX-0:
- Eliminado el comentario fuera de PHP; se conserva el comentario de traducción correcto dentro de `sprintf()`.
- Se excluyen checkbox/radio de la regla general de ancho completo y se evita su encogimiento en flex. Se conserva el ancho nativo de WordPress, en lugar de `width: auto`: la comprobación real mostró casillas desmarcadas de solo 2 px con ese valor. `.tbreg-checkbox` ahora usa `flex-direction: row`.
- Sin cambios del JSON, los formularios guardados o el formulario público. La versión 0.3.1 invalida la caché del CSS administrativo.
- **`TBREG_RUN_INTEGRATION=1`: 145 pruebas PHP / 1.370 aserciones**, sin omisiones; **30 pruebas DOM** correctas. WPCS/PHPCompatibility: 85 archivos sin errores ni avisos. Sintaxis PHP correcta en los dos archivos modificados. Activación, desactivación y reactivación con `WP_DEBUG=1`, sin avisos del plugin y con checksum/contador de las tres tablas conservados.
- [Capturas posteriores de UX-0](qa/editor-ux/despues/ux-0/): Configuración y Notificaciones completas en ambos tamaños y detalle de casillas en escritorio. Son capturas del administrador local con los avisos existentes de otros plugins; no se descartaron ni se modificó su configuración.

Notas de UX-1:
- Encabezado con insignia textual del **estado guardado** y colores para Borrador/Publicado/Cerrado. Los enlaces a Inscripciones y Exportar solo aparecen para formularios guardados y usuarios con la capacidad correspondiente; ambos conservan el filtro `form_id`.
- «Ver inscripciones (n)» cuenta todas las inscripciones del formulario, incluidas canceladas y lista de espera. `entries_count` sigue destinado a los cupos; se reutiliza la consulta de conteo del repositorio, sin cargar respuestas ni modificar tablas.
- Shortcode único bajo Nombre, fuera de los paneles de pestañas, con etiqueta asociada, botón de copia y anuncio independiente `aria-live`. Se corrigió la copia anterior del editor, que enviaba el ID del input al portapapeles; la copia del listado sigue funcionando por fila. Si Clipboard API no está disponible o rechaza la operación, se selecciona el texto para copiar con el teclado y se anuncia la alternativa.
- Slug en un `details/summary` nativo con «Editar»: operable con teclado y disponible sin JS. El input conserva su nombre POST, se envía también con el panel cerrado y su resumen se actualiza como texto. En formularios nuevos se indica que hay que guardar para obtener el shortcode. Se mencionan el bloque y el widget de Elementor.
- El separador nativo `wp-header-end` mantiene los avisos de WordPress fuera del encabezado flexible. El shortcode ocupa una fila completa en móvil para evitar recortar su texto.
- **`TBREG_RUN_INTEGRATION=1`: 146 pruebas PHP / 1.392 aserciones**, sin omisiones; **34 pruebas DOM** correctas. Una prueba de integración nueva verifica estados, total incluyendo canceladas/espera y permisos. Cuatro pruebas DOM nuevas verifican copia, alternativa manual, listado y slug. Tras el último ajuste del encabezado se repitió el grupo de repositorios: 25 pruebas / 272 aserciones correctas. WPCS/PHPCompatibility: 85 archivos sin errores ni avisos; sintaxis de los cinco PHP y dos JS/CJS modificados correcta.
- Chrome real, formulario ID 5: Tab → Copiar → Enter anuncia «Shortcode copiado.» sin guardar; `summary` del slug se abre con Enter; el shortcode se mantiene visible desde Configuración y Notificaciones. A 1400 y 375 px, `scrollWidth` coincide con el viewport; en móvil el input muestra el texto completo sin desplazamiento interno. [Capturas](qa/editor-ux/despues/ux-1/) e [informe](qa/editor-ux/despues/ux-1/verificacion.json).
- Activación, desactivación y reactivación con `WP_DEBUG=1`, sin avisos del plugin y conservando los checksums y contadores de las tres tablas. No se guardó ni publicó el formulario ID 5. Versión 0.3.2 para invalidar la caché de los assets modificados.
- Se conserva la pestaña Publicar con el selector de estado hasta UX-2, que implementará la barra lateral. Sin cambios del JSON ni del formulario público.

Notas de UX-2:
- Layout de dos columnas con barra lateral de 300 px, `position: sticky; top: 46px`, y tres pestañas: Campos, Configuración y Notificaciones. Estado y Guardar están fuera de los paneles. Se conserva un único control POST de estado; la opción Papelera solo aparece al editar un formulario que ya está en ese estado.
- Resumen de ocupación/cupo con barra de progreso cuando el cupo es limitado; mantiene la distinción entre ocupación y total de inscripciones. La ventana de inscripción muestra fechas locales sin convertirlas a la zona horaria del navegador, junto a la zona del sitio. «Cambiar» abre Configuración y enfoca Cupo. Los resúmenes se actualizan al editar sus controles.
- Aviso no bloqueante al elegir Publicado sin campo activo con rol correo. Cambiar el rol, agregar/eliminar campos o aplicar plantillas actualiza el aviso.
- Indicador de último guardado calculado desde `updated_at` UTC; al editar anuncia «Cambios sin guardar». Ctrl/Cmd+S utiliza `requestSubmit`, respeta validación nativa y del constructor, evita la repetición del atajo y muestra «Guardando formulario…» durante el envío. Se protege la salida con cambios; un envío cancelado no desactiva esa protección. Se recuperan los botones al volver mediante la caché de navegación.
- En menos de 1100 px, Publicar pasa antes de Nombre/pestañas, con detalles de cupo/fechas/vista previa desplegables y un botón Guardar adicional fijo abajo. Ambos botones usan el mismo formulario y nonce; el texto del estado inferior evita duplicar anuncios de lectores de pantalla. Sin JS, el panel conserva sus detalles abiertos y los botones POST funcionan.
- Vista previa en pestaña nueva mediante `admin-post.php?action=tbreg_preview_form`: solo GET, capacidad `tbreg_manage_forms`, nonce ligado al ID y formulario existente fuera de Papelera. Renderiza la **última versión guardada** con el renderer común, sin tema ni tokens de inscripción, con caché deshabilitada y `noindex`. Las opciones dependientes funcionan; submit está deshabilitado y el JS de vista previa impide envío, cámara y renovación de tokens. No se altera el envío de formularios públicos.
- **`TBREG_RUN_INTEGRATION=1`: 148 pruebas PHP / 1.426 aserciones**, sin omisiones, incluidas dos pruebas nuevas de autorización/render de vista previa. **38 pruebas DOM**, todas correctas: cuatro nuevas sobre panel, resúmenes, cambios/salida/recuperación de navegación y atajos/validación. WPCS/PHPCompatibility: 86 archivos sin errores ni avisos. Sintaxis correcta en los seis PHP y dos JS/CJS modificados o añadidos.
- Chrome real sobre el formulario ID 5: barra Publicar visible al desplazarse, con borde superior a 46 px y Guardar dentro de la pantalla; también disponible desde Configuración y Notificaciones. A 1400, 1024, 782 y 375 px, `scrollWidth` coincide con el viewport. A 375 px, tarjeta estática compacta y barra inferior fija hasta el borde de la pantalla; los detalles permiten acceder a Vista previa. [Capturas](qa/editor-ux/despues/ux-2/) e [informe](qa/editor-ux/despues/ux-2/verificacion.json).
- Guardado real con **Cmd+S** sobre un borrador temporal: título actualizado, dos campos conservados, aviso «Formulario guardado.» y estado de último guardado. El borrador de QA se eliminó al terminar. El formulario ID 5 no se guardó ni publicó; su revisión se comprobó antes/después. Vista previa real: Japonés → Básico → Básico 1, sin tokens de envío y con Inscribirme deshabilitado.
- Activación, desactivación y reactivación con `WP_DEBUG=1`, sin avisos del plugin y con checksums/contadores de las tres tablas conservados. Versión 0.3.3 para invalidar caché de assets. Sin cambios de esquema, dependencias, JSON ni `wp-config.php`. UX-3 queda pendiente: la paleta existente permanece hasta esa fase.

Notas de revisión de UX-2 (2026-10-15) — resueltas en UX-3:
- **Enlace «Cambiar» ambiguo:** en la barra Publicar está junto a «Hora del sitio: +00:00», así que parece que cambia la zona horaria, pero lleva a Configuración → Cupo. Moverlo junto a «Abre/Cierra» y, si la zona es UTC, agregar un enlace aparte «Configurar zona horaria» hacia `options-general.php#timezone_string`.
- **Guardar duplicado en móvil:** al cargar la página se ven a la vez el botón de la tarjeta Publicar y el de la barra inferior fija. Mostrar la barra inferior solo cuando el botón de la tarjeta sale de la pantalla (`IntersectionObserver`).
- **Paleta temporalmente estrecha:** con la barra lateral, a 1400 px la pantalla tiene 3 columnas y los botones de la paleta ocupan hasta 3 líneas («+ Fotografía (archivo o cámara)»). Es esperado hasta que UX-3 la reemplace por el panel de inserción; no hace falta corregirlo antes.

Notas de UX-3:
- Eliminada la paleta izquierda. El lienzo tiene contador de campos activos, Plantillas, Expandir/Contraer todo y estado vacío con cuatro plantillas. Elegir o buscar controles de interfaz no marca el formulario como modificado; aplicar una plantilla mantiene la confirmación y el archivo histórico existentes.
- Catálogo único con los 16 tipos en cinco grupos, búsqueda por nombre sin distinguir tildes y panel en la posición elegida: «+ Agregar campo» al final y «+» antes de cada tarjeta. En escritorio se muestran con hover/foco; en pantallas pequeñas o sin hover permanecen visibles para poder tocarlos. El nuevo campo queda abierto y con foco en Etiqueta.
- Flechas, Inicio/Fin, Enter y Escape permiten recorrer y cerrar el catálogo; Escape devuelve el foco al disparador. El panel tiene nombre accesible y los botones declaran `aria-expanded`/`aria-controls`. Una búsqueda sin resultados se anuncia y Enter no envía accidentalmente el formulario.
- Tarjetas cerradas con tipo, obligación, ancho, roles de correo/documento principal, padres de las opciones y cantidad de opciones. Cambiar etiquetas, roles, anchos u opciones actualiza los resúmenes, incluidas las etiquetas de los padres. Los textos se insertan con `textContent`; los campos Título o separador tienen franja sin caja y título en mayúsculas visuales.
- Menú `⋯` disponible con la tarjeta cerrada: Duplicar, Subir, Bajar y Eliminar. Tiene nombre, controles asociados y navegación con flechas/Inicio/Fin/Escape; Tab sale del menú y el foco se conserva tras mover. Eliminar campos usados conserva el archivo histórico y su confirmación.
- Duplicar realiza una copia profunda, asigna una clave nueva, conserva opciones, predeterminados, validación y referencias a los padres existentes; mantiene a los hijos del original vinculados al original. El duplicado no hereda el rol principal para evitar conflictos de unicidad, ni el bloqueo de un campo con inscripciones. Se anuncia la duplicación y se enfoca Etiqueta.
- Revisiones de UX-2: «Cambiar fechas» está junto a Abre/Cierra y enfoca Apertura en Configuración. UTC muestra un enlace independiente a Ajustes → Generales → Zona horaria, solo para quien tenga `manage_options`. `IntersectionObserver` oculta Guardar inferior mientras el botón principal es visible; si no existe esa API, conserva la barra como alternativa de guardado.
- **`TBREG_RUN_INTEGRATION=1`: 149 pruebas PHP / 1.436 aserciones**, sin omisiones; **45 pruebas DOM**, todas correctas. Una prueba PHP nueva cubre catálogo y permisos/configuración de zona horaria; siete DOM nuevas cubren inserción, buscador/teclado, duplicación, resúmenes/separadores, menú, plantillas y barra inferior. La suite PHP también recibió `TBREG_EDITOR_DOM_FIELDS` con el JSON producido por JavaScript: conserva tipos, orden y claves. La prueba dejó de asumir que el catálogo visual y `Field::TYPES` ordenaban Fotografía igual y comprueba el orden enviado realmente.
- WPCS/PHPCompatibility: **86 archivos** sin errores ni avisos. Sintaxis correcta en cuatro PHP y dos JS/CJS modificados. Activación, desactivación y reactivación con `WP_DEBUG=1`, sin avisos del plugin y con checksums/contadores de las tres tablas conservados.
- Chrome sobre el formulario ID 5: se leen Curso, Nivel y Subnivel con sus obligatorios, anchos y dependencias sin abrir tarjetas; buscar no marca cambios. Se revisaron **1400, 1024, 782 y 375 px**, sin desbordes horizontales. A 375 px se comprobó que Guardar inferior se oculta al ver el principal y reaparece al salir de la pantalla. [Capturas](qa/editor-ux/despues/ux-3/) e [informe](qa/editor-ux/despues/ux-3/verificacion.json).
- QA real de escritura sobre un borrador temporal de idiomas: inserción **con teclado** entre Nivel y Subnivel, duplicación de Subnivel mediante el menú y separador agregado **con mouse** antes de los datos del estudiante. Cmd+S guardó **21 campos**; la lectura posterior desde la base de datos confirmó claves originales conservadas, claves nuevas únicas, posición intermedia y opciones/dependencias idénticas en el duplicado. El borrador se eliminó al terminar; el formulario ID 5 conserva su revisión.
- Versión **0.3.4** para invalidar caché de CSS/JS. Sin cambios del formato JSON, tablas, dependencias, formulario público ni `wp-config.php`. UX-4 queda pendiente: la tarjeta abierta y la edición compacta de opciones se implementarán en esa fase.

Notas de revisión de UX-3 (2026-10-15) — resueltas en UX-4:
- **Flecha de la tarjeta superpuesta al botón `⋯`:** en todas las tarjetas cerradas, el indicador `⌄` del `summary` asoma por la esquina inferior izquierda del botón `⋯` (se ve en `lista-1400.png` y `separador-1400.png`). Separar ambos elementos: reservar espacio para la flecha a la derecha del menú o integrarla en el mismo grupo de acciones.
- **Botón `⋯` demasiado pesado:** con borde y unos 54×40 px, repetido en 18 tarjetas, se ve más que la etiqueta del campo. Usar un botón de icono sin borde (`.button-link` o estilo similar, unos 32×32 px) que muestre el borde solo con hover y foco, manteniendo un área táctil de al menos 32 px en móvil.


Notas de UX-4:
- Tarjeta abierta con pestañas internas **General / Opciones / Validación / Avanzado**, según el tipo. General conserva Etiqueta como primer input y agrupa Tipo, Ayuda, Placeholder y el interruptor Obligatorio. Los límites y el patrón están en Validación; ancho, rol, clases, parámetro oculto e identificador de solo lectura están en Avanzado. Copiar identificador tiene anuncio independiente y alternativa manual si falla el portapapeles. Las pestañas internas admiten flechas, Inicio/Fin y no interfieren con las tres pestañas principales.
- Opciones en filas compactas: etiqueta editable, valor guardado como texto con lápiz y controles de orden/eliminación dentro de un desplegable nativo. Editar un valor conserva la remisión de predeterminados y reglas dependientes; se actualizan los editores de sus hijos. Las advertencias de histórico y el orden con mouse/teclado permanecen disponibles.
- Selector de padre único arriba de la tabla, compartido por chips y matriz. Seleccionar otro padre **cambia la vista de edición**, sin imponer reglas ni borrar las de otros padres. «Siempre» elimina solo la restricción del padre seleccionado. Desmarcar todas sus opciones deja una condición incompleta, resaltada y bloqueada al guardar. Las condiciones adicionales siguen disponibles en el menú de cada opción, con resumen de AND entre padres y OR entre valores; la matriz muestra también el resumen de las otras condiciones. Se respeta el máximo existente de ocho padres por opción.
- La matriz utiliza los mismos objetos de opciones que los chips; cambiar de vista no normaliza ni reescribe `when`. Flechas e Inicio/Fin recorren sus casillas sin cambiar selecciones. En móvil, la matriz tiene desplazamiento propio, región accesible y foco visible, sin ensanchar la página.
- «Pegar varias…» acepta `Etiqueta` o `Etiqueta | valor`, con generación del valor solo para líneas sin valor explícito. La operación es atómica: rechaza texto vacío, valores duplicados, líneas ambiguas y más de 500 opciones; conserva opciones originales y agrega las nuevas sin condiciones. Escribir el borrador del pegado no marca cambios hasta aplicarlo.
- Revisiones de UX-3 resueltas: menú `⋯` de **32×32 px**, sin borde permanente, con borde/foco al interactuar. Se reserva espacio independiente para la flecha de la tarjeta, evitando la superposición en tarjetas abiertas y cerradas.
- **`TBREG_RUN_INTEGRATION=1`: 150 pruebas PHP / 1.442 aserciones**, sin omisiones, incluido el JSON generado por JavaScript. **52 pruebas DOM**, todas correctas: siete nuevas sobre secciones/copia, pegado atómico, matriz/chips, condiciones múltiples, reglas incompletas, valores estables y recorrido sin cambios. Una integración nueva guarda el esquema canónico de idiomas, consume su ida y vuelta por el DOM y compara campos y ajustes tras persistirlos. WPCS/PHPCompatibility: **86 archivos** sin errores ni avisos; sintaxis de tres PHP y dos JS/CJS correcta.
- Chrome, formulario ID 5: ocho opciones de «Subnivel / módulo» en filas de **45 px**; la tarjeta completa en Opciones mide **777,8 px** a 1400 px y la vista matriz **747,4 px**. Se revisaron **1400, 1024, 782 y 375 px**, sin desbordes de página. Se verificaron pestañas con teclado y navegación de casillas en la matriz. [Capturas](qa/editor-ux/despues/ux-4/) e [informe](qa/editor-ux/despues/ux-4/verificacion.json).
- QA real de guardado con **Cmd+S** en una copia temporal del formulario 5: después de recorrer tabla/matriz sin editar, los JSON `fields` y `settings` quedaron **idénticos byte a byte** en la base de datos. Después se pegaron dos opciones, se configuró una regla desde chips y otra desde la matriz, y se guardaron las dos correctamente; las 18 claves y las ocho opciones originales se conservaron. El borrador temporal se eliminó; la revisión del formulario ID 5 permanece intacta.
- Activación, desactivación y reactivación con `WP_DEBUG=1`: sin avisos del plugin y con checksums/contadores de las tres tablas conservados. Versión **0.3.5** para invalidar caché de assets. Sin cambios de esquema, formato JSON, dependencias, formulario público ni `wp-config.php`. UX-5 queda pendiente.

Notas de revisión de UX-4 (2026-10-15) — resueltas en UX-6:
- **«Siempre» junto a las opciones del padre:** en la tabla, «Siempre» se ve como una casilla más al lado de Básico/Intermedio…, como si pudiera combinarse con ellas. Diferenciarlo visualmente (separador o estilo de interruptor) y, si «Siempre» está activo, mostrar las demás desactivadas, de modo que se entienda que es excluyente.
- **Columna «Otras condiciones» con el texto «Siempre»:** en la matriz, cuando una opción no tiene condiciones por otros campos, la columna dice «Siempre», lo que se confunde con la columna «Siempre» del padre actual. Mostrar «—» o «Ninguna».

Decisiones previas de UX-5 (2026-10-15):
- El esquema existente usa `redirect_url` no vacío para activar la redirección y no contiene un modo separado. Para mantener el JSON y un comportamiento coherente, la selección temporal «Mostrar mensaje / Redirigir» se envía como control de interfaz fuera de `settings`: guardar «Mostrar mensaje» vacía `redirect_url`. Alternar antes de guardar conserva la URL en el editor; el mensaje de éxito siempre se conserva. Esta excepción a la conservación del ajuste oculto se explica junto al selector. Cupos/lista de espera, PDF y notificaciones sí conservan sus valores al ocultarse, incluso después de guardar.
- El esquema del aviso al administrador solo contiene `enabled`, `to` y `subject`; no se agrega un mensaje nuevo en UX-5. «Insertar dato» se ofrece en ese asunto y en el asunto/mensaje de confirmación existentes. El envío sigue pendiente de Fase 6 y se informa arriba de las tarjetas.
- Las dos notas de revisión sobre «Siempre» permanecen para UX-6.


Notas de UX-5:
- Configuración se organiza en cinco tarjetas: **Información del evento, Inscripciones, Después de inscribirse, Comprobante en PDF y Privacidad y seguridad**. Cupo usa «0 = ilimitado» como ayuda asociada. La nota de zona horaria está en Inscripciones y ofrece el enlace a Ajustes → Generales para UTC, con comprobación de `manage_options`.
- Los ajustes booleanos usan interruptores con texto a la derecha. Lista de espera solo se muestra con cupo mayor que cero; el texto del PDF, solo con PDF activo; los campos de notificación, solo con su aviso activo. Ocultarlos mantiene sus valores habilitados para el POST. Cambiar cupos, PDF, roles o plantillas actualiza la interfaz; elegir duplicados por correo sin un campo activo con rol Correo principal muestra un aviso no bloqueante.
- «Mostrar mensaje / Redirigir a una página» parte del `redirect_url` existente, sin agregar propiedades al JSON. Alternar conserva el borrador de URL en memoria; guardar «Mostrar mensaje» elimina explícitamente la URL, conservando el mensaje de éxito y los demás ajustes. El comportamiento se explica junto al selector. La URL es obligatoria solo para redirigir y se valida también en el servidor. Sin el nuevo control POST se conserva la compatibilidad con guardados anteriores. El panel PDF aclara la prioridad ya existente del comprobante sobre la redirección automática.
- Notificaciones tiene dos tarjetas, **Aviso al administrador** y **Confirmación al inscrito**, precedidas por el aviso visible de que el envío de correos llegará en una próxima versión. Se conservan únicamente los atributos existentes: el administrador tiene destinatarios y asunto; la confirmación tiene asunto y mensaje. UX-5 no implementa ni envía correos.
- «Insertar dato ▾» está junto a los tres controles de asunto/mensaje existentes. El selector presenta campos activos por su etiqueta y los datos generales `{event_name}`, `{entry_id}` y `{all_fields}`; excluye títulos, fotografías y campos archivados. Consulta las etiquetas y claves actuales al abrir, admite búsqueda sin tildes, flechas, Inicio/Fin, Enter y Escape, y devuelve el foco al cerrar. Inserta `{field:KEY}` en la selección o posición del cursor, conserva el texto de alrededor y anuncia el resultado en `aria-live`; abrir o buscar no marca cambios.
- Chrome detectó un cierre reentrante por `focusout` al ocultar/mover el selector, distinto del comportamiento inicial de jsdom. Se corrigió la reentrada y se añadió una prueba de regresión que reproduce ese evento: la inserción devuelve el foco al control y conserva su anuncio, tanto con mouse como con teclado.
- **`TBREG_RUN_INTEGRATION=1`: 152 pruebas PHP / 1.461 aserciones**, sin omisiones, con los JSON de campos y ajustes producidos por el DOM. **60 pruebas DOM**, todas correctas: ocho nuevas sobre tarjetas/valores ocultos, acción posterior, roles y duplicados, selección/cursor, búsqueda y teclado, catálogo dinámico, POST de notificaciones apagadas y cierre por blur. Dos integraciones nuevas verifican conservación de ajustes y ausencia de propiedades nuevas, acción posterior y validación, así como la persistencia de datos insertados con notificaciones apagadas. WPCS/PHPCompatibility: **86 archivos** sin errores ni avisos; sintaxis correcta en cuatro PHP y dos JS/CJS modificados.
- Chrome: Configuración y Notificaciones revisadas a **1400, 1024, 782 y 375 px**, con `scrollWidth` igual al viewport. Selector de datos revisado con búsqueda/Enter en escritorio y búsqueda/Escape en móvil; foco y anuncios comprobados. [Capturas](qa/editor-ux/despues/ux-5/) e [informe](qa/editor-ux/despues/ux-5/verificacion.json). No se descartaron avisos ni se modificaron ajustes de otros plugins.
- QA real con **Cmd+S** en borrador temporal: alternar cupo, PDF, avisos y acción posterior hasta su estado original conservó `fields` y `settings` **idénticos byte a byte** en base de datos. Se insertaron un campo, el nombre del evento y todos los datos en los tres controles de notificaciones; se apagaron ambos avisos y se guardó «Mostrar mensaje». La lectura posterior confirmó los tokens, el texto de PDF y la lista de espera conservados, y solo la URL eliminada. Los 18 campos y la revisión del formulario ID 5 permanecen intactos; el borrador temporal se eliminó.
- Activación, desactivación y reactivación con `WP_DEBUG=1`: sin avisos del plugin, checksums y contadores de las tres tablas conservados. Versión **0.3.6** para invalidar caché. Sin cambios de tablas, formato JSON, dependencias, formulario público ni `wp-config.php`. **UX-6 queda pendiente**, incluidas las dos notas de revisión sobre «Siempre».

Notas de revisión de UX-5 (2026-10-15) — resueltas en UX-6:
- **Texto técnico bajo «Mensaje de éxito»:** «Puedes alternar sin perder la URL antes de guardar. Al guardar «Mostrar mensaje», se elimina la redirección.» aparece aunque el formulario nunca haya tenido URL de redirección (caso del formulario 5). Mostrarlo solo cuando exista una URL guardada o escrita, y redactarlo para usuarios no técnicos, p. ej.: «Si guardas con «Mostrar mensaje», se quitará la redirección a {url}».
- **Conteo de aserciones:** en la revisión local salen 1.460 aserciones frente a las 1.461 reportadas (en UX-4 también hubo una de diferencia). Todas las pruebas pasan; identificar qué aserción depende del entorno (permisos, zona horaria o fixtures) y documentarlo, para que los conteos sean reproducibles.


Notas de UX-6:
- «Siempre» es un **interruptor separado por una línea**, con `role="switch"`, `aria-checked` y relación con sus opciones. Al activarlo se elimina únicamente la condición del padre que se está editando y se desactivan sus casillas. Al apagarlo se habilitan para elegir; una condición vacía sigue impidiendo guardar hasta seleccionar al menos una opción. La matriz aplica la misma exclusión y muestra **«Ninguna»** en Otras condiciones cuando no hay reglas de otros padres. Las reglas AND de otros campos se conservan.
- Se conserva el padre elegido al cambiar entre tabla y matriz, incluso después de eliminar una restricción. Las flechas de la matriz saltan casillas desactivadas; Inicio/Fin van a la primera/última celda disponible de la fila. El indicador de apertura de tarjetas se dibuja con CSS, sin texto decorativo que pueda anunciarse como contenido.
- El aviso de redirección solo aparece al seleccionar Mostrar mensaje cuando hay una dirección guardada o escrita: «Si guardas con «Mostrar mensaje», se quitará la redirección a {url}». Se inserta como texto y conserva como referencia la URL guardada si se vacía el borrador. En el formulario ID 5, que no tiene URL, no aparece el aviso.
- **Conteo reproducible:** la diferencia de una aserción de UX-4/UX-5 provenía de `test_editor_conditional_options_roundtrip_preserves_canonical_json`, que comparaba el JSON únicamente si existía `dom-roundtrip.json`. Se reprodujo antes del cambio: **5 aserciones sin fixture / 6 con fixture**. La comparación ahora se ejecuta también sobre el esquema canónico de PHP cuando no hay fixture. No dependía de permisos ni zona horaria. Ambas ejecuciones completas dan **152 pruebas / 1.461 aserciones**, sin omisiones y siempre con `TBREG_RUN_INTEGRATION=1`. Se conservan [salida sin fixtures](qa/editor-ux/despues/ux-6/php-sin-fixtures.txt), [salida con DOM](qa/editor-ux/despues/ux-6/php-con-dom.txt) e [instrucciones](qa/editor-ux/despues/ux-6/README.md).
- **65 pruebas JavaScript: 64 DOM + 1 de contraste**, todas correctas. Las cuatro DOM nuevas cubren exclusión de Siempre sin borrar otros padres, estados y flechas de matriz, aviso contextual de redirección y referencias ARIA tras insertar/duplicar/cambiar vistas. Permanecen verdes las pruebas previas de inserción intermedia, duplicación, pegado múltiple, matriz ↔ JSON, controles dependientes, cambios sin guardar, salida protegida y guardado. La prueba de contraste lee los colores del CSS real: ratios de **5,15:1 a 8,81:1**, superiores a 4,5:1 para texto normal. [Resultados de contraste](qa/editor-ux/despues/ux-6/contraste.json).
- Chrome, formulario ID 5: **24 capturas JPEG** de Campos, Configuración, Notificaciones, tarjeta abierta, matriz y panel de inserción a **1400, 1024, 782 y 375 px**. Las imágenes se verificaron contra sus dimensiones reales; las 24 vistas tienen `scrollWidth` igual al viewport. La matriz conserva desplazamiento interno en móvil. Subnivel sigue midiendo **777,8 px** en escritorio, con filas de **45 px**. [Capturas e informe](qa/editor-ux/despues/ux-6/) y [medidas por captura](qa/editor-ux/despues/ux-6/capturas.json).
- Revisión de teclado real: Inicio/Fin de pestañas, catálogo con búsqueda/flechas/Enter/Escape, menú `⋯` con flechas/Enter/Escape, duplicación, pegado, interruptor Siempre con Espacio, matriz con flechas y Cmd+S. Copiar anuncia «Shortcode copiado.»; duplicar, insertar y guardar anuncian sus estados; Escape devuelve el foco al disparador. La revisión DOM real del editor encontró **323 IDs únicos**, ninguna referencia ARIA rota y ningún error de consola. Se conservan [auditoría](qa/editor-ux/despues/ux-6/accesibilidad-dom.json) y [verificación](qa/editor-ux/despues/ux-6/verificacion.json).
- QA real en borrador temporal: alternar Siempre, restaurar Básico, probar la URL contextual y guardar con Cmd+S conservó `fields` y `settings` **idénticos byte a byte**. Después se insertó un campo entre Nivel y Subnivel y se duplicó Subnivel por teclado; se pegó una opción y se configuró su dependencia. El guardado persistió **20 campos**, claves originales conservadas, claves nuevas únicas, orden intermedio y dependencias correctas. El borrador temporal se eliminó; el formulario ID 5 mantiene su revisión y sus 18 campos.
- WPCS/PHPCompatibility: **86 archivos** sin errores ni avisos; sintaxis de cuatro PHP y tres JS/CJS correcta. Activación, desactivación y reactivación con `WP_DEBUG=1`: sin avisos del plugin y con checksums/contadores de las tres tablas conservados. Versión **0.3.7** para invalidar caché de assets. **Rediseño UX-0 a UX-6 completado**, sin cambios del esquema, formato JSON, dependencias, formulario público ni `wp-config.php`. El envío de correos permanece pendiente de la Fase 6 del plan general.
