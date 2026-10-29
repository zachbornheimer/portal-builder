/**
 * Applicant-facing status: Draft / Auto / Open / Closed.
 * Auto covers schedule-governed states (pre-launch, post-deadline).
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { applicantFacingStatus, previewUrl } from '../../src/wizard/definitionModel.js';

const TZ = 'America/New_York';
const iso = (s) => new Date(s);

test('draft post status is draft regardless of schedule', () => {
	const status = applicantFacingStatus('draft', { enabled: true }, iso('2026-06-01T12:00:00Z'));
	assert.deepEqual(status, { key: 'draft', label: 'Draft', detail: 'Not published yet' });
	assert.equal(
		applicantFacingStatus('pending', { enabled: true }, iso('2026-06-01T12:00:00Z')).key,
		'draft',
	);
});

test('published + disabled is closed (manual override)', () => {
	const status = applicantFacingStatus(
		'publish',
		{ enabled: false },
		iso('2026-06-01T12:00:00Z'),
	);
	assert.deepEqual(status, { key: 'closed', label: 'Closed', detail: 'Turned off manually' });
});

test('published + forceClosed is closed even when enabled is true', () => {
	const status = applicantFacingStatus(
		'publish',
		{ enabled: true, forceClosed: true },
		iso('2026-06-01T12:00:00Z'),
	);
	assert.deepEqual(status, { key: 'closed', label: 'Closed', detail: 'Turned off manually' });
});

test('legacy forceClosed-only publish is closed; forceClosed false is not', () => {
	assert.equal(
		applicantFacingStatus('publish', { forceClosed: true }, iso('2026-06-01T12:00:00Z')).key,
		'closed',
	);
	assert.equal(
		applicantFacingStatus('publish', { forceClosed: false }, iso('2026-06-01T12:00:00Z')).key,
		'open',
	);
});

test('manual override wins over the schedule', () => {
	const status = applicantFacingStatus(
		'publish',
		{ enabled: true, forceClosed: true, deadline: '2020-01-01T00:00:00' },
		iso('2026-06-01T12:00:00Z'),
	);
	assert.deepEqual(status, { key: 'closed', label: 'Closed', detail: 'Turned off manually' });
});

test('published + enabled + future launchAt is auto (not yet open)', () => {
	const status = applicantFacingStatus(
		'publish',
		{ enabled: true, timezone: TZ, launchAt: '2026-08-15T00:00:00' },
		iso('2026-06-01T12:00:00Z'),
	);
	assert.deepEqual(status, {
		key: 'auto',
		label: 'Auto',
		detail: 'Opens Saturday, August 15, 2026',
	});
});

test('published + enabled + past deadline is auto (closed by schedule)', () => {
	const status = applicantFacingStatus(
		'publish',
		{ enabled: true, timezone: TZ, deadline: '2026-10-20T23:59:59' },
		iso('2026-11-01T12:00:00Z'),
	);
	assert.deepEqual(status, {
		key: 'auto',
		label: 'Auto',
		detail: 'Closed Tuesday, October 20, 2026',
	});
});

test('published + enabled + inside the window is open', () => {
	const status = applicantFacingStatus(
		'publish',
		{
			enabled: true,
			timezone: TZ,
			launchAt: '2026-08-15T00:00:00',
			deadline: '2026-10-20T23:59:59',
		},
		iso('2026-09-01T12:00:00Z'),
	);
	assert.deepEqual(status, { key: 'open', label: 'Open', detail: 'Accepting submissions' });
});

test('window across the DST fall-back, both bounds set', () => {
	const publish = {
		enabled: true,
		timezone: TZ,
		launchAt: '2026-10-30T12:00:00',
		deadline: '2026-11-02T12:00:00',
	};
	// Before the launch wall time (EDT, 2026-10-30T16:00:00Z).
	assert.deepEqual(applicantFacingStatus('publish', publish, iso('2026-10-30T15:59:59Z')), {
		key: 'auto',
		label: 'Auto',
		detail: 'Opens Friday, October 30, 2026',
	});
	// Launch is inclusive.
	assert.equal(
		applicantFacingStatus('publish', publish, iso('2026-10-30T16:00:00Z')).key,
		'open',
	);
	// Inside the repeated 01:30 local hour (2026-11-01T05:30:00Z, first occurrence).
	assert.equal(
		applicantFacingStatus('publish', publish, iso('2026-11-01T05:30:00Z')).key,
		'open',
	);
	// Deadline is inclusive (EST, 2026-11-02T17:00:00Z).
	assert.equal(
		applicantFacingStatus('publish', publish, iso('2026-11-02T17:00:00Z')).key,
		'open',
	);
	// One second past the deadline: closed by schedule, not by a human.
	assert.deepEqual(applicantFacingStatus('publish', publish, iso('2026-11-02T17:00:01Z')), {
		key: 'auto',
		label: 'Auto',
		detail: 'Closed Monday, November 2, 2026',
	});
});

test('previewUrl builds the staff preview entry points', () => {
	assert.equal(
		previewUrl('https://example.org/portal/foo/', 'open'),
		'https://example.org/portal/foo/?preview=open',
	);
	assert.equal(
		previewUrl('https://example.org/portal/foo/', 'closed'),
		'https://example.org/portal/foo/?preview=closed',
	);
	assert.equal(
		previewUrl('https://example.org/portal/foo/?ref=list', 'open'),
		'https://example.org/portal/foo/?ref=list&preview=open',
	);
});
