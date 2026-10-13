/* global wp */
(function () {
	'use strict';
	const el = wp.element.createElement;
	const __ = wp.i18n.__;
	wp.blocks.registerBlockType('codwelt/toolsbox-registro', {
		title: __('ToolsBox Registros', 'toolsbox-registros'),
		description: __('Formulario de inscripción para eventos, clases y seminarios.', 'toolsbox-registros'),
		category: 'widgets', icon: 'clipboard',
		attributes: { formId: { type: 'integer', default: 0 } },
		edit: function (props) {
			const [forms, setForms] = wp.element.useState([]);
			const [error, setError] = wp.element.useState(false);
			wp.element.useEffect(function () {
				let alive = true;
				async function load() {
					try {
						let page = 1; let total = 1; let items = [];
						do {
							const response = await wp.apiFetch({ path: '/toolsbox-registros/v1/forms?page=' + page });
							items = items.concat(response.items); total = response.pages; page += 1;
						} while (page <= total && alive);
						if (alive) setForms(items);
					} catch (reason) { if (alive) setError(true); }
				}
				load(); return function () { alive = false; };
			}, []);
			const options = [{ label: __('Selecciona un formulario', 'toolsbox-registros'), value: 0 }].concat(forms.map(form => ({ label: form.title, value: form.id })));
			const selector = el(wp.components.SelectControl, { label: __('Formulario', 'toolsbox-registros'), value: props.attributes.formId, options: options, onChange: value => props.setAttributes({ formId: Number(value) }) });
			return el('div', wp.blockEditor.useBlockProps(),
				el(wp.blockEditor.InspectorControls, {}, el(wp.components.PanelBody, { title: __('ToolsBox Registros', 'toolsbox-registros') }, selector)),
				error ? el(wp.components.Notice, { status: 'warning', isDismissible: false }, __('Necesitas permiso para administrar formularios o recargar el editor.', 'toolsbox-registros')) : selector,
				props.attributes.formId > 0 ? el(wp.serverSideRender, { block: 'codwelt/toolsbox-registro', attributes: props.attributes }) : el('p', {}, __('Elige un formulario para mostrar su vista previa.', 'toolsbox-registros'))
			);
		},
		save: function () { return null; }
	});
}());
