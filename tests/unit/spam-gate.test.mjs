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

test('logged-in and members audiences require the captcha when keys are set', () => {
	const logged = run({ audience: 'logged_in', token: '', accept: false });
	assert.equal(logged.required, true);
	assert.equal(logged.ok, false);
	assert.equal(logged.code, 'dg_submission_captcha');

	const members = run({ audience: 'members', token: '', accept: false });
	assert.equal(members.required, true);
	assert.equal(members.ok, false);
	assert.equal(members.code, 'dg_submission_captcha');
});

test('anyone-audience with an accepted token is allowed', () => {
	const ok = run({ audience: 'anyone', token: 'ok-token', accept: true });
	assert.equal(ok.ok, true);
	assert.equal(ok.code, null);
});

test('members and logged_in require a captcha token when keys are set', () => {
	const members = run({ audience: 'members', token: '', accept: false });
	assert.equal(members.required, true);
	assert.equal(members.ok, false);
	assert.equal(members.code, 'dg_submission_captcha');

	const logged = run({ audience: 'logged_in', token: '', accept: false });
	assert.equal(logged.required, true);
	assert.equal(logged.ok, false);
	assert.equal(logged.code, 'dg_submission_captcha');
});

test('testMode with empty keys still requires a token and uses the dummy site key', () => {
	const missing = run({
		audience: 'anyone',
		token: '',
		site: '',
		secret: '',
		testMode: true,
		accept: false,
	});
	assert.equal(missing.required, true);
	assert.equal(missing.ok, false);
	assert.equal(missing.code, 'dg_submission_captcha');
	assert.equal(missing.siteKey, '1x00000000000000000000AA');
	assert.match(String(missing.widget || ''), /data-testid="dg-turnstile"/);
	assert.match(String(missing.widget || ''), /data-sitekey="1x00000000000000000000AA"/);
});
