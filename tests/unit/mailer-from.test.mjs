/**
 * Receipt From display name is a site option. Empty → Portal Submissions.
 * Receipt to is the applicant sub_email.
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
const submissionPath = path.join(
	root,
	'tests/fixtures/portals/herbolzheimer.submission.json',
);
const artifactDir = path.join(root, 'tests/.artifacts/mailer-from');
const DEFAULT_FROM_NAME = 'Portal Submissions';
const APPLICANT_EMAIL = 'applicant@example.com';
const FROM_EMAIL = 'receipts@example.com';

/**
 * @param {Record<string, unknown>} message
 */
function sendReceipt(message) {
	fs.mkdirSync(artifactDir, { recursive: true });
	const dest = path.join(
		artifactDir,
		`${String(message.name || 'send')}.json`,
	);
	fs.writeFileSync(dest, `${JSON.stringify(message, null, 2)}\n`);
	const r = spawnSync('php', [harness, 'send', dest, artifactDir], {
		encoding: 'utf8',
		env: { ...process.env, DG_TEST_MODE: '1' },
	});
	const out = `${r.stdout || ''}${r.stderr || ''}`;
	const start = out.indexOf('{');
	assert.notEqual(start, -1, `no JSON in: ${out}`);
	const data = JSON.parse(out.slice(start));
	return {
		code: r.status,
		out,
		payload:
			data.payload && typeof data.payload === 'object' ? data.payload : {},
	};
}

/**
 * @param {string} dir
 * @returns {string[]}
 */
function walkPhp(dir) {
	/** @type {string[]} */
	const files = [];
	for (const name of fs.readdirSync(dir)) {
		const full = path.join(dir, name);
		const st = fs.statSync(full);
		if (st.isDirectory()) {
			files.push(...walkPhp(full));
			continue;
		}
		if (name.endsWith('.php')) {
			files.push(full);
		}
	}
	return files;
}

test('empty from-name option uses Portal Submissions', () => {
	const { code, out, payload } = sendReceipt({
		name: 'empty-from-name',
		to: APPLICANT_EMAIL,
		portalId: 'from-name-empty',
		tokens: {},
		options: {
			dg_receipt_from_name: '',
			dg_receipt_from_email: FROM_EMAIL,
		},
	});
	assert.equal(code, 0, out);
	assert.equal(payload.from, DEFAULT_FROM_NAME);
	assert.equal(payload.fromHeader, `${DEFAULT_FROM_NAME} <${FROM_EMAIL}>`);
});

test('set from-name option wins over the default', () => {
	const { code, out, payload } = sendReceipt({
		name: 'set-from-name',
		to: APPLICANT_EMAIL,
		portalId: 'from-name-set',
		tokens: {},
		options: {
			dg_receipt_from_name: 'ISJAC Submissions',
			dg_receipt_from_email: FROM_EMAIL,
		},
	});
	assert.equal(code, 0, out);
	assert.equal(payload.from, 'ISJAC Submissions');
	assert.equal(payload.fromHeader, `ISJAC Submissions <${FROM_EMAIL}>`);
});

test('receipt to is the applicant sub_email', () => {
	const submission = JSON.parse(fs.readFileSync(submissionPath, 'utf8'));
	const wantTo = String(submission.values?.sub_email || '');
	assert.equal(wantTo, APPLICANT_EMAIL);

	const r = spawnSync(
		'php',
		[
			pipelineHarness,
			definition,
			submissionPath,
			artifactDir,
			'mailer-from-to',
		],
		{ encoding: 'utf8', env: { ...process.env, DG_TEST_MODE: '1' } },
	);
	const text = `${r.stdout || ''}${r.stderr || ''}`;
	assert.equal(r.status, 0, text);
	const start = text.indexOf('{');
	assert.notEqual(start, -1, `no JSON in: ${text}`);
	const data = JSON.parse(text.slice(start));
	assert.ok(data.mailPath && fs.existsSync(data.mailPath), 'mail capture');
	const mail = JSON.parse(fs.readFileSync(data.mailPath, 'utf8'));
	assert.notEqual(mail.kind, 'operator');
	assert.equal(mail.to, wantTo);
});

test('plugin PHP does not say Automated Submission Receipts', () => {
	const files = [
		path.join(root, 'portal-builder.php'),
		...walkPhp(path.join(root, 'includes')),
	];
	const hits = [];
	for (const file of files) {
		const text = fs.readFileSync(file, 'utf8');
		if (text.includes('Automated Submission Receipts')) {
			hits.push(path.relative(root, file));
		}
	}
	assert.deepEqual(hits, []);
});
