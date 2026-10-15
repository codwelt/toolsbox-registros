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
function setup(fields = [], used = [], markup = html) {
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
	w.eval(source);
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
test('Publicar está fuera de las tres pestañas y el aviso de correo no bloquea', () => {
	const { dom, w, d, state, input } = setup([textField]);
	assert.equal(d.querySelectorAll('[role="tab"]').length, 3);
	const status = d.querySelector('[name="tbreg_status"]');
	assert.equal(d.querySelectorAll('[name="tbreg_status"]').length, 1);
	for (const tab of d.querySelectorAll('[role="tab"]')) { tab.click(); assert.equal(status.closest('[hidden]'), null); }
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
	assert.equal(d.activeElement.id, 'tbreg-capacity'); assert.equal(d.getElementById('tbreg-panel-settings').hidden, false);
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
	for (const tab of d.querySelectorAll('[role="tab"]')) {
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
