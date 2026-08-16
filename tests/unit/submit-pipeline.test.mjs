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
const APPLICANT_EMAIL = 'applicant@example.com';
const PORTAL_TITLE = 'E2E Herbolzheimer Prize';

/**
 * @param {string} dir
 * @param {string} id
 * @returns {Array<Record<string, unknown> & { filePath: string }>}
 */
function loadMailCaptures(dir, id) {
  const mailDir = path.join(dir, 'mail');
  if (!fs.existsSync(mailDir)) {
    return [];
  }
  return fs
    .readdirSync(mailDir)
    .filter((name) => name.endsWith('.json'))
    .map((name) => {
      const filePath = path.join(mailDir, name);
      const payload = JSON.parse(fs.readFileSync(filePath, 'utf8'));
      return { filePath, ...payload };
    })
    .filter((mail) => mail.portalId === id);
}

/**
 * @param {Array<Record<string, unknown>>} mails
 */
function findApplicantMail(mails) {
  return mails.find(
    (mail) => mail.to === APPLICANT_EMAIL && mail.kind !== 'operator',
  );
}

/**
 * @param {Array<Record<string, unknown>>} mails
 */
function findOperatorMail(mails) {
  return mails.find(
    (mail) => mail.kind === 'operator' || (mail.to && mail.to !== APPLICANT_EMAIL),
  );
}

/**
 * @param {string} value
 */
function escapeRegExp(value) {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * Operator capture must carry title, application id, applicant email, receipt URL.
 *
 * @param {Record<string, unknown>} operatorMail
 * @param {Record<string, unknown>} data
 */
function assertOperatorNotify(operatorMail, data) {
  const row = data.row && typeof data.row === 'object' ? data.row : {};
  const appId = String(row.applicationId || row.application_id || '');
  const receiptUrl = String(data.receipt_url || row.receiptUrl || '');
  assert.ok(appId, 'application id');
  assert.ok(receiptUrl, 'receipt url');
  const haystack = [
    operatorMail.subject,
    operatorMail.body,
    JSON.stringify(operatorMail.tokens || {}),
  ].join('\n');
  assert.match(haystack, new RegExp(escapeRegExp(PORTAL_TITLE)));
  assert.match(haystack, new RegExp(escapeRegExp(appId)));
  assert.match(haystack, new RegExp(escapeRegExp(APPLICANT_EMAIL)));
  assert.match(haystack, new RegExp(escapeRegExp(receiptUrl)));
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

  // Mail captures: applicant receipt + operator notify
  assert.ok(data.mailPath, 'mail path');
  assert.ok(fs.existsSync(data.mailPath));
  const mails = loadMailCaptures(artifactDir, portalId);
  assert.equal(mails.length, 2, `expected applicant + operator captures, got ${mails.length}`);
  const applicantMail = findApplicantMail(mails);
  const operatorMail = findOperatorMail(mails);
  assert.ok(applicantMail, 'applicant mail capture');
  assert.equal(applicantMail.to, 'applicant@example.com');
  assert.match(String(applicantMail.subject || ''), /Application receipt/i);
  assert.equal(applicantMail.portalId, portalId);
  assert.ok(operatorMail, 'operator mail capture');
  assert.notEqual(operatorMail.to, applicantMail.to);
  // CLI / test-mode fallback when WP options are absent.
  assert.equal(operatorMail.to, 'operator@example.com');
  assert.equal(operatorMail.kind, 'operator');
  assertOperatorNotify(operatorMail, data);
});

test('operator log dest for anonymize is skip when anonymize is off', () => {
  const portal = 'herbolzheimer-anon-skip';
  const r = spawnSync(
    'php',
    [harness, definition, submission, artifactDir, portal],
    { encoding: 'utf8', env: { ...process.env, DG_TEST_MODE: '1' } },
  );
  const out = (r.stdout || '') + (r.stderr || '');
  assert.equal(r.status, 0, out);
  const data = JSON.parse((r.stdout || '').trim());
  assert.equal(data.ok, true);
  const rows = Array.isArray(data.logRows) ? data.logRows : [];
  assert.ok(rows.length > 0, `expected operator log rows: ${out}`);
  const dests = rows[rows.length - 1].dests || {};
  assert.equal(dests.anonymize, 'skip');
});

test('operator notify uses admin_email when notify setting is empty', () => {
  const portal = 'herbolzheimer-admin-email';
  const r = spawnSync(
    'php',
    [
      harness,
      definition,
      submission,
      artifactDir,
      portal,
      '--admin-email=host@isjac.org',
    ],
    { encoding: 'utf8', env: { ...process.env, DG_TEST_MODE: '1' } },
  );
  assert.equal(r.status, 0, (r.stdout || '') + (r.stderr || ''));
  const data = JSON.parse((r.stdout || '').trim());
  assert.equal(data.ok, true);
  const operatorMail = findOperatorMail(loadMailCaptures(artifactDir, portal));
  assert.ok(operatorMail, 'operator mail capture');
  assert.equal(operatorMail.to, 'host@isjac.org');
  assertOperatorNotify(operatorMail, data);
});

test('mailer send throw still writes dests and returns ok', () => {
  const portal = 'herbolzheimer-mail-throw';
  const r = spawnSync(
    'php',
    [
      harness,
      definition,
      submission,
      artifactDir,
      portal,
      '--mail-fail=throw',
    ],
    { encoding: 'utf8', env: { ...process.env, DG_TEST_MODE: '1' } },
  );
  assert.equal(r.status, 0, (r.stdout || '') + (r.stderr || ''));
  const data = JSON.parse((r.stdout || '').trim());
  assert.equal(data.ok, true);
  assert.ok(data.sheetPath && fs.existsSync(data.sheetPath), 'sheet written');
  assert.ok(data.drivePaths?.score && fs.existsSync(data.drivePaths.score), 'drive written');
});

test('mailer send false still writes dests and returns ok', () => {
  const portal = 'herbolzheimer-mail-false';
  const r = spawnSync(
    'php',
    [
      harness,
      definition,
      submission,
      artifactDir,
      portal,
      '--mail-fail=false',
    ],
    { encoding: 'utf8', env: { ...process.env, DG_TEST_MODE: '1' } },
  );
  assert.equal(r.status, 0, (r.stdout || '') + (r.stderr || ''));
  const data = JSON.parse((r.stdout || '').trim());
  assert.equal(data.ok, true);
  assert.ok(data.sheetPath && fs.existsSync(data.sheetPath), 'sheet written');
  assert.ok(data.drivePaths?.score && fs.existsSync(data.drivePaths.score), 'drive written');
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
 * @param {string} [definitionPath]
 */
function runLivePipeline(extraArgs = [], envOverride = {}, definitionPath = definition) {
  const env = { ...process.env, ...envOverride };
  delete env.DG_TEST_MODE;
  env.DG_ARTIFACT_DIR = liveArtifactDir;
  const r = spawnSync(
    'php',
    [
      harness,
      definitionPath,
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

const BUILTIN_ANONYMIZE_ACK =
  'I certify that my scores and recordings exclude any information that might identify the composer but do include title of work, instrumentation, and duration.';

/**
 * Minimal definition + values for anonymize_ack validation cases.
 * @param {boolean} anonymize
 * @param {Record<string, string>} [extraValues]
 */
function runAnonAckPipeline(anonymize, extraValues = {}) {
  const defPath = path.join(artifactDir, `anon-ack-def-${anonymize ? 'on' : 'off'}.json`);
  const subPath = path.join(artifactDir, `anon-ack-sub-${Date.now()}-${Math.random().toString(16).slice(2)}.json`);
  fs.mkdirSync(artifactDir, { recursive: true });
  fs.writeFileSync(
    defPath,
    JSON.stringify({
      version: 1,
      fields: [{ id: 'piece', type: 'short_text', label: 'Piece', required: true }],
      options: {
        anonymize,
        anonymizeApiKey: anonymize ? 'anon_ack_harness_key' : '',
      },
      publish: { enabled: true },
    }),
  );
  fs.writeFileSync(
    subPath,
    JSON.stringify({
      portalId: `anon-ack-${anonymize ? 'on' : 'off'}`,
      values: { sub_piece: 'Test Piece', ...extraValues },
      files: {},
    }),
  );
  const r = spawnSync(
    'php',
    [harness, defPath, subPath, artifactDir, `anon-ack-${anonymize ? 'on' : 'off'}`],
    { encoding: 'utf8', env: { ...process.env, DG_TEST_MODE: '1' } },
  );
  return {
    code: r.status,
    out: (r.stdout || '') + (r.stderr || ''),
    stdout: r.stdout || '',
    stderr: r.stderr || '',
  };
}

test('anonymize on without ack fails validation on anonymize_ack', () => {
  const { code, out } = runAnonAckPipeline(true);
  assert.notEqual(code, 0, out);
  assert.match(out, /dg_submission_invalid|must be accepted/i);
  assert.match(out, /anonymize_ack|I certify that my scores and recordings/i);
  // Prefer full label in the error when present.
  if (out.includes('must be accepted')) {
    assert.match(out, new RegExp(BUILTIN_ANONYMIZE_ACK.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
  }
});

test('anonymize on with sub_anonymize_ack checked passes validation', () => {
  const { code, out, stdout } = runAnonAckPipeline(true, { sub_anonymize_ack: '1' });
  assert.equal(code, 0, out);
  const data = JSON.parse(stdout.trim());
  assert.equal(data.ok, true);
});

test('anonymize off without ack is still valid', () => {
  const { code, out, stdout } = runAnonAckPipeline(false);
  assert.equal(code, 0, out);
  const data = JSON.parse(stdout.trim());
  assert.equal(data.ok, true);
});

const PRODUCTION_SHEET_ID = 'sheet_hk_fixture_not_prod';
const PRODUCTION_FOLDER_ID = 'drive_sub_fixture_not_prod';

/**
 * Live Google path with per-portal testMode on and a production mapping id.
 * DG_TEST_MODE stays unset so file adapters are not selected.
 */
function runTestModeLivePipeline() {
  const defPath = path.join(liveArtifactDir, 'zys-635-test-mode.definition.json');
  const raw = JSON.parse(fs.readFileSync(definition, 'utf8'));
  raw.publish = { ...(raw.publish || {}), enabled: true, testMode: true };
  fs.mkdirSync(liveArtifactDir, { recursive: true });
  fs.writeFileSync(defPath, JSON.stringify(raw));
  return runLivePipeline([], {}, defPath);
}

test('testMode does not write the mapped production spreadsheet id', () => {
  const { code, out } = runTestModeLivePipeline();
  assert.equal(code, 0, out);
  const data = parseHarnessJson(out);
  assert.equal(data.ok, true);
  const store = data.googleStore;
  assert.ok(store, 'fake google store record');
  const ids = store.spreadsheetIds || [];
  assert.ok(
    !ids.includes(PRODUCTION_SHEET_ID),
    `testMode must not write production spreadsheetId, got ${JSON.stringify(ids)}`,
  );
  const folders = store.driveFolders || [];
  assert.ok(
    !folders.includes(PRODUCTION_FOLDER_ID),
    `testMode must not write production folderId, got ${JSON.stringify(folders)}`,
  );
  const blob = JSON.stringify(store);
  assert.ok(
    !blob.includes(PRODUCTION_SHEET_ID),
    `production spreadsheetId leaked into store: ${blob}`,
  );
  assert.ok(
    !blob.includes(PRODUCTION_FOLDER_ID),
    `production folderId leaked into store: ${blob}`,
  );
  const rows = Array.isArray(data.logRows) ? data.logRows : [];
  const marked =
    data.test === true ||
    rows.some((row) => row && (row.test === true || row.mode === 'test'));
  assert.ok(
    marked,
    `operator log should mark the submit as test, got ${JSON.stringify({
      test: data.test,
      logRows: rows,
    })}`,
  );
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
