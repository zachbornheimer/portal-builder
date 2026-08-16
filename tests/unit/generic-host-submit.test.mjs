/**
 * Offline CI proof: generic starter submit → sheet adapter → receipt.
 *
 * Filmed host walks are extra; this file must stay host-agnostic.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { genericStarterTemplate } from '../../src/wizard/definitionModel.js';

const root = process.cwd();
const harness = path.join(root, 'tests/support/php-submit-pipeline.php');
const fixturesDir = path.join(root, 'tests/fixtures/portals');
const definitionPath = path.join(fixturesDir, 'generic-host.definition.json');
const submissionPath = path.join(fixturesDir, 'generic-host.submission.json');
const mappingPath = path.join(fixturesDir, 'generic-host.live-mapping.json');
const artifactDir = path.join(root, 'tests/.artifacts/generic-host');
const portalId = '42';

const GENERIC_SHEET_ID = 'sheet_generic_host_fixture_not_prod';
const GENERIC_TITLE = 'River Suite';
const APPLICANT_EMAIL = 'applicant@example.com';
const HOST_FORBIDDEN = /isjac\.org|zbornheimer@isjac\.org/i;

/**
 * @param {string} text
 */
function parseHarnessJson(text) {
  const start = text.indexOf('{');
  assert.notEqual(start, -1, `no JSON in: ${text}`);
  return JSON.parse(text.slice(start));
}

/**
 * @param {string} filePath
 */
function readUtf8(filePath) {
  assert.ok(fs.existsSync(filePath), `missing fixture: ${path.relative(root, filePath)}`);
  return fs.readFileSync(filePath, 'utf8');
}

test('generic starter submit writes fake google sheet row and a receipt', () => {
  const starter = genericStarterTemplate('Generic Host Portal');
  const definition = JSON.parse(readUtf8(definitionPath));
  const submission = JSON.parse(readUtf8(submissionPath));
  const mapping = JSON.parse(readUtf8(mappingPath));

  assert.equal(definition.fields.length, starter.fields.length);
  for (const [index, field] of starter.fields.entries()) {
    assert.equal(definition.fields[index].id, field.id, `field[${index}].id`);
    assert.equal(definition.fields[index].type, field.type, `field[${index}].type`);
  }
  const titleField = starter.fields.find((field) => field.type === 'short_text');
  const fileField = starter.fields.find((field) => String(field.type).includes('file'));
  assert.ok(titleField, 'starter title field');
  assert.ok(fileField, 'starter file field');
  const titleValue =
    submission.values[`sub_${titleField.id}`] ?? submission.values[titleField.id];
  assert.equal(titleValue, GENERIC_TITLE);
  assert.equal(submission.values.sub_email, APPLICANT_EMAIL);
  assert.equal(submission.files[fileField.id]?.name, 'sample-score.pdf');
  assert.equal(mapping.sheets[0].spreadsheetId, GENERIC_SHEET_ID);

  const env = { ...process.env };
  delete env.DG_TEST_MODE;
  env.DG_ARTIFACT_DIR = artifactDir;
  fs.mkdirSync(artifactDir, { recursive: true });

  const ran = spawnSync(
    'php',
    [
      harness,
      definitionPath,
      submissionPath,
      artifactDir,
      portalId,
      '--via-for-post',
      '--live-google',
      `--mapping=${mappingPath}`,
      '--open-state=open',
    ],
    { encoding: 'utf8', env },
  );
  const out = (ran.stdout || '') + (ran.stderr || '');
  assert.equal(ran.status, 0, out);

  const data = parseHarnessJson(out);
  assert.equal(data.ok, true);
  const store = data.googleStore;
  assert.ok(store, 'fake google store record');
  assert.ok(
    (store.spreadsheetIds || []).includes(GENERIC_SHEET_ID),
    `gsheet ids: ${JSON.stringify(store.spreadsheetIds)}`,
  );
  const cells = (store.cells || []).flat();
  assert.ok(
    cells.includes(GENERIC_TITLE),
    `sheet cells should include the title field, got ${JSON.stringify(store.cells)}`,
  );
  assert.ok(
    (store.driveFiles || []).length >= 1,
    `expected store_drive_file for the one file, got ${JSON.stringify(store.driveFiles)}`,
  );
  const receiptUrl = String(data.receipt_url || data.row?.receiptUrl || '');
  assert.match(receiptUrl, /dg-receipt|receipt/i, `receipt url: ${receiptUrl}`);
  assert.doesNotMatch(receiptUrl, HOST_FORBIDDEN);

  const proofText = [
    fs.readFileSync(new URL(import.meta.url), 'utf8'),
    readUtf8(definitionPath),
    readUtf8(submissionPath),
    readUtf8(mappingPath),
  ].join('\n');
  assert.doesNotMatch(proofText, HOST_FORBIDDEN);
});
