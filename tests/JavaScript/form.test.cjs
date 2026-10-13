/** Mejora progresiva, grupos requeridos, errores y foco con DOM real del PHP. */
'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require(process.env.TBREG_JSDOM_PATH || 'jsdom');
const directory = process.env.TBREG_FRONTEND_FIXTURE_DIR;
assert.ok(directory, 'Genera los fixtures PHP con integración activa.');
const html = fs.readFileSync(path.join(directory, 'form.html'), 'utf8');
const source = fs.readFileSync(path.resolve(__dirname, '../../assets/public/js/form.js'), 'utf8');
const tick = () => new Promise(resolve => setImmediate(resolve));
function setup(post, withFetch = true) {
	const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'https://wordpress.test/evento/' });
	const w = dom.window; const posts = [];
	if (withFetch) w.fetch = async (url, options) => {
		if (options.method !== 'POST') return { ok: true, json: async () => ({ tbreg_ts: '1000', tbreg_token: 'refreshed-token' }) };
		posts.push(options);
		return post ? post(options) : { ok: true, json: async () => ({ success: true, message: 'Inscripción confirmada', redirect_url: '' }) };
	};
	w.eval(source);
	const form = w.document.querySelector('form');
	form.dataset.restNonce = 'test-rest-nonce';
	const values = { f_text: 'Ana Muñoz', f_email: 'ana@example.test', f_tel: '+57 3001234567', f_number: '2.5', f_textarea: 'Comentarios', f_select: 'a', f_date: '2026-11-01', f_time: '13:30', f_url: 'https://example.test/', f_document: 'CC123' };
	for (const [key, value] of Object.entries(values)) form.elements['tbreg_values[' + key + ']'].value = value;
	form.querySelector('[name="tbreg_values[f_radio]"]').checked = true;
	form.querySelector('[name="tbreg_values[f_checkbox][]"]').checked = true;
	form.querySelector('[name="tbreg_values[f_consent]"]').checked = true;
	const submit = () => form.dispatchEvent(new w.Event('submit', { bubbles: true, cancelable: true }));
	return { dom, w, form, posts, submit };
}
test('sin fetch el formulario conserva POST tradicional y los campos firmados', () => {
	const { dom, form, posts, submit } = setup(null, false);
	assert.match(form.action, /admin-post\.php/); assert.equal(form.method, 'post');
	assert.ok(form.elements.tbreg_token.value); assert.ok(form.elements.tbreg_ts.value);
	assert.equal(submit(), true); assert.equal(posts.length, 0); dom.window.close();
});
test('envío fetch renueva token, preserva arrays y muestra éxito sin recargar', async () => {
	const { dom, w, form, posts, submit } = setup(); submit(); await tick();
	assert.equal(posts.length, 1); assert.equal(posts[0].body.get('tbreg_token'), 'refreshed-token');
	assert.equal(posts[0].body.get('tbreg_values[f_email]'), 'ana@example.test');
	assert.deepEqual(posts[0].body.getAll('tbreg_values[f_checkbox][]'), ['a']);
	assert.equal(posts[0].credentials, 'same-origin'); assert.ok(posts[0].headers['X-WP-Nonce']);
	assert.equal(form.hidden, true); assert.equal(w.document.activeElement.textContent, 'Inscripción confirmada');
	assert.equal(w.document.querySelectorAll('form')[1].hidden, false); dom.window.close();
});
test('errores del servidor se anuncian por campo y enfocan el primer error', async () => {
	const { dom, w, form, posts, submit } = setup(async () => ({ ok: false, json: async () => ({ code: 'tbreg_validation', message: 'Revisa los campos', data: { fields: { f_email: 'Correo inválido' } } }) }));
	submit(); await tick();
	const input = form.elements['tbreg_values[f_email]'];
	assert.equal(w.document.activeElement, input); assert.equal(input.getAttribute('aria-invalid'), 'true');
	assert.equal(form.querySelector('[data-tbreg-field="f_email"] [data-tbreg-error]').textContent, 'Correo inválido');
	assert.equal(form.querySelector('.tbreg-summary').hidden, false); assert.equal(form.hidden, false);
	assert.equal(form.querySelector('[type="submit"]').disabled, false); assert.equal(posts.length, 1); dom.window.close();
});
test('checkbox múltiple obligatorio pide al menos una opción y no todas', async () => {
	const { dom, form, posts, submit } = setup(); form.querySelector('[name="tbreg_values[f_checkbox][]"]').checked = false;
	submit(); await tick(); assert.equal(posts.length, 0);
	assert.equal(form.querySelector('[data-tbreg-field="f_checkbox"] [data-tbreg-error]').hidden, false);
	form.querySelector('[name="tbreg_values[f_checkbox][]"]').checked = true;
	submit(); await tick(); assert.equal(posts.length, 1); dom.window.close();
});
test('doble envío mientras hay petición en curso produce una sola inscripción', async () => {
	let release;
	const { dom, form, posts, submit } = setup(() => new Promise(resolve => { release = resolve; }));
	submit(); submit(); await tick(); assert.equal(posts.length, 1); assert.equal(form.getAttribute('aria-busy'), 'true');
	release({ ok: true, json: async () => ({ success: true, message: 'Confirmado' }) }); await tick();
	assert.equal(form.getAttribute('aria-busy'), 'false'); dom.window.close();
});
test('fallo de red conserva valores, permite reintentar y enfoca el aviso', async () => {
	const { dom, w, form, submit } = setup(async () => { throw new Error('offline'); });
	submit(); await tick(); assert.equal(form.elements['tbreg_values[f_email]'].value, 'ana@example.test');
	assert.equal(w.document.activeElement, form.querySelector('.tbreg-summary')); assert.equal(form.querySelector('[type="submit"]').disabled, false); dom.window.close();
});
test('mensajes y errores del servidor se muestran como texto sin interpretar HTML', async () => {
	const { dom, form, submit } = setup(async () => ({ ok: false, json: async () => ({ message: '<img src=x onerror=alert(1)>', data: { fields: { f_email: '<script>alert(1)</script>' } } }) }));
	submit(); await tick(); assert.equal(form.querySelectorAll('img,script').length, 0); assert.match(form.querySelector('.tbreg-summary').textContent, /<img/); dom.window.close();
});
test('IDs únicos y referencias accesibles resuelven en dos instancias del formulario', () => {
	const { dom, w } = setup(null, false); const d = w.document; const ids = Array.from(d.querySelectorAll('[id]'), node => node.id);
	assert.equal(new Set(ids).size, ids.length);
	d.querySelectorAll('label[for]').forEach(label => assert.ok(d.getElementById(label.htmlFor)));
	d.querySelectorAll('[aria-describedby]').forEach(node => node.getAttribute('aria-describedby').split(' ').forEach(id => assert.ok(d.getElementById(id))));
	dom.window.close();
});
