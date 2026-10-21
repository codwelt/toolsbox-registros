/** Pruebas DOM del constructor con el HTML y jQuery UI reales de WordPress. */
'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require(process.env.TBREG_JSDOM_PATH || 'jsdom');
const fixtureDir = process.env.TBREG_EDITOR_FIXTURE_DIR;
assert.ok(fixtureDir, 'Genera los fixtures PHP con TBREG_RUN_INTEGRATION=1 y TBREG_EDITOR_FIXTURE_DIR.');
const plugin = path.resolve(__dirname, '../..');
const wp = path.resolve(plugin, '../../..');
const html = fs.readFileSync(path.join(fixtureDir, 'editor.html'), 'utf8');
const config = fs.readFileSync(path.join(fixtureDir, 'builder-config.js'), 'utf8');
const source = fs.readFileSync(path.join(plugin, 'assets/admin/js/builder.js'), 'utf8');
function setup(fields = [], used = [], markup = html, beforeEval = () => {}) {
	const dom = new JSDOM(markup, { runScripts: 'outside-only', url: 'https://wordpress.test/wp-admin/' });
	const w = dom.window;
	w.confirm = () => true;
	w.HTMLElement.prototype.scrollIntoView = () => {};
	w.eval(config);
	w.tbreg_builder.fields = fields;
	w.tbreg_builder.used = used;
	for (const file of ['jquery/jquery.min.js', 'jquery/ui/core.min.js', 'jquery/ui/mouse.min.js', 'jquery/ui/sortable.min.js']) {
		w.eval(fs.readFileSync(path.join(wp, 'wp-includes/js', file), 'utf8'));
	}
	beforeEval(w); w.eval(source);
	const d = w.document;
	const state = () => JSON.parse(d.getElementById('tbreg-fields-json').value);
	const click = (root, label) => {
		const target = Array.from(root.querySelectorAll('button')).find(node => (node.getAttribute('aria-label') || node.textContent) === w.tbreg_builder.labels[label]);
		assert.ok(target, 'Botón ' + label); target.click();
	};
	const input = (root, label, value) => {
		const target = Array.from(root.querySelectorAll('label')).find(node => node.querySelector('span')?.textContent === w.tbreg_builder.labels[label])?.querySelector('input,select,textarea');
		assert.ok(target, 'Control ' + label); target.value = value;
		target.dispatchEvent(new w.Event(target.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true }));
		return target;
	};
	const submit = () => {
		const event = new w.Event('submit', { bubbles: true, cancelable: true });
		d.getElementById('tbreg-editor').dispatchEvent(event); return !event.defaultPrevented;
	};
	return { dom, w, d, state, click, input, submit };
}
const textField = { key: 'f_existing', type: 'text', label: 'Nombre', options: [], width: 'full', role: null, required: false, archived: false, default: '', validation: {} };
test('insertar en una posición intermedia abre Etiqueta y conserva claves y orden', () => {
	const { dom, w, d, state } = setup([textField, { ...textField, key: 'f_second', label: 'Segundo' }]);
	d.querySelector('[data-key="f_second"] .tbreg-insert-button').click();
	assert.equal(d.getElementById('tbreg-inserter').hidden, false); assert.equal(d.activeElement.id, 'tbreg-inserter-search');
	d.querySelector('[data-type="tel"]').click(); const saved = state();
	assert.deepEqual(saved.map(field => field.key === 'f_existing' || field.key === 'f_second' ? field.key : field.type), ['f_existing', 'tel', 'f_second']);
	assert.equal(d.getElementById('tbreg-inserter').hidden, true);
	assert.equal(d.activeElement.value, w.tbreg_builder.types.tel);
	assert.equal(d.activeElement.closest('.tbreg-field').dataset.key, saved[1].key);
	assert.equal(d.activeElement.closest('details').open, true); assert.match(d.getElementById('tbreg-field-status').textContent, /Campo agregado/);
	const reload = setup(saved); assert.deepEqual(reload.state(), saved); reload.dom.window.close(); dom.window.close();
});
test('buscador y flechas/Enter/Esc insertan sin enviar y sin ensuciar al buscar', () => {
	const { dom, w, d, state } = setup([textField]); const before = state();
	d.getElementById('tbreg-add-field').click(); const search = d.getElementById('tbreg-inserter-search');
	search.value = 'fotografia'; search.dispatchEvent(new w.Event('input', { bubbles: true }));
	assert.equal(d.querySelector('[data-type="photo"]').hidden, false); assert.equal(d.querySelector('[data-type="text"]').hidden, true);
	assert.notEqual(d.getElementById('tbreg-builder-status').textContent, w.tbreg_builder.labels.changed);
	search.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true, cancelable: true }));
	assert.equal(d.activeElement.dataset.type, 'photo');
	d.activeElement.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
	assert.equal(d.activeElement.id, 'tbreg-add-field'); assert.deepEqual(state(), before);
	d.getElementById('tbreg-add-field').click(); search.value = 'correo'; search.dispatchEvent(new w.Event('input', { bubbles: true }));
	const enter = new w.KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }); search.dispatchEvent(enter);
	assert.equal(enter.defaultPrevented, true); assert.equal(state().at(-1).type, 'email'); dom.window.close();
});
test('duplicar conserva opciones y dependencias con clave nueva y sin repetir roles', () => {
	const parent = { ...textField, key: 'f_parent', type: 'select', label: 'Nivel', options: [{ label: 'Básico', value: 'basico' }] };
	const child = { ...textField, key: 'f_child', type: 'select', label: 'Módulo', options: [{ label: 'Básico 1', value: 'b1', when: { f_parent: ['basico'] } }] };
	const { dom, d, state, click, input } = setup([parent, child, { ...textField, key: 'f_mail', type: 'email', role: 'email' }], ['f_child']);
	click(d.querySelector('[data-key="f_child"]'), 'duplicate'); const copy = state()[2];
	assert.notEqual(copy.key, 'f_child'); assert.deepEqual(copy.options, child.options); assert.equal(copy.archived, false);
	assert.equal(d.activeElement.closest('.tbreg-field').dataset.key, copy.key);
	input(d.querySelector('[data-key="' + copy.key + '"] .tbreg-option'), 'label', 'Nuevo módulo');
	assert.equal(state()[1].options[0].label, 'Básico 1'); assert.equal(state()[2].options[0].label, 'Nuevo módulo');
	click(d.querySelector('[data-key="f_mail"]'), 'duplicate'); assert.equal(state().at(-1).role, null);
	assert.equal(state().filter(field => field.role === 'email').length, 1);
	assert.match(d.getElementById('tbreg-field-status').textContent, /Campo duplicado/); dom.window.close();
});
test('resúmenes se actualizan por etiqueta, ancho, rol y dependencias; separadores y expandir', () => {
	const parent = { ...textField, key: 'f_parent', type: 'select', label: 'Nivel', required: true, width: 'half', options: [{ label: 'Básico', value: 'b' }] };
	const child = { ...textField, key: 'f_child', type: 'select', label: 'Módulo', options: [{ label: 'Uno', value: 'u', when: { f_parent: ['b'] } }] };
	const { dom, w, d, input } = setup([parent, child, { ...textField, key: 'f_heading', type: 'heading', label: 'Estudiante' }]);
	assert.equal(d.getElementById('tbreg-fields-count').textContent, '3');
	const chips = d.querySelector('[data-key="f_parent"] .tbreg-summary-chips').textContent;
	assert.ok(chips.includes('Obligatorio') && chips.includes('½') && chips.includes('1 opción'));
	assert.ok(d.querySelector('[data-key="f_heading"]').classList.contains('tbreg-field-heading'));
	d.getElementById('tbreg-expand-all').click(); assert.equal(d.querySelectorAll('.tbreg-field-details[open]').length, 3);
	assert.notEqual(d.getElementById('tbreg-builder-status').textContent, w.tbreg_builder.labels.changed);
	d.getElementById('tbreg-expand-all').click(); assert.equal(d.querySelectorAll('.tbreg-field-details[open]').length, 0);
	input(d.querySelector('[data-key="f_parent"]'), 'label', '<b>Nivel nuevo</b>');
	assert.ok(d.querySelector('[data-key="f_child"] .tbreg-summary-chips').textContent.includes('Depende de <b>Nivel nuevo</b>'));
	assert.equal(d.querySelector('.tbreg-summary-chips b'), null); dom.window.close();
});
test('menú de tarjeta cerrada admite flechas y Escape con foco visible en el disparador', () => {
	const { dom, w, d } = setup([textField]); const toggle = d.querySelector('.tbreg-menu-toggle');
	toggle.focus(); toggle.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true, cancelable: true }));
	assert.equal(d.activeElement.textContent, w.tbreg_builder.labels.duplicate);
	assert.equal(toggle.getAttribute('aria-expanded'), 'true'); assert.equal(d.querySelector('.tbreg-field-details').open, false);
	d.activeElement.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'End', bubbles: true, cancelable: true }));
	assert.equal(d.activeElement.textContent, w.tbreg_builder.labels.remove);
	d.activeElement.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
	assert.equal(d.activeElement, toggle); assert.equal(toggle.getAttribute('aria-expanded'), 'false'); dom.window.close();
});
test('plantillas del estado vacío y confirmación conservan los campos usados', () => {
	const { dom, w, d, state } = setup([textField], ['f_existing']);
	w.confirm = () => false; d.querySelector('[data-tbreg-template="languages"]').click(); assert.equal(state().length, 1);
	w.confirm = () => true; d.querySelector('[data-tbreg-template="languages"]').click();
	assert.equal(state().filter(field => !field.archived).length, 18); assert.equal(state().find(field => field.key === 'f_existing').archived, true);
	assert.equal(new Set(state().map(field => field.key)).size, state().length); dom.window.close();
});
test('Guardar inferior se oculta cuando el principal está visible y reaparece al salir', () => {
	let observed; let report;
	const { dom, d } = setup([], [], html, w => {
		w.IntersectionObserver = class { constructor(callback) { report = callback; } observe(node) { observed = node; } };
	});
	assert.equal(observed, d.querySelector('.tbreg-publish [data-tbreg-save]'));
	report([{ isIntersecting: true }]); assert.equal(d.querySelector('.tbreg-mobile-savebar').hidden, true);
	report([{ isIntersecting: false }]); assert.equal(d.querySelector('.tbreg-mobile-savebar').hidden, false); dom.window.close();
});
test('Publicar está fuera de las tres pestañas y el aviso de correo no bloquea', () => {
	const { dom, w, d, state, input } = setup([textField]);
	assert.equal(d.querySelectorAll('.tbreg-tabs [role="tab"]').length, 3);
	const status = d.querySelector('[name="tbreg_status"]');
	assert.equal(d.querySelectorAll('[name="tbreg_status"]').length, 1);
	for (const tab of d.querySelectorAll('.tbreg-tabs [role="tab"]')) { tab.click(); assert.equal(status.closest('[hidden]'), null); }
	status.value = 'published'; status.dispatchEvent(new w.Event('change', { bubbles: true }));
	assert.equal(d.getElementById('tbreg-email-warning').hidden, false);
	d.querySelector('[data-type="email"]').click();
	input(d.querySelector('.tbreg-field:last-child'), 'role', 'email');
	assert.ok(state().some(field => field.role === 'email'));
	assert.equal(d.getElementById('tbreg-email-warning').hidden, true);
	assert.equal(status.disabled, false); dom.window.close();
});
test('Cambiar enfoca Configuración y cupos/fechas conservan la hora local escrita', () => {
	const { dom, w, d } = setup();
	d.querySelector('[data-tbreg-settings-link]').click();
	assert.equal(d.activeElement.id, 'tbreg-opens-at'); assert.equal(d.getElementById('tbreg-panel-settings').hidden, false);
	const progress = d.getElementById('tbreg-capacity-progress'); progress.dataset.count = '2';
	const capacity = d.getElementById('tbreg-capacity'); capacity.value = '3'; capacity.dispatchEvent(new w.Event('input', { bubbles: true }));
	assert.equal(progress.hidden, false); assert.equal(progress.max, 3); assert.equal(progress.value, 2);
	capacity.value = '0'; capacity.dispatchEvent(new w.Event('input', { bubbles: true }));
	assert.equal(progress.hidden, true); assert.equal(d.getElementById('tbreg-capacity-summary').textContent, '∞');
	const opens = d.querySelector('[name="tbreg_settings[opens_at]"]'); opens.value = '2026-11-01T08:30'; opens.dispatchEvent(new w.Event('input', { bubbles: true }));
	assert.equal(d.getElementById('tbreg-opens-summary').textContent, '2026-11-01 08:30'); dom.window.close();
});
test('cambios sin guardar se anuncian, protegen la salida y se conservan si se cancela el envío', async () => {
	const { dom, w, d, input } = setup([textField]); const form = d.getElementById('tbreg-editor');
	const unload = () => { const event = new w.Event('beforeunload', { cancelable: true }); w.dispatchEvent(event); return event.defaultPrevented; };
	assert.equal(unload(), false);
	input(d.querySelector('.tbreg-field'), 'label', 'Nombre completo');
	assert.equal(unload(), true); assert.equal(d.getElementById('tbreg-builder-status').textContent, w.tbreg_builder.labels.changed);
	assert.equal(d.querySelector('[data-tbreg-save-status]').textContent, w.tbreg_builder.labels.changed);
	form.addEventListener('submit', event => event.preventDefault(), { once: true });
	form.dispatchEvent(new w.Event('submit', { bubbles: true, cancelable: true })); await new Promise(setImmediate);
	assert.equal(unload(), true); assert.equal(d.querySelector('[data-tbreg-save]').disabled, false);
	form.dispatchEvent(new w.Event('submit', { bubbles: true, cancelable: true })); await new Promise(setImmediate);
	assert.equal(unload(), false); assert.equal(d.getElementById('tbreg-builder-status').textContent, w.tbreg_builder.labels.saving);
	assert.equal(d.querySelector('[data-tbreg-save]').disabled, true);
	const restored = new w.Event('pageshow'); Object.defineProperty(restored, 'persisted', { value: true }); w.dispatchEvent(restored);
	assert.equal(unload(), true); assert.equal(d.querySelector('[data-tbreg-save]').disabled, false); dom.window.close();
});
test('Ctrl/Cmd+S usa el guardado con validación y no el diálogo del navegador', () => {
	const { dom, w, d } = setup([textField]); const form = d.getElementById('tbreg-editor'); let calls = 0;
	form.requestSubmit = button => { calls++; assert.equal(button, d.querySelector('[data-tbreg-save]')); };
	for (const modifier of ['ctrlKey', 'metaKey']) {
		const event = new w.KeyboardEvent('keydown', { key: 's', [modifier]: true, bubbles: true, cancelable: true }); d.dispatchEvent(event); assert.equal(event.defaultPrevented, true);
	}
	d.dispatchEvent(new w.KeyboardEvent('keydown', { key: 's', ctrlKey: true, repeat: true, bubbles: true, cancelable: true }));
	assert.equal(calls, 2);
	const capacity = d.getElementById('tbreg-capacity'); capacity.dispatchEvent(new w.Event('invalid', { cancelable: true }));
	assert.equal(d.getElementById('tbreg-panel-settings').hidden, false); dom.window.close();
});
test('shortcode permanece fuera de las pestañas y copia su valor sin enviar el formulario', async () => {
	const saved = fs.readFileSync(path.join(fixtureDir, 'editor-saved.html'), 'utf8');
	const { dom, w, d } = setup([], [], saved);
	const input = d.getElementById('tbreg-shortcode');
	const copy = d.querySelector('[data-tbreg-copy-target]');
	const status = d.getElementById('tbreg-copy-status');
	let copied; let submits = 0;
	Object.defineProperty(w.navigator, 'clipboard', { value: { writeText: async value => { copied = value; } } });
	d.getElementById('tbreg-editor').addEventListener('submit', () => { submits++; });
	assert.equal(input.readOnly, true);
	assert.equal(status.getAttribute('aria-live'), 'polite');
	for (const tab of d.querySelectorAll('.tbreg-tabs [role="tab"]')) {
		tab.click(); assert.equal(input.closest('[hidden]'), null);
		copy.focus(); copy.click(); await new Promise(setImmediate);
		assert.equal(copied, input.value);
		assert.match(copied, /^\[toolsbox_registro id="\d+"\]$/);
		assert.equal(status.textContent, w.tbreg_builder.labels.copied);
		assert.equal(d.activeElement, copy);
	}
	assert.equal(submits, 0); dom.window.close();
});
test('portapapeles rechazado o no disponible selecciona el shortcode para copia manual', async () => {
	for (const available of [true, false]) {
		const { dom, w, d } = setup([], [], fs.readFileSync(path.join(fixtureDir, 'editor-saved.html'), 'utf8'));
		if (available) Object.defineProperty(w.navigator, 'clipboard', { value: { writeText: async () => { throw new Error('denied'); } } });
		d.querySelector('[data-tbreg-copy-target]').click(); await new Promise(setImmediate);
		const input = d.getElementById('tbreg-shortcode');
		assert.equal(d.activeElement, input); assert.equal(input.selectionStart, 0); assert.equal(input.selectionEnd, input.value.length);
		assert.equal(d.getElementById('tbreg-copy-status').textContent, w.tbreg_builder.labels.copyManual);
		dom.window.close();
	}
});
test('copia del listado conserva el shortcode de la fila elegida', async () => {
	const { dom, w, d } = setup([], [], fs.readFileSync(path.join(fixtureDir, 'forms.html'), 'utf8'));
	const copied = []; Object.defineProperty(w.navigator, 'clipboard', { value: { writeText: async value => { copied.push(value); } } });
	const buttons = [...d.querySelectorAll('[data-tbreg-copy]')]; assert.ok(buttons.length >= 2);
	for (const button of buttons.slice(0, 2)) {
		button.click(); await Promise.resolve(); assert.equal(copied.at(-1), button.dataset.tbregCopy);
	}
	assert.notEqual(copied[0], copied[1]); dom.window.close();
});
test('slug editable permanece en el POST y actualiza su resumen sin interpretar HTML', () => {
	const { dom, w, d } = setup();
	const details = d.querySelector('.tbreg-slug-editor'); const input = d.getElementById('tbreg-slug');
	assert.equal(d.querySelectorAll('[name="tbreg_slug"]').length, 1);
	details.open = true; input.value = '<b>nuevo-slug</b>'; input.dispatchEvent(new w.Event('input', { bubbles: true }));
	assert.equal(d.getElementById('tbreg-slug-summary').textContent, '<b>nuevo-slug</b>');
	assert.equal(d.getElementById('tbreg-slug-summary').querySelector('b'), null);
	details.open = false; assert.equal(new w.FormData(d.getElementById('tbreg-editor')).get('tbreg_slug'), input.value);
	input.value = ''; input.dispatchEvent(new w.Event('input', { bubbles: true }));
	assert.equal(d.getElementById('tbreg-slug-summary').textContent, w.tbreg_builder.labels.slugAutomatic);
	dom.window.close();
});
test('plantilla de idiomas regenera claves y conserva relaciones editables y PDF',()=>{
 const {dom,w,d,state}=setup();d.getElementById('tbreg-template').value='languages';d.getElementById('tbreg-apply-template').click();const fields=state();const keys=new Set(fields.map(field=>field.key));
 assert.equal(fields.length,18);assert.equal(keys.size,18);assert.equal(d.querySelector('[name="tbreg_settings[pdf_enabled]"]').checked,true);
 for(const field of fields)for(const option of field.options||[])for(const parent of Object.keys(option.when||{}))assert.ok(keys.has(parent));
 const module=fields.find(field=>field.label==='Subnivel / módulo');const card=d.querySelector('[data-key="'+module.key+'"]');assert.ok(card.querySelector('.tbreg-option-conditions input[type="checkbox"]'));
 const firstRule=card.querySelector('.tbreg-option-conditions');firstRule.querySelector('input[type="checkbox"]').click();assert.ok(Object.values(state().find(field=>field.key===module.key).options[0].when)[0].length===0);dom.window.close();
});
test('tipos nativos, opciones y orden: serialización y recarga conservan claves', () => {
	const { dom, w, d, state, input, click, submit } = setup();
	for (const type of Object.keys(w.tbreg_builder.types)) d.querySelector('[data-type="' + type + '"]').click();
	assert.equal(state().length, Object.keys(w.tbreg_builder.types).length);
	assert.equal(new Set(state().map(field => field.key)).size, Object.keys(w.tbreg_builder.types).length);
	for (const field of state().filter(field => ['select', 'radio', 'checkbox'].includes(field.type))) {
		let card = d.querySelector('[data-key="' + field.key + '"]');
		input(card.querySelector('.tbreg-option'), 'label', 'Bogotá');
		click(card, 'addOption'); card = d.querySelector('[data-key="' + field.key + '"]');
		input(card.querySelector('.tbreg-option:last-child'), 'label', 'Medellín');
		const options = card.querySelector('.tbreg-options');
		options.insertBefore(options.lastElementChild, options.firstElementChild);
		w.jQuery(options).sortable('instance').options.update();
		assert.deepEqual(state().find(item => item.key === field.key).options.map(option => option.value), ['medellin', 'bogota']);
	}
	const list = d.getElementById('tbreg-fields');
	list.replaceChildren(...Array.from(list.children).reverse());
	w.jQuery(list).sortable('instance').options.update();
	assert.deepEqual(state().map(field => field.type), Object.keys(w.tbreg_builder.types).reverse());
	assert.equal(submit(), true);
	const saved = state();
	fs.writeFileSync(path.join(fixtureDir, 'dom-fields.json'), JSON.stringify(saved));
	const reloaded = setup(saved);
	assert.deepEqual(reloaded.state(), saved);
	assert.equal(reloaded.d.querySelectorAll('.tbreg-field').length, Object.keys(w.tbreg_builder.types).length);
	reloaded.dom.window.close(); dom.window.close();
});
test('opciones nuevas generan valores y los valores editados son estables', () => {
	const { dom, d, state, input } = setup();
	d.querySelector('[data-type="select"]').click();
	const option = d.querySelector('.tbreg-option');
	input(option, 'label', 'Bogotá Centro');
	assert.equal(state()[0].options[0].value, 'bogota-centro');
	assert.equal(state()[0].default, '');
	input(option, 'value', 'capital'); input(option, 'label', 'Distrito capital');
	assert.equal(state()[0].options[0].value, 'capital');
	assert.equal(state()[0].options[0].auto, false);
	dom.window.close();
});
test('orden por teclado y edición de etiqueta mantienen el identificador', () => {
	const { dom, d, state, click, input } = setup([textField, { ...textField, key: 'f_second', label: 'Segundo' }]);
	input(d.querySelector('.tbreg-field'), 'label', 'Nombre completo');
	click(d.querySelector('.tbreg-field'), 'down');
	assert.deepEqual(state().map(field => field.key), ['f_second', 'f_existing']);
	assert.equal(state()[1].label, 'Nombre completo');
	assert.ok(['Subir', 'Bajar'].includes(d.activeElement.textContent));
	assert.equal(d.activeElement.closest('.tbreg-field').dataset.key, 'f_existing');
	dom.window.close();
});
test('campos usados se archivan, bloquean tipo y se conservan al recargar', () => {
	const { dom, d, state, click, w } = setup([textField], ['f_existing']);
	const type = Array.from(d.querySelectorAll('label')).find(node => node.querySelector('span')?.textContent === w.tbreg_builder.labels.type).querySelector('select');
	assert.equal(type.disabled, true);
	click(d.querySelector('.tbreg-field'), 'remove');
	assert.equal(state()[0].archived, true);
	assert.equal(d.querySelectorAll('.tbreg-field').length, 0);
	assert.equal(d.getElementById('tbreg-archived').hidden, false);
	const reload = setup(state(), ['f_existing']);
	assert.equal(reload.state()[0].key, 'f_existing');
	reload.dom.window.close(); dom.window.close();
});
test('opciones repetidas bloquean envío y enfocan el campo', () => {
	const { dom, d, input, click, submit } = setup();
	d.querySelector('[data-type="radio"]').click();
	input(d.querySelector('.tbreg-option'), 'label', 'Bogotá');
	click(d.querySelector('.tbreg-field'), 'addOption');
	input(d.querySelector('.tbreg-option:last-child'), 'label', 'Bogotá');
	d.querySelector('[data-tab="settings"]').click();
	assert.equal(submit(), false);
	assert.equal(d.getElementById('tbreg-builder-error').hidden, false);
	assert.equal(d.getElementById('tbreg-panel-fields').hidden, false);
	assert.equal(d.activeElement.tagName, 'INPUT');
	dom.window.close();
});
test('plantillas reemplazan sin eliminar campos con datos y generan nuevas claves', () => {
	const { dom, d, state } = setup([textField], ['f_existing']);
	d.getElementById('tbreg-template').value = 'webinar'; d.getElementById('tbreg-apply-template').click();
	assert.equal(state().find(field => field.key === 'f_existing').archived, true);
	assert.equal(d.querySelector('[name="tbreg_settings[event][modality]"]').value, 'virtual');
	const firstKeys = state().filter(field => !field.archived).map(field => field.key);
	d.getElementById('tbreg-apply-template').click();
	assert.ok(state().filter(field => !field.archived).every(field => !firstKeys.includes(field.key)));
	assert.equal(state().filter(field => field.archived).length, 1);
	dom.window.close();
});
test('contenido del campo usa texto y no ejecuta HTML', () => {
	const { dom, d, input, state } = setup([textField]);
	input(d.querySelector('.tbreg-field'), 'label', '<img src=x onerror=alert(1)>');
	assert.equal(d.querySelectorAll('.tbreg-field img').length, 0);
	assert.equal(state()[0].label, '<img src=x onerror=alert(1)>');
	dom.window.close();
});
test('pestañas admiten flechas, Inicio y Fin', () => {
	const { dom, w, d } = setup();
	d.querySelector('[data-tab="fields"]').dispatchEvent(new w.KeyboardEvent('keydown', { key: 'End', bubbles: true }));
	assert.equal(d.activeElement.dataset.tab, 'notifications');
	assert.equal(d.getElementById('tbreg-panel-notifications').hidden, false);
	d.activeElement.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'Home', bubbles: true }));
	assert.equal(d.activeElement.dataset.tab, 'fields');
	dom.window.close();
});

test('ajustes muestran solo controles compatibles y limpian valores al cambiar el tipo', () => {
	const {dom, w, d, input, state} = setup([{...textField, placeholder:'Nombre', role:'name', validation:{minlength:2, maxlength:40, pattern:'[a-z]+'}}]);
	const has = (card, label) => Array.from(card.querySelectorAll('.tbreg-control')).find(n=>n.querySelector('span')?.textContent===w.tbreg_builder.labels[label]);
	let card=d.querySelector('.tbreg-field');
	assert.ok(has(card,'placeholder')); assert.ok(has(card,'minlength')); assert.ok(has(card,'pattern')); assert.equal(has(card,'min'),undefined);
	input(card,'type','number'); card=d.querySelector('.tbreg-field');
	assert.ok(has(card,'min')); assert.equal(has(card,'minlength'),undefined); assert.equal(has(card,'placeholder')!==undefined,true); assert.equal(has(card,'role'),undefined);
	assert.equal(state()[0].role,null);assert.equal(state()[0].validation.pattern,null);assert.equal(state()[0].validation.minlength,null);
	input(card,'type','date'); card=d.querySelector('.tbreg-field'); assert.equal(has(card,'min').querySelector('input').type,'date'); assert.equal(has(card,'placeholder'),undefined);
	input(card,'type','consent'); card=d.querySelector('.tbreg-field');assert.equal(card.querySelector('.tbreg-checkbox input').disabled,true);assert.equal(card.querySelector('[data-default-key]'),null);assert.equal(state()[0].required,true);
	input(card,'type','heading');card=d.querySelector('.tbreg-field');assert.equal(has(card,'width'),undefined);assert.equal(card.querySelector('.tbreg-checkbox'),null);assert.equal(state()[0].required,false);
	dom.window.close();
});
test('predeterminados usan opciones visibles y se actualizan al renombrar o eliminar', () => {
	for (const type of ['select','radio','checkbox']) {
		const field={...textField,type,options:[{value:'a',label:'Bogotá',auto:true},{value:'b',label:'Medellín'}],default:type==='checkbox'?['a']:'a'};
		const {dom,d,input,state,click,w}=setup([field]);
		let host=d.querySelector('[data-default-key]');
		if(type==='checkbox') {assert.equal(host.querySelector('input[value="a"]').checked,true);assert.equal(host.querySelectorAll('input[type="text"]').length,0);}
		else {assert.equal(host.querySelector('select').value,'a');assert.equal(host.querySelector('option[value="a"]').textContent,'Bogotá');}
		input(d.querySelector('.tbreg-option'),'label','Cali');host=d.querySelector('[data-default-key]');
		assert.deepEqual(state()[0].default,type==='checkbox'?['cali']:'cali');assert.ok(host.textContent.includes('Cali'));assert.equal(host.textContent.includes('Bogotá'),false);
		input(d.querySelector('.tbreg-option'),'value','capital');assert.deepEqual(state()[0].default,type==='checkbox'?['capital']:'capital');
		click(d.querySelector('.tbreg-option'),'remove');assert.deepEqual(state()[0].default,type==='checkbox'?[]:'');assert.equal(d.querySelector('[data-default-key]').textContent.includes('Cali'),false);
		assert.equal(w.tbreg_builder.labels.default.length>0,true);dom.window.close();
	}
});

const levels = { ...textField, key: 'f_level', type: 'select', label: 'Nivel', options: [{ label: 'Básico', value: 'b' }, { label: 'Intermedio', value: 'i' }] };
const courses = { ...levels, key: 'f_course', label: 'Curso', options: [{ label: 'Japonés', value: 'j' }, { label: 'Coreano', value: 'k' }] };
const modules = { ...levels, key: 'f_module', label: 'Módulo', options: [{ label: 'Uno', value: 'uno', when: { f_level: ['b'], f_course: ['j'] } }, { label: 'Libre', value: 'libre' }], default: 'uno' };
function section(d, key, name) { d.querySelector('[data-key="' + key + '"] [data-section="' + name + '"]').click(); }
test('pestañas internas con flechas y clave copiable no ensucian ni cambian el JSON', async () => {
	const { dom, w, d, state, click } = setup([textField]); const before = state(); const card = d.querySelector('.tbreg-field');
	card.querySelector('summary').click(); const tab = card.querySelector('[data-section="general"]');
	tab.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'End', bubbles: true, cancelable: true }));
	assert.equal(d.activeElement.dataset.section, 'advanced'); assert.equal(d.getElementById(d.activeElement.getAttribute('aria-controls')).hidden, false);
	assert.equal(d.querySelector('[data-tab="fields"]').getAttribute('aria-selected'), 'true');
	let copied; Object.defineProperty(w.navigator, 'clipboard', { value: { writeText: async value => { copied = value; } } });
	click(card, 'copyKey'); await new Promise(setImmediate); assert.equal(copied, textField.key); assert.equal(card.querySelector('.tbreg-key').readOnly, true);
	assert.match(card.querySelector('[role="status"]').textContent, /Identificador copiado/);
	Object.defineProperty(w.navigator.clipboard, 'writeText', { value: async () => { throw new Error('denied'); } });
	click(card, 'copyKey'); await new Promise(setImmediate); assert.equal(d.activeElement.value, textField.key); assert.equal(d.activeElement.selectionEnd, textField.key.length);
	assert.deepEqual(state(), before); assert.notEqual(d.getElementById('tbreg-builder-status').textContent, w.tbreg_builder.labels.changed); dom.window.close();
});
test('pegado múltiple es atómico, conserva valores existentes y no interpreta HTML', () => {
	const { dom, w, d, state, click } = setup([levels]); section(d, 'f_level', 'options'); let card = d.querySelector('.tbreg-field');
	click(card, 'bulkPaste'); const bulk = card.querySelector('.tbreg-bulk textarea'); const before = state();
	bulk.value = 'Básico repetido | b\nNuevo | n'; bulk.dispatchEvent(new w.Event('input', { bubbles: true }));
	assert.deepEqual(state(), before); assert.notEqual(d.getElementById('tbreg-builder-status').textContent, w.tbreg_builder.labels.changed);
	click(card, 'bulkApply'); assert.deepEqual(state(), before); assert.ok(card.querySelector('.tbreg-bulk [role="status"]').textContent.length);
	bulk.value = 'Básico 2\nTurista | turista_1\n<img src=x> | literal'; click(card, 'bulkApply');
	assert.deepEqual(state()[0].options.slice(0, 2), before[0].options);
	assert.deepEqual(state()[0].options.slice(2).map(option => option.value), ['basico-2', 'turista_1', 'literal']);
	assert.equal(d.querySelector('.tbreg-field img'), null); assert.equal(d.activeElement.value, 'Básico 2');
	assert.equal(d.activeElement.closest('.tbreg-field-section').hidden, false); dom.window.close();
});
test('matriz y chips editan la misma regla y conservan las condiciones de otros padres', () => {
	const { dom, w, d, state, click } = setup([levels, courses, modules]); const getCard = () => d.querySelector('[data-key="f_module"]');
	section(d, 'f_module', 'options'); const before = state(); click(getCard(), 'matrix');
	assert.deepEqual(state(), before); assert.equal(getCard().querySelectorAll('.tbreg-matrix tbody tr').length, 2);
	const check = getCard().querySelector('.tbreg-matrix input[aria-label="Uno: Intermedio"]'); check.click();
	assert.deepEqual(state()[2].options[0].when, { f_level: ['b', 'i'], f_course: ['j'] });
	click(getCard(), 'optionTable'); const rule = getCard().querySelector('.tbreg-primary-condition'); assert.equal(rule.querySelector('input[value="i"]').checked, true);
	rule.querySelector('input[value="b"]').click(); assert.equal(d.activeElement.value, 'b');
	assert.deepEqual(state()[2].options[0].when, { f_level: ['i'], f_course: ['j'] });
	click(getCard(), 'matrix'); getCard().querySelector('.tbreg-matrix input[aria-label="Uno: Básico"]').click();
	assert.deepEqual(state()[2].options[0].when.f_level, ['b', 'i']);
	const firstCheck = getCard().querySelector('.tbreg-matrix input'); firstCheck.focus(); firstCheck.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true, cancelable: true }));
	assert.equal(d.activeElement.getAttribute('aria-label'), 'Uno: Básico');
	getCard().querySelector('.tbreg-matrix input[aria-label="Uno: Básico"]').click(); click(getCard(), 'optionTable');
	const selector = getCard().querySelector('.tbreg-option-toolbar select'); selector.value = 'f_course'; selector.dispatchEvent(new w.Event('change', { bubbles: true }));
	assert.deepEqual(state()[2].options[0].when, { f_level: ['i'], f_course: ['j'] });
	getCard().querySelector('.tbreg-primary-condition input[value="k"]').click();
	assert.deepEqual(state()[2].options[0].when, { f_level: ['i'], f_course: ['j', 'k'] }); dom.window.close();
});
test('Siempre elimina solo la condición elegida; una condición vacía bloquea el guardado', () => {
	const { dom, d, state, click, submit } = setup([levels, courses, modules]); section(d, 'f_module', 'options');
	let card = d.querySelector('[data-key="f_module"]'); card.querySelector('.tbreg-primary-condition input[value="b"]').click();
	assert.equal(submit(), false); assert.ok(d.querySelector('.tbreg-rule-invalid'));
	card = d.querySelector('[data-key="f_module"]'); card.querySelector('.tbreg-primary-condition button').click();
	assert.deepEqual(state()[2].options[0].when, { f_course: ['j'] });
	assert.equal(submit(), true); dom.window.close();
});
test('condiciones adicionales se editan y eliminan sin aplanar las reglas', () => {
	const { dom, d, state, click } = setup([levels, courses, modules]); section(d, 'f_module', 'options');
	let card = d.querySelector('[data-key="f_module"]'); card.querySelector('.tbreg-condition input[value="k"]').click();
	assert.deepEqual(state()[2].options[0].when, { f_level: ['b'], f_course: ['j', 'k'] });
	click(card.querySelector('.tbreg-condition'), 'remove'); card = d.querySelector('[data-key="f_module"]');
	assert.deepEqual(state()[2].options[0].when, { f_level: ['b'] }); click(card.querySelector('.tbreg-option'), 'otherCondition');
	assert.deepEqual(state()[2].options[0].when, { f_level: ['b'], f_course: ['j'] }); assert.equal(card.isConnected, false); dom.window.close();
});
test('lápiz expone el valor guardado sin cambiarlo; editarlo remapea valores predeterminados y dependencias', async () => {
	const { dom, d, state, input } = setup([levels, { ...modules, options: [{ label: 'Uno', value: 'uno', when: { f_level: ['b'] } }] }]); section(d, 'f_level', 'options');
	const row = d.querySelector('.tbreg-option'); const before = state(); row.querySelector('.tbreg-icon').click();
	assert.equal(d.activeElement.value, 'b'); assert.deepEqual(state(), before); assert.equal(row.querySelector('.tbreg-value > div').hidden, false);
	input(row, 'value', 'basico'); input(row, 'label', 'Inicial');
	assert.equal(state()[0].options[0].value, 'basico'); assert.deepEqual(state()[1].options[0].when, { f_level: ['basico'] }); await new Promise(setImmediate); assert.ok(d.querySelector('[data-key="f_module"] .tbreg-primary-condition input[value="basico"]')); assert.match(d.querySelector('[data-key="f_module"] .tbreg-primary-condition').textContent, /Inicial/); dom.window.close();
});
test('ida y vuelta de campos canónicos PHP es idéntica tras recorrer vistas y padres', () => {
	const original = JSON.parse(fs.readFileSync(path.join(fixtureDir, 'canonical-fields.json'), 'utf8'));
	const { dom, w, d, state, submit } = setup(original);
	assert.deepEqual(state(), original);
	for (const field of original.filter(field => ['select', 'radio', 'checkbox'].includes(field.type))) {
		section(d, field.key, 'options'); let card = d.querySelector('[data-key="' + field.key + '"]');
		const select = card.querySelector('.tbreg-option-toolbar select');
		for (const option of [...select.options]) { select.value = option.value; select.dispatchEvent(new w.Event('change', { bubbles: true })); card = d.querySelector('[data-key="' + field.key + '"]'); const live = card.querySelector('.tbreg-option-toolbar select'); if (live.value) { card.querySelector('.tbreg-matrix-toggle').click(); d.querySelector('[data-key="' + field.key + '"] .tbreg-matrix-toggle').click(); } }
	}
	assert.deepEqual(state(), original); assert.equal(submit(), true);
	fs.writeFileSync(path.join(fixtureDir, 'dom-roundtrip.json'), JSON.stringify(state())); dom.window.close();
});

const settingsHtml = fs.readFileSync(path.join(fixtureDir, 'editor-settings.html'), 'utf8');
const settingsFields = JSON.parse(fs.readFileSync(path.join(fixtureDir, 'settings-fields.json'), 'utf8'));
const setting = (d, name) => d.getElementById('tbreg-editor').elements.namedItem('tbreg_settings[' + name + ']');
const notification = (d, name, key) => d.getElementById('tbreg-editor').elements.namedItem('tbreg_settings[' + name + '][' + key + ']');
test('tarjetas y controles dependientes conservan valores ocultos en el POST', () => {
	const { dom, w, d, state } = setup(settingsFields, [], settingsHtml); const before = state();
	assert.deepEqual([...d.querySelectorAll('#tbreg-panel-settings .tbreg-settings-card > h2')].map(n => n.textContent), ['Información del evento', 'Inscripciones', 'Después de inscribirse', 'Comprobante en PDF', 'Privacidad y seguridad']);
	assert.equal(d.querySelectorAll('#tbreg-panel-notifications .tbreg-settings-card').length, 2);
	assert.equal(d.getElementById('tbreg-waitlist-options').hidden, true); assert.equal(setting(d, 'waitlist').checked, true);
	setting(d, 'capacity').value = '25'; setting(d, 'capacity').dispatchEvent(new w.Event('input', { bubbles: true }));
	assert.equal(d.getElementById('tbreg-waitlist-options').hidden, false);
	setting(d, 'capacity').value = '0'; setting(d, 'capacity').dispatchEvent(new w.Event('input', { bubbles: true }));
	for (const [name, panel] of [['pdf_enabled', 'tbreg-pdf-options']]) {
		assert.equal(d.getElementById(panel).hidden, true); setting(d, name).click(); assert.equal(d.getElementById(panel).hidden, false); setting(d, name).click(); assert.equal(d.getElementById(panel).hidden, true);
	}
	for (const [name, panel] of [['notify_admin', 'tbreg-notify-admin-options'], ['confirm_attendee', 'tbreg-confirm-attendee-options']]) {
		assert.equal(d.getElementById(panel).hidden, true); notification(d, name, 'enabled').click(); assert.equal(d.getElementById(panel).hidden, false); notification(d, name, 'enabled').click(); assert.equal(d.getElementById(panel).hidden, true);
		assert.equal(notification(d, name, 'subject').disabled, false);
	}
	const post = new w.FormData(d.getElementById('tbreg-editor'));
	assert.equal(post.get('tbreg_settings[receipt_text]'), 'Segunda página guardada'); assert.equal(post.get('tbreg_settings[waitlist]'), '1');
	assert.equal(post.get('tbreg_settings[notify_admin][to]'), 'qa@example.test'); assert.equal(post.get('tbreg_settings[confirm_attendee][body]'), '<p>Hola {field:f_name}</p>');
	assert.deepEqual(state(), before); dom.window.close();
});
test('diseño del PDF alterna sus paneles y el selector de logo actualiza el valor enviado', () => {
	let selected = null;
	const media = { open() { this.handler(); }, off() { return this; }, on(event, handler) { this.handler = handler; return this; }, state: () => ({ get: () => ({ first: () => ({ toJSON: () => selected }) }) }) };
	const { dom, w, d } = setup(settingsFields, [], settingsHtml, win => { win.wp = { media: () => media }; });
	setting(d, 'pdf_enabled').click();
	const receipt = d.querySelector('[data-tbreg-dependent="pdf_receipt"]'); const enrollment = d.querySelector('[data-tbreg-dependent="pdf_enrollment"]');
	assert.equal(receipt.hidden, false); assert.equal(enrollment.hidden, true);
	setting(d, 'pdf][template').value = 'enrollment'; setting(d, 'pdf][template').dispatchEvent(new w.Event('change', { bubbles: true }));
	assert.equal(receipt.hidden, true); assert.equal(enrollment.hidden, false);
	selected = { id: 33, url: 'https://wordpress.test/logo.png' };
	d.querySelector('[data-tbreg-logo="choose"]').click();
	assert.equal(setting(d, 'pdf][logo_id').value, '33'); assert.equal(d.querySelector('.tbreg-pdf-logo img').hidden, false);
	d.querySelector('[data-tbreg-logo="remove"]').click();
	const post = new w.FormData(d.getElementById('tbreg-editor'));
	assert.equal(post.get('tbreg_settings[pdf][logo_id]'), '0'); assert.equal(post.get('tbreg_settings[pdf][template]'), 'enrollment'); assert.equal(post.get('tbreg_settings[pdf][show_options]'), '1');
	dom.window.close();
});
test('acción posterior conserva el borrador de URL al alternar y desactiva su validación al mostrar mensaje', () => {
	const { dom, w, d } = setup(settingsFields, [], settingsHtml); const url = setting(d, 'redirect_url');
	assert.equal(d.getElementById('tbreg-redirect-options').hidden, false); assert.equal(url.required, true);
	d.querySelector('[name="tbreg_after_submit"][value="message"]').click();
	assert.equal(url.value, 'https://example.test/gracias'); assert.equal(url.disabled, true); assert.equal(url.required, false); assert.equal(d.getElementById('tbreg-success-options').hidden, false);
	assert.equal(new w.FormData(d.getElementById('tbreg-editor')).has('tbreg_settings[redirect_url]'), false);
	d.querySelector('[name="tbreg_after_submit"][value="redirect"]').click();
	assert.equal(url.value, 'https://example.test/gracias'); assert.equal(url.disabled, false); assert.equal(url.required, true);
	assert.equal(new w.FormData(d.getElementById('tbreg-editor')).get('tbreg_settings[success_message]'), 'Mensaje guardado'); dom.window.close();
});
test('aviso de duplicados por correo sigue los roles activos al crear y eliminar campos', () => {
	const { dom, w, d, state, input, click } = setup([textField]); const duplicates = setting(d, 'prevent_duplicates');
	duplicates.value = 'email'; duplicates.dispatchEvent(new w.Event('change', { bubbles: true })); assert.equal(d.getElementById('tbreg-duplicate-email-warning').hidden, false);
	d.querySelector('[data-type="email"]').click(); const key = state().at(-1).key; input(d.querySelector('[data-key="' + key + '"]'), 'role', 'email');
	assert.equal(d.getElementById('tbreg-duplicate-email-warning').hidden, true); click(d.querySelector('[data-key="' + key + '"]'), 'remove');
	assert.equal(d.getElementById('tbreg-duplicate-email-warning').hidden, false); dom.window.close();
});
test('Insertar dato reemplaza selección en asuntos y mensaje, enfoca el cursor y anuncia sin interpretar HTML', () => {
	const { dom, w, d } = setup([{ ...textField, label: '<b>Nombre</b>' }]);
	d.querySelector('[data-tab="notifications"]').click();
	for (const id of ['tbreg-admin-subject', 'tbreg-attendee-subject', 'tbreg-attendee-body']) {
		const target = d.getElementById(id); target.value = 'Antes [aquí] después'; target.focus(); target.setSelectionRange(6, 12);
		d.querySelector('[data-tbreg-merge-target="' + id + '"]').click();
		const choice = [...d.querySelectorAll('.tbreg-merge-choice')].find(n => n.textContent === '<b>Nombre</b>'); assert.ok(choice); assert.equal(choice.querySelector('b'), null); choice.click();
		assert.equal(target.value, 'Antes {field:f_existing} después'); assert.equal(d.activeElement, target); assert.equal(target.selectionStart, 'Antes {field:f_existing}'.length);
		assert.equal(d.getElementById('tbreg-merge-picker').hidden, true); assert.equal(d.getElementById('tbreg-builder-status').textContent, w.tbreg_builder.labels.changed);
		assert.equal(d.getElementById('tbreg-merge-status').textContent, 'Dato insertado: <b>Nombre</b>.');
	}
	dom.window.close();
});
test('selector de datos se recorre con teclado, busca sin ensuciar y restaura el foco al cerrar', () => {
	const { dom, w, d } = setup([textField]); d.querySelector('[data-tab="notifications"]').click();
	const trigger = d.querySelector('[data-tbreg-merge-target="tbreg-admin-subject"]'); trigger.click(); const search = d.getElementById('tbreg-merge-search');
	search.value = 'inexistente'; search.dispatchEvent(new w.Event('input', { bubbles: true })); assert.equal(d.getElementById('tbreg-merge-empty').hidden, false);
	const enter = new w.KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }); search.dispatchEvent(enter); assert.equal(enter.defaultPrevented, true);
	search.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true })); assert.equal(d.activeElement, trigger); assert.equal(trigger.getAttribute('aria-expanded'), 'false');
	assert.notEqual(d.getElementById('tbreg-builder-status').textContent, w.tbreg_builder.labels.changed);
	trigger.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true, cancelable: true })); assert.equal(d.activeElement.textContent, w.tbreg_builder.labels.mergeEvent);
	d.activeElement.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'End', bubbles: true, cancelable: true })); assert.equal(d.activeElement.textContent, 'Nombre');
	d.getElementById('tbreg-admin-subject').focus(); assert.equal(d.getElementById('tbreg-merge-picker').hidden, true); dom.window.close();
});
test('catálogo de datos refleja etiquetas y claves actuales, excluye archivados, fotos y títulos', () => {
	const { dom, d, input, state } = setup([textField, { ...textField, key: 'f_old', archived: true }, { ...textField, key: 'f_photo', type: 'photo', label: 'Foto' }, { ...textField, key: 'f_heading', type: 'heading', label: 'Separador' }]);
	input(d.querySelector('[data-key="f_existing"]'), 'label', 'Nombre actualizado'); d.querySelector('[data-type="email"]').click(); const mail = state().find(field => field.type === 'email');
	d.querySelector('[data-tab="notifications"]').click(); d.querySelector('[data-tbreg-merge-target="tbreg-admin-subject"]').click();
	const choices = [...d.querySelectorAll('.tbreg-merge-choice')].map(n => n.textContent); assert.ok(choices.includes('Nombre actualizado')); assert.ok(choices.includes('Correo electrónico'));
	assert.equal(choices.includes('Foto'), false); assert.equal(choices.includes('Separador'), false); assert.equal(choices.includes('Nombre'), false); assert.ok(mail); [...d.querySelectorAll('.tbreg-merge-choice')].find(node => node.textContent === 'Correo electrónico').click(); assert.equal(d.getElementById('tbreg-admin-subject').value, '{field:' + mail.key + '}'); dom.window.close();
});
test('datos insertados se incluyen en POST aunque luego se apaguen las notificaciones', () => {
	const { dom, w, d } = setup(settingsFields, [], settingsHtml); d.querySelector('[data-tab="notifications"]').click();
	for (const [name, id, label] of [['notify_admin', 'tbreg-admin-subject', 'Nombre'], ['confirm_attendee', 'tbreg-attendee-body', 'Todos los datos']]) {
		notification(d, name, 'enabled').click(); const target = d.getElementById(id); target.focus(); target.setSelectionRange(target.value.length, target.value.length);
		d.querySelector('[data-tbreg-merge-target="' + id + '"]').click(); [...d.querySelectorAll('.tbreg-merge-choice')].find(node => node.textContent === label).click(); notification(d, name, 'enabled').click();
	}
	const post = new w.FormData(d.getElementById('tbreg-editor')); const settings = {};
	for (const [name, value] of post) {
		if (!name.startsWith('tbreg_settings[')) continue;
		const keys = [...name.matchAll(/\[([^\]]+)\]/g)].map(match => match[1]); let current = settings;
		keys.slice(0, -1).forEach(key => { current = current[key] ||= {}; }); current[keys.at(-1)] = value;
	}
	assert.equal(settings.notify_admin.subject, 'Alta {event_name}{field:f_name}'); assert.equal(settings.confirm_attendee.body, '<p>Hola {field:f_name}</p>{all_fields}');
	assert.equal(settings.notify_admin.enabled, undefined); assert.equal(settings.confirm_attendee.enabled, undefined); assert.equal(settings.waitlist, '1');
	fs.writeFileSync(path.join(fixtureDir, 'dom-settings-post.json'), JSON.stringify({ settings, mode: post.get('tbreg_after_submit') })); dom.window.close();
});
test('blur nativo al ocultar el selector no reentra en el cierre ni pierde el foco y anuncio', () => {
	const { dom, w, d } = setup([textField]); d.querySelector('[data-tab="notifications"]').click();
	const picker = d.getElementById('tbreg-merge-picker'); const home = picker.parentElement;
	d.querySelector('[data-tbreg-merge-target="tbreg-admin-subject"]').click(); let calls = 0; const append = home.append.bind(home);
	home.append = (...nodes) => {
		assert.equal(++calls, 1, 'No debe haber un segundo cierre desde blur');
		picker.dispatchEvent(new w.FocusEvent('focusout', { bubbles: true, relatedTarget: null })); append(...nodes);
	};
	d.querySelector('.tbreg-merge-choice').click();
	assert.equal(calls, 1); assert.equal(d.activeElement.id, 'tbreg-admin-subject'); assert.equal(picker.hidden, true);
	assert.equal(d.getElementById('tbreg-admin-subject').value, '{event_name}'); assert.equal(d.getElementById('tbreg-merge-status').textContent, 'Dato insertado: Nombre del evento.'); dom.window.close();
});

test('Siempre es un interruptor excluyente y no borra reglas de otros padres', () => {
	const { dom, d, state, click } = setup([levels, courses, modules]); const card = () => d.querySelector('[data-key="f_module"]'); section(d, 'f_module', 'options');
	let free = card().querySelectorAll('.tbreg-primary-condition')[1]; let always = free.querySelector('[role="switch"]');
	assert.equal(always.getAttribute('aria-checked'), 'true'); assert.ok([...free.querySelectorAll('input')].every(input => input.disabled));
	assert.ok(d.getElementById(always.getAttribute('aria-controls'))); always.click();
	free = card().querySelectorAll('.tbreg-primary-condition')[1]; assert.ok([...free.querySelectorAll('input')].every(input => !input.disabled)); free.querySelector('input[value="b"]').click();
	assert.deepEqual(state()[2].options[1].when, { f_level: ['b'] });
	card().querySelector('.tbreg-primary-condition [role="switch"]').click();
	assert.deepEqual(state()[2].options[0].when, { f_course: ['j'] });
	assert.ok([...card().querySelector('.tbreg-primary-condition').querySelectorAll('input')].every(input => input.disabled));
	click(card(), 'matrix'); const cells = card().querySelectorAll('.tbreg-matrix tbody tr');
	assert.match(cells[0].lastElementChild.textContent, /Curso = Japonés/); assert.equal(cells[1].lastElementChild.textContent, 'Ninguna'); dom.window.close();
});
test('matriz desactiva las casillas excluidas y las flechas saltan celdas desactivadas', () => {
	const extra = { ...modules.options[0], label: 'Tres', value: 'tres' }; const { dom, w, d, state, click } = setup([levels, courses, { ...modules, options: [...modules.options, extra] }]);
	section(d, 'f_module', 'options'); let card = d.querySelector('[data-key="f_module"]'); click(card, 'matrix'); card = d.querySelector('[data-key="f_module"]');
	const rows = card.querySelectorAll('.tbreg-matrix tbody tr'); const free = rows[1].querySelectorAll('input');
	assert.equal(free[0].getAttribute('role'), 'switch'); assert.equal(free[0].checked, true); assert.equal(free[1].disabled, true);
	const before = state(); const first = rows[0].querySelector('input[aria-label="Uno: Básico"]'); first.focus(); first.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true, cancelable: true }));
	assert.equal(d.activeElement.getAttribute('aria-label'), 'Tres: Básico'); assert.deepEqual(state(), before);
	free[0].click(); assert.equal(free[1].disabled, false); free[1].click(); assert.deepEqual(state()[2].options[1].when, { f_level: ['b'] });
	free[0].click(); assert.equal(free[1].disabled, true); assert.equal(state()[2].options[1].when, undefined); dom.window.close();
});
test('aviso de redirección solo muestra una dirección guardada o escrita, como texto', () => {
	const { dom, w, d } = setup(); const url = setting(d, 'redirect_url'); const help = d.getElementById('tbreg-redirect-help');
	assert.equal(help.hidden, true); assert.equal(help.textContent, '');
	d.querySelector('[name="tbreg_after_submit"][value="redirect"]').click(); url.value = 'https://example.test/<b>'; url.dispatchEvent(new w.Event('input', { bubbles: true })); assert.equal(help.hidden, true);
	d.querySelector('[name="tbreg_after_submit"][value="message"]').click(); assert.equal(help.hidden, false); assert.ok(help.textContent.includes('https://example.test/<b>')); assert.equal(help.querySelector('b'), null);
	url.value = ''; url.dispatchEvent(new w.Event('input', { bubbles: true })); assert.equal(help.hidden, true); dom.window.close();
	const saved = setup(settingsFields, [], settingsHtml); const oldUrl = setting(saved.d, 'redirect_url'); oldUrl.value = ''; saved.d.querySelector('[name="tbreg_after_submit"][value="message"]').click();
	assert.equal(saved.d.getElementById('tbreg-redirect-help').hidden, false); assert.match(saved.d.getElementById('tbreg-redirect-help').textContent, /https:\/\/example.test\/gracias/); saved.dom.window.close();
});
test('IDs y relaciones ARIA resuelven después de insertar, duplicar y cambiar vistas', () => {
	const { dom, d, click } = setup([levels, courses, modules]); section(d, 'f_module', 'options'); let card = d.querySelector('[data-key="f_module"]');
	click(card, 'duplicate'); d.querySelector('[data-key="f_module"] .tbreg-insert-button').click(); d.querySelector('[data-type="text"]').click(); card = d.querySelector('[data-key="f_module"]'); section(d, 'f_module', 'options'); click(card, 'matrix');
	const ids = [...d.querySelectorAll('[id]')].map(node => node.id); assert.equal(new Set(ids).size, ids.length);
	for (const node of d.querySelectorAll('[aria-controls], [aria-labelledby], [aria-describedby]')) {
		for (const attribute of ['aria-controls', 'aria-labelledby', 'aria-describedby']) for (const id of (node.getAttribute(attribute) || '').split(/\s+/).filter(Boolean)) assert.ok(d.getElementById(id), attribute + ': ' + id);
	}
	for (const label of d.querySelectorAll('label[for]')) assert.ok(d.getElementById(label.htmlFor)); dom.window.close();
});
