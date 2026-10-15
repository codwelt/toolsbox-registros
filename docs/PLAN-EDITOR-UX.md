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
| UX-2 | ⬜ Pendiente | Barra lateral y guardado. |
| UX-3 | ⬜ Pendiente | Lista e inserción de campos. |
| UX-4 | ⬜ Pendiente | Tarjeta abierta y opciones. |
| UX-5 | ⬜ Pendiente | Configuración y Notificaciones. |
| UX-6 | ⬜ Pendiente | Responsive, accesibilidad y pruebas del rediseño completo. |

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
