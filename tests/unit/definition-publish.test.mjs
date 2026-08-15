/**
 * Publish helpers: dual-write, launch default, preview query, status label.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import {
	applyLaunchDefault,
	blankDefinition,
	dualWritePublish,
	normalizeLoaded,
	previewUrl,
} from '../../src/wizard/definitionModel.js';

test('blank definition dual-writes enabled true and forceClosed false', () => {
	const def = blankDefinition('Portal');
	assert.equal(def.publish.enabled, true);
	assert.equal(def.publish.forceClosed, false);
	assert.equal(def.publish.launchAt, null);
	assert.equal(def.options.anonymizeApiKey, null);
});

test('normalizeLoaded dual-writes enabled from legacy forceClosed', () => {
	const def = normalizeLoaded({
		title: 'Legacy',
		fields: [],
		publish: { forceClosed: true },
	});
	assert.equal(def.publish.enabled, false);
	assert.equal(def.publish.forceClosed, true);
	assert.equal(def.publish.launchAt, null);
	assert.equal(def.options.anonymizeApiKey, null);
});

test('dualWritePublish lets enabled win over a stale forceClosed', () => {
	const next = dualWritePublish({ enabled: true, forceClosed: true });
	assert.equal(next.enabled, true);
	assert.equal(next.forceClosed, false);
});

test('applyLaunchDefault writes today 00:00 in the portal timezone when blank', () => {
	const now = new Date('2026-06-15T18:30:00Z');
	const next = applyLaunchDefault(
		{ timezone: 'America/New_York', launchAt: null, enabled: true },
		now
	);
	assert.equal(next.launchAt, '2026-06-15T00:00:00');
	assert.equal(next.enabled, true);
	assert.equal(next.forceClosed, false);
});

test('applyLaunchDefault keeps an explicit launchAt', () => {
	const next = applyLaunchDefault({
		timezone: 'America/New_York',
		launchAt: '2026-09-01T09:00:00',
		enabled: false,
	});
	assert.equal(next.launchAt, '2026-09-01T09:00:00');
	assert.equal(next.enabled, false);
	assert.equal(next.forceClosed, true);
});

test('previewUrl appends preview=true without breaking an existing query', () => {
	assert.equal(previewUrl('https://example.com/portal/foo/'), 'https://example.com/portal/foo/?preview=true');
	assert.equal(
		previewUrl('https://example.com/portal/foo/?ref=list'),
		'https://example.com/portal/foo/?ref=list&preview=true'
	);
	assert.equal(previewUrl(''), '');
});
