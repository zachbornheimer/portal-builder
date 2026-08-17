/**
 * Live isjac.org still stores pb_*. Dual-read dg_* then pb_*; dg_* wins when both exist.
 * Compat-write refreshes an existing pb_* so a 0.1.2 rollback keeps a fresh Google token.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const harness = path.join(root, 'tests/support/php-portal-options.php');
const artifactDir = path.join(root, 'tests/.artifacts/portal-options');

/**
 * @param {object} payload
 */
function run(payload) {
	fs.mkdirSync(artifactDir, { recursive: true });
	const file = path.join(artifactDir, `${payload.name || 'case'}.json`);
	fs.writeFileSync(file, JSON.stringify(payload));
	const r = spawnSync('php', [harness, file], { encoding: 'utf8' });
	const out = `${r.stdout || ''}${r.stderr || ''}`;
	let data = null;
	try {
		data = JSON.parse((r.stdout || '').trim());
	} catch {
		data = null;
	}
	return { code: r.status, out, data };
}

test('option stored only as pb_login_url is still returned for dg_login_url', () => {
	const { code, out, data } = run({
		name: 'legacy-login',
		action: 'get',
		key: 'dg_login_url',
		default: '',
		options: { pb_login_url: '/signin' },
	});
	assert.equal(code, 0, out);
	assert.ok(data, out);
	assert.equal(data.value, '/signin');
});

test('dg_login_url wins when both dg_ and pb_ login options exist', () => {
	const { code, out, data } = run({
		name: 'dg-login-wins',
		action: 'get',
		key: 'dg_login_url',
		default: '',
		options: { pb_login_url: '/old', dg_login_url: '/new' },
	});
	assert.equal(code, 0, out);
	assert.ok(data, out);
	assert.equal(data.value, '/new');
});

test('stored empty string on dg_login_url wins and does not fall through to pb_', () => {
	const { code, out, data } = run({
		name: 'empty-dg-wins',
		action: 'get',
		key: 'dg_login_url',
		default: '/fallback',
		options: { dg_login_url: '', pb_login_url: '/signin' },
	});
	assert.equal(code, 0, out);
	assert.ok(data, out);
	assert.equal(data.value, '');
});

test('set writes dg_ and refreshes an existing pb_ login option', () => {
	const { code, out, data } = run({
		name: 'set-dual-login',
		action: 'set',
		key: 'dg_login_url',
		value: '/fresh',
		options: { pb_login_url: '/stale' },
	});
	assert.equal(code, 0, out);
	assert.ok(data, out);
	assert.equal(data.store.dg_login_url, '/fresh');
	assert.equal(data.store.pb_login_url, '/fresh');
	assert.equal(data.read, '/fresh');
});

test('set does not invent a pb_ key when only dg_ is being created', () => {
	const { code, out, data } = run({
		name: 'set-dg-only',
		action: 'set',
		key: 'dg_login_url',
		value: '/only-new',
		options: {},
	});
	assert.equal(code, 0, out);
	assert.ok(data, out);
	assert.equal(data.store.dg_login_url, '/only-new');
	assert.equal(Object.hasOwn(data.store, 'pb_login_url'), false);
});

test('Google access key stored only as pb_google_access_key is still returned', () => {
	const { code, out, data } = run({
		name: 'legacy-google',
		action: 'google_access',
		options: { pb_google_access_key: 'ya29.legacy-token' },
	});
	assert.equal(code, 0, out);
	assert.ok(data, out);
	assert.equal(data.constant, 'dg_google_access_key');
	assert.equal(data.value, 'ya29.legacy-token');
});

test('dg_google_access_key wins when both Google access keys exist', () => {
	const { code, out, data } = run({
		name: 'dg-google-wins',
		action: 'google_access',
		options: {
			pb_google_access_key: 'ya29.stale',
			dg_google_access_key: 'ya29.fresh',
		},
	});
	assert.equal(code, 0, out);
	assert.ok(data, out);
	assert.equal(data.value, 'ya29.fresh');
});

test('set refreshes an existing pb_google_access_key so rollback stays token-fresh', () => {
	const { code, out, data } = run({
		name: 'set-dual-google',
		action: 'google_set',
		value: 'ya29.rotated',
		options: { pb_google_access_key: 'ya29.stale' },
	});
	assert.equal(code, 0, out);
	assert.ok(data, out);
	assert.equal(data.store.dg_google_access_key, 'ya29.rotated');
	assert.equal(data.store.pb_google_access_key, 'ya29.rotated');
});

test('promote moves pb_login_url onto dg_login_url and deletes the legacy key', () => {
	const { code, out, data } = run({
		name: 'promote-pb-only',
		action: 'promote',
		key: 'dg_login_url',
		default: '',
		options: { pb_login_url: '/signin' },
	});
	assert.equal(code, 0, out);
	assert.ok(data, out);
	assert.equal(data.read, '/signin');
	assert.equal(data.store.dg_login_url, '/signin');
	assert.equal(Object.hasOwn(data.store, 'pb_login_url'), false);
});

test('promote keeps dg_login_url and deletes pb_ when both are set', () => {
	const { code, out, data } = run({
		name: 'promote-both-set',
		action: 'promote',
		key: 'dg_login_url',
		default: '',
		options: { dg_login_url: '/new', pb_login_url: '/old' },
	});
	assert.equal(code, 0, out);
	assert.ok(data, out);
	assert.equal(data.read, '/new');
	assert.equal(data.store.dg_login_url, '/new');
	assert.equal(Object.hasOwn(data.store, 'pb_login_url'), false);
});

test('promote does not overwrite an empty dg_login_url from pb_', () => {
	const { code, out, data } = run({
		name: 'promote-empty-dg-stays',
		action: 'promote',
		key: 'dg_login_url',
		default: '/fallback',
		options: { dg_login_url: '', pb_login_url: '/signin' },
	});
	assert.equal(code, 0, out);
	assert.ok(data, out);
	assert.equal(data.read, '');
	assert.equal(data.store.dg_login_url, '');
	assert.equal(Object.hasOwn(data.store, 'pb_login_url'), false);
});

test('site login_url still resolves when only pb_login_url is stored', () => {
	const { code, out, data } = run({
		name: 'site-legacy-login',
		action: 'login_url',
		options: { pb_login_url: '/signin' },
		redirect: 'https://example.test/here',
	});
	assert.equal(code, 0, out);
	assert.ok(data, out);
	assert.match(data.login_url, /\/signin\?redirect_to=/);
});
