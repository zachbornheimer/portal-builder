/**
 * Unit tests for definition-aware submit pipeline (PHP CLI harness).
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
const expectedRow = JSON.parse(
  fs.readFileSync(
    path.join(root, 'tests/fixtures/expected/herbolzheimer.sheet-row.json'),
    'utf8',
  ),
);
const artifactDir = path.join(root, 'tests/.artifacts');
const portalId = 'herbolzheimer';

/**
 * @param {string[]} extraArgs
 */
function runPipeline(extraArgs = []) {
  const r = spawnSync(
    'php',
    [harness, definition, submission, artifactDir, portalId, ...extraArgs],
    { encoding: 'utf8', env: { ...process.env, DG_TEST_MODE: '1' } },
  );
  return {
    code: r.status,
    out: (r.stdout || '') + (r.stderr || ''),
    stdout: r.stdout || '',
    stderr: r.stderr || '',
  };
}

test('herbolzheimer submission writes sheet, drive, and mail artifacts', () => {
  const { code, out, stdout } = runPipeline();
  assert.equal(code, 0, out);
  const data = JSON.parse(stdout.trim());
  assert.equal(data.ok, true);
  assert.equal(data.status, 'synced');
  assert.equal(data.portalId, portalId);

  // Sheet JSONL
  assert.ok(fs.existsSync(data.sheetPath), 'sheet path exists');
  const lines = fs
    .readFileSync(data.sheetPath, 'utf8')
    .trim()
    .split('\n')
    .filter(Boolean);
  assert.equal(lines.length, 1);
  const row = JSON.parse(lines[0]);
  for (const [key, want] of Object.entries(expectedRow)) {
    assert.equal(row[key], want, `row.${key}`);
  }
  assert.ok(row.score_path, 'score_path on row');
  assert.ok(row.recording_path, 'recording_path on row');

  // Drive files
  assert.ok(data.drivePaths?.score, 'score drive path');
  assert.ok(data.drivePaths?.recording, 'recording drive path');
  assert.ok(fs.existsSync(data.drivePaths.score));
  assert.ok(fs.existsSync(data.drivePaths.recording));
  assert.match(data.drivePaths.score, /score-sample-score\.pdf$/);
  assert.match(data.drivePaths.recording, /recording-sample-recording\.mp3$/);

  // Mail capture
  assert.ok(data.mailPath, 'mail path');
  assert.ok(fs.existsSync(data.mailPath));
  const mail = JSON.parse(fs.readFileSync(data.mailPath, 'utf8'));
  assert.equal(mail.to, 'applicant@example.com');
  assert.match(mail.subject, /Application receipt/i);
  assert.equal(mail.portalId, portalId);
});

test('missing required field fails validation without artifacts overwrite of good data', () => {
  const badSub = path.join(root, 'tests/.artifacts/bad-submission.json');
  fs.mkdirSync(path.dirname(badSub), { recursive: true });
  const good = JSON.parse(fs.readFileSync(submission, 'utf8'));
  delete good.values.sub_work_title;
  delete good.values.sub_name;
  good.portalId = 'herbolzheimer-bad';
  fs.writeFileSync(badSub, JSON.stringify(good));

  const r = spawnSync(
    'php',
    [harness, definition, badSub, artifactDir, 'herbolzheimer-bad'],
    { encoding: 'utf8', env: { ...process.env, DG_TEST_MODE: '1' } },
  );
  assert.notEqual(r.status, 0, r.stdout + r.stderr);
  const errText = (r.stdout || '') + (r.stderr || '');
  assert.match(errText, /dg_submission_invalid|required/i);

  // No sheet artifact for the bad portal.
  const badSheet = path.join(artifactDir, 'sheets', 'herbolzheimer-bad.jsonl');
  assert.equal(fs.existsSync(badSheet), false);
});

test('bad MIME on score_file is rejected (no sheet row)', () => {
  const badMime = path.join(root, 'tests/.artifacts/bad-mime-submission.json');
  const txtPath = path.join(root, 'tests/.artifacts/not-a-score.txt');
  fs.mkdirSync(path.dirname(badMime), { recursive: true });
  fs.writeFileSync(txtPath, 'this is not a pdf\n');
  const good = JSON.parse(fs.readFileSync(submission, 'utf8'));
  good.portalId = 'herbolzheimer-mime';
  good.files = {
    ...good.files,
    score: { name: 'not-a-score.txt', path: txtPath },
  };
  fs.writeFileSync(badMime, JSON.stringify(good));

  const r = spawnSync(
    'php',
    [harness, definition, badMime, artifactDir, 'herbolzheimer-mime'],
    { encoding: 'utf8', env: { ...process.env, DG_TEST_MODE: '1' } },
  );
  assert.notEqual(r.status, 0, r.stdout + r.stderr);
  const errText = (r.stdout || '') + (r.stderr || '');
  assert.match(errText, /dg_submission_invalid|PDF|valid/i);

  const sheet = path.join(artifactDir, 'sheets', 'herbolzheimer-mime.jsonl');
  assert.equal(fs.existsSync(sheet), false);
});

test('two sequential submits append two sheet rows (idempotent enough)', () => {
  const portal = 'herbolzheimer-twice';
  // First run clears via harness; second must append.
  const first = spawnSync(
    'php',
    [harness, definition, submission, artifactDir, portal],
    { encoding: 'utf8', env: { ...process.env, DG_TEST_MODE: '1' } },
  );
  assert.equal(first.status, 0, first.stdout + first.stderr);

  // Second run without clear: call pipeline append only via a second process
  // that does not clear — harness always clears, so append by reusing sheets API
  // through a second harness run would wipe. Use a dedicated double-run harness flag.
  const second = spawnSync(
    'php',
    [harness, definition, submission, artifactDir, portal, '--append'],
    { encoding: 'utf8', env: { ...process.env, DG_TEST_MODE: '1' } },
  );
  assert.equal(second.status, 0, second.stdout + second.stderr);

  const sheet = path.join(artifactDir, 'sheets', `${portal}.jsonl`);
  assert.ok(fs.existsSync(sheet));
  const lines = fs.readFileSync(sheet, 'utf8').trim().split('\n').filter(Boolean);
  assert.equal(lines.length, 2, `expected 2 rows, got ${lines.length}`);
  for (const line of lines) {
    const row = JSON.parse(line);
    assert.equal(row.work_title, expectedRow.work_title);
    assert.equal(row.sub_email, expectedRow.sub_email);
  }
});

const liveMapping = path.join(
  root,
  'tests/fixtures/portals/herbolzheimer.live-mapping.json',
);
const multiDestMapping = path.join(
  root,
  'tests/fixtures/portals/herbolzheimer.multi-dest-mapping.json',
);
const liveArtifactDir = path.join(root, 'tests/.artifacts/live-adapters');

/**
 * @param {string[]} extraArgs
 * @param {NodeJS.ProcessEnv} [envOverride]
 */
function runLivePipeline(extraArgs = [], envOverride = {}) {
  const env = { ...process.env, ...envOverride };
  delete env.DG_TEST_MODE;
  env.DG_ARTIFACT_DIR = liveArtifactDir;
  const r = spawnSync(
    'php',
    [
      harness,
      definition,
      submission,
      liveArtifactDir,
      '42',
      '--via-for-post',
      '--live-google',
      `--mapping=${liveMapping}`,
      ...extraArgs,
    ],
    { encoding: 'utf8', env },
  );
  return {
    code: r.status,
    out: (r.stdout || '') + (r.stderr || ''),
    stdout: r.stdout || '',
    stderr: r.stderr || '',
  };
}

function parseHarnessJson(text) {
  const start = text.indexOf('{');
  assert.notEqual(start, -1, `no JSON in: ${text}`);
  return JSON.parse(text.slice(start));
}

test('without DG_TEST_MODE a mapped herbolzheimer submit writes google sheet and drive', () => {
  const { code, out } = runLivePipeline();
  assert.equal(code, 0, out);
  const data = parseHarnessJson(out);
  assert.equal(data.ok, true);
  const store = data.googleStore;
  assert.ok(store, 'fake google store record');
  assert.ok(
    store.spreadsheetIds.includes('sheet_hk_fixture_not_prod'),
    `gsheet ids: ${JSON.stringify(store.spreadsheetIds)}`,
  );
  const titleCells = (store.cells || []).flat();
  assert.ok(
    titleCells.includes('Symphony No. 1'),
    `cells should include work title, got ${JSON.stringify(store.cells)}`,
  );
  assert.ok(
    (store.driveFiles || []).length >= 1,
    `expected store_drive_file for score, got ${JSON.stringify(store.driveFiles)}`,
  );
  const scoreLink = data.drivePaths?.score || '';
  assert.match(
    String(scoreLink),
    /^https:\/\/drive\.google\.com\/file\/d\//,
    `score cell/path should be a Drive URL, got ${scoreLink}`,
  );
});

test('closed portal does not write to the fake google store', () => {
  const { code, out } = runLivePipeline(['--open-state=closed']);
  assert.notEqual(code, 0, out);
  const data = parseHarnessJson(out);
  assert.equal(data.ok, false);
  assert.match(String(data.code || data.message || out), /closed|preview/i);
  const calls = data.googleStore?.calls || [];
  assert.equal(calls.length, 0, `expected zero store calls, got ${JSON.stringify(calls)}`);
});

test('preview request does not write to the fake google store', () => {
  const { code, out } = runLivePipeline(['--open-state=preview']);
  assert.notEqual(code, 0, out);
  const data = parseHarnessJson(out);
  assert.equal(data.ok, false);
  assert.match(String(data.code || data.message || out), /preview/i);
  const calls = data.googleStore?.calls || [];
  assert.equal(calls.length, 0, `expected zero store calls, got ${JSON.stringify(calls)}`);
});

test('multi-dest fieldDest writes two spreadsheet ids', () => {
  const env = { ...process.env };
  delete env.DG_TEST_MODE;
  env.DG_ARTIFACT_DIR = liveArtifactDir;
  const r = spawnSync(
    'php',
    [
      harness,
      definition,
      submission,
      liveArtifactDir,
      '42',
      '--via-for-post',
      '--live-google',
      `--mapping=${multiDestMapping}`,
      '--open-state=open',
    ],
    { encoding: 'utf8', env },
  );
  assert.equal(r.status, 0, (r.stdout || '') + (r.stderr || ''));
  const data = parseHarnessJson((r.stdout || '') + (r.stderr || ''));
  const ids = data.googleStore?.spreadsheetIds || [];
  assert.ok(
    ids.includes('sheet_hk_fixture_not_prod'),
    `missing housekeeping id in ${JSON.stringify(ids)}`,
  );
  assert.ok(
    ids.includes('sheet_adj_fixture_not_prod'),
    `missing adjudicator id in ${JSON.stringify(ids)}`,
  );
  const unique = [...new Set(ids)];
  assert.equal(unique.length, 2, `expected two distinct spreadsheet ids, got ${JSON.stringify(ids)}`);
});
