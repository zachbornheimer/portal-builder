/**
 * Drives shipped Settings → Google probe (PHP CLI harness).
 *
 * No live Google. No hardcoded production Sheet ID.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import path from 'node:path';

const root = process.cwd();
const harness = path.join(root, 'tests/support/php-google-probe.php');
const FIXED_NOW = '2026-08-16T14:32:01Z';

/**
 * @param {Record<string, unknown>} payload
 * @returns {Record<string, unknown>}
 */
function runHarness(payload) {
	const result = spawnSync('php', [harness, JSON.stringify(payload)], {
		encoding: 'utf8',
	});
	const out = `${result.stdout || ''}${result.stderr || ''}`;
	assert.equal(result.status, 0, out);
	const start = out.indexOf('{');
	assert.ok(start >= 0, `expected JSON from shipped harness, got: ${out}`);
	return JSON.parse(out.slice(start));
}

test('Settings → Google renders a probe button, folder/sheet inputs, and delete-row help', () => {
	const data = runHarness({ op: 'render' });
	assert.equal(data.missing, undefined, 'expected shipped probe render, not a missing method');
	const html = String(data.html || '');
	assert.match(html, /id="dg-google-probe-submit"/);
	assert.match(html, /id="dg-google-probe-folder-id"/);
	assert.match(html, /id="dg-google-probe-sheet-id"/);
	assert.match(html, /id="dg-google-probe-result"/);
	assert.match(html, /DRAGONGATE_TEST/);
	assert.match(html, /delete this row/i);
	assert.doesNotMatch(html, /wp_die/i);
});

test('missing Google secret returns a named human error and does not wp_die', () => {
	const data = runHarness({
		op: 'probe',
		secret: '',
		token: 'oauth-access-token',
		sheetId: 'sheet-probe-id',
		now: FIXED_NOW,
	});
	assert.equal(data.missing, undefined, 'expected shipped probe run, not a missing method');
	assert.equal(data.ok, false);
	assert.match(String(data.message || ''), /secret key is missing/i);
	assert.deepEqual(data.wpDie, []);
	assert.equal(data.appended, null);
});

test('missing Google token returns a named human error and does not wp_die', () => {
	const data = runHarness({
		op: 'probe',
		secret: 'oauth-client-json',
		token: '',
		sheetId: 'sheet-probe-id',
		now: FIXED_NOW,
	});
	assert.equal(data.missing, undefined);
	assert.equal(data.ok, false);
	assert.match(String(data.message || ''), /access key is missing/i);
	assert.deepEqual(data.wpDie, []);
	assert.equal(data.appended, null);
});

test('probe builds a row containing DRAGONGATE_TEST and an ISO timestamp', () => {
	const data = runHarness({ op: 'row', now: FIXED_NOW });
	assert.equal(data.missing, undefined, 'expected shipped test_row, not a missing method');
	assert.ok(Array.isArray(data.row), `expected row array, got ${JSON.stringify(data.row)}`);
	const joined = data.row.map(String).join(' ');
	assert.match(joined, /DRAGONGATE_TEST/);
	assert.match(joined, /2026-08-16T14:32:01Z/);
});

test('handler rejects missing manage_options without wp_die', () => {
	const data = runHarness({
		op: 'handle',
		can: false,
		nonceValid: true,
		sheetId: 'sheet-probe-id',
		now: FIXED_NOW,
	});
	assert.equal(data.missing, undefined, 'expected shipped Settings handler');
	assert.equal(data.ok, false);
	assert.match(String(data.message || ''), /administrator access/i);
	assert.deepEqual(data.wpDie, []);
	assert.equal(data.appended, null);
});

test('handler rejects missing or invalid nonce without wp_die', () => {
	const missing = runHarness({
		op: 'handle',
		can: true,
		nonceValid: true,
		nonce: '',
		sheetId: 'sheet-probe-id',
		now: FIXED_NOW,
	});
	assert.equal(missing.missing, undefined, 'expected shipped Settings handler');
	assert.equal(missing.ok, false);
	assert.match(String(missing.message || ''), /expired|reload/i);
	assert.deepEqual(missing.wpDie, []);

	const invalid = runHarness({
		op: 'handle',
		can: true,
		nonceValid: false,
		nonce: 'forged',
		sheetId: 'sheet-probe-id',
		now: FIXED_NOW,
	});
	assert.equal(invalid.ok, false);
	assert.match(String(invalid.message || ''), /expired|reload/i);
	assert.deepEqual(invalid.wpDie, []);
	assert.equal(invalid.appended, null);
});

test('blank folder skips list; blank sheet fails append; success writes dg_google_test_ok', () => {
	const noSheet = runHarness({
		op: 'probe',
		folderId: '',
		sheetId: '',
		now: FIXED_NOW,
	});
	assert.equal(noSheet.missing, undefined);
	assert.equal(noSheet.ok, false);
	assert.match(String(noSheet.message || ''), /sheet id is required/i);
	assert.equal(noSheet.listed, null);
	assert.equal(noSheet.appended, null);
	assert.deepEqual(noSheet.wpDie, []);

	const listed = runHarness({
		op: 'handle',
		can: true,
		nonceValid: true,
		folderId: 'folder-probe-id',
		sheetId: 'sheet-probe-id',
		now: FIXED_NOW,
	});
	assert.equal(listed.ok, true, JSON.stringify(listed));
	assert.equal(listed.listed, 'folder-probe-id');
	assert.ok(listed.appended);
	assert.equal(listed.appended.sheet_id, 'sheet-probe-id');
	const row = listed.appended.row.map(String).join(' ');
	assert.match(row, /DRAGONGATE_TEST/);
	assert.match(row, /2026-08-16T14:32:01Z/);
	assert.equal(listed.updated.dg_google_test_ok, FIXED_NOW);
	assert.match(String(listed.message || ''), /delete/i);
	assert.deepEqual(listed.wpDie, []);

	const skipList = runHarness({
		op: 'probe',
		folderId: '',
		sheetId: 'sheet-probe-id',
		now: FIXED_NOW,
	});
	assert.equal(skipList.ok, true, JSON.stringify(skipList));
	assert.equal(skipList.listed, null);
	assert.equal(skipList.appended.sheet_id, 'sheet-probe-id');
	assert.equal(skipList.updated.dg_google_test_ok, FIXED_NOW);
});

test('403, Shared Drive, and bad ID failures become human sentences', () => {
	const forbidden = runHarness({
		op: 'probe',
		sheetId: 'sheet-probe-id',
		appendError: '403 Forbidden: The caller does not have permission',
		appendErrorCode: 403,
		now: FIXED_NOW,
	});
	assert.equal(forbidden.ok, false);
	assert.match(String(forbidden.message || ''), /denied access \(403\)/i);
	assert.doesNotMatch(String(forbidden.message || ''), /#\d|stack|Trace/);
	assert.deepEqual(forbidden.wpDie, []);

	const shared = runHarness({
		op: 'probe',
		folderId: 'folder-probe-id',
		sheetId: 'sheet-probe-id',
		listError: 'File not found: Shared Drive requires supportsAllDrives',
		listErrorCode: 404,
		now: FIXED_NOW,
	});
	assert.equal(shared.ok, false);
	assert.match(String(shared.message || ''), /shared drive/i);
	assert.deepEqual(shared.wpDie, []);

	const badId = runHarness({
		op: 'probe',
		sheetId: 'not-a-real-id',
		appendError: 'Requested entity was not found',
		appendErrorCode: 404,
		now: FIXED_NOW,
	});
	assert.equal(badId.ok, false);
	assert.match(String(badId.message || ''), /not valid/i);
	assert.deepEqual(badId.wpDie, []);
});

test('probe list_folder sends supportsAllDrives', () => {
	const data = runHarness({ op: 'list_params' });
	assert.equal(data.missing, undefined, 'expected shipped probe store list_folder');
	assert.equal(data.hasSupportsAllDrives, true, JSON.stringify(data));
	assert.equal(data.supportsAllDrives, true);
});
