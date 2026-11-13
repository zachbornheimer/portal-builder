/**
 * Bits field slice: Combobox, Label+input, Select, public island.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();

function read(rel) {
	return fs.readFileSync(path.join(root, rel), 'utf8');
}

test('Combobox.svelte imports Bits Combobox and has no hand-rolled focusedIndex listbox', () => {
	const src = read('src/Combobox.svelte');
	assert.match(src, /from ["']bits-ui["']/);
	assert.match(src, /Combobox/);
	assert.doesNotMatch(src, /focusedIndex/);
	assert.doesNotMatch(src, /handleClickOutside/);
});

test('TextField and IdField use Bits Label via shared Field', () => {
	const text = read('src/fields/TextField.svelte');
	const id = read('src/fields/IdField.svelte');
	const field = read('src/ui/Field.svelte');
	assert.match(text, /from ['"]\.\.\/ui\/Field\.svelte['"]/);
	assert.match(id, /from ['"]\.\.\/ui\/Field\.svelte['"]/);
	assert.match(field, /from ["']bits-ui["']/);
	assert.match(field, /Label/);
});

test('PublishStep has no raw select; uses Bits Select', () => {
	const src = read('src/wizard/steps/PublishStep.svelte');
	assert.doesNotMatch(src, /<select[\s>]/);
	assert.match(src, /SelectField|from ["']bits-ui["']|<Select/);
});

test('PublishStep has no raw radio inputs; uses Bits RadioGroup', () => {
	const src = read('src/wizard/steps/PublishStep.svelte');
	assert.doesNotMatch(src, /<input type="radio"/);
	assert.match(src, /RadioGroup/);
	assert.match(src, /from ["']bits-ui["']/);
});

test('wizard and fields have no raw select', () => {
	const files = [
		'src/wizard/steps/PublishStep.svelte',
		'src/wizard/steps/MapStep.svelte',
		'src/wizard/steps/BuildFieldEditor.svelte',
		'src/fields/TextField.svelte',
		'src/fields/IdField.svelte',
		'src/fields/TagsField.svelte',
	];
	for (const rel of files) {
		assert.doesNotMatch(read(rel), /<select[\s>]/, rel);
	}
});

test('public renderer marks form testids and POST names stay sub_*', () => {
	const php = read('includes/Definition/class-portal-definition-renderer.php');
	assert.match(php, /data-testid="dg-form"|data-testid='dg-form'/);
	assert.match(php, /data-testid="dg-field-/);
	assert.match(php, /NAME_PREFIX = 'sub_'/);
	assert.match(php, /sub_anonymize_ack/);
});

test('public island enhances non-file fields through Bits Label/Checkbox', () => {
	const index = read('src/public/index.js');
	const field = read('src/public/Field.svelte');
	const check = read('src/public/CheckField.svelte');
	assert.match(index, /Field\.svelte|enhanceFields|data-dg-field-type/);
	assert.match(field, /from ["']bits-ui["']/);
	assert.match(field, /Label/);
	assert.match(check, /from ["']bits-ui["']/);
	assert.doesNotMatch(index, /WizardShell/);
});
