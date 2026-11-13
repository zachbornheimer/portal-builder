/**
 * Unit tests for plugin-realpath test-mode marker resolution.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {
	phpArtifactDir,
	pluginRoot,
	writeTestModeMarker,
} from '../support/enable-test-mode.mjs';

function makeTempDir(label) {
	return fs.mkdtempSync(path.join(os.tmpdir(), `dg-${label}-`));
}

test('pluginRoot prefers realpath of pluginPath', () => {
	const root = makeTempDir('plugin');
	const env = {
		pluginPath: root,
		pluginMustResolveTo: '/does/not/exist',
		repoRoot: '/repo',
	};
	assert.equal(pluginRoot(env), fs.realpathSync(root));
	assert.equal(phpArtifactDir(env), path.join(fs.realpathSync(root), 'tests/.artifacts'));
});

test('writeTestModeMarker writes plugin and worktree markers', () => {
	const plugin = makeTempDir('mode-plugin');
	const worktree = makeTempDir('mode-worktree');
	const env = {
		pluginPath: plugin,
		repoRoot: worktree,
		artifactDirAbs: path.join(worktree, 'tests/.artifacts'),
	};
	const written = writeTestModeMarker(env);
	assert.ok(fs.existsSync(written.markerPath));
	assert.equal(
		fs.readFileSync(written.markerPath, 'utf8').trim(),
		'enabled-by-filmed-spec',
	);
	assert.ok(fs.existsSync(path.join(env.artifactDirAbs, '.dg-test-mode')));
});
