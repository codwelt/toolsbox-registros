/** Accesibilidad y contrato de descarga del formulario HTML real. */
'use strict';
const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {JSDOM}=require(process.env.TBREG_JSDOM_PATH||'jsdom');
const directory=process.env.TBREG_EXPORT_FIXTURE_DIR;
assert.ok(directory,'Genera el fixture con TBREG_RUN_INTEGRATION=1 y TBREG_EXPORT_FIXTURE_DIR.');
test('exportación usa POST con nonce, controles etiquetados y fechas locales',()=>{
 const dom=new JSDOM(fs.readFileSync(path.join(directory,'export.html'),'utf8')); const d=dom.window.document;
 const form=d.querySelector('.tbreg-export-form');assert.equal(form.method,'post');assert.match(form.action,/admin-post\.php/);
 assert.equal(form.querySelector('[name="action"]').value,'tbreg_export_entries');assert.ok(form.querySelector('[name="tbreg_nonce"]').value);
 const ids=[...d.querySelectorAll('[id]')].map(n=>n.id);assert.equal(new Set(ids).size,ids.length);
 for(const label of d.querySelectorAll('label[for]')) assert.ok(d.getElementById(label.htmlFor));
 for(const name of ['form_id','format','delimiter','status','date_from','date_to','s']) {const input=form.querySelector('[name="'+name+'"]');assert.ok(input);assert.ok(d.querySelector('label[for="'+input.id+'"]'));}
 assert.equal(form.querySelector('[name="form_id"]').required,true);assert.equal(form.querySelector('[name="delimiter"]').value,';');
 assert.equal(form.querySelector('[name="date_from"]').type,'date');assert.ok(d.body.textContent.includes('America/Bogota'));
 assert.equal(form.querySelector('select[name="format"]').querySelectorAll('option').length,2);dom.window.close();
});
