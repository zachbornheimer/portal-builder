/**
 * Plugin header Version must be semver and match PB_VERSION.
 * README must document ZIP upgrade that keeps _portal_definition.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const bootstrap = fs.readFileSync(path.join(root, 'portal-builder.php'), 'utf8');
const readme = fs.readFileSync(path.join(root, 'README.md'), 'utf8');

const SEMVER = /^\d+\.\d+\.\d+$/;
const REQUIRED_PHP = '8.0';
const REQUIRED_WP = '6.4';

const UPGRADE_FACTS = [
	{ name: 'upgrade-by-ZIP heading', re: /^##\s+Upgrade by ZIP\b/m },
	{ name: 'replace the plugin ZIP', re: /replace.{0,80}zip|zip.{0,80}replace/i },
	{ name: 'activate after replace', re: /activate/i },
	{ name: '_portal_definition post meta', re: /_portal_definition/ },
	{ name: 'do not delete portal posts', re: /do not delete.{0,80}posts|posts.{0,80}not deleted|does not delete.{0,60}posts/i },
	{ name: 'Dashboard Updates', re: /Dashboard → Updates|Dashboard -> Updates/ },
	{ name: 'stable GitHub Release', re: /stable.{0,40}GitHub Release|GitHub Release.{0,40}stable/i },
];

function headerField(name) {
	const match = bootstrap.match(new RegExp(`^\\s*\\*\\s*${name}:\\s*(\\S+)`, 'm'));
	assert.ok(match, `plugin header missing ${name}`);
	return match[1];
}

function runtimeVersion() {
	const match = bootstrap.match(/define\(\s*'PB_VERSION'\s*,\s*'([^']+)'\s*\)/);
	assert.ok(match, 'PB_VERSION constant missing from plugin bootstrap');
	return match[1];
}

test('plugin header Version is semver and equals PB_VERSION', () => {
	const version = headerField('Version');
	assert.match(version, SEMVER, `Version ${version} is not X.Y.Z semver`);
	assert.equal(runtimeVersion(), version, 'PB_VERSION drifted from header Version');
});

test('plugin header declares PHP 8.0 and WordPress 6.4 floors', () => {
	assert.equal(headerField('Requires PHP'), REQUIRED_PHP);
	assert.equal(headerField('Requires at least'), REQUIRED_WP);
});

test('README documents ZIP upgrade that keeps _portal_definition', () => {
	const missing = UPGRADE_FACTS.filter((item) => !item.re.test(readme)).map((item) => item.name);
	assert.deepEqual(missing, [], `README missing upgrade facts: ${missing.join(', ')}`);
});
