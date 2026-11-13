/**
 * Portal_Submission_Selections::columns — filterable branch columns.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const harness = path.join(root, 'tests/support/php-selection-columns.php');
const cfsDef = path.join(
	root,
	'tests/fixtures/portals/call-for-scores.definition.json',
);
const scoresSub = path.join(
	root,
	'tests/fixtures/portals/call-for-scores.scores-submission.json',
);
const posterSub = path.join(
	root,
	'tests/fixtures/portals/call-for-scores.poster-submission.json',
);
const herbolzheimerDef = path.join(
	root,
	'tests/fixtures/portals/herbolzheimer.definition.json',
);

/**
 * @param {string} definitionPath
 * @param {string} [valuesPath]
 */
function runColumns(definitionPath, valuesPath) {
	const args = ['php', harness, definitionPath];
	if (valuesPath) args.push(valuesPath);
	const r = spawnSync(args[0], args.slice(1), {
		encoding: 'utf8',
		env: { ...process.env, DG_TEST_MODE: '1' },
	});
	return {
		code: r.status,
		out: (r.stdout || '') + (r.stderr || ''),
		stdout: r.stdout || '',
	};
}

test('scores values → category + score_kind labels and joined path', () => {
	const { code, out, stdout } = runColumns(cfsDef, scoresSub);
	assert.equal(code, 0, out);
	const cols = JSON.parse(stdout.trim());
	assert.equal(cols.category, 'scores');
	assert.equal(cols.category_label, 'Scores/Recordings');
	assert.equal(cols.score_kind, 'large');
	assert.equal(
		cols.score_kind_label,
		'New Music Masterclass Workshop (Large Ensemble)',
	);
	assert.equal(
		cols.selection_path,
		'Scores/Recordings › New Music Masterclass Workshop (Large Ensemble)',
	);
});

test('poster values → poster label, empty score_kind columns, path = poster label', () => {
	const { code, out, stdout } = runColumns(cfsDef, posterSub);
	assert.equal(code, 0, out);
	const cols = JSON.parse(stdout.trim());
	assert.equal(cols.category, 'poster');
	assert.equal(cols.category_label, 'Poster Sessions');
	assert.equal(cols.score_kind, '');
	assert.equal(cols.score_kind_label, '');
	assert.equal(cols.selection_path, 'Poster Sessions');
});

test('definition with no branches → empty columns (no selection_path, no *_label)', () => {
	const { code, out, stdout } = runColumns(herbolzheimerDef);
	assert.equal(code, 0, out);
	const cols = JSON.parse(stdout.trim());
	assert.deepEqual(cols, {});
	assert.equal(cols.selection_path, undefined);
	assert.ok(
		!Object.keys(cols).some((k) => k.endsWith('_label')),
		'no *_label keys',
	);
});
