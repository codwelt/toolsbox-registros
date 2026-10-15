/** Opciones dependientes, PDF y cámara simulada; nunca usa la cámara del equipo. */
'use strict';
const {test}=require('node:test');const assert=require('node:assert/strict');const fs=require('node:fs');const path=require('node:path');
const {JSDOM}=require(process.env.TBREG_JSDOM_PATH||'jsdom');
const directory=process.env.TBREG_LANGUAGE_FIXTURE_DIR;assert.ok(directory,'Genera fixtures de idiomas con TBREG_RUN_INTEGRATION=1.');
const html=fs.readFileSync(path.join(directory,'form.html'),'utf8');const source=fs.readFileSync(path.resolve(__dirname,'../../assets/public/js/form.js'),'utf8');
const tick=()=>new Promise(resolve=>setImmediate(resolve));
function setup(camera) {
 const dom=new JSDOM(html,{runScripts:'outside-only',url:'https://wordpress.test/inscripcion/',pretendToBeVisual:true});const w=dom.window;const form=w.document.querySelector('form');let cameraCalls=0,stops=0;
 w.fetch=async(url,opts)=>({ok:true,json:async()=>opts.method==='POST'?{success:true,message:'Guardado',pdf_url:'https://wordpress.test/wp-admin/admin-post.php?action=tbreg_receipt&token=prueba'}:{tbreg_ts:'1000',tbreg_token:'renovado'}});
 w.URL.createObjectURL=()=> 'blob:foto-prueba';w.URL.revokeObjectURL=()=>{};
 w.DataTransfer=class {constructor(){this.files=[];this.items={add:file=>this.files.push(file)};}};
 const input=form.querySelector('input[type="file"]');let files=[];Object.defineProperty(input,'files',{get:()=>files,set:value=>{files=value;}});
 const video=form.querySelector('video');video.play=async()=>{};Object.defineProperty(video,'videoWidth',{value:640});Object.defineProperty(video,'videoHeight',{value:480});
 w.HTMLCanvasElement.prototype.getContext=()=>({drawImage:()=>{}});w.HTMLCanvasElement.prototype.toBlob=function(cb){cb(new w.Blob(['imagen simulada'],{type:'image/jpeg'}));};
 Object.defineProperty(w.navigator,'mediaDevices',{value:{getUserMedia:async options=>{cameraCalls++;assert.equal(options.audio,false);if(camera==='deny')throw new Error('Denegado');return {getTracks:()=>[{stop:()=>{stops++;}}]};}}});
 w.eval(source);
 const set=(key,value)=>{const node=form.querySelector('[name="tbreg_values['+key+']"]');node.value=value;node.dispatchEvent(new w.Event('change',{bubbles:true}));return node;};
 return {dom,w,form,set,input,calls:()=>cameraCalls,stops:()=>stops};
}
test('nivel filtra módulos y un cambio limpia subnivel, modalidad, días y horario incompatibles',()=>{
 const {dom,form,set}=setup();set('f_course','japones');set('f_level','basico');const module=set('f_module','basico_1');set('f_mode','virtual');set('f_days','lunes_miercoles');set('f_schedule','virtual_18_20');
 assert.equal(module.querySelector('[value="intermedio_1"]').disabled,true);set('f_level','intermedio');
 for(const key of ['f_module','f_mode','f_days','f_schedule'])assert.equal(form.elements['tbreg_values['+key+']'].value,'');
 assert.equal(module.querySelector('[value="intermedio_1"]').disabled,false);dom.window.close();
});
test('horarios dependen del idioma, modalidad y días, conservando solo combinaciones ofertadas',()=>{
 const {dom,form,set}=setup();set('f_course','japones');set('f_level','basico');set('f_module','basico_2');set('f_mode','virtual');set('f_days','lunes_miercoles');const schedule=set('f_schedule','virtual_18_20');
 set('f_course','coreano');assert.equal(schedule.value,'');assert.equal(schedule.querySelector('[value="virtual_19_21"]').disabled,false);assert.equal(schedule.querySelector('[value="virtual_18_20"]').disabled,true);
 set('f_mode','presencial');assert.equal(form.elements['tbreg_values[f_days]'].value,'');set('f_days','sabados');assert.equal(schedule.querySelector('[value="presencial_14_17"]').disabled,false);dom.window.close();
});
test('cámara se solicita al pulsar, captura como archivo y detiene todas las pistas',async()=>{
 const {dom,w,form,input,calls,stops}=setup();assert.equal(calls(),0);form.querySelector('[data-camera-open]').click();await tick();assert.equal(calls(),1);assert.equal(form.querySelector('video').hidden,false);
 form.querySelector('[data-camera-take]').click();await tick();assert.equal(input.files[0].name,'fotografia.jpg');assert.equal(input.files[0].type,'image/jpeg');assert.equal(stops(),1);assert.equal(form.querySelector('video').hidden,true);assert.equal(form.querySelector('.tbreg-photo-preview').hidden,false);
 w.dispatchEvent(new w.Event('pagehide'));dom.window.close();
});
test('permiso de cámara rechazado mantiene disponible el archivo y permite reintentar',async()=>{
 const {dom,form}=setup('deny');form.querySelector('[data-camera-open]').click();await tick();assert.equal(form.querySelector('video').hidden,true);assert.equal(form.querySelector('input[type="file"]').disabled,false);assert.equal(form.querySelector('[data-camera-open]').disabled,false);assert.match(form.querySelector('.tbreg-camera-status').textContent,/seleccionar una foto/);dom.window.close();
});
test('éxito ofrece PDF del mismo origen y usa una inscripción guardada',async()=>{
 const {dom,w,form,set}=setup();for(const [key,value]of Object.entries({f_course:'mandarin',f_level:'basico',f_module:'basico_1',f_mode:'virtual',f_days:'lunes_miercoles',f_schedule:'virtual_18_20',f_name:'Ana',f_birth:'1990-05-15',f_document:'CC123',f_mobile:'3001234567',f_email:'qa@example.test'}))set(key,value);
 form.querySelector('[name="tbreg_values[f_consent]"]').checked=true;form.dispatchEvent(new w.Event('submit',{cancelable:true,bubbles:true}));await tick();const link=w.document.querySelector('.tbreg-success .tbreg-pdf-download');assert.ok(link);assert.match(link.href,/action=tbreg_receipt/);assert.equal(link.textContent,'Descargar PDF de mi inscripción');assert.equal(form.hidden,true);dom.window.close();
});
