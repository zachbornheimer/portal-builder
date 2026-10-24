/**
 * ZYS-630 / ZYS-1532: Turnstile spam gate — require only when both keys set;
 * reject paths append evidence without storing tokens.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const root = process.cwd();
const harness = path.join(root, 'tests/support/php-spam-gate.php');

function run(payload) {
	const r = spawnSync('php', [harness, JSON.stringify(payload)], { encoding: 'utf8' });
	assert.equal(r.status, 0, `${r.stdout || ''}${r.stderr || ''}`);
	return JSON.parse((r.stdout || '').trim());
}

function freshLogDir(label) {
	return fs.mkdtempSync(path.join(os.tmpdir(), `dg-spam-${label}-`));
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
	assert.match(src, /function verify_live_detail/);
	assert.doesNotMatch(src, /pb_turnstile/);
});

test('anyone-audience with an accepted token is allowed', () => {
	const ok = run({ audience: 'anyone', token: 'ok-token', accept: true });
	assert.equal(ok.ok, true);
	assert.equal(ok.code, null);
	assert.equal(Array.isArray(ok.rejects) ? ok.rejects.length : -1, 0);
});

test('legacy Google reCAPTCHA is not enqueued without leftover sitekey', () => {
	const src = fs.readFileSync(path.join(root, 'includes/templates.php'), 'utf8');
	assert.match(src, /dg_recaptcha_sitekey/);
	assert.match(src, /enqueue_recaptcha_script/);
	assert.match(src, /trim\(\$sitekey\)/);
});

test('empty token reject logs skipped outcome without storing a token (ZYS-1532)', () => {
	const logDir = freshLogDir('empty');
	const token = '';
	const r = run({
		audience: 'anyone',
		token,
		accept: false,
		portalId: 'cfs-1532',
		email: 'applicant@example.com',
		wpUserId: 0,
		remoteIp: '203.0.113.10',
		userAgent: 'Mozilla/5.0 ZYS-1532-test',
		logDir,
	});
	assert.equal(r.ok, false);
	assert.equal(r.code, 'dg_submission_captcha');
	assert.equal(r.message, 'Please complete the spam check and try again.');
	assert.ok(Array.isArray(r.rejects) && r.rejects.length === 1);
	const row = r.rejects[0];
	assert.equal(row.portalId, 'cfs-1532');
	assert.equal(row.audience, 'anyone');
	assert.equal(row.outcome, 'skipped');
	assert.equal(row.tokenEmpty, true);
	assert.equal(row.tokenPresent, false);
	assert.equal(row.wpUserId, 0);
	assert.equal(row.remoteIp, '203.0.113.10');
	assert.match(String(row.userAgent || ''), /ZYS-1532-test/);
	assert.ok(row.emailHash && typeof row.emailHash === 'string');
	assert.doesNotMatch(row.emailHash, /applicant@example\.com/i);
	assert.equal(row.token, undefined);
	assert.doesNotMatch(r.logRaw || '', /cf-turnstile-response/);
	assert.ok(fs.existsSync(path.join(logDir, 'spam-rejects.jsonl')));
});

test('bad verifier reject logs verifier_false and never stores raw token (ZYS-1532)', () => {
	const logDir = freshLogDir('bad');
	const forged = 'forged-turnstile-token-do-not-store';
	const r = run({
		audience: 'anyone',
		token: forged,
		accept: false,
		portalId: 'cfs-1532-bad',
		email: 'florian-style@example.com',
		wpUserId: 42,
		remoteIp: '198.51.100.7',
		logDir,
	});
	assert.equal(r.ok, false);
	assert.equal(r.code, 'dg_submission_captcha');
	assert.equal(r.message, 'The spam check failed. Refresh the page and try again.');
	assert.ok(Array.isArray(r.rejects) && r.rejects.length === 1);
	const row = r.rejects[0];
	assert.equal(row.portalId, 'cfs-1532-bad');
	assert.equal(row.outcome, 'verifier_false');
	assert.equal(row.tokenEmpty, false);
	assert.equal(row.tokenPresent, true);
	assert.equal(row.wpUserId, 42);
	assert.equal(row.remoteIp, '198.51.100.7');
	assert.ok(row.emailHash && typeof row.emailHash === 'string');
	assert.equal(row.token, undefined);
	assert.doesNotMatch(r.logRaw || '', new RegExp(forged.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
	assert.doesNotMatch(JSON.stringify(row), new RegExp(forged.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
});

test('pipeline passes portal id into Spam_Gate::admit (ZYS-1532)', () => {
	const src = fs.readFileSync(
		path.join(root, 'includes/Submission/class-portal-submission-pipeline.php'),
		'utf8',
	);
	assert.match(src, /Portal_Spam_Gate::admit\(\s*\$definition,\s*\$values,\s*null,\s*\$portal_id\s*\)/);
});
