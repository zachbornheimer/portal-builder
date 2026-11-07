/**
 * Third-party libraries ship under the DragonGate\Vendor\ prefix (ZYS-2607).
 *
 * Production fatal: another plugin (op-dashboard / OptimizePress) loaded
 * Guzzle 6.3.3 and psr/log 1.x first, so the plugin's own Google client died
 * with "Undefined constant GuzzleHttp\ClientInterface::MAJOR_VERSION".
 * Namespace-prefixing every dependency (Strauss, scripts/prefix-vendor.sh)
 * makes load order irrelevant. These tests fail if a dependency is shipped or
 * referenced unprefixed again.
 *
 * Needs `composer install` (which runs the prefix step); `mise run setup` does.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const harness = path.join(root, 'tests/support/php-vendor-collision.php');
const foreign = path.join(root, 'tests/fixtures/foreign-vendor/autoload.php');
const prefixedAutoload = path.join(root, 'vendor-prefixed/autoload.php');
const unprefixedAutoload = path.join(root, 'vendor/autoload.php');
const PREFIX = 'DragonGate\\Vendor\\';
const THIRD_PARTY_ROOTS = ['GuzzleHttp', 'Monolog', 'Psr', 'Google', 'Firebase', 'PHPMailer'];

function prefixedTreeBuilt() {
	return fs.existsSync(prefixedAutoload) && fs.statSync(prefixedAutoload).size > 200;
}

function runCollision({ ours, prefix }) {
	const r = spawnSync(
		'php',
		['-d', 'error_reporting=0', harness, `--foreign=${foreign}`, `--ours=${ours}`, `--prefix=${prefix}`],
		{ encoding: 'utf8' },
	);
	const out = (r.stdout || '') + (r.stderr || '');
	let data = null;
	try {
		const start = out.indexOf('{');
		data = start >= 0 ? JSON.parse(out.slice(start)) : null;
	} catch {
		data = null;
	}
	return { code: r.status, out, data };
}

function phpFiles(dir) {
	const found = [];
	for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
		const full = path.join(dir, entry.name);
		if (entry.isDirectory()) {
			found.push(...phpFiles(full));
		} else if (entry.name.endsWith('.php')) {
			found.push(full);
		}
	}
	return found;
}

test('prefixed vendor tree is built (run composer install)', () => {
	assert.equal(
		prefixedTreeBuilt(),
		true,
		'vendor-prefixed/autoload.php missing or a stub; run `composer install` (runs scripts/prefix-vendor.sh)',
	);
});

test('plugin loads Guzzle, Monolog, psr/log and the Google client after another plugin loaded its own', () => {
	assert.equal(prefixedTreeBuilt(), true, 'vendor-prefixed/ not built');
	const { code, out, data } = runCollision({ ours: prefixedAutoload, prefix: PREFIX });
	assert.equal(code, 0, out);
	assert.ok(data, out);
	assert.equal(data.ok, true, out);
	assert.equal(
		data.foreignGuzzleMajorDefined,
		false,
		'fixture must model Guzzle 6 (no ClientInterface::MAJOR_VERSION)',
	);
	assert.match(data.authHandler, /^DragonGate\\Vendor\\Google\\AuthHandler\\Guzzle[78]AuthHandler$/);
	assert.equal(data.loggerImplementsPsr, true);
});

test('control: the unprefixed vendor tree fatals under the same load order', (t) => {
	if (!fs.existsSync(unprefixedAutoload)) {
		t.skip('vendor/autoload.php absent (production-only install)');
		return;
	}
	const { code, data, out } = runCollision({ ours: unprefixedAutoload, prefix: '' });
	assert.notEqual(code, 0, `fixture lost its power to reproduce the collision: ${out}`);
	assert.ok(data, out);
	assert.equal(data.ok, false, out);
	assert.match(data.error, /MAJOR_VERSION/, out);
});

test('plugin code references only prefixed third-party classes', () => {
	const files = [path.join(root, 'portal-builder.php'), ...phpFiles(path.join(root, 'includes'))];
	const roots = THIRD_PARTY_ROOTS.join('|');
	// `use X\...;`, `new X\...`, and any X\... or \X\... outside a longer name.
	const unprefixed = new RegExp(`(?:^\\s*use\\s+|\\bnew\\s+|[^\\\\\\w])\\\\?(?:${roots})\\\\[A-Z]\\w*`, 'm');
	const globalAlias = /\bGoogle_(?:Client|Service\w*|Model|Collection|Exception)\b/;
	const offenders = [];
	for (const file of files) {
		for (const [i, line] of fs.readFileSync(file, 'utf8').split('\n').entries()) {
			const code = line.replace(/^\s*(?:\*|\/\/).*$/, '');
			if (code.includes(PREFIX)) {
				continue;
			}
			if (unprefixed.test(code) || globalAlias.test(code)) {
				offenders.push(`${path.relative(root, file)}:${i + 1}: ${line.trim()}`);
			}
		}
	}
	assert.deepEqual(offenders, [], `unprefixed vendor references:\n${offenders.join('\n')}`);
});

test('plugin never loads the unprefixed Composer autoloader', () => {
	const files = [path.join(root, 'portal-builder.php'), ...phpFiles(path.join(root, 'includes'))];
	const offenders = files.filter((file) => /['"/]vendor\/autoload\.php/.test(fs.readFileSync(file, 'utf8')));
	assert.deepEqual(
		offenders.map((f) => path.relative(root, f)),
		[],
		'require vendor-prefixed/autoload.php only',
	);
});

test('vendor-prefixed contains no unprefixed namespace and no global Google_ alias', () => {
	assert.equal(prefixedTreeBuilt(), true, 'vendor-prefixed/ not built');
	const roots = THIRD_PARTY_ROOTS.join('|');
	const bareNamespace = new RegExp(`^namespace\\s+(?:${roots})\\\\`, 'm');
	const globalAlias = /^class_alias\(.*'Google_/m;
	const offenders = [];
	for (const file of phpFiles(path.join(root, 'vendor-prefixed'))) {
		const src = fs.readFileSync(file, 'utf8');
		if (bareNamespace.test(src)) {
			offenders.push(`${path.relative(root, file)}: unprefixed namespace`);
		}
		if (globalAlias.test(src)) {
			offenders.push(`${path.relative(root, file)}: global Google_ class_alias`);
		}
	}
	assert.deepEqual(offenders.slice(0, 10), [], 'vendor-prefixed leaks colliding names');
});

test('only the Google services the plugin uses are shipped', () => {
	assert.equal(prefixedTreeBuilt(), true, 'vendor-prefixed/ not built');
	const services = path.join(root, 'vendor-prefixed/google/apiclient-services/src');
	const shipped = fs
		.readdirSync(services, { withFileTypes: true })
		.filter((e) => e.isDirectory())
		.map((e) => e.name)
		.sort();
	assert.deepEqual(shipped, ['Drive', 'Sheets']);
});

test('prefix step is wired into composer, mise build, make release and the release zip', () => {
	const composer = JSON.parse(fs.readFileSync(path.join(root, 'composer.json'), 'utf8'));
	assert.equal(composer.extra.strauss.namespace_prefix, PREFIX);
	assert.equal(composer.extra.strauss.target_directory, 'vendor-prefixed');
	assert.deepEqual(composer.extra['google/apiclient-services'].sort(), ['Drive', 'Sheets']);
	assert.ok(composer.scripts['post-install-cmd'].includes('@prefix-vendor'), 'post-install-cmd must prefix');
	assert.ok(composer.scripts['post-update-cmd'].includes('@prefix-vendor'), 'post-update-cmd must prefix');

	const mise = fs.readFileSync(path.join(root, 'mise.toml'), 'utf8');
	const build = mise.slice(mise.indexOf('[tasks.build]'), mise.indexOf('[tasks.lint]'));
	assert.match(build, /prefix-vendor/, 'mise build must run the prefix step');

	const zipScript = fs.readFileSync(path.join(root, 'scripts/make-release-zip.sh'), 'utf8');
	assert.match(zipScript, /vendor-prefixed\/autoload\.php/, 'release zip must require vendor-prefixed/autoload.php');
	assert.doesNotMatch(
		zipScript,
		/cp -R vendor\/\. /,
		'release zip must not copy the unprefixed vendor/ tree',
	);
});
