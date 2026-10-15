/* global turnstile, grecaptcha */
(function () {
	'use strict';
	function mountCaptcha(provider) {
		document.querySelectorAll('.tbreg-captcha[data-provider="' + provider + '"]').forEach(container => {
			if (container.dataset.widgetId !== undefined) return;
			const api = provider === 'turnstile' ? window.turnstile : window.grecaptcha;
			if (!api || !api.render) return;
			container.dataset.widgetId = String(api.render(container, { sitekey: container.dataset.siteKey }));
		});
	}
	window.tbreg_turnstile_ready = () => mountCaptcha('turnstile');
	window.tbreg_recaptcha_ready = () => mountCaptcha('recaptcha');
	mountCaptcha('turnstile'); mountCaptcha('recaptcha');
	const mounted = new WeakSet();
	function mount(root) {
	const forms = Array.from(root.querySelectorAll('.tbreg-form'));
	if (root.matches && root.matches('.tbreg-form')) forms.unshift(root);
	forms.forEach(form => {
		if (mounted.has(form)) return;
		mounted.add(form);
		// Relaciones de opciones: resolver padres antes de hijos aunque se reordenen.
		const fieldNodes = Array.from(form.querySelectorAll('[data-tbreg-field]'));
		const answers = () => Object.fromEntries(fieldNodes.map(field => {
			const select = field.querySelector('select'); const checked = field.querySelector('input:checked');
			return [field.dataset.tbregField, select ? select.value : checked ? checked.value : ''];
		}));
		function dependentOptions() {
			const visited = new Set(); const visiting = new Set();
			function update(field) {
				const key = field.dataset.tbregField; if (visited.has(key) || visiting.has(key)) return;
				visiting.add(key);
				const nodes = Array.from(field.querySelectorAll('[data-tbreg-when]'));
				nodes.forEach(node => {
					const when = JSON.parse(node.dataset.tbregWhen);
					Object.keys(when).forEach(parent => { const found = fieldNodes.find(item => item.dataset.tbregField === parent); if (found) update(found); });
				});
				const values = answers();
				nodes.forEach(node => {
					const allowed = Object.entries(JSON.parse(node.dataset.tbregWhen)).every(([parent, choices]) => choices.includes(values[parent]));
					node.disabled = !allowed;
					if (node.tagName === 'OPTION') { node.hidden = !allowed; if (!allowed && node.selected) node.closest('select').value = ''; }
					else { node.closest('label').hidden = !allowed; if (!allowed) node.checked = false; }
				});
				visiting.delete(key); visited.add(key);
			}
			fieldNodes.forEach(update);
		}
		form.addEventListener('change', dependentOptions); form.addEventListener('reset', () => setTimeout(dependentOptions,0)); dependentOptions();
		if (form.dataset.preview === 'true') {
			form.addEventListener('submit', event => event.preventDefault());
			return;
		}
		form.querySelectorAll('.tbreg-photo').forEach(field => {
			const input = field.querySelector('input[type="file"]'); const preview = field.querySelector('.tbreg-photo-preview');
			const controls = field.querySelector('.tbreg-camera-controls'); const video = field.querySelector('video');
			const open = field.querySelector('[data-camera-open]'); const take = field.querySelector('[data-camera-take]'); const cancel = field.querySelector('[data-camera-cancel]'); const status = field.querySelector('.tbreg-camera-status');
			let stream, previewUrl, opening = false, disposed = false, cameraVersion = 0;
			const stop = () => { cameraVersion++; if (stream) stream.getTracks().forEach(track => track.stop()); stream = null; video.srcObject = null; video.hidden = true; take.hidden = true; cancel.hidden = true; open.disabled = false; };
			const cleanup = () => { disposed = true; stop(); if (previewUrl) URL.revokeObjectURL(previewUrl); };
			input.addEventListener('change', () => {
				stop(); if (previewUrl) URL.revokeObjectURL(previewUrl); preview.hidden = !input.files.length;
				if (input.files.length) { previewUrl = URL.createObjectURL(input.files[0]); preview.src = previewUrl; }
			});
			if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.DataTransfer) {
				controls.hidden = false;
				open.addEventListener('click', async () => {
					if (opening || disposed) return; opening = true; open.disabled = true; status.textContent = ''; const version = ++cameraVersion;
					try {
						const acquired = await navigator.mediaDevices.getUserMedia({video:{facingMode:'user',width:{ideal:800},height:{ideal:800}},audio:false});
						if (disposed || version !== cameraVersion) { acquired.getTracks().forEach(track=>track.stop()); return; }
						stream = acquired; video.srcObject = stream; video.hidden = false; take.hidden = false; cancel.hidden = false; await video.play();
					} catch(error) { stop(); status.textContent = status.dataset.cameraError; }
					finally { opening = false; if (!stream) open.disabled = false; }
				});
				take.addEventListener('click', () => {
					if (!stream || !video.videoWidth) return;
					const version = cameraVersion; const canvas = document.createElement('canvas'); const scale = Math.min(1,800/Math.max(video.videoWidth,video.videoHeight));
					canvas.width = Math.round(video.videoWidth*scale); canvas.height = Math.round(video.videoHeight*scale); canvas.getContext('2d').drawImage(video,0,0,canvas.width,canvas.height);
					canvas.toBlob(blob => {
						if (!blob || disposed || version !== cameraVersion) return;
						try { const transfer = new DataTransfer(); transfer.items.add(new File([blob],'fotografia.jpg',{type:'image/jpeg'})); input.files = transfer.files; }
						catch(error) { stop(); status.textContent=status.dataset.cameraError; return; }
						input.dispatchEvent(new Event('change',{bubbles:true})); status.textContent = status.dataset.cameraCaptured;
					},'image/jpeg',0.8);
				});
				cancel.addEventListener('click',stop);
			}
			window.addEventListener('pagehide',cleanup); document.addEventListener('visibilitychange',()=>{if(document.hidden) stop();});
			form.addEventListener('tbreg-success',cleanup); form.addEventListener('reset',()=>{stop(); if(previewUrl) URL.revokeObjectURL(previewUrl); preview.hidden=true; status.textContent='';});
		});
		if (!window.fetch || !window.FormData) return;
		const summary = form.querySelector('.tbreg-summary');
		const submit = form.querySelector('[type="submit"]');
		let busy = false;
		const headers = () => form.dataset.restNonce ? { 'X-WP-Nonce': form.dataset.restNonce } : {};
		// Un token fresco permite usar la página aun si el HTML procede de caché.
		const tokenReady = (async () => {
			try {
				const url = new URL(form.dataset.tokenEndpoint, window.location.href);
				url.searchParams.set('tbreg_refresh', String(Date.now()));
				const response = await fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store', headers: headers() });
				const data = await response.json();
				if (response.ok && typeof data.tbreg_ts === 'string' && typeof data.tbreg_token === 'string') {
					form.elements.tbreg_ts.value = data.tbreg_ts; form.elements.tbreg_token.value = data.tbreg_token;
				}
			} catch (error) { /* El token del HTML conserva el respaldo de POST tradicional. */ }
		})();
		function clearErrors() {
			summary.hidden = true; summary.replaceChildren();
			form.querySelectorAll('[data-tbreg-error]').forEach(node => { node.textContent = ''; node.hidden = true; });
			form.querySelectorAll('[aria-invalid]').forEach(node => node.setAttribute('aria-invalid', 'false'));
		}
		function showErrors(message, errors = {}) {
			summary.hidden = false; summary.replaceChildren();
			const paragraph = document.createElement('p'); paragraph.textContent = message; summary.append(paragraph);
			const list = document.createElement('ul'); let first;
			Object.entries(errors).forEach(([key, text]) => {
				const field = Array.from(form.querySelectorAll('[data-tbreg-field]')).find(node => node.dataset.tbregField === key);
				if (!field) return;
				const error = field.querySelector('[data-tbreg-error]'); error.textContent = String(text); error.hidden = false;
				field.querySelectorAll('[aria-invalid]').forEach(node => node.setAttribute('aria-invalid', 'true'));
				const control = field.querySelector('input:not([type="hidden"]),select,textarea');
				if (!control) return;
				if (!first) first = control;
				const label = field.querySelector('legend,label'); const item = document.createElement('li'); const link = document.createElement('a');
				link.href = '#' + control.id; link.textContent = (label ? label.textContent.trim() + ': ' : '') + String(text);
				link.addEventListener('click', event => { event.preventDefault(); control.focus(); }); item.append(link); list.append(item);
			});
			if (list.children.length) summary.append(list);
			(first || summary).focus();
		}
		function resetCaptcha() {
			form.querySelectorAll('.tbreg-captcha[data-widget-id]').forEach(container => {
				if (container.dataset.provider === 'turnstile' && window.turnstile) turnstile.reset(container.dataset.widgetId);
				if (container.dataset.provider === 'recaptcha' && window.grecaptcha) grecaptcha.reset(Number(container.dataset.widgetId));
			});
		}
		form.addEventListener('submit', async event => {
			event.preventDefault(); if (busy) return;
			clearErrors();
			if (!form.reportValidity()) return;
			const groupErrors = {};
			form.querySelectorAll('fieldset[data-required="true"]').forEach(group => {
				if (!group.querySelector('input:checked')) groupErrors[group.closest('[data-tbreg-field]').dataset.tbregField] = form.dataset.groupRequired;
			});
			if (Object.keys(groupErrors).length) { showErrors(form.dataset.groupRequired, groupErrors); return; }
			busy = true; submit.disabled = true; form.setAttribute('aria-busy', 'true');
			try {
				await tokenReady;
				const response = await fetch(form.dataset.endpoint, { method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: headers(), body: new FormData(form) });
				const data = await response.json();
				if (!response.ok || data.success !== true) { showErrors(typeof data.message === 'string' ? data.message : form.dataset.networkError, data.data && data.data.fields ? data.data.fields : {}); resetCaptcha(); return; }
				if (data.redirect_url && !data.pdf_url) {
					const target = new URL(data.redirect_url, window.location.href);
					if (target.origin === window.location.origin) { window.location.assign(target.href); return; }
				}
				const success = document.createElement('div'); success.className = 'tbreg-success'; success.setAttribute('role', 'status'); success.tabIndex = -1; success.textContent = data.message;
				if (typeof data.pdf_url === 'string' && data.pdf_url) {
					const target = new URL(data.pdf_url, window.location.href);
					if (target.origin === window.location.origin) { const link = document.createElement('a'); link.href = target.href; link.className = 'tbreg-pdf-download'; link.textContent = form.dataset.pdfLabel; link.referrerPolicy = 'no-referrer'; success.append(document.createElement('br'),link); }
				}
				form.dispatchEvent(new Event('tbreg-success'));
				form.after(success); form.hidden = true; success.focus();
			} catch (error) { showErrors(form.dataset.networkError); resetCaptcha(); }
			finally { busy = false; submit.disabled = false; form.setAttribute('aria-busy', 'false'); }
		});
		// En la respuesta PRG, el servidor también aplica autofocus sin JavaScript.
		const initialError = form.querySelector('[aria-invalid="true"]'); if (initialError) initialError.focus();
	});
	}
	document.addEventListener('tbreg:mount', event => mount(event.detail || document));
	mount(document);
}());
