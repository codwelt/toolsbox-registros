/** Contraste WCAG del texto del editor: paleta real del CSS, sin navegador simulado. */
'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const source = fs.readFileSync(path.resolve(__dirname, '../../assets/admin/css/admin.css'), 'utf8');
const luminance = hex => {
	const value = hex.slice(1); const expanded = value.length === 3 ? [...value].map(c => c + c).join('') : value;
	const rgb = expanded.match(/../g).map(c => parseInt(c, 16) / 255).map(c => c <= .04045 ? c / 12.92 : ((c + .055) / 1.055) ** 2.4);
	return rgb[0] * .2126 + rgb[1] * .7152 + rgb[2] * .0722;
};
function palette(selector) {
	const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
	const rule = source.match(new RegExp(escaped + '\\s*\\{([^}]+)\\}'))?.[1]; assert.ok(rule, selector);
	const color = rule.match(/(?:^|;)\s*color:\s*(#[\da-f]+)/i)?.[1];
	const background = rule.match(/(?:^|;)\s*background:\s*(#[\da-f]+)/i)?.[1]; assert.ok(color && background, selector + ': colores explícitos');
	const a = luminance(color), b = luminance(background); return { selector, color, background, contrast: (Math.max(a, b) + .05) / (Math.min(a, b) + .05) };
}
test('chips, insignias y opciones seleccionadas mantienen contraste AA de texto normal', () => {
	const report = ['.tbreg-type-badge', '.tbreg-status-badge', '.tbreg-status-badge[data-status="published"]', '.tbreg-status-badge[data-status="closed"]', '.tbreg-rule-chip:has(input:checked)', '.tbreg-rule-chip:has(input:disabled)'].map(palette);
	for (const item of report) assert.ok(item.contrast >= 4.5, item.selector + ': ' + item.contrast.toFixed(2));
	const directory = process.env.TBREG_EDITOR_FIXTURE_DIR;
	if (directory) fs.writeFileSync(path.join(directory, 'contrast.json'), JSON.stringify(report, null, 2));
});
