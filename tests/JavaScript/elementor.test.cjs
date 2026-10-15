/** Montaje dinámico e idempotente con el HTML PHP y el puente de Elementor reales. */
'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM} = require(process.env.TBREG_JSDOM_PATH || 'jsdom');
const fixture = fs.readFileSync(path.join(process.env.TBREG_LANGUAGE_FIXTURE_DIR, 'form.html'), 'utf8');
const formScript = fs.readFileSync(path.resolve(__dirname, '../../assets/public/js/form.js'), 'utf8');
const bridge = fs.readFileSync(path.resolve(__dirname, '../../assets/public/js/elementor.js'), 'utf8');
const tick = () => new Promise(resolve => setImmediate(resolve));
function setup(late = false) {
	const dom = new JSDOM('<!doctype html><body></body>', {runScripts: 'outside-only', url: 'https://wordpress.test/idiomas/'});
	const w = dom.window; const hooks = new Map(); let init, gets = 0, posts = 0, registrations = 0;
	w.fetch = async (url, options) => {
		if (options.method === 'POST') { posts++; return {ok: true, json: async () => ({success: true, message: 'Guardado', pdf_url: 'https://wordpress.test/pdf'})}; }
		gets++; return {ok: true, json: async () => ({tbreg_ts: '1000', tbreg_token: 'renovado'})};
	};
	w.jQuery = () => ({on: (name, callback) => { assert.equal(name, 'elementor/frontend/init'); init = callback; }});
	const frontend = {hooks: {addAction: (name, callback) => { registrations++; hooks.set(name, callback); }}};
	if (!late) w.elementorFrontend = frontend;
	w.eval(formScript); w.eval(bridge);
	const start = () => { w.elementorFrontend = frontend; init(); };
	const insert = preview => {
		const root = w.document.createElement('div'); root.innerHTML = fixture; root.querySelectorAll('link').forEach(node => node.remove());
		if (preview) { root.querySelector('form').dataset.preview = 'true'; root.querySelector('button[type="submit"]').disabled = true; }
		w.document.body.append(root); return root;
	};
	const ready = root => hooks.get('frontend/element_ready/tbreg_form.default')([root]);
	return {dom, w, start, insert, ready, gets: () => gets, posts: () => posts, registrations: () => registrations};
}
test('inserción dinámica renueva token una sola vez aunque Elementor monte el widget varias veces', async () => {
	const qa = setup(); const root = qa.insert(); qa.ready(root); qa.ready(root); qa.start(); qa.start(); await tick();
	assert.equal(qa.gets(), 1); assert.equal(qa.registrations(), 1);
	assert.equal(root.querySelector('[name="tbreg_token"]').value, 'renovado');
	const form = root.querySelector('form');
	const set = (key, value) => { const control = form.elements['tbreg_values[' + key + ']']; control.value = value; control.dispatchEvent(new qa.w.Event('change', {bubbles: true})); };
	for (const [key, value] of Object.entries({f_course:'japones', f_level:'basico', f_module:'basico_1', f_mode:'virtual', f_days:'lunes_miercoles', f_schedule:'virtual_18_20', f_name:'Ana QA', f_birth:'1990-05-15', f_document:'CC123', f_mobile:'3001234567', f_email:'qa@example.test'})) set(key, value);
	form.querySelector('[name="tbreg_values[f_consent]"]').checked = true;
	form.dispatchEvent(new qa.w.Event('submit', {bubbles:true, cancelable:true})); await tick();
	assert.equal(qa.posts(), 1); assert.ok(root.querySelector('.tbreg-pdf-download')); qa.dom.window.close();
});
test('vista previa filtra opciones y bloquea envío, renovación de tokens y cámara', async () => {
	const qa = setup(true); qa.start(); const root = qa.insert(true); let cameras = 0;
	Object.defineProperty(qa.w.navigator, 'mediaDevices', {value:{getUserMedia: () => { cameras++; throw new Error('No debe solicitarse'); }}});
	const form = root.querySelector('form'); qa.ready(root);
	for (const [key, value] of Object.entries({f_course:'japones', f_level:'basico'})) {
		const control = form.elements['tbreg_values[' + key + ']']; control.value = value; control.dispatchEvent(new qa.w.Event('change', {bubbles:true}));
	}
	assert.equal(form.elements['tbreg_values[f_module]'].querySelector('[value="intermedio_1"]').disabled, true);
	assert.equal(form.dispatchEvent(new qa.w.Event('submit', {cancelable:true})), false);
	root.querySelector('[data-camera-open]').click(); await tick();
	assert.equal(qa.posts(), 0); assert.equal(qa.gets(), 0); assert.equal(cameras, 0); qa.dom.window.close();
});
test('reemplazar la vista previa y dos widgets independientes inicializan los nuevos nodos', async () => {
	const qa = setup(); const first = qa.insert(); const second = qa.insert(); qa.ready(first); qa.ready(second); await tick(); assert.equal(qa.gets(), 2);
	first.remove(); const replacement = qa.insert(); qa.ready(replacement); qa.ready(second); await tick(); assert.equal(qa.gets(), 3);
	qa.dom.window.close();
});
