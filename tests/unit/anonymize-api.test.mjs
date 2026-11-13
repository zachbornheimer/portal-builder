/**
 * All Intersections anonymize path — fake transport only, never the live host.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const harness = path.join(root, 'tests/support/php-anonymize.php');
const artifactDir = path.join(root, 'tests/.artifacts/anonymize');
const TEST_KEY = 'anon_test_harness_key';
const ORIGINAL_PDF = '%PDF-1.3 original-score\n';
const DOWNLOAD_PDF = '%PDF-1.4 anonymized-score\n';
const UUID_V4 =
	/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
const CREATE_PATH = '/v1/anonymizations';
const DEFAULT_CREATE_URL = `https://api.allintersections.com${CREATE_PATH}`;

/**
 * @param {string} id
 */
function downloadPath(id) {
	return `${CREATE_PATH}/${id}/content`;
}

/**
 * @param {Record<string, unknown>} extra
 */
function completedCreate(extra = {}) {
	return {
		status: 200,
		body: JSON.stringify({
			object: 'anonymization',
			request_id: 'req_01TESTANONYMIZE000000000001',
			idempotency_key: '00000000-0000-4000-8000-000000000001',
			livemode: false,
			data: {
				id: 'an_test_completed',
				status: 'completed',
				filename: 'score.pdf',
				file_type: 'application/pdf',
				bytes: DOWNLOAD_PDF.length,
				created: 1700000000,
				...extra,
			},
		}),
	};
}

/**
 * @param {string} body
 * @param {number} [status]
 */
function rawReply(body, status = 200) {
	return { status, body };
}

/**
 * @param {object} scenario
 */
function runScenario(scenario) {
	fs.mkdirSync(artifactDir, { recursive: true });
	const file = path.join(artifactDir, `${scenario.portalId || 'anon'}.scenario.json`);
	fs.writeFileSync(file, JSON.stringify(scenario));
	const r = spawnSync('php', [harness, file], {
		encoding: 'utf8',
		env: { ...process.env },
	});
	const out = `${r.stdout || ''}${r.stderr || ''}`;
	let data = null;
	try {
		data = JSON.parse((r.stdout || '').trim());
	} catch {
		data = null;
	}
	return { code: r.status, out, data };
}

function enabledOptions() {
	return {
		anonymize: true,
		anonymizeEndpoint: null,
		anonymizeApiKey: TEST_KEY,
	};
}

test('create envelope plus PDF download replaces the original and records Bearer plus UUID v4', () => {
	const { code, out, data } = runScenario({
		entry: 'pipeline',
		portalId: 'anon-valid-pdf',
		artifactDir,
		filename: 'score.pdf',
		original: ORIGINAL_PDF,
		download: DOWNLOAD_PDF,
		options: enabledOptions(),
		script: [completedCreate(), rawReply(DOWNLOAD_PDF)],
	});
	assert.equal(code, 0, out);
	assert.ok(data, out);
	assert.equal(data.submit_ok, true);
	assert.equal(data.stored_equals_download, true, 'stored bytes must be the download');
	assert.equal(data.stored_equals_original, false);
	assert.equal(data.call_count, 2);
	const [create, download] = data.calls;
	assert.equal(create.method, 'POST');
	assert.equal(create.url, DEFAULT_CREATE_URL);
	assert.equal(create.authorization, `Bearer ${TEST_KEY}`);
	assert.match(create.idempotency_key, UUID_V4);
	assert.equal(create.has_multipart, true);
	assert.equal(create.timeout >= 120, true, 'create timeout must be >= 120s');
	assert.equal(create.url_has_key, false);
	assert.equal(download.method, 'POST');
	assert.equal(download.path, downloadPath('an_test_completed'));
	assert.equal(download.authorization, `Bearer ${TEST_KEY}`);
	assert.match(download.idempotency_key, UUID_V4);
	assert.equal(download.url_has_key, false);
});

test('create 500 twice then 200 reuses the same Idempotency-Key', () => {
	const { code, out, data } = runScenario({
		entry: 'anonymizer',
		portalId: 'anon-retry-5xx',
		filename: 'score.pdf',
		original: ORIGINAL_PDF,
		download: DOWNLOAD_PDF,
		options: enabledOptions(),
		script: [
			{ status: 500, body: JSON.stringify({ code: 'internal_error' }) },
			{ status: 500, body: JSON.stringify({ code: 'internal_error' }) },
			completedCreate(),
			rawReply(DOWNLOAD_PDF),
		],
	});
	assert.equal(code, 0, out);
	assert.ok(data, out);
	assert.equal(data.stored_equals_download, true);
	const creates = data.calls.filter((c) => c.path === CREATE_PATH);
	assert.equal(creates.length, 3);
	const keys = creates.map((c) => c.idempotency_key);
	assert.equal(keys[0], keys[1]);
	assert.equal(keys[1], keys[2]);
	assert.match(keys[0], UUID_V4);
});

test('invalid download bytes keep the original', () => {
	const { code, out, data } = runScenario({
		entry: 'pipeline',
		portalId: 'anon-invalid-download',
		artifactDir,
		filename: 'score.pdf',
		original: ORIGINAL_PDF,
		download: DOWNLOAD_PDF,
		options: enabledOptions(),
		script: [completedCreate(), rawReply('this is not a pdf')],
	});
	assert.equal(code, 0, out);
	assert.ok(data, out);
	assert.equal(data.submit_ok, true);
	assert.equal(data.stored_equals_original, true);
	assert.equal(data.stored_equals_download, false);
});

test('transport throw keeps the original and submit still succeeds', () => {
	const { code, out, data } = runScenario({
		entry: 'pipeline',
		portalId: 'anon-timeout',
		artifactDir,
		filename: 'score.pdf',
		original: ORIGINAL_PDF,
		download: DOWNLOAD_PDF,
		options: enabledOptions(),
		script: [{ throw: 'timed out after 120s' }],
	});
	assert.equal(code, 0, out);
	assert.ok(data, out);
	assert.equal(data.submit_ok, true);
	assert.equal(data.stored_equals_original, true);
	assert.equal(data.stored_equals_download, false);
});

test('anonymize false makes zero transport calls', () => {
	const { code, out, data } = runScenario({
		entry: 'pipeline',
		portalId: 'anon-disabled',
		artifactDir,
		filename: 'score.pdf',
		original: ORIGINAL_PDF,
		download: DOWNLOAD_PDF,
		options: {
			anonymize: false,
			anonymizeEndpoint: null,
			anonymizeApiKey: TEST_KEY,
		},
		script: [completedCreate(), rawReply(DOWNLOAD_PDF)],
	});
	assert.equal(code, 0, out);
	assert.ok(data, out);
	assert.equal(data.submit_ok, true);
	assert.equal(data.call_count, 0);
	assert.equal(data.stored_equals_original, true);
});

test('file larger than 50 MiB makes zero transport calls and keeps original', () => {
	const oversized = `%PDF-1.3\n${'A'.repeat(50 * 1024 * 1024 + 1)}`;
	const { code, out, data } = runScenario({
		entry: 'anonymizer',
		portalId: 'anon-too-large',
		filename: 'score.pdf',
		original: oversized,
		download: DOWNLOAD_PDF,
		options: enabledOptions(),
		script: [completedCreate(), rawReply(DOWNLOAD_PDF)],
	});
	assert.equal(code, 0, out);
	assert.ok(data, out);
	assert.equal(data.call_count, 0);
	assert.equal(data.stored_equals_original, true);
	assert.equal(data.stored_equals_download, false);
});
