/**
 * Plugin header Version must be semver and match DG_VERSION.
 * README must document ZIP upgrade that keeps _portal_definition.
 * The declared PHP floor must agree with the composer resolution platform and
 * the release build.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const bootstrap = fs.readFileSync(path.join(root, 'portal-builder.php'), 'utf8');
const readme = fs.readFileSync(path.join(root, 'README.md'), 'utf8');
const composer = JSON.parse(fs.readFileSync(path.join(root, 'composer.json'), 'utf8'));
const releaseWorkflow = fs.readFileSync(path.join(root, '.github/workflows/release.yml'), 'utf8');

const SEMVER = /^\d+\.\d+\.\d+$/;
const REQUIRED_PHP = '8.1';
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
	const match = bootstrap.match(/define\(\s*'DG_VERSION'\s*,\s*'([^']+)'\s*\)/);
	assert.ok(match, 'DG_VERSION constant missing from plugin bootstrap');
	return match[1];
}

function floorFrom(version) {
	return version.split('.').slice(0, 2).join('.');
}

test('plugin header Version is semver and equals DG_VERSION', () => {
	const version = headerField('Version');
	assert.match(version, SEMVER, `Version ${version} is not X.Y.Z semver`);
	assert.equal(runtimeVersion(), version, 'DG_VERSION drifted from header Version');
});

test('plugin header declares PHP 8.1 and WordPress 6.4 floors', () => {
	assert.equal(headerField('Requires PHP'), REQUIRED_PHP);
	assert.equal(headerField('Requires at least'), REQUIRED_WP);
});

test('PHP floor agrees across header, README, composer platform, and release build', () => {
	assert.equal(floorFrom(headerField('Requires PHP')), REQUIRED_PHP);

	const readmePhp = readme.match(/requires php:?\**\s*(\d+\.\d+)/i);
	assert.ok(readmePhp, 'README does not state the required PHP version');
	assert.equal(floorFrom(readmePhp[1]), REQUIRED_PHP, 'README PHP floor drifted from the header');

	const platformPhp = composer.config?.platform?.php;
	assert.ok(platformPhp, 'composer.json config.platform.php is missing');
	assert.equal(floorFrom(platformPhp), REQUIRED_PHP, 'composer resolution platform drifted from the header');

	const workflowPhp = releaseWorkflow.match(/php-version:\s*"([^"]+)"/);
	assert.ok(workflowPhp, 'release workflow has no php-version');
	assert.equal(floorFrom(workflowPhp[1]), REQUIRED_PHP, 'release build PHP drifted from the header');
});

test('README documents ZIP upgrade that keeps _portal_definition', () => {
	const missing = UPGRADE_FACTS.filter((item) => !item.re.test(readme)).map((item) => item.name);
	assert.deepEqual(missing, [], `README missing upgrade facts: ${missing.join(', ')}`);
});
