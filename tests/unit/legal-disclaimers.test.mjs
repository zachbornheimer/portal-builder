/**
 * Site legal disclaimers on the definition public form.
 *
 * Production copy lives in dg_legal_disclaimers (Data_Table rows).
 * These fixtures match the live ISJAC option; they are not product copy.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const parseHarness = path.join(root, 'tests/support/php-legal-disclaimers.php');
const renderHarness = path.join(root, 'tests/support/php-render-definition.php');
const submitHarness = path.join(root, 'tests/support/php-submit-pipeline.php');
const artifactDir = path.join(root, 'tests/.artifacts/legal-disclaimers');

const OWNERSHIP_TEXT =
	'I certify that this work is solely my own, that I am solely responsible for the content, organization, and construction of this work, and that I have the sole authority to grant ISJAC permission to display this work.';
const PROMO_TEXT =
	'I grant ISJAC the non-exclusive right to record, use, perform, distribute and otherwise exploit the submitted work or any portion(s) thereof for the purpose of advertising, publicizing or otherwise promoting ISJAC and its associated events by any and all means including without limitation, audio-visual trailers, commercials, promotions and advertisements, in any and all medium or forum whether now known or hereafter devised.';

const LIVE_TABLE = [
	['sub_cert_ownership', OWNERSHIP_TEXT],
	['sub_cert_promo', PROMO_TEXT],
	['', ''],
];

const LIVE_ROWS = [
	{ id: 'sub_cert_ownership', text: OWNERSHIP_TEXT },
	{ id: 'sub_cert_promo', text: PROMO_TEXT },
];

/**
 * @param {unknown} payload
 * @param {string} name
 */
function writePayload(payload, name) {
	fs.mkdirSync(artifactDir, { recursive: true });
	const file = path.join(artifactDir, name);
	fs.writeFileSync(file, JSON.stringify(payload));
	return file;
}

/**
 * @param {string} harness
 * @param {string[]} args
 */
function runPhp(harness, args) {
	const r = spawnSync('php', [harness, ...args], {
		encoding: 'utf8',
		env: { ...process.env, DG_TEST_MODE: '1' },
	});
	return {
		code: r.status,
		out: `${r.stdout || ''}${r.stderr || ''}`,
		stdout: r.stdout || '',
		stderr: r.stderr || '',
	};
}

/**
 * @param {unknown} payload
 * @param {string} name
 */
function parseDisclaimers(payload, name) {
	const file = writePayload(payload, name);
	const { code, out, stdout } = runPhp(parseHarness, [file]);
	let data = null;
	try {
		data = JSON.parse(stdout.trim());
	} catch {
		data = null;
	}
	return { code, out, data };
}

/**
 * @param {unknown} payload
 * @param {string} name
 */
function renderDefinition(payload, name) {
	const file = writePayload(payload, name);
	const { code, out, stdout } = runPhp(renderHarness, [file]);
	let data = null;
	try {
		data = JSON.parse(stdout.trim());
	} catch {
		data = null;
	}
	return { code, out, data };
}

/**
 * @param {boolean} anonymize
 * @param {Record<string, string>} extraValues
 * @param {string} name
 */
function submitWithSiteDisclaimers(anonymize, extraValues, name) {
	const defPath = writePayload(
		{
			version: 1,
			fields: [{ id: 'piece', type: 'short_text', label: 'Piece', required: true }],
			options: {
				anonymize,
				anonymizeApiKey: anonymize ? 'legal_disclaimer_harness_key' : '',
			},
			publish: { enabled: true },
		},
		`${name}.definition.json`,
	);
	const subPath = writePayload(
		{
			portalId: name,
			legalDisclaimers: LIVE_ROWS,
			values: { sub_piece: 'Test Piece', ...extraValues },
			files: {},
		},
		`${name}.submission.json`,
	);
	return runPhp(submitHarness, [defPath, subPath, artifactDir, name]);
}

test('parse of live double-encoded option yields two rows with stripped field ids', () => {
	const { code, out, data } = parseDisclaimers(
		{ action: 'parse', raw: JSON.stringify(JSON.stringify(LIVE_TABLE)) },
		'parse-double-encoded.json',
	);
	assert.equal(code, 0, out);
	assert.ok(data && Array.isArray(data.rows), out);
	assert.equal(data.rows.length, 2, JSON.stringify(data.rows));
	assert.deepEqual(
		data.rows.map((row) => row.field_id),
		['cert_ownership', 'cert_promo'],
	);
	assert.equal(data.rows[0].id, 'sub_cert_ownership');
	assert.equal(data.rows[0].text, OWNERSHIP_TEXT);
	assert.equal(data.rows[1].id, 'sub_cert_promo');
	assert.equal(data.rows[1].text, PROMO_TEXT);
});

test('parse drops empty third row', () => {
	const { code, out, data } = parseDisclaimers(
		{ action: 'parse', raw: LIVE_TABLE },
		'parse-empty-third.json',
	);
	assert.equal(code, 0, out);
	assert.ok(data && Array.isArray(data.rows), out);
	assert.equal(data.rows.length, 2, JSON.stringify(data.rows));
	assert.deepEqual(
		data.rows.map((row) => row.field_id),
		['cert_ownership', 'cert_promo'],
	);
});

test('definition render includes both site disclaimers as required checkboxes and anonymize ack', () => {
	const { code, out, data } = renderDefinition(
		{
			definition: {
				version: 1,
				fields: [{ id: 'piece', type: 'short_text', label: 'Piece', required: true }],
				options: { anonymize: true },
			},
			legalDisclaimers: LIVE_ROWS,
		},
		'render-injected.json',
	);
	assert.equal(code, 0, out);
	assert.ok(data && data.html, out);
	assert.match(data.html, new RegExp(OWNERSHIP_TEXT.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
	assert.match(data.html, new RegExp(PROMO_TEXT.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
	assert.match(data.html, /name="sub_cert_ownership"/);
	assert.match(data.html, /name="sub_cert_promo"/);
	assert.match(data.html, /name="sub_cert_ownership"[^>]*required|id="sub_cert_ownership"[^>]*required/);
	assert.match(data.html, /name="sub_cert_promo"[^>]*required|id="sub_cert_promo"[^>]*required/);
	assert.match(data.html, /name="sub_anonymize_ack"/);
	assert.match(data.html, /dg-field--disclaimer/);
});

test('definition render without site disclaimers does not invent agreement texts', () => {
	const { code, out, data } = renderDefinition(
		{
			version: 1,
			fields: [{ id: 'piece', type: 'short_text', label: 'Piece', required: true }],
			options: { anonymize: true },
		},
		'render-no-inject.json',
	);
	assert.equal(code, 0, out);
	assert.ok(data && data.html, out);
	assert.equal(data.html.includes(OWNERSHIP_TEXT), false);
	assert.equal(data.html.includes(PROMO_TEXT), false);
	assert.equal(data.html.includes('name="sub_cert_ownership"'), false);
	assert.equal(data.html.includes('name="sub_cert_promo"'), false);
	assert.match(data.html, /name="sub_anonymize_ack"/);
});

test('submit with anonymize ack but unchecked site disclaimers fails validation', () => {
	const { code, out } = submitWithSiteDisclaimers(
		true,
		{ sub_anonymize_ack: '1' },
		'submit-missing-site',
	);
	assert.notEqual(code, 0, out);
	assert.match(out, /dg_submission_invalid|must be accepted/i);
	assert.match(out, /cert_ownership|solely my own/i);
});

test('submit with ownership, promo, and anonymize ack checked passes', () => {
	const { code, out, stdout } = submitWithSiteDisclaimers(
		true,
		{
			sub_anonymize_ack: '1',
			sub_cert_ownership: '1',
			sub_cert_promo: '1',
		},
		'submit-all-checked',
	);
	assert.equal(code, 0, out);
	const data = JSON.parse(stdout.trim());
	assert.equal(data.ok, true);
});
