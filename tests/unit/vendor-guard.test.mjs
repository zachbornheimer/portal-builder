/**
 * The vendor-prefix guard (scripts/vendor-guard.mjs, rules/unprefixed-vendor-reference.yml.tmpl).
 *
 * ZYS-2607: an unprefixed GuzzleHttp\ / Psr\ / Google\ name in plugin code resolves to whichever
 * plugin loaded that library first, and fataled isjac.org on submit. The guard must fail on every
 * unprefixed form and stay quiet on prefixed names, comments and look-alikes.
 *
 * Needs `foxguard` on PATH (`mise run test` provides the pinned one).
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

import { deriveGuardedNamespaces } from '../../scripts/vendor-guard.mjs';

const root = process.cwd();
const guard = path.join(root, 'scripts/vendor-guard.mjs');
const fixtures = path.join(root, 'tests/fixtures/vendor-guard');
const EXIT_FINDINGS = 1;
const EXIT_CANNOT_RUN = 2;

const scratchDirs = [];
test.after(() => {
	for (const dir of scratchDirs) {
		fs.rmSync(dir, { recursive: true, force: true });
	}
});

function scratch() {
	const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'vendor-guard-test-'));
	scratchDirs.push(dir);
	return dir;
}

/** A copy of the clean fixture, optionally overlaid with the offending fixture's PHP. */
function tree({ offending = false } = {}) {
	const dir = scratch();
	fs.cpSync(path.join(fixtures, 'clean'), dir, { recursive: true });
	if (offending) {
		fs.cpSync(path.join(fixtures, 'offending'), dir, { recursive: true });
	}
	return dir;
}

function readJson(file) {
	return JSON.parse(fs.readFileSync(file, 'utf8'));
}

function runGuard(args, { env } = {}) {
	const run = spawnSync(process.execPath, [guard, ...args], { encoding: 'utf8', env: { ...process.env, ...env } });
	return { code: run.status, out: `${run.stdout}${run.stderr}`, stdout: run.stdout, stderr: run.stderr };
}

/** The source line behind each `  path:line` finding the guard printed. */
function flaggedLines(dir, stderr) {
	return [...stderr.matchAll(/^ {2}(\S+?):(\d+)$/gm)].map(([, file, line]) =>
		fs.readFileSync(path.join(dir, file), 'utf8').split('\n')[Number(line) - 1].trim(),
	);
}

test('derives the guarded namespaces from the production packages in composer.lock', () => {
	const { roots, exempt, underivable } = deriveGuardedNamespaces(
		readJson(path.join(root, 'composer.lock')),
		readJson(path.join(root, 'composer.json')),
	);
	for (const expected of ['GuzzleHttp', 'Monolog', 'Psr', 'Google', 'Firebase', 'PHPMailer']) {
		assert.ok(roots.has(expected), `${expected} is bundled but not guarded: ${[...roots.keys()]}`);
	}
	assert.equal(roots.has('Symfony'), false, 'Strauss leaves the symfony polyfills unprefixed on purpose');
	assert.ok(exempt.includes('symfony/polyfill-php80'));
	assert.deepEqual(underivable, [], 'every production package must declare a namespace the guard can read');
});

test('dev packages and exempt packages are not guarded; a new production package is', () => {
	const composer = readJson(path.join(fixtures, 'clean/composer.json'));
	const lock = readJson(path.join(fixtures, 'clean/composer.lock'));
	const before = deriveGuardedNamespaces(lock, composer).roots;
	assert.deepEqual([...before.keys()].sort(), ['Google', 'GuzzleHttp', 'Psr']);

	lock.packages.push({ name: 'acme/widgets', autoload: { 'psr-4': { 'Acme\\Widgets\\': 'src/' } } });
	assert.deepEqual([...deriveGuardedNamespaces(lock, composer).roots.keys()].sort(), ['Acme', 'Google', 'GuzzleHttp', 'Psr']);
});

test('a dependency added to composer.lock is covered with no edit to the rule', () => {
	const dir = tree();
	const lockFile = path.join(dir, 'composer.lock');
	const lock = readJson(lockFile);
	lock.packages.push({ name: 'acme/widgets', autoload: { 'psr-4': { 'Acme\\Widgets\\': 'src/' } } });
	fs.writeFileSync(lockFile, JSON.stringify(lock));
	const composerFile = path.join(dir, 'composer.json');
	const composer = readJson(composerFile);
	composer.extra.strauss.packages.push('acme/widgets');
	fs.writeFileSync(composerFile, JSON.stringify(composer));
	fs.writeFileSync(path.join(dir, 'includes/class-acme.php'), "<?php\n$widget = new \\Acme\\Widgets\\Widget();\n");

	const { code, out } = runGuard(['--root', dir]);
	assert.equal(code, EXIT_FINDINGS, out);
	assert.match(out, /includes\/class-acme\.php:2/);
	assert.match(out, /unprefixed Acme reference/);
});

test('flags every unprefixed form and names the file, the line and the fix', () => {
	const dir = tree({ offending: true });
	const { code, stderr } = runGuard(['--root', dir]);
	assert.equal(code, EXIT_FINDINGS, stderr);
	assert.deepEqual(flaggedLines(dir, stderr).sort(), [
		"'Psr\\\\Log\\\\NullLogger',",
		"$check   = $value instanceof Psr\\Log\\LoggerInterface;",
		"$drive = new \\Google_Service_Drive( $client );",
		"$fqcn    = new \\GuzzleHttp\\Psr7\\Request( 'GET', '/' );",
		"$global  = class_exists( 'Google_Client' );",
		"$static  = Google\\Service\\Drive::class;",
		"$string  = class_exists( 'GuzzleHttp\\\\Client' );",
		'use GuzzleHttp\\Client;',
		'use \\Google\\Service\\Drive;',
	].sort());
	assert.match(stderr, /includes\/class-offending\.php:\d+/);
	assert.match(stderr, /templates\/offending-template\.php:2/);
	assert.match(stderr, /fix: name the bundled copy: DragonGate\\Vendor\\GuzzleHttp\\/);
	assert.match(stderr, /README\.md, "Vendor prefix guard"/);
});

test('passes prefixed names, comments, look-alike names and the exempt polyfill', () => {
	const { code, out } = runGuard(['--root', tree()]);
	assert.equal(code, 0, out);
	assert.match(out, /vendor-prefix-guard: ok/);
});

test('the real plugin tree is clean', () => {
	const { code, out } = runGuard([]);
	assert.equal(code, 0, out);
});

test('fails when first-party PHP loads the unprefixed Composer autoloader', () => {
	const dir = tree();
	fs.writeFileSync(
		path.join(dir, 'includes/class-autoload.php'),
		"<?php\nrequire_once dirname( __DIR__ ) . '/vendor/autoload.php';\n",
	);
	const { code, out } = runGuard(['--root', dir]);
	assert.equal(code, EXIT_FINDINGS, out);
	assert.match(out, /includes\/class-autoload\.php:2\n\s+loads the unprefixed Composer tree/);
	assert.match(out, /require vendor-prefixed\/autoload\.php instead/);
});

test('fails when portal-builder.php no longer requires vendor-prefixed/autoload.php', () => {
	const dir = tree();
	fs.writeFileSync(path.join(dir, 'portal-builder.php'), "<?php\nrequire_once __DIR__ . '/vendor/autoload.php';\n");
	const { code, out } = runGuard(['--root', dir]);
	assert.equal(code, EXIT_FINDINGS, out);
	assert.match(out, /portal-builder\.php\n\s+does not require vendor-prefixed\/autoload\.php/);
	assert.match(out, /portal-builder\.php:2/);
});

test('fails when composer.json requires a package Strauss would not prefix', () => {
	const dir = tree();
	const file = path.join(dir, 'composer.json');
	const composer = readJson(file);
	composer.require['acme/widgets'] = '^1.0';
	fs.writeFileSync(file, JSON.stringify(composer));
	const { code, out } = runGuard(['--root', dir]);
	assert.equal(code, EXIT_FINDINGS, out);
	assert.match(out, /require "acme\/widgets" is not in extra\.strauss\.packages/);
});

test('fails when composer.json autoloads a tree under vendor/', () => {
	const dir = tree();
	const file = path.join(dir, 'composer.json');
	const composer = readJson(file);
	composer.autoload = { classmap: ['vendor/google/apiclient/src/'], 'psr-4': { 'Acme\\': 'vendor/acme/src' } };
	fs.writeFileSync(file, JSON.stringify(composer));
	const { code, out } = runGuard(['--root', dir]);
	assert.equal(code, EXIT_FINDINGS, out);
	assert.match(out, /autoload\.classmap points into vendor\/google\/apiclient\/src\//);
	assert.match(out, /autoload\.psr-4 points into vendor\/acme\/src/);
});

test('fails when a production package declares no namespace the guard can read', () => {
	const dir = tree();
	const file = path.join(dir, 'composer.lock');
	const lock = readJson(file);
	lock.packages.push({ name: 'acme/classmap-only', autoload: { classmap: ['lib/'] } });
	fs.writeFileSync(file, JSON.stringify(lock));
	const { code, out } = runGuard(['--root', dir]);
	assert.equal(code, EXIT_FINDINGS, out);
	assert.match(out, /acme\/classmap-only declares no psr-4\/psr-0 namespace/);
});

test('--built fails until every production package is in vendor-prefixed/', () => {
	const dir = tree();
	const missingTree = runGuard(['--root', dir, '--built']);
	assert.equal(missingTree.code, EXIT_FINDINGS, missingTree.out);
	assert.match(missingTree.out, /vendor-prefixed\/autoload\.php is missing/);

	fs.mkdirSync(path.join(dir, 'vendor-prefixed'), { recursive: true });
	fs.writeFileSync(path.join(dir, 'vendor-prefixed/autoload.php'), '<?php\n');
	for (const name of ['google/apiclient', 'guzzlehttp/guzzle', 'symfony/polyfill-php80']) {
		fs.mkdirSync(path.join(dir, 'vendor-prefixed', name), { recursive: true });
	}
	const missingPackage = runGuard(['--root', dir, '--built']);
	assert.equal(missingPackage.code, EXIT_FINDINGS, missingPackage.out);
	assert.match(missingPackage.out, /production package psr\/log \(composer\.lock\) is absent from vendor-prefixed\//);

	fs.mkdirSync(path.join(dir, 'vendor-prefixed/psr/log'), { recursive: true });
	const complete = runGuard(['--root', dir, '--built']);
	assert.equal(complete.code, 0, complete.out);
});

test('--pushed re-proves the built tree only when a build input changed', () => {
	const dir = tree();
	const phpOnly = runGuard(['--root', dir, '--pushed', '--', 'includes/class-clean.php']);
	assert.equal(phpOnly.code, 0, phpOnly.out);

	const buildInput = runGuard(['--root', dir, '--pushed', '--', 'composer.lock']);
	assert.equal(buildInput.code, EXIT_FINDINGS, buildInput.out);
	assert.match(buildInput.out, /vendor-prefixed\/autoload\.php is missing/);
});

test('fails closed when foxguard is absent or cannot load the generated rules', () => {
	const dir = tree({ offending: true });

	const empty = scratch();
	const absent = runGuard(['--root', dir], { env: { PATH: empty } });
	assert.equal(absent.code, EXIT_CANNOT_RUN, absent.out);
	assert.match(absent.out, /foxguard is not on PATH/);

	const bin = scratch();
	fs.writeFileSync(
		path.join(bin, 'foxguard'),
		'#!/bin/sh\necho "Warning: Failed to parse YAML /tmp/rules.yml" >&2\necho \'{"findings":[]}\'\n',
		{ mode: 0o755 },
	);
	const broken = runGuard(['--root', dir], { env: { PATH: bin } });
	assert.equal(broken.code, EXIT_CANNOT_RUN, broken.out);
	assert.match(broken.out, /did not load the generated rules/);
});
