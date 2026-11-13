/**
 * Branch visibility plan — selected path only.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { visibleBranchOptions } from '../../src/wizard/branchVisibility.js';

test('selected papers → only papers visible', () => {
	const plan = visibleBranchOptions('papers', ['poster', 'papers', 'scores']);
	assert.deepEqual(plan, [
		{ id: 'poster', visible: false },
		{ id: 'papers', visible: true },
		{ id: 'scores', visible: false },
	]);
});

test('empty selection → none visible', () => {
	const plan = visibleBranchOptions('', ['poster', 'papers', 'scores']);
	assert.ok(plan.every((p) => p.visible === false));
	const none = visibleBranchOptions(null, ['poster', 'papers']);
	assert.ok(none.every((p) => p.visible === false));
});

test('unknown selection → none visible', () => {
	const plan = visibleBranchOptions('other', ['poster', 'papers']);
	assert.ok(plan.every((p) => p.visible === false));
});
