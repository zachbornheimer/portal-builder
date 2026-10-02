/**
 * ZYS-630 / ZYS-1532: Turnstile spam gate — require only when both keys set.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
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
	assert.equal(missing.complete, true);
	assert.equal(missing.ok, false);
	assert.equal(missing.code, 'dg_submission_captcha');

	const bad = run({ audience: 'anyone', token: 'forged', accept: false });
	assert.equal(bad.ok, false);
	assert.equal(bad.code, 'dg_submission_captcha');
});

test('members and logged-in submits still need Turnstile when keys are set', () => {
	const logged = run({ audience: 'logged_in', token: '', accept: false });
	assert.equal(logged.required, true);
	assert.equal(logged.ok, false);
	assert.equal(logged.code, 'dg_submission_captcha');

	const members = run({ audience: 'members', token: '', accept: false });
	assert.equal(members.required, true);
	assert.equal(members.ok, false);
	assert.equal(members.code, 'dg_submission_captcha');
});

test('site key without secret does not require (ZYS-1532 MSG_INVALID path)', () => {
	const r = run({ audience: 'anyone', token: 'tok', secret: '', site: 'site-only', accept: false });
	assert.equal(r.complete, false);
	assert.equal(r.incomplete, true);
	assert.equal(r.required, false);
	assert.equal(r.ok, true);
	assert.equal(r.code, null);
});

test('secret without site key does not require', () => {
	const r = run({ audience: 'members', token: '', secret: 'sec-only', site: '', accept: false });
	assert.equal(r.complete, false);
	assert.equal(r.incomplete, true);
	assert.equal(r.required, false);
	assert.equal(r.ok, true);
});

test('Turnstile options are dg_turnstile_site_key and dg_turnstile_secret', () => {
	const src = fs.readFileSync(
		path.join(root, 'includes/Submission/class-portal-spam-gate.php'),
		'utf8',
	);
	assert.match(src, /dg_turnstile_site_key/);
	assert.match(src, /dg_turnstile_secret/);
	assert.match(src, /function keys_complete/);
	assert.doesNotMatch(src, /pb_turnstile/);
});

test('anyone-audience with an accepted token is allowed', () => {
	const ok = run({ audience: 'anyone', token: 'ok-token', accept: true });
	assert.equal(ok.ok, true);
	assert.equal(ok.code, null);
});

test('legacy Google reCAPTCHA is not enqueued without leftover sitekey', () => {
	const src = fs.readFileSync(path.join(root, 'includes/templates.php'), 'utf8');
	assert.match(src, /dg_recaptcha_sitekey/);
	assert.match(src, /enqueue_recaptcha_script/);
	assert.match(src, /trim\(\$sitekey\)/);
});
