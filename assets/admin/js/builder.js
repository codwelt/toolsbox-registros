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
		form.querySelectorAll('[role="tab"]').forEach(tab => {
			const active = tab.dataset.tab === name;
			tab.setAttribute('aria-selected', String(active));
			tab.tabIndex = active ? 0 : -1;
			document.getElementById(tab.getAttribute('aria-controls')).hidden = !active;
		});
	};
	const tabs = Array.from(form.querySelectorAll('[role="tab"]'));
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
		if (panel) activateTab(panel.id.replace('tbreg-panel-', ''));
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
	const renderConditions = (row, field, option) => {
		const host = el('div',undefined,'tbreg-option-conditions'); host.append(el('p',labels.conditionsHelp,'description')); row.append(host);
		option.when = option.when || {};
		Object.entries(option.when).forEach(([parentKey, allowed]) => {
			const line = el('div',undefined,'tbreg-condition'); host.append(line);
			const candidates = fields.filter(parent=>!parent.archived && parent.key!==field.key && ['select','radio'].includes(parent.type));
			const choices = Object.fromEntries(candidates.map(parent=>[parent.key,parent.label]));
			if (!choices[parentKey]) choices[parentKey] = parentKey;
			control(line,labels.condition,parentKey,next=>{if(next!==parentKey){ delete option.when[parentKey]; const parent=fields.find(item=>item.key===next); option.when[next]=parent && parent.options.length ? [parent.options[0].value] : []; sync(); render(field.key); }},choices);
			const parent = fields.find(item=>item.key===parentKey); const group=el('fieldset'); group.append(el('legend',labels.conditionValues)); line.append(group);
			(parent ? parent.options : []).forEach(choice=>{
				const label=el('label',undefined,'tbreg-checkbox');const check=el('input');check.type='checkbox';check.checked=allowed.includes(choice.value);
				check.addEventListener('change',()=>{option.when[parentKey]=Array.from(group.querySelectorAll('input:checked'),node=>node.value);sync();});check.value=choice.value;label.append(check,document.createTextNode(choice.label));group.append(label);
			});
			line.append(button(labels.remove,()=>{delete option.when[parentKey];sync();render(field.key);}));
		});
		const add=button(labels.addCondition,()=>{
			const parent=fields.find(item=>!item.archived && item.key!==field.key && ['select','radio'].includes(item.type) && !Object.hasOwnProperty.call(option.when,item.key));
			if(parent){option.when[parent.key]=parent.options.length?[parent.options[0].value]:[];sync();render(field.key);}
		});
		add.disabled=Object.keys(option.when).length>=8 || !fields.some(item=>!item.archived && item.key!==field.key && ['select','radio'].includes(item.type) && !Object.hasOwnProperty.call(option.when,item.key)); host.append(add);
	};
	const renderOptions = (parent, field) => {
		parent.append(el('h4', labels.options));
		if (used.has(field.key)) parent.append(el('p', labels.optionWarning, 'description tbreg-warning'));
		const optionList = el('ul', undefined, 'tbreg-options'); parent.append(optionList);
		field.options.forEach(option => {
			const row = el('li', undefined, 'tbreg-option');
			const rowId = 'tbreg-option-' + (++serial);
			row.id = rowId; row.tbregOption = option;
			const handle = el('span', '↕', 'tbreg-option-handle'); handle.title = labels.drag; handle.setAttribute('aria-hidden', 'true'); row.append(handle);
			let valueInput;
			control(row, labels.label, option.label, value => {
				option.label = value;
				if (option.auto) {
					const next = slug(value); remapDefault(field, option.value, next); option.value = next; valueInput.value = next;
				}
				refreshDefault(field);
			});
			valueInput = control(row, labels.value, option.value, value => { remapDefault(field, option.value, value); option.auto = false; option.value = value; refreshDefault(field); });
			const actions = el('div', undefined, 'tbreg-option-actions');
			actions.append(button(labels.up, () => { move(field.options, option, -1); render(field.key); }), button(labels.down, () => { move(field.options, option, 1); render(field.key); }), button(labels.remove, () => {
				field.options.splice(field.options.indexOf(option), 1);
				if (Array.isArray(field.default)) field.default = field.default.filter(value => value !== option.value);
				else if (field.default === option.value) field.default = '';
				sync(); render(field.key);
			}));
			Array.from(actions.children).forEach((node, index) => { node.setAttribute('aria-label', node.textContent); node.title = node.textContent; node.textContent = ['↑', '↓', '×'][index]; });
			row.append(actions); renderConditions(row,field,option); optionList.append(row);
		});
		if (window.jQuery && jQuery.fn.sortable) {
			jQuery(optionList).sortable({ handle: '.tbreg-option-handle', items: '> li', update: () => {
				field.options = Array.from(optionList.children, row => row.tbregOption); sync();
			} });
		}
		parent.append(button(labels.addOption, () => {
			field.options.push({ label: '', value: '', auto: true }); sync(); render();
			const last = document.querySelector('[data-key="' + field.key + '"] .tbreg-options li:last-child input');
			if (last) last.focus();
		}));
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
		}, 'button tbreg-menu-toggle');
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
		body.append(el('p', labels.key + ': ' + field.key, 'description tbreg-key'));
		const grid = el('div', undefined, 'tbreg-field-grid'); body.append(grid);
		control(grid, labels.label, field.label, value => { field.label = value; title.textContent = value || config.types[field.type]; });
		const typeControl = control(grid, labels.type, field.type, value => {
			field.type = value; field.options = isChoice(field) ? [{ label: '', value: '', auto: true }] : [];
			if (value !== 'hidden') field.query_param = '';
			field.default = ''; normalize(field); render();
		}, config.types);
		typeControl.disabled = used.has(field.key);
		if (typeControl.disabled) grid.append(el('p', labels.lockedType, 'description'));
		['help', 'css_class'].forEach(name => control(grid, labels[name], field[name] || '', value => { field[name] = value; }, null, name === 'help' ? 'textarea' : 'text'));
		if (hasPlaceholder(field.type)) control(grid, labels.placeholder, field.placeholder || '', value => { field.placeholder = value; });
		if (field.type !== 'heading') control(grid, labels.width, field.width || 'full', value => { field.width = value; }, { full: labels.full, half: labels.half, third: labels.third });
		const roles = rolesFor(field.type);
		if (roles.length) {
			const choices = { '': labels.none }; roles.forEach(role => { choices[role] = labels[role]; });
			control(grid, labels.role, field.role || '', value => { field.role = value || null; }, choices);
		}
		if (!['heading', 'hidden'].includes(field.type)) {
			const required = el('label', undefined, 'tbreg-checkbox');
			const check = el('input'); check.type = 'checkbox'; check.checked = !!field.required || field.type === 'consent'; check.disabled = field.type === 'consent';
			check.addEventListener('change', () => { field.required = check.checked; sync(); }); required.append(check, document.createTextNode(labels.required)); grid.append(required);
		}
		field.validation = field.validation || {};
		if (['number', 'date', 'time'].includes(field.type)) ['min', 'max'].forEach(name => {
			const type = field.type;
			const input = control(grid, labels[name], field.validation[name], value => { field.validation[name] = value === '' ? null : type === 'number' ? Number(value) : value; }, null, type);
			if (type === 'number') input.step = 'any';
		});
		if (hasLength(field.type)) ['minlength', 'maxlength'].forEach(name => {
			const input = control(grid, labels[name], field.validation[name], value => { field.validation[name] = value === '' ? null : Number(value); }, null, 'number'); input.step = '1'; input.min = '0';
		});
		if (hasPattern(field.type)) control(grid, labels.pattern, field.validation.pattern, value => { field.validation.pattern = value || null; });
		if (field.type === 'hidden') control(grid, labels.queryParam, field.query_param || '', value => { field.query_param = value; });
		if (isChoice(field)) renderOptions(body, field);
		if (!['heading', 'consent', 'photo'].includes(field.type)) {
			const defaults = el('div', undefined, 'tbreg-default'); defaults.dataset.defaultKey = field.key; body.append(defaults); renderDefault(defaults, field);
		}
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
			const target = requested || (card && card.querySelector(focusLabel ? '[data-action]:not(:disabled)' : 'input'));
			if (target && target.closest('[role="menu"]')) {
				target.closest('[role="menu"]').hidden = false; card.querySelector('[aria-haspopup]').setAttribute('aria-expanded', 'true');
			}
			if (target) target.focus();
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
