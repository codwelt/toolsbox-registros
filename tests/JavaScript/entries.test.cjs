/** Contratos de accesibilidad y separación GET/POST de la Fase 4. */
'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require(process.env.TBREG_JSDOM_PATH || 'jsdom');
const directory = process.env.TBREG_ENTRIES_FIXTURE_DIR;
assert.ok(directory, 'Genera fixtures de Fase 4 con TBREG_RUN_INTEGRATION=1 y TBREG_ENTRIES_FIXTURE_DIR.');
test('listado tiene IDs únicos, filtros GET y acciones POST sin colisión del dispatcher', () => {
 const dom = new JSDOM(fs.readFileSync(path.join(directory, 'entries.html'), 'utf8'));
 const d=dom.window.document; const ids=[...d.querySelectorAll('[id]')].map(n=>n.id);
 assert.equal(new Set(ids).size,ids.length);
 const forms=[...d.querySelectorAll('form')]; assert.equal(forms.length,2); assert.equal(forms[0].method,'get');assert.equal(forms[1].method,'post');
 assert.equal(forms[1].querySelector('[name="action"]').value,'tbreg_entries_action');assert.equal(forms[1].querySelectorAll('select[name="action"]').length,0);
 assert.ok(forms[1].querySelector('[name="tbreg_nonce"]'));assert.ok(forms[1].querySelector('[name="tbreg_ids[]"]').getAttribute('aria-label'));
 for(const label of d.querySelectorAll('label[for]')) assert.ok(d.getElementById(label.htmlFor),'Etiqueta con destino '+label.htmlFor);
 assert.equal(d.querySelectorAll('tbody .toggle-row').length,d.querySelectorAll('tbody tr').length);
 dom.window.close();
});
test('edición conserva campos históricos y vincula labels, ayudas y errores accesibles', () => {
 const dom=new JSDOM(fs.readFileSync(path.join(directory,'detail.html'),'utf8')); const d=dom.window.document;const form=d.querySelector('.tbreg-entry-editor');
 assert.equal(form.method,'post');assert.match(form.action,/admin-post\.php/);assert.ok(form.querySelector('[name="tbreg_revision"]'));assert.ok(form.querySelector('[name="tbreg_form_revision"]'));
 for(const key of ['f_document','f_consent','f_hidden']) assert.equal(form.querySelector('[name="tbreg_values['+key+']"]'),null);
 assert.ok(d.body.textContent.includes('Documento (eliminado)'));assert.ok(d.body.textContent.includes('CC1'));assert.ok(d.body.textContent.includes('Sí'));
 const ids=[...d.querySelectorAll('[id]')].map(n=>n.id);assert.equal(new Set(ids).size,ids.length);
 for(const node of d.querySelectorAll('[aria-describedby]')) for(const id of node.getAttribute('aria-describedby').split(/\s+/)) assert.ok(d.getElementById(id),'Referencia accesible '+id);
 for(const label of d.querySelectorAll('label[for]')) assert.ok(d.getElementById(label.htmlFor));
 dom.window.close();
});
