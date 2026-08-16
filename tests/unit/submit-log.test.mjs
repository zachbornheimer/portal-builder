/**
 * ZYS-617: each definition submit writes an operator log row.
 * Drives the shipped pipeline through php-submit-pipeline.php.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const harness = path.join(root, 'tests/support/php-submit-pipeline.php');
const definition = path.join(
  root,
  'tests/fixtures/portals/herbolzheimer.definition.json',
);
const submission = path.join(
  root,
  'tests/fixtures/portals/herbolzheimer.submission.json',
);
const artifactDir = path.join(root, 'tests/.artifacts/submit-log');
const portalId = 'zys-617-submit-log';
const DEST_KEYS = ['sheet', 'drive', 'anonymize', 'mail'];

/**
 * Prefer the pretty-printed harness object, not a `{` inside error_log.
 *
 * @param {string} text Combined stdout/stderr.
 * @returns {Record<string, unknown>|null}
 */
function parseHarnessPayload(text) {
  const lines = text.split('\n');
  const start = lines.findIndex((line) => line.trim() === '{');
  const raw = start === -1 ? text.slice(text.lastIndexOf('{')) : lines.slice(start).join('\n');
  if (!raw || raw.indexOf('{') === -1) {
    return null;
  }
  return JSON.parse(raw);
}

/**
 * @param {string[]} extraArgs
 */
function runPipeline(extraArgs = []) {
  const r = spawnSync(
    'php',
    [harness, definition, submission, artifactDir, portalId, ...extraArgs],
    { encoding: 'utf8', env: { ...process.env, DG_TEST_MODE: '1' } },
  );
  const out = (r.stdout || '') + (r.stderr || '');
  let data = null;
  try {
    data = parseHarnessPayload(out);
  } catch {
    data = null;
  }
  return {
    code: r.status,
    out,
    stdout: r.stdout || '',
    stderr: r.stderr || '',
    data,
  };
}

/**
 * Operator rows the shipped pipeline wrote (file first, harness view second).
 *
 * @param {ReturnType<typeof runPipeline>} run
 * @returns {Array<Record<string, unknown>>}
 */
function operatorRows(run) {
  const fromHarness = Array.isArray(run.data?.logRows) ? run.data.logRows : [];
  if (fromHarness.length > 0) {
    return fromHarness;
  }
  const logPath =
    (run.data && typeof run.data.logPath === 'string' && run.data.logPath) ||
    path.join(artifactDir, 'dg-logs', `${portalId}.jsonl`);
  if (!fs.existsSync(logPath)) {
    return [];
  }
  return fs
    .readFileSync(logPath, 'utf8')
    .trim()
    .split('\n')
    .filter(Boolean)
    .map((line) => JSON.parse(line));
}

/**
 * @param {Record<string, unknown>} row
 */
function assertNoFileBytes(row) {
  const blob = JSON.stringify(row);
  assert.doesNotMatch(blob, /%PDF/);
  assert.doesNotMatch(blob, /ID3/);
  assert.doesNotMatch(blob, /"contents"/);
  assert.doesNotMatch(blob, /"buffer"/);
  assert.doesNotMatch(blob, /"fileBytes"/);
  assert.ok(blob.length < 8000, `log row looks like it stored file bytes (${blob.length})`);
}

/**
 * @param {Record<string, unknown>} row
 */
function assertDestKeys(row) {
  const dests = row.dests;
  assert.equal(typeof dests, 'object', 'dests object');
  assert.ok(dests, 'dests present');
  for (const key of DEST_KEYS) {
    const value = dests[key];
    assert.equal(typeof value, 'string', `dests.${key}`);
    assert.notEqual(value, '', `dests.${key} omitted`);
  }
}

/**
 * @param {unknown} value
 */
function hasAppId(value) {
  if (!value || typeof value !== 'object') {
    return false;
  }
  const row = /** @type {Record<string, unknown>} */ (value);
  const id = row.applicationId || row.application_id || row.appId;
  return typeof id === 'string' && id.length > 0;
}

test('operator log: happy-path ok plus forced Drive failure row', () => {
  const happy = runPipeline();
  assert.equal(happy.code, 0, happy.out);
  assert.ok(happy.data && happy.data.ok === true, happy.out);

  const afterHappy = operatorRows(happy);
  assert.ok(
    afterHappy.some(hasAppId),
    'after a definition submit there is no operator log row (app id + dest results)',
  );
  const okRow = afterHappy.find((row) => String(row.errorCode || '') === 'ok');
  assert.ok(okRow, `happy-path row errorCode=ok, got ${JSON.stringify(afterHappy)}`);
  assertDestKeys(okRow);
  assert.equal(okRow.dests.sheet, 'ok');
  assert.equal(okRow.dests.drive, 'ok');
  assert.equal(okRow.dests.mail, 'ok');
  assert.match(String(okRow.dests.anonymize), /^(skip|warning|ok)$/);
  assert.ok(okRow.time, 'time');
  assertNoFileBytes(okRow);

  const failed = runPipeline(['--append', '--drive-fail']);
  const failRows = operatorRows(failed);
  const driveRow = failRows.find((row) => {
    const dests = row.dests && typeof row.dests === 'object' ? row.dests : {};
    return dests.drive === 'fail';
  });
  assert.ok(driveRow, `drive-fail row missing: ${JSON.stringify(failRows)}`);
  assertDestKeys(driveRow);
  assert.match(String(driveRow.errorCode || ''), /drive/i);
  assert.notEqual(String(driveRow.errorCode), 'ok');
  assertNoFileBytes(driveRow);

  const errors = Array.isArray(failed.data?.lastErrors) ? failed.data.lastErrors : [];
  const publicHtml =
    typeof failed.data?.publicHtml === 'string' ? failed.data.publicHtml : '';
  assert.equal(errors.length, 1, `expected one public error, got ${JSON.stringify(errors)}`);
  const sentence = String(errors[0]?.message || '');
  assert.match(sentence, /try again/i);
  assert.match(sentence, /contact/i);
  assert.doesNotMatch(sentence, /\{"error"/);
  assert.doesNotMatch(sentence, /invalid_grant/i);
  assert.doesNotMatch(publicHtml, /\{"error"/);
  assert.doesNotMatch(publicHtml, /invalid_grant/i);
  assert.doesNotMatch(publicHtml, /error_description/);
  const liCount = (publicHtml.match(/<li\b/g) || []).length;
  assert.equal(liCount, 1, `expected one public <li>, got ${publicHtml}`);

  const adminRows = Array.isArray(failed.data?.adminRows)
    ? failed.data.adminRows
    : failRows;
  const adminCodes = adminRows.map((row) => String(row.errorCode || ''));
  assert.ok(adminCodes.includes('ok'), `admin last-N missing ok: ${JSON.stringify(adminRows)}`);
  assert.ok(
    adminRows.some((row) => row.dests && row.dests.drive === 'fail'),
    `admin last-N missing drive fail: ${JSON.stringify(adminRows)}`,
  );
  for (const row of adminRows) {
    assertNoFileBytes(row);
  }
  const adminHtml = typeof failed.data?.adminHtml === 'string' ? failed.data.adminHtml : '';
  assert.ok(adminHtml.includes('data-dg-submit-log'), `admin html missing: ${adminHtml}`);
});
