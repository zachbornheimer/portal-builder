/**
 * GitHub Releases offers inject into WordPress update_plugins and keep the live folder.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
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

test('keep_folder only renames when this plugin is upgrading (not themes or other packages)', () => {
	const data = run();
	assert.equal(data.themeUnchanged, true, 'theme upgrade must leave source path untouched');
	assert.equal(data.emptyUnchanged, true, 'empty hook_extra must leave source path untouched');
	assert.equal(data.pluginRenamed, true, 'this plugin upgrade still renames to live folder');
});

test('relocate_legacy_folder moves portal-builder-0.0.4a to dragongate-portals and rewrites active_plugins', () => {
	const data = run();
	assert.equal(data.relocateOk, true, 'legacy folder must relocate and rewrite active list');
	assert.equal(data.folderConst, 'dragongate-portals');
	assert.equal(data.fallbackFile, 'dragongate-portals/portal-builder.php');
});

test('relocate_legacy_folder no-ops when dragongate-portals already exists', () => {
	const data = run();
	assert.equal(data.relocateNoClobber, true, 'must not clobber an existing dest folder');
});

test('release zip PREFIX and push dest use dragongate-portals', () => {
	const zipSh = fs.readFileSync(path.join(root, 'scripts/make-release-zip.sh'), 'utf8');
	const pushSh = fs.readFileSync(path.join(root, 'scripts/push-plugin.sh'), 'utf8');
	assert.match(zipSh, /PREFIX="dragongate-portals"/);
	assert.match(pushSh, /plugins\/dragongate-portals\//);
});

test('inject offers the DragonGate seal icons and compatibility fields', () => {
	const data = run();
	assert.equal(data.hasIcons, true, 'inject must set icons');
	assert.equal(data.iconSeal, true, `icon-128 missing from ${data.icon1x}`);
	assert.equal(data.injectTested, true);
	assert.equal(data.injectRequires, true);
	assert.equal(data.injectPhp, true);
});

test('info_from_release exposes author, compatibility, icons, and changelog', () => {
	const data = run();
	assert.equal(data.infoOk, true);
	assert.equal(data.infoAuthor, true);
	assert.equal(data.infoRequires, true);
	assert.equal(data.infoPhp, true);
	assert.equal(data.infoTested, true);
	assert.equal(data.infoIcons, true);
	assert.equal(data.infoChangelog, true);
	assert.equal(data.infoFeatures, true);
	assert.equal(data.infoDesc, true);
	assert.equal(data.infoUpdated, true);
	assert.equal(data.thinFallsBack, true, 'compare-only GitHub body must use bundled CHANGELOG');
});
