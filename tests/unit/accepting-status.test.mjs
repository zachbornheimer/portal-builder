import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
	applicantFacingStatus,
	dualWritePublish,
	normalizeLoaded,
} from '../../src/wizard/definitionModel.js';

const now = new Date('2026-10-30T16:00:00Z');
const window = {
	timezone: 'America/New_York',
	launchAt: '2026-10-30T12:00:00',
	deadline: '2026-11-02T12:00:00',
};

for (const enabled of [true, false]) {
	for (const [name, schedule, expectedKey, canToggle] of [
		['inside the window', window, enabled ? 'open' : 'closed', true],
		['after the deadline', { ...window, deadline: '2026-10-29T12:00:00' }, enabled ? 'auto' : 'closed', false],
		['before launch', { ...window, launchAt: '2026-10-31T12:00:00' }, enabled ? 'auto' : 'closed', false],
	]) {
		test(`${enabled ? 'enabled' : 'manual off'} ${name}: effective state and switch availability agree`, () => {
			const publish = dualWritePublish({ ...schedule, enabled });
			const original = structuredClone(publish);
			const status = applicantFacingStatus('publish', publish, now);
			assert.equal(status.key, expectedKey);
			assert.equal(status.key === 'open', enabled && canToggle);
			assert.equal(status.canToggle, canToggle);
			if (!canToggle) {
				assert.match(status.title, /October (29|31), 2026/);
				assert.match(status.title, /send an invitation from Records/);
				if (!enabled) assert.match(status.title, /Turned off manually/);
			}
			assert.deepEqual(publish, original, 'presentation state must not mutate persisted flags');
			assert.equal(Object.hasOwn(publish, 'canToggle'), false);
			assert.equal(Object.hasOwn(publish, 'title'), false);
		});
	}

	test(`draft takes precedence with enabled=${enabled}, including a closed schedule`, () => {
		const status = applicantFacingStatus('draft', {
			enabled,
			deadline: '2020-01-01T00:00:00',
		}, now);
		assert.equal(status.key, 'draft');
		assert.equal(status.canToggle, false);
		assert.equal(status.title, 'Not published yet');
	});
}

test('legacy forceClosed and normalized operator off have the same switch behavior', () => {
	for (const schedule of [window, { ...window, deadline: '2026-10-29T12:00:00' }]) {
		const legacy = { ...schedule, forceClosed: true };
		const normalized = normalizeLoaded({ publish: legacy }).publish;
		assert.deepEqual(
			applicantFacingStatus('publish', legacy, now),
			applicantFacingStatus('publish', normalized, now),
		);
	}
});

test('launch and deadline instants are inclusive for both operator settings', () => {
	for (const enabled of [true, false]) {
		for (const instant of ['2026-10-30T16:00:00Z', '2026-11-02T17:00:00Z']) {
			const status = applicantFacingStatus('publish', { ...window, enabled }, new Date(instant));
			assert.equal(status.canToggle, true);
			assert.equal(status.key, enabled ? 'open' : 'closed');
		}
	}
});

test('explicit offsets and fallback timezone keep titles on the portal calendar date', () => {
	for (const timezone of [null, 'Invalid/Timezone', 'America/New_York']) {
		const status = applicantFacingStatus('publish', {
			enabled: true,
			timezone,
			deadline: '2026-10-30T01:00:00Z',
		}, now);
		assert.equal(status.canToggle, false);
		assert.match(status.title, /deadline on Thursday, October 29, 2026/);
	}
});

test('missing or unparseable schedules do not disable the manual control', () => {
	for (const schedule of [{}, { launchAt: 'invalid', deadline: 'invalid' }]) {
		for (const enabled of [true, false]) {
			const status = applicantFacingStatus('publish', { ...schedule, enabled }, now);
			assert.equal(status.canToggle, true);
			assert.equal(status.key, enabled ? 'open' : 'closed');
		}
	}
});

test('wizard switch binds effective status and guards the mutation with a fresh status', () => {
	const shell = readFileSync(new URL('../../src/wizard/WizardShell.svelte', import.meta.url), 'utf8');
	assert.match(shell, /const accepting = \$derived\(applicantStatus\.key === 'open'\)/);
	assert.match(shell, /aria-checked=\{accepting \? 'true' : 'false'\}/);
	assert.match(shell, /disabled=\{saveState === 'saving' \|\| !applicantStatus\.canToggle\}/);
	assert.match(shell, /title=\{applicantStatus\.title\}/);
	assert.match(shell, /async function toggleAccepting\(\) \{\s*\/\/[^\n]*\n\s*const status = applicantFacingStatus\(liveStatus, definition\.publish\);\s*if \(saveState === 'saving' \|\| !status\.canToggle\) return;\s*const nextEnabled = status\.key !== 'open';/);
	assert.match(shell, /onclick=\{toggleAccepting\}/);
});

test('Save portal always publishes while Save draft explicitly keeps the portal unpublished', () => {
	const shell = readFileSync(new URL('../../src/wizard/WizardShell.svelte', import.meta.url), 'utf8');
	assert.match(shell, /async function savePortal\(\) \{\s*await persist\(\{ status: 'publish' \}\);\s*\}/);
	assert.match(shell, /async function saveDraft\(\) \{\s*await persist\(\{ status: 'draft' \}\);\s*\}/);
	assert.match(shell, /onSavePortal=\{savePortal\}/);
	assert.match(shell, /onSaveDraft=\{saveDraft\}/);
});

test('publishing a manually closed draft preserves closure and makes reopening available inside its schedule', () => {
	const definition = normalizeLoaded({ publish: { ...window, enabled: false, forceClosed: true } });
	const original = structuredClone(definition.publish);
	const draft = applicantFacingStatus('draft', definition.publish, now);
	assert.equal(draft.key, 'draft');
	assert.equal(draft.canToggle, false);

	const published = applicantFacingStatus('publish', definition.publish, now);
	assert.equal(published.key, 'closed');
	assert.equal(published.canToggle, true);
	assert.deepEqual(definition.publish, original);

	const reopened = dualWritePublish({ ...definition.publish, enabled: true });
	assert.equal(applicantFacingStatus('publish', reopened, now).key, 'open');
});
