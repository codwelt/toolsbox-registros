/* global tbreg_builder, jQuery */
(function () {
	'use strict';
	const config = window.tbreg_builder || { labels: {} };
	const labels = config.labels;
	const clone = value => JSON.parse(JSON.stringify(value));
	const el = (tag, text, className) => {
		const node = document.createElement(tag);
		if (text !== undefined) node.textContent = text;
		if (className) node.className = className;
		return node;
	};
	const button = (text, callback, className = 'button') => {
		const node = el('button', text, className);
		node.type = 'button';
		node.addEventListener('click', callback);
		return node;
	};
	document.querySelectorAll('[data-tbreg-copy], [data-tbreg-copy-target]').forEach(node => {
		node.addEventListener('click', async () => {
			const status = document.getElementById('tbreg-copy-status') || document.getElementById('tbreg-builder-status');
			const input = node.dataset.tbregCopyTarget ? document.getElementById(node.dataset.tbregCopyTarget) : node.parentElement.querySelector('input');
			if (status) status.textContent = '';
			try {
				await navigator.clipboard.writeText(input ? input.value : node.dataset.tbregCopy);
				if (status) status.textContent = labels.copied;
			} catch (error) {
				if (input) { input.focus(); input.select(); }
				if (status) status.textContent = labels.copyManual;
			}
		});
	});
	const form = document.getElementById('tbreg-editor');
	if (!form) return;
	const slugInput = document.getElementById('tbreg-slug');
	const slugSummary = document.getElementById('tbreg-slug-summary');
	if (slugInput && slugSummary) slugInput.addEventListener('input', () => {
		slugSummary.textContent = slugInput.value || labels.slugAutomatic;
	});
	const list = document.getElementById('tbreg-fields');
	const hidden = document.getElementById('tbreg-fields-json');
	const used = new Set(config.used);
	let fields = clone(config.fields);
	let changed = false;
	let submitting = false;
	let serial = 0;
	const saveStatus = document.getElementById('tbreg-builder-status');
	const savedStatus = saveStatus.textContent;
	const saveButtons = [...form.querySelectorAll('[data-tbreg-save]')];
	const showSaveStatus = text => {
		if (saveStatus.textContent !== text) saveStatus.textContent = text;
		form.querySelectorAll('[data-tbreg-save-status]').forEach(node => { node.textContent = text; });
	};
	const publishDetails = form.querySelector('.tbreg-publish-details');
	if (publishDetails && window.matchMedia) {
		const desktop = window.matchMedia('(min-width: 1100px)');
		const adaptPanel = () => { publishDetails.open = desktop.matches; };
		adaptPanel(); desktop.addEventListener('change', adaptPanel);
	}
	const mobileSavebar = form.querySelector('.tbreg-mobile-savebar');
	if (mobileSavebar && window.IntersectionObserver) {
		const observer = new IntersectionObserver(entries => {
			mobileSavebar.hidden = entries[0].isIntersecting;
		}, { rootMargin: '-46px 0px 0px 0px', threshold: 0 });
		observer.observe(saveButtons[0]);
	}
	const updatePublishSummary = () => {
		const status = form.elements.namedItem('tbreg_status');
		const warning = document.getElementById('tbreg-email-warning');
		if (warning) warning.hidden = status.value !== 'published' || fields.some(field => !field.archived && field.role === 'email');
		const capacity = form.elements.namedItem('tbreg_settings[capacity]');
		const progress = document.getElementById('tbreg-capacity-progress');
		if (capacity && progress) {
			const limit = Number.isFinite(capacity.valueAsNumber) && capacity.valueAsNumber > 0 ? capacity.valueAsNumber : 0;
			document.getElementById('tbreg-capacity-summary').textContent = limit ? String(limit) : '∞';
			progress.hidden = !limit; progress.max = limit || 1; progress.value = Math.min(Number(progress.dataset.count), limit || 1);
		}
		for (const key of ['opens', 'closes']) {
			const input = form.elements.namedItem('tbreg_settings[' + key + '_at]');
			const output = document.getElementById('tbreg-' + key + '-summary');
			if (input && output) output.textContent = input.value ? input.value.replace('T', ' ').slice(0, 16) : labels.noDate;
		}
	};
	const savedRedirectUrl = form.elements.namedItem('tbreg_settings[redirect_url]')?.value || '';
	const updateSettingsUI = () => {
		const setting = name => form.elements.namedItem('tbreg_settings[' + name + ']');
		const mode = form.querySelector('[name="tbreg_after_submit"]:checked')?.value;
		const visible = {
			waitlist: Number(setting('capacity')?.value) > 0,
			pdf: setting('pdf_enabled')?.checked,
			message: mode !== 'redirect',
			redirect: mode === 'redirect',
			notify_admin: form.elements.namedItem('tbreg_settings[notify_admin][enabled]')?.checked,
			confirm_attendee: form.elements.namedItem('tbreg_settings[confirm_attendee][enabled]')?.checked
		};
		form.querySelectorAll('[data-tbreg-dependent]').forEach(panel => { panel.hidden = !visible[panel.dataset.tbregDependent]; });
		const url = setting('redirect_url');
		if (url) { url.disabled = mode === 'message'; url.required = mode === 'redirect'; url.setAttribute('aria-describedby', 'tbreg-redirect-help'); }
		const redirectHelp = document.getElementById('tbreg-redirect-help');
		if (redirectHelp && url) {
			const destination = url.value.trim() || savedRedirectUrl;
			redirectHelp.hidden = mode !== 'message' || !destination;
			redirectHelp.textContent = destination ? labels.redirectRemoval.replace('%s', destination) : '';
			if (redirectHelp.hidden) url.removeAttribute('aria-describedby');
		}
		const capacity = setting('capacity');
		if (capacity) { capacity.setAttribute('aria-describedby', 'tbreg-capacity-help'); capacity.setAttribute('aria-controls', 'tbreg-waitlist-options'); }
		const warning = document.getElementById('tbreg-duplicate-email-warning');
		if (warning) warning.hidden = setting('prevent_duplicates').value !== 'email' || fields.some(field => !field.archived && field.role === 'email');
	};
	const newKey = () => {
		const bytes = new Uint8Array(8);
		window.crypto.getRandomValues(bytes);
		return 'f_' + Array.from(bytes, value => value.toString(16).padStart(2, '0')).join('');
	};
	// Vista previa: sanitize_title() en PHP genera el valor definitivo al guardar.
	const slug = text => text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim().replace(/[^a-z0-9\s_-]/g, '').replace(/[\s-]+/g, '-');
	const isChoice = field => ['select', 'radio', 'checkbox'].includes(field.type);
	const hasPlaceholder = type => ['text', 'tel', 'document', 'textarea', 'email', 'url', 'number'].includes(type);
	const hasLength = type => ['text', 'tel', 'document', 'textarea', 'email', 'url'].includes(type);
	const hasPattern = type => ['text', 'tel', 'document'].includes(type);
	const rolesFor = type => type === 'email' ? ['email'] : type === 'document' ? ['document'] : type === 'text' ? ['name', 'document'] : type === 'textarea' ? ['name'] : [];
	const normalize = field => {
		field.validation = field.validation || {};
		if (!hasPlaceholder(field.type)) field.placeholder = '';
		if (!rolesFor(field.type).includes(field.role)) field.role = null;
		if (['heading', 'hidden'].includes(field.type)) field.required = false;
		if (field.type === 'photo') field.default = '';
		if (field.type === 'consent') { field.required = true; field.default = ''; }
		if (field.type === 'heading') { field.default = ''; field.width = 'full'; }
		if (field.type !== 'hidden') field.query_param = '';
		for (const name of ['min', 'max', 'minlength', 'maxlength', 'pattern']) {
			const allowed = name.includes('length') ? hasLength(field.type) : name === 'pattern' ? hasPattern(field.type) : ['number', 'date', 'time'].includes(field.type);
			if (!allowed) field.validation[name] = null;
		}
	};
	const sync = (dirty = true) => {
		fields.forEach(normalize);
		hidden.value = JSON.stringify(fields);
		if (dirty) {
			changed = true;
			showSaveStatus(labels.changed);
		}
		updatePublishSummary();
		updateSettingsUI();
		refreshSummaries();
	};
	const changedControl = event => { if (!event.target.closest('[data-tbreg-ui]')) sync(); };
	form.addEventListener('input', changedControl);
	form.addEventListener('change', changedControl);
	window.addEventListener('beforeunload', event => {
		if (changed) { event.preventDefault(); event.returnValue = ''; }
	});
	window.addEventListener('pageshow', event => {
		if (!event.persisted) return;
		submitting = false; changed = true; showSaveStatus(labels.changed);
		saveButtons.forEach(button => { button.disabled = false; });
	});
	const activateTab = name => {
		form.querySelectorAll('.tbreg-tabs [role="tab"]').forEach(tab => {
			const active = tab.dataset.tab === name;
			tab.setAttribute('aria-selected', String(active));
			tab.tabIndex = active ? 0 : -1;
			document.getElementById(tab.getAttribute('aria-controls')).hidden = !active;
		});
	};
	const tabs = Array.from(form.querySelectorAll('.tbreg-tabs [role="tab"]'));
	form.querySelectorAll('[data-tbreg-settings-link]').forEach(link => link.addEventListener('click', event => {
		event.preventDefault(); activateTab('settings'); document.getElementById('tbreg-opens-at').focus();
	}));
	document.addEventListener('keydown', event => {
		if (!(event.ctrlKey || event.metaKey) || event.altKey || event.key.toLowerCase() !== 's') return;
		event.preventDefault();
		if (event.repeat || submitting) return;
		if (form.requestSubmit) form.requestSubmit(saveButtons[0]); else saveButtons[0].click();
	});
	// La validación nativa debe mostrar también los controles de pestañas ocultas.
	form.addEventListener('invalid', event => {
		const panel = event.target.closest('[role="tabpanel"]');
		if (panel) {
			activateTab('fields');
			const card = event.target.closest('.tbreg-field-details');
			if (card) { card.open = true; card.querySelector('[aria-controls="' + panel.id + '"]')?.click(); }
			else activateTab(panel.id.replace('tbreg-panel-', ''));
		}
	}, true);
	tabs.forEach((tab, index) => {
		tab.addEventListener('click', () => activateTab(tab.dataset.tab));
		tab.addEventListener('keydown', event => {
			if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
			event.preventDefault();
			const target = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
			activateTab(tabs[target].dataset.tab);
			tabs[target].focus();
		});
	});
	const mergePicker = document.getElementById('tbreg-merge-picker');
	const mergeHome = mergePicker.parentElement;
	const mergeSearch = document.getElementById('tbreg-merge-search');
	const mergeChoices = document.getElementById('tbreg-merge-choices');
	const mergeStatus = document.getElementById('tbreg-merge-status');
	let mergeTrigger = null;
	let mergeTarget = null;
	let mergeRange = [0, 0];
	const closeMergePicker = (restoreFocus = true) => {
		mergePicker.hidden = true; if (mergePicker.parentElement !== mergeHome) mergeHome.append(mergePicker);
		if (mergeTrigger) { mergeTrigger.setAttribute('aria-expanded', 'false'); if (restoreFocus) mergeTrigger.focus(); }
	};
	const visibleMergeChoices = () => [...mergeChoices.querySelectorAll('button')].filter(node => !node.hidden);
	const filterMergeChoices = () => {
		const simplify = text => text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase();
		const query = simplify(mergeSearch.value.trim());
		mergeChoices.querySelectorAll('button').forEach(node => { node.hidden = !simplify(node.textContent).includes(query); });
		mergeChoices.querySelectorAll('section').forEach(group => { group.hidden = ![...group.querySelectorAll('button')].some(node => !node.hidden); });
		document.getElementById('tbreg-merge-empty').hidden = visibleMergeChoices().length > 0;
	};
	const openMergePicker = trigger => {
		if (mergeTrigger === trigger && !mergePicker.hidden) { closeMergePicker(); return; }
		closeMergePicker(false); mergeTrigger = trigger; mergeTarget = document.getElementById(trigger.dataset.tbregMergeTarget);
		mergeRange = [mergeTarget.selectionStart ?? mergeTarget.value.length, mergeTarget.selectionEnd ?? mergeTarget.value.length];
		mergeChoices.replaceChildren();
		const groups = [
			[labels.mergeGeneral, [[labels.mergeEvent, '{event_name}'], [labels.mergeEntry, '{entry_id}'], [labels.mergeAll, '{all_fields}']]],
			[labels.mergeFields, fields.filter(field => !field.archived && !['heading', 'photo'].includes(field.type)).map(field => [field.label, '{field:' + field.key + '}'])]
		];
		groups.forEach(([heading, choices]) => {
			if (!choices.length) return;
			const group = el('section'); group.append(el('h4', heading));
			choices.forEach(([text, token]) => group.append(button(text, () => {
				mergeTarget.setRangeText(token, ...mergeRange, 'end');
				closeMergePicker(false); mergeTarget.focus(); mergeTarget.dispatchEvent(new Event('input', { bubbles: true }));
				mergeStatus.textContent = labels.mergeInserted.replace('%s', text);
			}, 'button tbreg-merge-choice')));
			mergeChoices.append(group);
		});
		trigger.closest('.tbreg-merge-control').append(mergePicker); mergePicker.hidden = false;
		trigger.setAttribute('aria-expanded', 'true'); mergeSearch.value = ''; filterMergeChoices(); mergeSearch.focus();
	};
	form.querySelectorAll('[data-tbreg-merge-target]').forEach(trigger => {
		trigger.hidden = false;
		trigger.addEventListener('click', () => openMergePicker(trigger));
		trigger.addEventListener('keydown', event => { if (event.key === 'ArrowDown') { event.preventDefault(); openMergePicker(trigger); visibleMergeChoices()[0]?.focus(); } });
	});
	mergeSearch.addEventListener('input', filterMergeChoices);
	document.getElementById('tbreg-merge-close').addEventListener('click', () => closeMergePicker());
	mergePicker.addEventListener('keydown', event => {
		if (event.key === 'Escape') { event.preventDefault(); closeMergePicker(); return; }
		const choices = visibleMergeChoices(); const index = choices.indexOf(document.activeElement);
		if (event.key === 'Enter' && event.target === mergeSearch) { event.preventDefault(); choices[0]?.click(); return; }
		if (!['ArrowUp', 'ArrowDown', 'Home', 'End'].includes(event.key) || (index < 0 && !['ArrowUp', 'ArrowDown'].includes(event.key))) return;
		event.preventDefault(); if (!choices.length) return;
		const next = event.key === 'Home' ? 0 : event.key === 'End' ? choices.length - 1 : index < 0 ? (event.key === 'ArrowUp' ? choices.length - 1 : 0) : (index + (event.key === 'ArrowUp' ? -1 : 1) + choices.length) % choices.length;
		choices[next].focus();
	});
	mergePicker.addEventListener('focusout', event => { if (!mergePicker.hidden && !mergePicker.contains(event.relatedTarget) && event.relatedTarget !== mergeTrigger) closeMergePicker(false); });
	document.addEventListener('click', event => { if (!mergePicker.hidden && !mergePicker.contains(event.target) && !mergeTrigger.contains(event.target)) closeMergePicker(false); });
	const control = (parent, title, value, update, choices, type = 'text') => {
		const wrapper = el('label', undefined, 'tbreg-control');
		wrapper.append(el('span', title));
		const input = el(choices ? 'select' : type === 'textarea' ? 'textarea' : 'input');
		if (choices) Object.entries(choices).forEach(([key, label]) => {
			const option = el('option', label); option.value = key; input.append(option);
		});
		else if (type !== 'textarea') input.type = type;
		input.value = value === null || value === undefined ? '' : value;
		input.addEventListener(choices ? 'change' : 'input', () => { update(input.value); sync(); });
		wrapper.append(input); parent.append(wrapper);
		return input;
	};
	const move = (collection, item, step) => {
		const index = collection.indexOf(item);
		const target = index + step;
		if (target >= 0 && target < collection.length) {
			collection.splice(index, 1); collection.splice(target, 0, item); sync();
		}
	};
	const pendingRelated = new Set();
	const refreshRelatedOptions = field => {
		if (pendingRelated.has(field.key)) return;
		pendingRelated.add(field.key);
		queueMicrotask(() => {
			pendingRelated.delete(field.key);
			fields.filter(child => child.key !== field.key && !child.archived && child.options.some(option => Object.hasOwnProperty.call(option.when || {}, field.key))).forEach(child => {
				const host = document.getElementById('tbreg-section-' + child.key + '-options-panel'); if (!host) return;
				if (window.jQuery && jQuery.fn.sortable) jQuery(host).find('.tbreg-options.ui-sortable').sortable('destroy');
				host.replaceChildren(); renderOptions(host, child); const defaults = el('div', undefined, 'tbreg-default'); defaults.dataset.defaultKey = child.key; host.append(defaults); renderDefault(defaults, child);
			});
		});
	};
	const refreshDefault = field => {
		const host = form.querySelector('[data-default-key="' + field.key + '"]');
		if (host) { host.replaceChildren(); renderDefault(host, field); }
	};
	const remapDefault = (field, oldValue, value) => {
		if (!oldValue) return;
		fields.forEach(child=>(child.options||[]).forEach(option=>{if(option.when && option.when[field.key]) option.when[field.key]=option.when[field.key].map(item=>item===oldValue?value:item);}));
		if (Array.isArray(field.default)) field.default = field.default.map(item => item === oldValue ? value : item);
		else if (field.default === oldValue) field.default = value;
	};
	const renderDefault = (parent, field) => {
		if (field.type === 'checkbox') {
			const group = el('fieldset'); group.append(el('legend', labels.default)); parent.append(group);
			field.options.forEach(option => {
				const label = el('label', undefined, 'tbreg-checkbox'); const input = el('input'); input.type = 'checkbox'; input.value = option.value;
				input.checked = Array.isArray(field.default) && field.default.includes(option.value);
				input.addEventListener('change', () => { field.default = Array.from(group.querySelectorAll('input:checked'), node => node.value); sync(); });
				label.append(input, document.createTextNode(option.label)); group.append(label);
			});
		} else if (isChoice(field)) {
			const choices = { '': labels.none }; field.options.forEach(option => { if (option.value) choices[option.value] = option.label; });
			control(parent, labels.default, field.default, value => { field.default = value; }, choices);
		} else {
			const type = ['number', 'date', 'time'].includes(field.type) ? field.type : 'text';
			const input = control(parent, labels.default, field.default, value => { field.default = value; }, null, type);
			if (type === 'number') input.step = 'any';
		}
	};
	// UI state never becomes part of the saved field schema.
	const sections = new Map();
	const optionViews = new Map();
	const parentViews = new Map();
	const parentsFor = field => fields.filter(parent => !parent.archived && parent.key !== field.key && ['select', 'radio'].includes(parent.type));
	const primaryParent = field => {
		if (parentViews.has(field.key)) return parentViews.get(field.key);
		const keys = field.options.flatMap(option => Object.keys(option.when || {}));
		const key = keys[0] || ''; parentViews.set(field.key, key); return key;
	};
	const setRule = (option, key, values) => {
		if (values === null) {
			if (option.when) { delete option.when[key]; if (!Object.keys(option.when).length) delete option.when; }
		} else {
			if (!option.when || Array.isArray(option.when)) option.when = {};
			option.when[key] = values;
		}
		sync();
	};
	const ruleSummary = option => Object.entries(option.when || {}).map(([key, values]) => {
		const parent = fields.find(item => item.key === key);
		return (parent ? parent.label : key) + ' = ' + values.map(value => parent?.options.find(choice => choice.value === value)?.label || value).join(labels.or);
	}).join(labels.and) || labels.always;
	const renderRuleChoices = (host, field, option, key, changedRule) => {
		const parent = fields.find(item => item.key === key);
		if (!parent) return;
		const group = el('fieldset', undefined, 'tbreg-rule-chips tbreg-option-conditions');
		const legend = el('legend', parent.label); legend.className = 'screen-reader-text'; group.append(legend);
		const unrestricted = !Object.hasOwnProperty.call(option.when || {}, key);
		const always = button(labels.always, () => { setRule(option, key, unrestricted ? [] : null); changedRule(null); }, 'tbreg-always-switch');
		always.setAttribute('role', 'switch'); always.setAttribute('aria-checked', String(unrestricted));
		const choices = el('span', undefined, 'tbreg-rule-values'); choices.id = 'tbreg-rule-values-' + (++serial); always.setAttribute('aria-controls', choices.id);
		always.title = labels.alwaysHelp; always.disabled = unrestricted && Object.keys(option.when || {}).length >= 8; group.append(always, choices);
		parent.options.forEach(choice => {
			const label = el('label', undefined, 'tbreg-rule-chip');
			const check = el('input'); check.type = 'checkbox'; check.value = choice.value;
			check.checked = !unrestricted && option.when[key].includes(choice.value);
			check.disabled = unrestricted;
			check.addEventListener('change', () => {
				const selected = [...group.querySelectorAll('input:checked')].map(input => input.value);
				setRule(option, key, selected); changedRule(choice.value);
			});
			label.append(check, document.createTextNode(choice.label)); choices.append(label);
		});
		group.classList.toggle('tbreg-rule-invalid', !unrestricted && !option.when[key].length);
		host.append(group);
	};
	const renderConditions = (host, field, option, primary) => {
		const advanced = el('details', undefined, 'tbreg-extra-conditions');
		const summary = el('summary', ruleSummary(option)); advanced.append(summary);
		const refresh = () => { summary.textContent = ruleSummary(option); };
		Object.entries(option.when || {}).filter(([key]) => key !== primary).forEach(([key]) => {
			const line = el('div', undefined, 'tbreg-condition'); advanced.append(line);
			const candidates = parentsFor(field).filter(parent => parent.key === key || !Object.hasOwnProperty.call(option.when || {}, parent.key));
			const choices = Object.fromEntries(candidates.map(parent => [parent.key, parent.label]));
			if (!choices[key]) choices[key] = key;
			control(line, labels.condition, key, next => {
				if (next === key) return;
				setRule(option, key, null);
				const parent = fields.find(item => item.key === next);
				setRule(option, next, parent.options.length ? [parent.options[0].value] : []); render(field.key);
			}, choices);
			const ruleHost = el('div'); line.append(ruleHost);
			const refreshChoices = value => { ruleHost.replaceChildren(); renderRuleChoices(ruleHost, field, option, key, refreshChoices); refresh(); ([...ruleHost.querySelectorAll('input')].find(input => input.value === value) || ruleHost.querySelector('button')).focus(); };
			renderRuleChoices(ruleHost, field, option, key, refreshChoices);
			line.append(button(labels.remove, () => { setRule(option, key, null); render(field.key); }));
		});
		const add = button(labels.otherCondition, () => {
			const parent = parentsFor(field).find(item => !Object.hasOwnProperty.call(option.when || {}, item.key));
			if (!parent) return;
			setRule(option, parent.key, parent.options.length ? [parent.options[0].value] : []);
			render(field.key);
			const rows = form.querySelectorAll('[data-key="' + field.key + '"] .tbreg-option');
			const row = [...rows].find(row => row.tbregOption === option);
			if (row) { row.querySelector('.tbreg-option-actions').open = true; row.querySelector('.tbreg-extra-conditions').open = true; row.querySelector('.tbreg-extra-conditions button')?.focus(); }
		});
		add.disabled = Object.keys(option.when || {}).length >= 8 || !parentsFor(field).some(item => !Object.hasOwnProperty.call(option.when || {}, item.key));
		advanced.append(add); host.append(advanced);
	};
	const renderMatrix = (host, field, key) => {
		const parent = fields.find(item => item.key === key);
		if (!parent) return;
		const table = el('table', undefined, 'widefat striped tbreg-matrix');
		table.append(el('caption', field.label + ' × ' + parent.label));
		const head = el('thead'); const headings = el('tr'); head.append(headings); table.append(head);
		[field.label, labels.always, ...parent.options.map(option => option.label), labels.otherConditions].forEach(text => { const th = el('th', text); th.scope = 'col'; headings.append(th); });
		const body = el('tbody'); table.append(body);
		field.options.forEach(option => {
			const row = el('tr'); const th = el('th', option.label); th.scope = 'row'; row.append(th); body.append(row);
			const unrestricted = !Object.hasOwnProperty.call(option.when || {}, key);
			[null, ...parent.options.map(choice => choice.value)].forEach((value, index) => {
				const cell = el('td'); const check = el('input'); check.type = 'checkbox';
				check.setAttribute('aria-label', option.label + ': ' + (index ? parent.options[index - 1].label : labels.always));
				check.checked = value === null ? unrestricted : !unrestricted && option.when[key].includes(value);
				check.disabled = index === 0 ? unrestricted && Object.keys(option.when || {}).length >= 8 : unrestricted;
				if (index === 0) check.setAttribute('role', 'switch');
				check.addEventListener('change', () => {
					if (value === null) setRule(option, key, check.checked ? null : []);
					else {
						const allowed = [...(option.when?.[key] || [])];
						const selected = check.checked ? [...allowed, value] : allowed.filter(item => item !== value);
						setRule(option, key, parent.options.filter(choice => selected.includes(choice.value)).map(choice => choice.value));
					}
					const checks = row.querySelectorAll('input'); const always = !Object.hasOwnProperty.call(option.when || {}, key);
					checks.forEach((input, i) => { input.checked = i === 0 ? always : !always && option.when[key].includes(parent.options[i - 1].value); input.disabled = i === 0 ? always && Object.keys(option.when || {}).length >= 8 : always; });
					row.classList.toggle('tbreg-rule-invalid', !always && !option.when[key].length);
				});
				cell.append(check); row.append(cell);
			});
			const other = Object.fromEntries(Object.entries(option.when || {}).filter(([parentKey]) => parentKey !== key));
			row.append(el('td', Object.keys(other).length ? ruleSummary({ when: other }) : labels.noOtherRules));
			row.classList.toggle('tbreg-rule-invalid', !unrestricted && !option.when[key].length);
		});
		table.addEventListener('keydown', event => {
			if (event.target.tagName !== 'INPUT' || !['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'].includes(event.key)) return;
			event.preventDefault(); const rows = [...body.rows]; const row = event.target.closest('tr'); const inputs = [...row.querySelectorAll('input')]; const column = inputs.indexOf(event.target); const index = rows.indexOf(row);
			let target;
			if (event.key === 'Home' || event.key === 'End') {
				const enabled = inputs.filter(input => !input.disabled); target = event.key === 'Home' ? enabled[0] : enabled.at(-1);
			} else if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
				const step = event.key === 'ArrowLeft' ? -1 : 1;
				for (let next = column + step; next >= 0 && next < inputs.length; next += step) { if (!inputs[next].disabled) { target = inputs[next]; break; } }
			} else {
				const step = event.key === 'ArrowUp' ? -1 : 1;
				for (let next = index + step; next >= 0 && next < rows.length; next += step) { const candidate = rows[next].querySelectorAll('input')[column]; if (candidate && !candidate.disabled) { target = candidate; break; } }
			}
			if (target) target.focus();
		});
		const scroll = el('div', undefined, 'tbreg-matrix-scroll'); scroll.tabIndex = 0; scroll.setAttribute('role', 'region'); scroll.setAttribute('aria-label', field.label + ' × ' + parent.label); scroll.append(table); host.append(scroll);
	};
	const renderOptions = (parent, field) => {
		if (used.has(field.key)) parent.append(el('p', labels.optionWarning, 'description tbreg-warning'));
		const primary = primaryParent(field);
		const toolbar = el('div', undefined, 'tbreg-option-toolbar'); toolbar.dataset.tbregUi = '';
		const selector = el('label', undefined, 'tbreg-control'); selector.append(el('span', labels.parentField));
		const select = el('select');
		const candidates = { '': labels.none, ...Object.fromEntries(parentsFor(field).map(item => [item.key, item.label])) };
		if (primary && !candidates[primary]) candidates[primary] = primary;
		Object.entries(candidates).forEach(([value, text]) => { const option = el('option', text); option.value = value; select.append(option); }); select.value = primary;
		select.addEventListener('change', () => { parentViews.set(field.key, select.value); render(field.key); form.querySelector('[data-key="' + field.key + '"] .tbreg-option-toolbar select').focus(); });
		selector.append(select); toolbar.append(selector);
		const matrix = optionViews.get(field.key) === 'matrix' && !!primary;
		const toggle = button(matrix ? labels.optionTable : labels.matrix, () => { optionViews.set(field.key, matrix ? 'table' : 'matrix'); render(field.key); form.querySelector('[data-key="' + field.key + '"] .tbreg-matrix-toggle').focus(); }, 'button tbreg-matrix-toggle');
		toggle.disabled = !primary; toggle.setAttribute('aria-pressed', String(matrix)); toolbar.append(toggle); parent.append(toolbar, el('p', labels.parentHelp, 'description tbreg-parent-help'));
		if (matrix) renderMatrix(parent, field, primary);
		const columns = el('div', undefined, 'tbreg-options-heading'); columns.hidden = matrix; columns.setAttribute('aria-hidden', 'true'); columns.append(el('span'), el('span', labels.label), el('span', labels.value), el('span', labels.condition), el('span')); parent.append(columns);
		const optionList = el('ul', undefined, 'tbreg-options'); optionList.hidden = matrix; parent.append(optionList);
		field.options.forEach((option, optionIndex) => {
			const row = el('li', undefined, 'tbreg-option'); row.id = 'tbreg-option-' + (++serial); row.tbregOption = option;
			const handle = el('span', '↕', 'tbreg-option-handle'); handle.title = labels.drag; handle.setAttribute('aria-hidden', 'true'); row.append(handle);
			let valueInput; let valueText;
			const labelInput = control(row, labels.label, option.label, value => {
				option.label = value;
				if (option.auto) { const next = slug(value); remapDefault(field, option.value, next); option.value = next; valueInput.value = next; valueText.textContent = next; }
				refreshDefault(field); refreshRelatedOptions(field);
			}); labelInput.setAttribute('aria-label', labels.label + ' ' + (optionIndex + 1));
			const valueHost = el('div', undefined, 'tbreg-value'); row.append(valueHost);
			valueText = el('code', option.value); const valueEditor = el('div'); valueEditor.hidden = true;
			valueInput = control(valueEditor, labels.value, option.value, value => { remapDefault(field, option.value, value); option.auto = false; option.value = value; valueText.textContent = value; refreshDefault(field); refreshRelatedOptions(field); });
			valueInput.setAttribute('aria-label', labels.value + ' ' + (optionIndex + 1));
			const edit = button('✎', () => { valueEditor.hidden = !valueEditor.hidden; valueText.hidden = !valueEditor.hidden; edit.setAttribute('aria-expanded', String(!valueEditor.hidden)); if (!valueEditor.hidden) valueInput.focus(); }, 'tbreg-icon');
			edit.setAttribute('aria-label', labels.editValue + ' ' + (optionIndex + 1)); edit.setAttribute('aria-expanded', 'false'); valueEditor.id = row.id + '-value'; edit.setAttribute('aria-controls', valueEditor.id);
			valueHost.append(valueText, edit, valueEditor);
			const conditionCell = el('div', undefined, 'tbreg-primary-condition'); row.append(conditionCell);
			const refreshRule = value => { const always = value === null; conditionCell.replaceChildren(); renderRuleChoices(conditionCell, field, option, primary, refreshRule); const next = always ? conditionCell.querySelector('button') : [...conditionCell.querySelectorAll('input')].find(input => input.value === value); if (next) next.focus(); const summary = actions.querySelector('.tbreg-extra-conditions > summary'); if (summary) summary.textContent = ruleSummary(option); };
			renderRuleChoices(conditionCell, field, option, primary, refreshRule);
			const actions = el('details', undefined, 'tbreg-option-actions'); const actionSummary = el('summary', '⋯'); actionSummary.setAttribute('aria-label', labels.options + ' ' + (optionIndex + 1)); actions.append(actionSummary);
			const actionButtons = el('div', undefined, 'tbreg-option-buttons');
			actionButtons.append(button(labels.up, () => { move(field.options, option, -1); render(field.key); }), button(labels.down, () => { move(field.options, option, 1); render(field.key); }), button(labels.remove, () => {
				field.options.splice(field.options.indexOf(option), 1);
				if (Array.isArray(field.default)) field.default = field.default.filter(value => value !== option.value);
				else if (field.default === option.value) field.default = '';
				sync(); render(field.key);
			}));
			actionButtons.children[0].disabled = optionIndex === 0; actionButtons.children[1].disabled = optionIndex === field.options.length - 1;
			actions.append(actionButtons); renderConditions(actions, field, option, primary); row.append(actions); optionList.append(row);
		});
		if (window.jQuery && jQuery.fn.sortable) jQuery(optionList).sortable({ handle: '.tbreg-option-handle', items: '> li', update: () => { field.options = [...optionList.children].map(row => row.tbregOption); sync(); } });
		const tools = el('div', undefined, 'tbreg-option-tools'); parent.append(tools);
		tools.append(button(labels.addOption, () => {
			field.options.push({ label: '', value: '', auto: true }); optionViews.set(field.key, 'table'); sections.set(field.key, 'options'); sync(); render(field.key);
			form.querySelector('[data-key="' + field.key + '"] .tbreg-options li:last-child input').focus();
		}));
		const bulk = el('div', undefined, 'tbreg-bulk'); bulk.hidden = true; bulk.dataset.tbregUi = ''; bulk.id = 'tbreg-bulk-' + field.key;
		const help = el('label', labels.bulkHelp); const textarea = el('textarea'); textarea.id = bulk.id + '-text'; help.htmlFor = textarea.id;
		const status = el('p'); status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
		const paste = button(labels.bulkPaste, () => { bulk.hidden = !bulk.hidden; paste.setAttribute('aria-expanded', String(!bulk.hidden)); if (!bulk.hidden) textarea.focus(); }); paste.setAttribute('aria-expanded', 'false'); paste.setAttribute('aria-controls', bulk.id); tools.append(paste);
		bulk.append(help, textarea, button(labels.bulkApply, () => {
			const incoming = textarea.value.split(/\r?\n/).filter(line => line.trim()).map(line => {
				const parts = line.split('|'); return { label: parts[0].trim(), value: parts.length === 1 ? slug(parts[0].trim()) : parts.length === 2 ? parts[1].trim() : '' };
			});
			const values = new Set(field.options.map(option => option.value));
			if (!incoming.length || incoming.length + field.options.length > 500 || incoming.some(option => { if (!option.label || !option.value || values.has(option.value)) return true; values.add(option.value); return false; })) { status.textContent = labels.bulkInvalid; return; }
			field.options.push(...incoming); optionViews.set(field.key, 'table'); sync(); render(field.key); announce(labels.bulkAdded);
			form.querySelector('[data-key="' + field.key + '"] .tbreg-options li:nth-child(' + (field.options.length - incoming.length + 1) + ') input').focus();
		}), button(labels.cancel, () => { bulk.hidden = true; paste.setAttribute('aria-expanded', 'false'); paste.focus(); }), status); parent.append(bulk);
	};
	const inserter = document.getElementById('tbreg-inserter');
	const inserterEnd = document.getElementById('tbreg-inserter-end');
	const addFieldButton = document.getElementById('tbreg-add-field');
	const searchTypes = document.getElementById('tbreg-inserter-search');
	const expandAll = document.getElementById('tbreg-expand-all');
	let insertionBefore = null;
	let inserterTrigger = null;
	const announce = text => { document.getElementById('tbreg-field-status').textContent = text; };
	const closeInserter = (restoreFocus = true) => {
		inserter.hidden = true; inserterEnd.append(inserter);
		if (inserterTrigger) inserterTrigger.setAttribute('aria-expanded', 'false');
		if (restoreFocus) (inserterTrigger && inserterTrigger.isConnected ? inserterTrigger : addFieldButton).focus();
		insertionBefore = null;
	};
	const visibleTypes = () => [...inserter.querySelectorAll('[data-type]')].filter(node => !node.hidden);
	const filterTypes = () => {
		const query = searchTypes.value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase().trim();
		inserter.querySelectorAll('[data-type]').forEach(node => {
			node.hidden = !node.textContent.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase().includes(query);
		});
		inserter.querySelectorAll('.tbreg-inserter-group').forEach(group => { group.hidden = ![...group.querySelectorAll('[data-type]')].some(node => !node.hidden); });
		document.getElementById('tbreg-inserter-empty').hidden = visibleTypes().length > 0;
	};
	const openInserter = (trigger, before = null) => {
		closeInserter(false); insertionBefore = before; inserterTrigger = trigger;
		trigger.parentElement.append(inserter); inserter.hidden = false;
		trigger.setAttribute('aria-expanded', 'true'); searchTypes.value = ''; filterTypes(); searchTypes.focus();
	};
	addFieldButton.addEventListener('click', () => openInserter(addFieldButton));
	document.getElementById('tbreg-close-inserter').addEventListener('click', () => closeInserter());
	searchTypes.addEventListener('input', filterTypes);
	inserter.addEventListener('keydown', event => {
		if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); closeInserter(); return; }
		const choices = visibleTypes(); const index = choices.indexOf(document.activeElement);
		if (event.key === 'Enter' && event.target === searchTypes) { event.preventDefault(); if (choices[0]) choices[0].click(); return; }
		if (!['ArrowDown', 'ArrowUp', 'ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key) || (index < 0 && !['ArrowDown', 'ArrowUp'].includes(event.key))) return;
		event.preventDefault(); if (!choices.length) return;
		const next = event.key === 'Home' ? 0 : event.key === 'End' ? choices.length - 1 : index < 0 ? (event.key === 'ArrowUp' ? choices.length - 1 : 0) : (index + (['ArrowUp', 'ArrowLeft'].includes(event.key) ? -1 : 1) + choices.length) % choices.length;
		choices[next].focus();
	});
	const closeMenus = () => {
		list.querySelectorAll('.tbreg-field-menu').forEach(menu => {
			menu.querySelector('[role="menu"]').hidden = true;
			menu.querySelector('[aria-haspopup]').setAttribute('aria-expanded', 'false');
		});
	};
	document.addEventListener('click', event => {
		if (!inserter.hidden && !inserter.contains(event.target) && !inserterTrigger.contains(event.target)) closeInserter(false);
		if (!event.target.closest('.tbreg-field-menu')) closeMenus();
	});
	const updateExpandLabel = () => {
		const cards = [...list.querySelectorAll('.tbreg-field-details')];
		expandAll.disabled = cards.length === 0;
		expandAll.textContent = cards.length && cards.every(node => node.open) ? labels.collapseAll : labels.expandAll;
	};
	expandAll.addEventListener('click', () => {
		const cards = [...list.querySelectorAll('.tbreg-field-details')]; const open = !cards.every(node => node.open);
		cards.forEach(node => { node.open = open; }); updateExpandLabel();
	});
	function refreshSummaries() {
		const active = fields.filter(field => !field.archived);
		document.getElementById('tbreg-fields-count').textContent = String(active.length);
		active.forEach((field, index) => {
			const item = [...list.children].find(node => node.dataset.key === field.key); if (!item) return;
			item.classList.toggle('tbreg-field-heading', field.type === 'heading');
			item.querySelector('.tbreg-field-title').textContent = field.label || config.types[field.type];
			const chips = item.querySelector('.tbreg-summary-chips'); chips.replaceChildren();
			const chip = text => chips.append(el('span', text, 'tbreg-type-badge'));
			chip(config.types[field.type]);
			if (field.required) chip(labels.required);
			const width = el('span', field.width === 'half' ? '½' : field.width === 'third' ? '⅓' : labels.full, 'tbreg-type-badge');
			width.setAttribute('aria-label', labels.width + ': ' + labels[field.width || 'full']); chips.append(width);
			if (field.role === 'email') chip(labels.emailPrincipal);
			if (field.role === 'document') chip(labels.docPrincipal);
			const parents = [...new Set((field.options || []).flatMap(option => Object.keys(option.when || {})))];
			if (parents.length) chip('⇢ ' + labels.dependsOn.replace('%s', parents.map(key => fields.find(parent => parent.key === key)?.label || key).join(', ')));
			if (isChoice(field)) chip(field.options.length === 1 ? labels.oneOption : labels.optionCount.replace('%s', String(field.options.length)));
			item.querySelector('[data-action="up"]').disabled = index === 0;
			item.querySelector('[data-action="down"]').disabled = index === active.length - 1;
			item.querySelector('[aria-haspopup]').setAttribute('aria-label', labels.fieldActions.replace('%s', field.label));
			item.querySelector('.tbreg-insert-button').setAttribute('aria-label', labels.insertBefore.replace('%s', field.label));
		});
		updateExpandLabel();
	}
	const fieldMenu = (field, index, total) => {
		const menu = el('div', undefined, 'tbreg-field-menu'); const panel = el('div', undefined, 'tbreg-menu-panel');
		panel.id = 'tbreg-actions-' + field.key; panel.hidden = true; panel.setAttribute('role', 'menu');
		const toggle = button('⋯', () => {
			const open = panel.hidden; closeMenus(); panel.hidden = !open; toggle.setAttribute('aria-expanded', String(open));
			if (open) panel.querySelector('button:not(:disabled)').focus();
		}, 'tbreg-icon tbreg-menu-toggle');
		toggle.id = 'tbreg-menu-toggle-' + field.key; panel.setAttribute('aria-labelledby', toggle.id);
		toggle.setAttribute('aria-haspopup', 'menu'); toggle.setAttribute('aria-expanded', 'false'); toggle.setAttribute('aria-controls', panel.id);
		const duplicate = button(labels.duplicate, () => {
			const copy = clone(field); copy.key = newKey(); copy.label = labels.copyLabel.replace('%s', field.label); copy.archived = false; copy.role = null;
			fields.splice(fields.indexOf(field) + 1, 0, copy); sync(); render(copy.key); announce(labels.duplicated.replace('%s', copy.label));
		});
		const up = button(labels.up, () => { move(fields, field, -1); render(field.key, labels.up, false); }); up.dataset.action = 'up'; up.disabled = index === 0;
		const down = button(labels.down, () => { move(fields, field, 1); render(field.key, labels.down, false); }); down.dataset.action = 'down'; down.disabled = index === total - 1;
		const remove = button(labels.remove, () => {
			if (used.has(field.key)) { if (!window.confirm(labels.archiveWarning)) return; field.archived = true; }
			else fields.splice(fields.indexOf(field), 1);
			sync(); render(); const next = list.children[Math.min(index, list.children.length - 1)]; (next ? next.querySelector('summary') : addFieldButton).focus();
		});
		[duplicate, up, down, remove].forEach(node => { node.setAttribute('role', 'menuitem'); node.tabIndex = -1; panel.append(node); });
		menu.append(toggle, panel);
		menu.addEventListener('focusout', event => {
			if (!menu.contains(event.relatedTarget)) { panel.hidden = true; toggle.setAttribute('aria-expanded', 'false'); }
		});
		menu.addEventListener('keydown', event => {
			if (event.key === 'Escape') { event.preventDefault(); closeMenus(); toggle.focus(); return; }
			if (!['ArrowUp', 'ArrowDown', 'Home', 'End'].includes(event.key)) return;
			event.preventDefault(); const choices = [...panel.querySelectorAll('button:not(:disabled)')]; const current = choices.indexOf(document.activeElement);
			panel.hidden = false; toggle.setAttribute('aria-expanded', 'true');
			choices[event.key === 'Home' ? 0 : event.key === 'End' ? choices.length - 1 : current < 0 ? (event.key === 'ArrowUp' ? choices.length - 1 : 0) : (current + (event.key === 'ArrowUp' ? -1 : 1) + choices.length) % choices.length].focus();
		});
		return menu;
	};
	const renderCard = (field, index, total, openKeys) => {
		const item = el('li', undefined, 'tbreg-field'); item.dataset.key = field.key;
		const slot = el('div', undefined, 'tbreg-insert-slot'); const insert = button('+', () => openInserter(insert, field.key), 'button tbreg-insert-button');
		insert.setAttribute('aria-controls', 'tbreg-inserter'); insert.setAttribute('aria-expanded', 'false'); slot.append(insert); item.append(slot);
		const shell = el('div', undefined, 'tbreg-field-shell'); shell.append(fieldMenu(field, index, total)); item.append(shell);
		const details = el('details', undefined, 'tbreg-field-details'); details.open = openKeys.has(field.key); details.addEventListener('toggle', updateExpandLabel);
		const summary = el('summary');
		const handle = el('span', '↕', 'tbreg-field-handle'); handle.title = labels.drag; handle.setAttribute('aria-hidden', 'true');
		const title = el('strong', field.label || config.types[field.type], 'tbreg-field-title');
		const heading = el('span', undefined, 'tbreg-summary-text'); heading.append(title, el('span', undefined, 'tbreg-summary-chips'));
		summary.append(handle, heading); details.append(summary);
		const body = el('div', undefined, 'tbreg-field-body'); details.append(body);
		const navigation = el('div', undefined, 'tbreg-field-tabs'); navigation.setAttribute('role', 'tablist'); navigation.setAttribute('aria-label', field.label); navigation.dataset.tbregUi = ''; body.append(navigation);
		const panels = {};
		const names = ['general', ...(isChoice(field) ? ['options'] : []), ...(['number', 'date', 'time'].includes(field.type) || hasLength(field.type) || hasPattern(field.type) ? ['validation'] : []), 'advanced'];
		const activate = name => {
			sections.set(field.key, name);
			[...navigation.children].forEach(tab => { const active = tab.dataset.section === name; tab.setAttribute('aria-selected', String(active)); tab.tabIndex = active ? 0 : -1; panels[tab.dataset.section].hidden = !active; });
		};
		names.forEach((name, index) => {
			const tab = button(labels[name], () => activate(name), 'tbreg-field-tab'); tab.dataset.section = name; tab.id = 'tbreg-section-' + field.key + '-' + name; tab.setAttribute('role', 'tab');
			const panel = el('div', undefined, 'tbreg-field-section'); panel.id = tab.id + '-panel'; panel.setAttribute('role', 'tabpanel'); panel.setAttribute('aria-labelledby', tab.id); tab.setAttribute('aria-controls', panel.id);
			panels[name] = panel; navigation.append(tab); body.append(panel);
			tab.addEventListener('keydown', event => { if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return; event.preventDefault(); const next = event.key === 'Home' ? 0 : event.key === 'End' ? names.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + names.length) % names.length; activate(names[next]); navigation.children[next].focus(); });
		});
		const grid = el('div', undefined, 'tbreg-field-grid'); panels.general.append(grid);
		control(grid, labels.label, field.label, value => { field.label = value; title.textContent = value || config.types[field.type]; });
		const typeControl = control(grid, labels.type, field.type, value => {
			field.type = value; field.options = isChoice(field) ? [{ label: '', value: '', auto: true }] : [];
			if (value !== 'hidden') field.query_param = '';
			field.default = ''; sections.set(field.key, 'general'); normalize(field); render();
		}, config.types);
		typeControl.disabled = used.has(field.key);
		if (typeControl.disabled) grid.append(el('p', labels.lockedType, 'description'));
		control(grid, labels.help, field.help || '', value => { field.help = value; }, null, 'textarea');
		if (hasPlaceholder(field.type)) control(grid, labels.placeholder, field.placeholder || '', value => { field.placeholder = value; });
		if (!['heading', 'hidden'].includes(field.type)) {
			const required = el('label', undefined, 'tbreg-checkbox tbreg-switch');
			const check = el('input'); check.type = 'checkbox'; check.setAttribute('role', 'switch'); check.checked = !!field.required || field.type === 'consent'; check.disabled = field.type === 'consent';
			check.addEventListener('change', () => { field.required = check.checked; sync(); }); required.append(check, document.createTextNode(labels.required)); grid.append(required);
		}
		const advanced = el('div', undefined, 'tbreg-field-grid'); panels.advanced.append(advanced);
		if (field.type !== 'heading') control(advanced, labels.width, field.width || 'full', value => { field.width = value; }, { full: labels.full, half: labels.half, third: labels.third });
		const roles = rolesFor(field.type);
		if (roles.length) { const choices = { '': labels.none }; roles.forEach(role => { choices[role] = labels[role]; }); control(advanced, labels.role, field.role || '', value => { field.role = value || null; }, choices); }
		control(advanced, labels.css_class, field.css_class || '', value => { field.css_class = value; });
		if (field.type === 'hidden') control(advanced, labels.queryParam, field.query_param || '', value => { field.query_param = value; });
		const keyHost = el('div', undefined, 'tbreg-full tbreg-key-control'); keyHost.dataset.tbregUi = '';
		const keyLabel = el('label', labels.key); const keyInput = el('input', undefined, 'tbreg-key'); keyInput.readOnly = true; keyInput.value = field.key; keyInput.id = 'tbreg-key-' + field.key; keyLabel.htmlFor = keyInput.id;
		const keyStatus = el('p', undefined, 'description'); keyStatus.setAttribute('role', 'status'); keyStatus.setAttribute('aria-live', 'polite');
		keyHost.append(keyLabel, keyInput, button(labels.copyKey, async () => {
			keyStatus.textContent = '';
			try { await navigator.clipboard.writeText(field.key); keyStatus.textContent = labels.keyCopied; }
			catch (error) { keyInput.focus(); keyInput.select(); keyStatus.textContent = labels.keyManual; }
		}), keyStatus); advanced.append(keyHost);
		if (panels.validation) {
			const validation = el('div', undefined, 'tbreg-field-grid'); panels.validation.append(validation);
			if (['number', 'date', 'time'].includes(field.type)) ['min', 'max'].forEach(name => {
				const type = field.type; const input = control(validation, labels[name], field.validation[name], value => { field.validation[name] = value === '' ? null : type === 'number' ? Number(value) : value; }, null, type); if (type === 'number') input.step = 'any';
			});
			if (hasLength(field.type)) ['minlength', 'maxlength'].forEach(name => { const input = control(validation, labels[name], field.validation[name], value => { field.validation[name] = value === '' ? null : Number(value); }, null, 'number'); input.step = '1'; input.min = '0'; });
			if (hasPattern(field.type)) control(validation, labels.pattern, field.validation.pattern, value => { field.validation.pattern = value || null; });
		}
		if (isChoice(field)) renderOptions(panels.options, field);
		if (!['heading', 'consent', 'photo'].includes(field.type)) {
			const defaults = el('div', undefined, 'tbreg-default'); defaults.dataset.defaultKey = field.key; (panels.options || panels.general).append(defaults); renderDefault(defaults, field);
		}
		activate(names.includes(sections.get(field.key)) ? sections.get(field.key) : 'general');
		shell.append(details); return item;
	};
	function render(focusKey, focusLabel, openField = true) {
		const openKeys = new Set(Array.from(list.querySelectorAll('.tbreg-field-details[open]'), node => node.closest('.tbreg-field').dataset.key));
		closeInserter(false);
		if (focusKey && openField) openKeys.add(focusKey);
		if (window.jQuery && jQuery.fn.sortable) {
			jQuery(list).find('.tbreg-options.ui-sortable').sortable('destroy');
			if (jQuery(list).hasClass('ui-sortable')) jQuery(list).sortable('destroy');
		}
		list.replaceChildren();
		const active = fields.filter(field => !field.archived);
		fields = active.concat(fields.filter(field => field.archived));
		active.forEach((field, index) => list.append(renderCard(field, index, active.length, openKeys)));
		document.getElementById('tbreg-fields-empty').hidden = active.length > 0;
		const archived = document.getElementById('tbreg-archived');
		const archivedList = archived.querySelector('ul'); archivedList.replaceChildren();
		fields.filter(field => field.archived).forEach(field => archivedList.append(el('li', field.label + ' — ' + field.key)));
		archived.hidden = archivedList.children.length === 0;
		if (window.jQuery && jQuery.fn.sortable) jQuery(list).sortable({ handle: '.tbreg-field-handle', items: '> li', placeholder: 'tbreg-sort-placeholder', update: () => {
			const keys = Array.from(list.children, node => node.dataset.key);
			fields = keys.map(key => fields.find(field => field.key === key)).concat(fields.filter(field => field.archived)); sync();
		} });
		if (focusKey) {
			const card = Array.from(list.children).find(node => node.dataset.key === focusKey);
			const requested = focusLabel && card ? Array.from(card.querySelectorAll('button')).find(node => node.textContent === focusLabel && !node.disabled) : null;
			const target = requested || (card && card.querySelector(focusLabel ? '[data-action]:not(:disabled)' : '.tbreg-field-section:not([hidden]) input:not([type="checkbox"])'));
			if (target && target.closest('[role="menu"]')) {
				target.closest('[role="menu"]').hidden = false; card.querySelector('[aria-haspopup]').setAttribute('aria-expanded', 'true');
			}
			if (target) { const panel = target.closest('.tbreg-field-section'); if (panel) card.querySelector('[aria-controls="' + panel.id + '"]').click(); target.focus(); }
		}
		sync(false);
	}
	form.querySelectorAll('[data-type]').forEach(node => node.addEventListener('click', () => {
		const field = { key: newKey(), type: node.dataset.type, label: config.types[node.dataset.type], required: node.dataset.type === 'consent', archived: false, options: [], validation: {}, width: 'full', role: null, default: '' };
		if (isChoice(field)) field.options = [{ label: '', value: '', auto: true }];
		const index = insertionBefore === null ? -1 : fields.findIndex(item => item.key === insertionBefore);
		fields.splice(index < 0 ? fields.filter(item => !item.archived).length : index, 0, field);
		sync(); render(field.key); announce(labels.added.replace('%s', field.label));
	}));
	const applyTemplate = name => {
		const template = config.templates[name];
		if (!template || (fields.some(field => !field.archived) && !window.confirm(labels.templateWarning))) return;
		fields = fields.filter(field => field.archived || used.has(field.key)).map(field => ({ ...field, archived: true }));
		const incoming=clone(template.fields); const keyMap=Object.fromEntries(incoming.map(field=>[field.key,newKey()]));
		incoming.forEach(field=>{field.key=keyMap[field.key];(field.options||[]).forEach(option=>{if(option.when) option.when=Object.fromEntries(Object.entries(option.when).map(([parent,values])=>[keyMap[parent]||parent,values]));});});
		fields = fields.concat(incoming);
		if (template.settings && template.settings.pdf_enabled !== undefined) form.elements['tbreg_settings[pdf_enabled]'].checked = template.settings.pdf_enabled;
		if (template.settings && template.settings.receipt_text !== undefined) form.elements['tbreg_settings[receipt_text]'].value = template.settings.receipt_text;
		if (template.settings && template.settings.event) form.elements['tbreg_settings[event][modality]'].value = template.settings.event.modality;
		sync(); render(fields.find(field => !field.archived).key); document.querySelector('.tbreg-templates').open = false; announce(labels.templateApplied);
	};
	document.getElementById('tbreg-apply-template').addEventListener('click', () => applyTemplate(document.getElementById('tbreg-template').value));
	form.querySelectorAll('[data-tbreg-template]').forEach(node => node.addEventListener('click', () => applyTemplate(node.dataset.tbregTemplate)));
	const invalidField = () => {
		const keys = new Set(); const roles = new Set();
		return fields.find(field => {
			if (!/^[a-z0-9_]{3,64}$/.test(field.key) || keys.has(field.key)) return true; keys.add(field.key);
			if (field.archived) return false;
			if (!field.label.trim() || !config.types[field.type]) return true;
			if (field.role) { if (roles.has(field.role)) return true; roles.add(field.role); }
			if (isChoice(field)) {
				const values = new Set();
				if (!field.options.length || field.options.some(option => {
					if (!option.label.trim() || !option.value.trim() || values.has(option.value.trim())) return true;
					values.add(option.value.trim()); return false;
				})) return true;
				if (field.options.some(option => Object.values(option.when || {}).some(values => !values.length))) return true;
				const defaults = Array.isArray(field.default) ? field.default : [field.default];
				if (defaults.some(value => value && !values.has(value))) return true;
			}
			const v = field.validation || {};
			if (['min', 'max', 'minlength', 'maxlength'].some(name => v[name] !== null && v[name] !== undefined && ((name.includes('length') || field.type === 'number') ? (!Number.isFinite(v[name]) || (name.includes('length') && (!Number.isInteger(v[name]) || v[name] < 0))) : !/^\d{4}-\d{2}-\d{2}$|^\d{2}:\d{2}(?::\d{2})?$/.test(v[name])))) return true;
			return [['min', 'max'], ['minlength', 'maxlength']].some(([min, max]) => v[min] !== null && v[min] !== undefined && v[max] !== null && v[max] !== undefined && v[min] > v[max]);
		});
	};
	form.addEventListener('submit', event => {
		if (submitting) { event.preventDefault(); return; }
		sync(false);
		const invalid = invalidField();
		const error = document.getElementById('tbreg-builder-error');
		if (invalid) {
			event.preventDefault(); error.hidden = false; error.querySelector('p').textContent = labels.invalid;
			activateTab('fields'); render(invalid.key); error.scrollIntoView({ block: 'nearest' }); return;
		}
		error.hidden = true;
		// Espera a otros validadores antes de quitar la protección al salir.
		queueMicrotask(() => {
			if (event.defaultPrevented) return;
			changed = false; submitting = true; showSaveStatus(labels.saving);
			saveButtons.forEach(button => { button.disabled = true; });
		});
	});
	fields.forEach(normalize);
	render();
	showSaveStatus(savedStatus);
	updatePublishSummary();
}());
