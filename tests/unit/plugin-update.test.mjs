/**
 * GitHub Releases offers inject into WordPress update_plugins and keep the live folder.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import path from 'node:path';

const root = process.cwd();
const harness = path.join(root, 'tests/support/php-portal-update.php');

function run() {
	const r = spawnSync('php', [harness], { encoding: 'utf8' });
	assert.equal(r.status, 0, `${r.stdout || ''}${r.stderr || ''}`);
	return JSON.parse((r.stdout || '').trim());
}

test('stable newer GitHub ZIP becomes an update; prerelease and same version do not', () => {
	const data = run();
	assert.equal(data.semverV, '0.1.0');
	assert.equal(data.notNewer, true);
	assert.equal(data.newer, true);
	assert.equal(data.packageZip, true);
	assert.equal(data.sameIgnored, true);
	assert.equal(data.preIgnored, true);
	assert.equal(data.rollIgnored, true);
	assert.equal(data.fromLocation.tag_name, 'v0.2.0');
	assert.match(data.fromLocation.assets[0].browser_download_url, /portal-builder-0\.2\.0\.zip$/);
});

test('update injects the live plugin basename and keeps portal-builder-0.0.4a', () => {
	const data = run();
	assert.equal(data.injected, true);
	assert.equal(data.newVersion, '0.2.0');
	assert.equal(data.folderKept, true);
	assert.equal(data.renamedPath, 'portal-builder-0.0.4a');
});
