/**
 * Unit tests for Portal_Staged_File (retained name + stage/read/forget + pipeline).
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const harness = path.join(root, 'tests/support/php-staged-file.php');
const artifactDir = path.join(root, 'tests/.artifacts');
const scenarioDir = path.join(artifactDir, 'staged-scenarios');

/**
 * @param {Record<string, unknown>} scenario
 */
function runScenario(scenario) {
  fs.mkdirSync(scenarioDir, { recursive: true });
  const file = path.join(
    scenarioDir,
    `sc-${Date.now()}-${Math.random().toString(16).slice(2)}.json`,
  );
  fs.writeFileSync(file, JSON.stringify({ artifactDir, ...scenario }));
  const r = spawnSync('php', [harness, file], {
    encoding: 'utf8',
    env: { ...process.env, DG_TEST_MODE: '1' },
  });
  const out = (r.stdout || '') + (r.stderr || '');
  let data = null;
  try {
    const start = out.indexOf('{');
    data = start >= 0 ? JSON.parse(out.slice(start)) : null;
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

const retainedCases = [
  { name: 'suffix before extension', original: 'blank.pdf', suffix: '_BIO', want: 'blank_BIO.pdf' },
  { name: 'already suffixed', original: 'blank_BIO.pdf', suffix: '_BIO', want: 'blank_BIO.pdf' },
  { name: 'score suffix', original: 'sample-score.pdf', suffix: '_SCORE', want: 'sample-score_SCORE.pdf' },
  {
    name: 'sanitize unsafe chars',
    original: 'my score (final).pdf',
    suffix: '_SCORE',
    // Unsafe runs collapse to a single _ (Drive sanitize class).
    want: 'my_score_final__SCORE.pdf',
  },
  { name: 'empty suffix keeps sanitized base', original: 'blank.pdf', suffix: '', want: 'blank.pdf' },
  {
    name: 'no double when stem ends with suffix',
    original: 'piece_SCORE.pdf',
    suffix: '_SCORE',
    want: 'piece_SCORE.pdf',
  },
];

/** Completed create envelope + raw PDF download (matches Portal_Anonymizer_Reply). */
function anonScript(downloadBytes) {
  return [
    {
      status: 200,
      body: JSON.stringify({
        object: 'anonymization',
        data: {
          id: 'an_stage_test',
          status: 'completed',
          filename: 'score.pdf',
          file_type: 'application/pdf',
          bytes: downloadBytes.length,
        },
      }),
    },
    { status: 200, body: downloadBytes },
  ];
}

for (const tc of retainedCases) {
  test(`retained_name: ${tc.name}`, () => {
    const { code, out, data } = runScenario({
      entry: 'retained_name',
      original: tc.original,
      suffix: tc.suffix,
    });
    assert.equal(code, 0, out);
    assert.ok(data, out);
    assert.equal(data.ok, true, out);
    assert.equal(data.retainedName, tc.want, out);
  });
}

test('stage without anonymize: storedName uses suffix, bytes unchanged', () => {
  const original = '%PDF-1.4\n%stage-no-anon\n';
  const { code, out, data } = runScenario({
    entry: 'stage',
    portalId: 'stage-no-anon',
    fieldId: 'score',
    originalName: 'blank.pdf',
    fileSuffix: '_SCORE',
    buffer: original,
    options: { anonymize: false },
  });
  assert.equal(code, 0, out);
  assert.ok(data, out);
  assert.equal(data.ok, true, out);
  assert.equal(data.storedName, 'blank_SCORE.pdf');
  assert.equal(data.originalName, 'blank.pdf');
  assert.equal(data.anonymized, false);
  assert.equal(data.storedEqualsOrig, true);
  assert.ok(typeof data.token === 'string' && data.token.length >= 32, `token=${data.token}`);
  assert.match(data.token, /^[a-f0-9]+$/i);
  assert.equal(data.callCount, 0);
});

test('stage with anonymize + fake transport: different bytes, anonymized true', () => {
  const original = '%PDF-1.4\n%original-identifying\n';
  const download = '%PDF-1.4\n%stripped-identity\n';
  const { code, out, data } = runScenario({
    entry: 'stage',
    portalId: 'stage-anon',
    fieldId: 'score',
    originalName: 'blank.pdf',
    fileSuffix: '_BIO',
    buffer: original,
    options: {
      anonymize: true,
      anonymizeEndpoint: 'https://anon.test',
      anonymizeApiKey: 'test-key-not-real',
    },
    script: anonScript(download),
  });
  assert.equal(code, 0, out);
  assert.ok(data, out);
  assert.equal(data.ok, true, out);
  assert.equal(data.storedName, 'blank_BIO.pdf');
  assert.equal(data.anonymized, true);
  assert.equal(data.storedEqualsOrig, false);
  assert.ok(data.storedBytesLen > 0);
  assert.ok(data.callCount >= 1, `expected anonymizer calls, got ${data.callCount}`);
});

test('stage anonymize on + empty key does not store original', () => {
  const original = '%PDF-1.4\n%identifying-empty-key\n';
  const { code, out, data } = runScenario({
    entry: 'stage',
    portalId: 'stage-empty-key',
    fieldId: 'score',
    originalName: 'blank.pdf',
    fileSuffix: '_SCORE',
    buffer: original,
    options: {
      anonymize: true,
      anonymizeEndpoint: null,
      anonymizeApiKey: '',
    },
  });
  assert.ok(data, out);
  assert.equal(data.ok, false, `empty key must block stage: ${out}`);
  assert.match(String(data.code || ''), /invalid|anonymize|config|staged/i);
  assert.notEqual(data.storedEqualsOrig, true);
  assert.equal(data.callCount || 0, 0);
  assert.ok(code !== 0 || data.ok === false);
});

test('stage anonymizeFailClosed + API error does not store original', () => {
  const original = '%PDF-1.4\n%identifying-fail-closed\n';
  const { code, out, data } = runScenario({
    entry: 'stage',
    portalId: 'stage-fail-closed',
    fieldId: 'score',
    originalName: 'blank.pdf',
    fileSuffix: '_SCORE',
    buffer: original,
    options: {
      anonymize: true,
      anonymizeEndpoint: 'https://anon.test',
      anonymizeApiKey: 'test-key-not-real',
      anonymizeFailClosed: true,
    },
    script: [{ throw: 'timed out after 120s' }],
  });
  assert.ok(data, out);
  assert.equal(data.ok, false, `fail-closed must block stage: ${out}`);
  assert.notEqual(data.storedEqualsOrig, true);
  assert.ok(code !== 0 || data.ok === false);
});

test('pipeline uses staged token; drive has staged bytes; no second anonymize', () => {
  const original = '%PDF-1.4\n%pipe-original\n';
  const download = '%PDF-1.4\n%pipe-anonymized\n';
  const { code, out, data } = runScenario({
    entry: 'pipeline',
    portalId: 'stage-pipe-anon',
    fieldId: 'score',
    originalName: 'blank.pdf',
    fileSuffix: '_SCORE',
    buffer: original,
    anonBytes: download,
    anonymize: true,
  });
  assert.equal(code, 0, out);
  assert.ok(data, out);
  assert.equal(data.ok, true, out);
  assert.equal(data.submitOk, true);
  assert.equal(data.storedName, 'blank_SCORE.pdf');
  assert.equal(data.anonymized, true);
  assert.equal(data.driveEqualsStaged, true, out);
  assert.equal(data.driveEqualsOrig, false);
  assert.equal(data.extraAnonCalls, 0, `second anonymize fired: ${JSON.stringify(data)}`);
  assert.ok(data.drivePath, 'drive path present');
  assert.ok(fs.existsSync(data.drivePath), `drive file missing: ${data.drivePath}`);
});

test('pipeline without anonymize stages suffix and writes original bytes', () => {
  const original = '%PDF-1.4\n%pipe-plain\n';
  const { code, out, data } = runScenario({
    entry: 'pipeline',
    portalId: 'stage-pipe-plain',
    fieldId: 'score',
    originalName: 'blank.pdf',
    fileSuffix: '_SCORE',
    buffer: original,
    anonymize: false,
  });
  assert.equal(code, 0, out);
  assert.ok(data, out);
  assert.equal(data.ok, true, out);
  assert.equal(data.storedName, 'blank_SCORE.pdf');
  assert.equal(data.anonymized, false);
  assert.equal(data.driveEqualsStaged, true);
  assert.equal(data.extraAnonCalls, 0);
});

test('forget removes staged token', () => {
  const original = '%PDF-1.4\n%to-forget\n';
  const staged = runScenario({
    entry: 'stage',
    portalId: 'stage-forget',
    fieldId: 'score',
    originalName: 'blank.pdf',
    buffer: original,
    options: { anonymize: false },
  });
  assert.equal(staged.code, 0, staged.out);
  const token = staged.data.token;
  const forgotten = runScenario({ entry: 'forget', token });
  assert.equal(forgotten.code, 0, forgotten.out);
  assert.equal(forgotten.data.gone, true);
});

test('GET staged file serves raw bytes (not JSON) via rest_pre_serve_request', () => {
  const original = '%PDF-1.4\n%open-to-confirm-bytes\n';
  const { code, out, data } = runScenario({
    entry: 'serve',
    portalId: 'stage-serve-raw',
    fieldId: 'score',
    originalName: 'blank.pdf',
    buffer: original,
  });
  assert.equal(code, 0, out);
  assert.ok(data, out);
  assert.equal(data.ok, true, out);
  assert.equal(data.serveReturnedTrue, true, 'serve_raw_request must claim the response');
  assert.equal(data.bodyEqualsStaged, true, 'echoed body must equal staged PDF bytes');
  assert.equal(data.bodyIsJsonString, false, 'body must not be JSON-encoded');
  assert.equal(data.bodyStartsPdf, true);
  assert.equal(data.contentType, 'application/pdf');
  assert.equal(data.storedName, 'blank_SCORE.pdf');
});
