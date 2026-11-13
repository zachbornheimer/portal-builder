/**
 * Call-for-scores branch submit pipeline (scores / poster / missing file).
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const harness = path.join(root, 'tests/support/php-submit-pipeline.php');
const definition = path.join(
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
const artifactDir = path.join(root, 'tests/.artifacts');

/**
 * @param {string} submissionPath
 * @param {string} portalId
 * @param {string[]} [extra]
 */
function runPipeline(submissionPath, portalId, extra = []) {
	const r = spawnSync(
		'php',
		[harness, definition, submissionPath, artifactDir, portalId, ...extra],
		{ encoding: 'utf8', env: { ...process.env, DG_TEST_MODE: '1' } },
	);
	return {
		code: r.status,
		out: (r.stdout || '') + (r.stderr || ''),
		stdout: r.stdout || '',
		stderr: r.stderr || '',
	};
}

test('scores path writes JSONL with category + score_kind and drive files', () => {
	const portalId = 'call-for-scores';
	const { code, out, stdout } = runPipeline(scoresSub, portalId);
	assert.equal(code, 0, out);
	const data = JSON.parse(stdout.trim());
	assert.equal(data.ok, true);
	assert.ok(fs.existsSync(data.sheetPath), 'sheet path exists');

	const lines = fs
		.readFileSync(data.sheetPath, 'utf8')
		.trim()
		.split('\n')
		.filter(Boolean);
	assert.ok(lines.length >= 1);
	const row = JSON.parse(lines[lines.length - 1]);
	assert.equal(row.category, 'scores');
	assert.equal(row.sub_category, 'scores');
	assert.equal(row.score_kind, 'large');
	assert.equal(row.sub_score_kind, 'large');
	assert.equal(row.category_label, 'Scores/Recordings');
	assert.equal(
		row.score_kind_label,
		'New Music Masterclass Workshop (Large Ensemble)',
	);
	assert.equal(
		row.selection_path,
		'Scores/Recordings › New Music Masterclass Workshop (Large Ensemble)',
	);
	assert.equal(row.large_title, 'Fanfare for Brass');
	assert.ok(row.large_score_path, 'large_score_path on row');
	assert.ok(row.large_rec_path, 'large_rec_path on row');

	assert.ok(data.drivePaths?.large_score, 'large_score drive path');
	assert.ok(data.drivePaths?.large_rec, 'large_rec drive path');
	assert.ok(fs.existsSync(data.drivePaths.large_score));
	assert.ok(fs.existsSync(data.drivePaths.large_rec));
});

test('poster path succeeds without score or recording files', () => {
	const portalId = 'call-for-scores-poster';
	const { code, out, stdout } = runPipeline(posterSub, portalId);
	assert.equal(code, 0, out);
	const data = JSON.parse(stdout.trim());
	assert.equal(data.ok, true);

	const lines = fs
		.readFileSync(data.sheetPath, 'utf8')
		.trim()
		.split('\n')
		.filter(Boolean);
	const row = JSON.parse(lines[lines.length - 1]);
	assert.equal(row.category, 'poster');
	assert.equal(row.sub_category, 'poster');
	assert.equal(row.category_label, 'Poster Sessions');
	assert.equal(row.score_kind, '');
	assert.equal(row.score_kind_label, '');
	assert.equal(row.selection_path, 'Poster Sessions');
	assert.ok(row.poster_description_path);
	assert.equal(row.large_score_path, undefined);
	assert.equal(row.large_rec_path, undefined);
});

test('scores path missing large_score fails validation without sheet row', () => {
	const portalId = 'call-for-scores-missing';
	const badSub = path.join(root, 'tests/.artifacts/cfs-missing-score.json');
	fs.mkdirSync(path.dirname(badSub), { recursive: true });
	const good = JSON.parse(fs.readFileSync(scoresSub, 'utf8'));
	good.portalId = portalId;
	delete good.files.large_score;
	fs.writeFileSync(badSub, JSON.stringify(good));

	const { code, out } = runPipeline(badSub, portalId);
	assert.notEqual(code, 0, out);
	assert.match(out, /dg_submission_invalid|required/i);

	const sheet = path.join(artifactDir, 'sheets', `${portalId}.jsonl`);
	assert.equal(fs.existsSync(sheet), false);
});
