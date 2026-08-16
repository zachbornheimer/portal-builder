/**
 * Applicant notification phrase: a day is “on or before {weekday date}”;
 * a window is “in {text}” unless the text already has a preposition.
 * Drives the shipped PHP owner via a harness — not a JS reimplementation.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const harness = path.join(root, 'tests/support/php-notification-when.php');
const artifactDir = path.join(root, 'tests/.artifacts/notification-when');
const ownerFile = path.join(
	root,
	'includes/Submission/class-portal-notification-when.php',
);
const submissionFile = path.join(root, 'includes/class-portal-submission.php');
const pipelineFile = path.join(
	root,
	'includes/Submission/class-portal-submission-pipeline.php',
);
const pluginFile = path.join(root, 'portal-builder.php');
const metaFile = path.join(root, 'includes/class-portal-meta.php');

const META_DATE = '_portal_applicant_notification_date';
const META_KIND = '_portal_applicant_notification_kind';
const META_WINDOW = '_portal_applicant_notification_window';

/**
 * @param {Record<string, string>} meta
 * @param {number} [portalId]
 */
function runWhen(meta, portalId = 1) {
	fs.mkdirSync(artifactDir, { recursive: true });
	const file = path.join(artifactDir, `case-${Date.now()}-${Math.random()}.json`);
	fs.writeFileSync(
		file,
		`${JSON.stringify({ portal_id: portalId, meta }, null, 2)}\n`,
	);
	const r = spawnSync('php', [harness, file], { encoding: 'utf8' });
	const out = `${r.stdout || ''}${r.stderr || ''}`;
	assert.equal(r.status, 0, out);
	const start = out.indexOf('{');
	assert.notEqual(start, -1, `no JSON in: ${out}`);
	return JSON.parse(out.slice(start));
}

test('calendar day is on or before the weekday date', () => {
	const { phrase, sentence } = runWhen({ [META_DATE]: '2026-12-15' });
	assert.match(phrase, /on or before/);
	assert.match(phrase, /Tuesday, December 15, 2026/);
	assert.doesNotMatch(phrase, /\.$/);
	assert.match(sentence, /will be made on or before/);
	assert.match(sentence, /Tuesday, December 15, 2026/);
});

test('window mid-December is in mid-December', () => {
	const { phrase } = runWhen({
		[META_KIND]: 'window',
		[META_WINDOW]: 'mid-December',
	});
	assert.equal(phrase, 'in mid-December');
});

test('window that already starts with by is left as-is', () => {
	const { phrase } = runWhen({
		[META_KIND]: 'window',
		[META_WINDOW]: 'by early January',
	});
	assert.equal(phrase, 'by early January');
});

test('empty meta produces an empty phrase and drops will be made', () => {
	const { phrase, sentence } = runWhen({});
	assert.equal(phrase, '');
	assert.doesNotMatch(sentence, /will be made/);
	assert.match(sentence, /shortly\./);
});

test('unparseable date meta is on or before the raw value', () => {
	const { phrase } = runWhen({ [META_DATE]: 'sometime-soon' });
	assert.equal(phrase, 'on or before sometime-soon');
});

test('empty kind with a stored date is treated as a day', () => {
	const { phrase } = runWhen({
		[META_KIND]: '',
		[META_DATE]: '2026-12-15',
	});
	assert.equal(phrase, 'on or before Tuesday, December 15, 2026');
});

test('one owner formats the date; mail and success sentence call it', () => {
	const owner = fs.readFileSync(ownerFile, 'utf8');
	const submission = fs.readFileSync(submissionFile, 'utf8');
	const pipeline = fs.readFileSync(pipelineFile, 'utf8');
	const plugin = fs.readFileSync(pluginFile, 'utf8');
	const meta = fs.readFileSync(metaFile, 'utf8');

	assert.match(owner, /class Portal_Notification_When/);
	assert.match(owner, /function phrase\s*\(/);
	assert.match(owner, /l, F j, Y/);

	assert.doesNotMatch(submission, /strtotime\s*\(\s*\$raw_notification/);
	assert.doesNotMatch(submission, /NOTIFICATION_DATE_FORMAT/);
	assert.doesNotMatch(submission, /date\s*\(\s*'l, F j, Y'/);
	assert.match(submission, /Portal_Notification_When::phrase/);

	assert.match(pipeline, /Portal_Notification_When::phrase/);
	assert.doesNotMatch(pipeline, /NOTIFICATION_DATE_FORMAT/);

	assert.match(plugin, /Portal_Notification_When::phrase/);
	assert.match(plugin, /Portal_Notification_When::success_copy/);
	assert.doesNotMatch(plugin, /strtotime\s*\(\s*\$raw_notification/);
	assert.doesNotMatch(plugin, /date\s*\(\s*'l, F j, Y'/);

	assert.match(plugin, /_portal_applicant_notification_kind/);
	assert.match(plugin, /_portal_applicant_notification_window/);
	assert.match(plugin, /On or before a date/);
	assert.match(plugin, /A general time/);
	assert.match(plugin, /mid-December/);
	assert.match(meta, /case 'text'/);
});
