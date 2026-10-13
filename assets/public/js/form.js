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
	document.querySelectorAll('.tbreg-form[data-preview="false"]').forEach(form => {
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
				if (data.redirect_url) {
					const target = new URL(data.redirect_url, window.location.href);
					if (target.origin === window.location.origin) { window.location.assign(target.href); return; }
				}
				const success = document.createElement('div'); success.className = 'tbreg-success'; success.setAttribute('role', 'status'); success.tabIndex = -1; success.textContent = data.message;
				form.after(success); form.hidden = true; success.focus();
			} catch (error) { showErrors(form.dataset.networkError); resetCaptcha(); }
			finally { busy = false; submit.disabled = false; form.setAttribute('aria-busy', 'false'); }
		});
		// En la respuesta PRG, el servidor también aplica autofocus sin JavaScript.
		const initialError = form.querySelector('[aria-invalid="true"]'); if (initialError) initialError.focus();
	});
}());
