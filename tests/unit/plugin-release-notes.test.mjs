/**
 * Conventional-commit subjects become release notes markdown and HTML.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import path from 'node:path';

const root = process.cwd();
const harness = path.join(root, 'tests/support/php-portal-release-notes.php');

function run() {
	const r = spawnSync('php', [harness], { encoding: 'utf8' });
	assert.equal(r.status, 0, `${r.stdout || ''}${r.stderr || ''}`);
	return JSON.parse((r.stdout || '').trim());
}

test('markdown groups conventional subjects and skips chore(release)', () => {
	const data = run();
	assert.equal(data.mdHasFeatures, true);
	assert.equal(data.mdHasAdmins, true);
	assert.equal(data.mdHasFixes, true);
	assert.equal(data.mdSkipsRelease, true);
	assert.equal(data.mdHasCompare, true);
	assert.equal(data.mdHasOther, true);
});

test('html_from_markdown renders headings, lists, and bold', () => {
	const data = run();
	assert.equal(data.htmlHasHeading, true);
	assert.equal(data.htmlHasListItem, true);
	assert.equal(data.htmlHasStrong, true);
	assert.equal(data.githubHasList, true);
	assert.equal(data.githubHasH3, true);
});
