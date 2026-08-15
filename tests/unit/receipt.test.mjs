/**
 * Receipt URL carries only app id + HMAC. Mailer interpolates closed tokens.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const harness = path.join(root, 'tests/support/php-receipt.php');
const pipelineHarness = path.join(root, 'tests/support/php-submit-pipeline.php');
const definition = path.join(
	root,
	'tests/fixtures/portals/herbolzheimer.definition.json',
);
const submission = path.join(
	root,
	'tests/fixtures/portals/herbolzheimer.submission.json',
);
const artifactDir = path.join(root, 'tests/.artifacts/receipt');

/**
 * @param {string[]} args
 * @param {NodeJS.ProcessEnv} [envOverride]
 */
function runReceipt(args, envOverride = {}) {
	const r = spawnSync('php', [harness, ...args], {
		encoding: 'utf8',
		env: { ...process.env, DG_TEST_MODE: '1', ...envOverride },
	});
	return {
		code: r.status,
		out: (r.stdout || '') + (r.stderr || ''),
		stdout: r.stdout || '',
	};
}

function parseJson(text) {
	const start = text.indexOf('{') === -1 ? text.indexOf('[') : text.indexOf('{');
	assert.notEqual(start, -1, `no JSON in: ${text}`);
	return JSON.parse(text.slice(start));
}

/**
 * @param {string} name
 * @param {unknown} data
 */
function writeJson(name, data) {
	const dest = path.join(artifactDir, name);
	fs.mkdirSync(path.dirname(dest), { recursive: true });
	fs.writeFileSync(dest, `${JSON.stringify(data, null, 2)}\n`);
	return dest;
}

test('receipt URL from builder has no email= or name=', () => {
	const { code, out, stdout } = runReceipt(['url', '25657', 'dg_no_pii']);
	assert.equal(code, 0, out);
	const data = parseJson(stdout);
	const url = String(data.url || '');
	assert.match(url, /dg-receipt=1/);
	assert.match(url, /(?:\?|&)app=dg_no_pii(?:&|$)/);
	assert.match(url, /(?:\?|&)t=[0-9a-f]{32,}/i);
	assert.doesNotMatch(url, /email=/i);
	assert.doesNotMatch(url, /name=/i);
	assert.doesNotMatch(url, /work_title=/i);
});

test('HMAC mismatch does not render stored identity', () => {
	const { code, out, stdout } = runReceipt([
		'render',
		'dg_hmac_mismatch',
		'bad',
		'Alice Secret',
		'alice.secret@example.com',
	]);
	assert.equal(code, 0, out);
	const html = String(parseJson(stdout).html || '');
	assert.match(html, /dg-receipt/);
	assert.doesNotMatch(html, /Alice Secret/);
	assert.doesNotMatch(html, /alice\.secret@example\.com/i);
	assert.match(html, /invalid|not found|expired/i);
});

test('matching HMAC renders stored applicant name', () => {
	const { code, out, stdout } = runReceipt([
		'render',
		'dg_hmac_ok',
		'good',
		'Alice Secret',
		'alice.secret@example.com',
	]);
	assert.equal(code, 0, out);
	const html = String(parseJson(stdout).html || '');
	assert.match(html, /Alice Secret/);
	assert.match(html, /alice\.secret@example\.com/i);
	assert.match(html, /dg_hmac_ok/);
});

test('mailer interpolates {{$receiptLink}} and {{receipt_url}} aliases', () => {
	const templateFile = writeJson('template.json', {
		template:
			'Hello {{applicant_name}}. Link A {{$receiptLink}} Link B {{receipt_url}} for {{portal_title}}.',
	});
	const tokensFile = writeJson('tokens.json', {
		applicant_name: 'John Doe',
		portal_title: '2027 Call for Scores and Papers',
		receipt_url: 'http://localhost:10033/portal/25657?dg-receipt=1&app=dg_x&t=abc',
	});
	const { code, out, stdout } = runReceipt([
		'interpolate',
		templateFile,
		tokensFile,
	]);
	assert.equal(code, 0, out);
	const result = String(parseJson(stdout).result || '');
	assert.match(result, /Hello John Doe/);
	assert.match(result, /2027 Call for Scores and Papers/);
	assert.equal(
		(result.match(/dg-receipt=1&app=dg_x&t=abc/g) || []).length,
		2,
		result,
	);
});

test('pipeline receipt URL and mail body omit email query args', () => {
	const env = { ...process.env, DG_TEST_MODE: '1' };
	const r = spawnSync(
		'php',
		[pipelineHarness, definition, submission, artifactDir, 'herbolzheimer-receipt'],
		{ encoding: 'utf8', env },
	);
	const text = (r.stdout || '') + (r.stderr || '');
	assert.equal(r.status, 0, text);
	const data = parseJson(text);
	const url = String(data.receipt_url || data.row?.receiptUrl || '');
	assert.ok(url, `expected receipt url, got ${JSON.stringify(data.row)}`);
	assert.doesNotMatch(url, /email=/i);
	assert.doesNotMatch(url, /name=/i);
	assert.match(url, /dg-receipt=1/);
	assert.ok(data.mailPath && fs.existsSync(data.mailPath), 'mail capture');
	const mail = JSON.parse(fs.readFileSync(data.mailPath, 'utf8'));
	const blob = `${mail.subject || ''}\n${mail.body || ''}\n${JSON.stringify(mail.tokens || {})}`;
	assert.match(blob, /dg-receipt=1/);
	assert.doesNotMatch(blob, /email=/i);
	assert.equal(data.row?.email, 'applicant@example.com');
	assert.match(String(data.row?.dateReceived || ''), /[A-Za-z]{3} \d{2}, \d{4}/);
});
