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
