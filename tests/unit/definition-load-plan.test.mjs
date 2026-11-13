import test from 'node:test';
import assert from 'node:assert/strict';
import { planDefinitionLoad } from '../../src/wizard/definitionModel.js';

test('missing seed means fetch (legacy mount)', () => {
	assert.deepEqual(planDefinitionLoad(undefined), { mode: 'fetch', definition: null });
});

test('null seed means ready blank — no fetch', () => {
	assert.deepEqual(planDefinitionLoad(null), { mode: 'blank', definition: null });
});

test('embedded definition hydrates without fetch', () => {
	const seed = { version: 1, title: 'Call', fields: [{ id: 't', type: 'short_text', label: 'Title' }] };
	assert.deepEqual(planDefinitionLoad(seed), { mode: 'hydrate', definition: seed });
});

test('malformed seed is treated as blank, not fetch', () => {
	assert.equal(planDefinitionLoad({}).mode, 'blank');
	assert.equal(planDefinitionLoad([]).mode, 'blank');
	assert.equal(planDefinitionLoad('nope').mode, 'blank');
});
