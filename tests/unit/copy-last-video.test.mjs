/**
 * Unit tests for the filmed-video copy helper (no Playwright).
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {
	copyLastVideo,
	copyVideoToStable,
	findLatestVideo,
	listVideoFiles,
} from '../support/copy-last-video.mjs';

function makeTempDir(label) {
	return fs.mkdtempSync(path.join(os.tmpdir(), `dg-${label}-`));
}

test('findLatestVideo returns the newest webm or mp4', () => {
	const root = makeTempDir('videos');
	const nested = path.join(root, 'run-a');
	fs.mkdirSync(nested);
	const older = path.join(nested, 'video.webm');
	const newer = path.join(root, 'later.mp4');
	fs.writeFileSync(older, 'old');
	fs.writeFileSync(newer, 'new');
	const past = Date.now() - 10_000;
	fs.utimesSync(older, past / 1000, past / 1000);

	assert.deepEqual(listVideoFiles(root).sort(), [older, newer].sort());
	assert.equal(findLatestVideo(root), newer);
	assert.equal(findLatestVideo(path.join(root, 'missing')), null);
});

test('findLatestVideo prefers extra-context page@ film over newer fixture video.webm', () => {
	const root = makeTempDir('prefer-page');
	const fixture = path.join(root, 'video.webm');
	const applicant = path.join(root, 'page@c5eb86d7-walk.webm');
	fs.writeFileSync(fixture, 'admin-dashboard');
	fs.writeFileSync(applicant, 'public-form-walk');
	const past = Date.now() - 10_000;
	fs.utimesSync(applicant, past / 1000, past / 1000);

	assert.equal(findLatestVideo(root), applicant);
});

test('copyLastVideo writes last-public-submit with the source extension', () => {
	const root = makeTempDir('copy');
	const destDir = path.join(root, 'out');
	const source = path.join(root, 'clip.webm');
	fs.writeFileSync(source, 'frames');

	const dest = copyLastVideo({ searchDir: root, destDir });
	assert.equal(dest, path.join(destDir, 'last-public-submit.webm'));
	assert.equal(fs.readFileSync(dest, 'utf8'), 'frames');

	const explicit = copyVideoToStable(source, destDir);
	assert.equal(explicit, dest);
});
