# QA del editor UX-6 — 0.3.7

WordPress local de desarrollo, PHP 8.2, Node 20 y jsdom de desarrollo. Sin cambios del formulario ID 5 ni dependencias de runtime nuevas.

## Conteo de aserciones

Antes de UX-6, `test_editor_conditional_options_roundtrip_preserves_canonical_json` ejecutaba una comparación adicional solo si encontraba `dom-roundtrip.json`: 5 aserciones sin ese archivo y 6 con él. Esto explica la diferencia entre 1.460 y 1.461 en UX-5, y la diferencia de una en UX-4. No era una variación de permisos ni de zona horaria.

Ahora compara siempre el JSON: utiliza el original canónico de PHP cuando no hay fixture y el generado por JavaScript cuando sí existe. La suite completa tiene **152 pruebas / 1.461 aserciones** en ambos casos, sin omisiones. Las salidas de ambas ejecuciones están en `php-sin-fixtures.txt` y `php-con-dom.txt`.

Desde la carpeta del plugin, con los ejecutables de PHP y Node disponibles en PATH:

```sh
TBREG_RUN_INTEGRATION=1 php vendor/bin/phpunit
```

Para reproducir también el recorrido PHP → DOM → guardado PHP, ajusta la ruta de jsdom al entorno de desarrollo. En esta máquina se utilizó `/Users/user/Proyectos/web-firmy-module/node_modules/jsdom`. No se instala en el plugin ni se empaqueta.

```sh
export TBREG_RUN_INTEGRATION=1
export TBREG_JSDOM_PATH=/Users/user/Proyectos/web-firmy-module/node_modules/jsdom
tbreg_qa_dir="$(mktemp -d /private/tmp/tbreg-editor-ux6.XXXXXX)"
export TBREG_EDITOR_FIXTURE_DIR="$tbreg_qa_dir/editor"
export TBREG_FRONTEND_FIXTURE_DIR="$tbreg_qa_dir/frontend"
export TBREG_ENTRIES_FIXTURE_DIR="$tbreg_qa_dir/entries"
export TBREG_EXPORT_FIXTURE_DIR="$tbreg_qa_dir/export"
export TBREG_LANGUAGE_FIXTURE_DIR="$tbreg_qa_dir/languages"
mkdir -p "$TBREG_EDITOR_FIXTURE_DIR" "$TBREG_FRONTEND_FIXTURE_DIR" "$TBREG_ENTRIES_FIXTURE_DIR" "$TBREG_EXPORT_FIXTURE_DIR" "$TBREG_LANGUAGE_FIXTURE_DIR"
php vendor/bin/phpunit
node --test tests/JavaScript/*.test.cjs
TBREG_EDITOR_DOM_FIELDS="$TBREG_EDITOR_FIXTURE_DIR/dom-fields.json" php vendor/bin/phpunit
php vendor/bin/phpcs
```

Resultados: ambas ejecuciones PHP, **152 / 1.461**; JavaScript, **65 pruebas** (64 DOM y 1 contraste); WPCS/PHPCompatibility, **86 archivos sin errores ni avisos**. Las tablas de integración usan prefijos aleatorios y se eliminan al terminar.

## Capturas y accesibilidad

`{campos,configuracion,notificaciones,tarjeta,matriz,insercion}-{1400,1024,782,375}.jpg` son las **24 capturas principales**, de 1.000 px de alto. Son JPEG originales del navegador; `capturas.json` contiene las dimensiones reales del archivo, las del viewport, el ancho de página y el estado de guardado. Todas las vistas conservan el ancho del viewport. La matriz puede desplazarse dentro de su región en móvil.

`siempre-activo-1400.jpg` y `aviso-redireccion-1400.jpg` muestran los casos adicionales en el borrador temporal de QA. Este borrador se eliminó al terminar. Las capturas conservan los avisos existentes de otros plugins; no se cambiaron sus ajustes.

`accesibilidad-dom.json` registra 323 IDs únicos, ninguna referencia ARIA rota y los colores aplicados en Chrome. Se revisaron foco y teclado de pestañas, inserción, menú, duplicación, Siempre, matriz, copia y guardado. Las pruebas DOM cubren además salida con cambios, validación y anuncios de estado.

`contraste.json` mide los seis pares de color del CSS del editor, de **5,15:1 a 8,81:1**, por encima del mínimo 4,5:1 para texto normal. La comprobación se mantiene como prueba automática. Estos resultados corresponden a los chips, insignias y estados incluidos en el informe.

`verificacion.json` resume las pruebas y el guardado real: JSON idéntico al alternar controles y restaurar su estado; 20 campos guardados tras insertar y duplicar con teclado, pegar una opción y configurar su dependencia; claves y ajustes conservados. El ID 5 mantiene su revisión. Activación, desactivación y reactivación con `WP_DEBUG=1` conservaron checksums y contadores de las tres tablas, sin avisos del plugin.

El rediseño del editor está completo. El envío de correos sigue pendiente de la Fase 6 del plan general.
