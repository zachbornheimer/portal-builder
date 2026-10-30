import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = (file) => fs.readFileSync(new URL(`../../${file}`, import.meta.url), 'utf8');

test('shared admin checkbox keeps its base styling and omits empty form names', () => {
	const source = read('src/ui/CheckField.svelte');
	assert.match(source, /class=\{`dg-check-row \$\{rowClass\}`\.trim\(\)\}/);
	assert.match(source, /name=\{name \|\| undefined\}/);
	assert.match(source, /\{checked\}/);
	assert.match(source, /\{onchange\}/);
});

test('every admin CheckField caller supplies reactive checked state and a change handler', () => {
	for (const file of ['BuildFieldEditor', 'MapStep', 'PublishStep']) {
		const source = read(`src/wizard/steps/${file}.svelte`);
		const controls = [...source.matchAll(/<CheckField\s[\s\S]*?\/>/g)];
		assert.ok(controls.length > 0, file);
		for (const [control] of controls) {
			assert.match(control, /checked=\{/, `${file}: ${control}`);
			assert.match(control, /onchange=\{/, `${file}: ${control}`);
			assert.doesNotMatch(control, /name=""/, file);
		}
	}
});

test('checked and focus states override the WordPress-scoped unchecked control rule', () => {
	const css = read('src/styles.css');
	assert.match(css, /#wpbody-content \.dg-wizard \.dg-check-row input\[type=["']checkbox["']\]:checked,/);
	assert.match(css, /#wpbody-content \.dg-wizard \.dg-check-row input:focus-visible,/);
	assert.match(css, /#wpbody-content \.dg-wizard \.dg-check-row \[role=["']radio["']\]\[data-state=["']checked["']\],/);
	assert.match(css, /#wpbody-content \.dg-wizard \.dg-check-row input::before\s*\{\s*content: none;/);
});

test('all four publish inheritance actions live in aligned wrapping rows', () => {
	const source = read('src/wizard/steps/PublishStep.svelte');
	assert.equal([...source.matchAll(/class="dg-default-row"/g)].length, 4);
	assert.equal([...source.matchAll(/>\s*Use site default\s*<\/button>/g)].length, 4);
	assert.match(read('src/styles.css'), /\.dg-default-row\s*\{\s*display: flex;\s*align-items: center;\s*flex-wrap: wrap;\s*gap: 8px;/);
});

test('actual Default Settings uses scoped rows, including delegated Google controls', () => {
	const settings = read('includes/class-portal-settings.php');
	const probe = read('includes/adapters/class-portal-google-probe.php');
	assert.match(settings, /class="wrap dg-default-settings"/);
	assert.equal([...settings.matchAll(/<label class="dg-settings-check-row"/g)].length, 5);
	assert.equal([...probe.matchAll(/<p class="dg-settings-control-row"/g)].length, 3);
	assert.match(read('assets/admin.css'), /\.dg-default-settings \.form-table > tbody > tr\s*\{\s*display: flex;\s*align-items: center;\s*flex-wrap: wrap;/);
	assert.match(settings, /assets\/admin\.css/);
});
