/**
 * ZYS-630: anyone-audience submit without a valid captcha token is rejected.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import path from 'node:path';

const root = process.cwd();
const harness = path.join(root, 'tests/support/php-spam-gate.php');

function run(payload) {
	const r = spawnSync('php', [harness, JSON.stringify(payload)], { encoding: 'utf8' });
	assert.equal(r.status, 0, `${r.stdout || ''}${r.stderr || ''}`);
	return JSON.parse((r.stdout || '').trim());
}

test('anyone-audience submit without a valid captcha token is rejected', () => {
	const missing = run({ audience: 'anyone', token: '', accept: false });
	assert.equal(missing.required, true);
	assert.equal(missing.ok, false);
	assert.equal(missing.code, 'dg_submission_captcha');

	const bad = run({ audience: 'anyone', token: 'forged', accept: false });
	assert.equal(bad.ok, false);
	assert.equal(bad.code, 'dg_submission_captcha');
});

test('logged-in and members audiences skip the captcha', () => {
	const logged = run({ audience: 'logged_in', token: '', accept: false });
	assert.equal(logged.required, false);
	assert.equal(logged.ok, true);

	const members = run({ audience: 'members', token: '', accept: false });
	assert.equal(members.required, false);
	assert.equal(members.ok, true);
});

test('anyone-audience with an accepted token is allowed', () => {
	const ok = run({ audience: 'anyone', token: 'ok-token', accept: true });
	assert.equal(ok.ok, true);
	assert.equal(ok.code, null);
});
