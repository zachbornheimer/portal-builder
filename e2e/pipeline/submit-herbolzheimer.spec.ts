/**
 * Pipeline e2e (offline-capable): definition submit → mock Sheet/Drive/Mail.
 * Uses the PHP CLI harness so LocalWP is not required for this phase foundation.
 */
import { test, expect } from '@playwright/test';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { loadEnv } from '../../tests/support/load-env.mjs';

const env = loadEnv();
const root = env.repoRoot;
const harness = path.join(root, 'tests/support/php-submit-pipeline.php');
const definition = path.join(
  root,
  'tests/fixtures/portals/herbolzheimer.definition.json',
);
const submission = path.join(
  root,
  'tests/fixtures/portals/herbolzheimer.submission.json',
);
const portalId = 'herbolzheimer';

test('submit herbolzheimer produces sheet/drive/mail artifacts', async () => {
  const r = spawnSync(
    'php',
    [harness, definition, submission, env.artifactDirAbs, portalId],
    {
      encoding: 'utf8',
      env: { ...process.env, DG_TEST_MODE: '1' },
      cwd: root,
    },
  );
  expect(r.status, (r.stdout || '') + (r.stderr || '')).toBe(0);
  const data = JSON.parse((r.stdout || '').trim());
  expect(data.ok).toBeTruthy();
  expect(data.status).toBe('synced');

  const sheet = path.join(env.artifactDirAbs, 'sheets', `${portalId}.jsonl`);
  const driveDir = path.join(env.artifactDirAbs, 'drive', portalId);
  expect(fs.existsSync(sheet)).toBeTruthy();
  expect(fs.existsSync(driveDir)).toBeTruthy();
  expect(fs.existsSync(data.mailPath)).toBeTruthy();

  const row = JSON.parse(fs.readFileSync(sheet, 'utf8').trim().split('\n')[0]!);
  expect(row.sub_email).toBe('applicant@example.com');
  expect(row.work_title).toBe('Symphony No. 1');
  expect(row.sub_work_title).toBe('Symphony No. 1');

  // Second submit appends a second row (plan 3.5).
  const r2 = spawnSync(
    'php',
    [harness, definition, submission, env.artifactDirAbs, portalId, '--append'],
    {
      encoding: 'utf8',
      env: { ...process.env, DG_TEST_MODE: '1' },
      cwd: root,
    },
  );
  expect(r2.status, (r2.stdout || '') + (r2.stderr || '')).toBe(0);
  const lines = fs.readFileSync(sheet, 'utf8').trim().split('\n').filter(Boolean);
  expect(lines.length).toBe(2);

  const summary = path.join(
    env.artifactDirAbs,
    `pipeline-herbolzheimer-${portalId}.json`,
  );
  fs.writeFileSync(
    summary,
    JSON.stringify(
      {
        ok: true,
        portalId,
        sheetPath: data.sheetPath,
        drivePaths: data.drivePaths,
        mailPath: data.mailPath,
        rows: lines.length,
      },
      null,
      2,
    ),
  );
});

test('missing required field and bad MIME produce no sheet artifact', async () => {
  const badPortal = 'herbolzheimer-validation';
  const badSub = path.join(env.artifactDirAbs, 'e2e-bad-submission.json');
  const txtPath = path.join(env.artifactDirAbs, 'e2e-not-score.txt');
  fs.writeFileSync(txtPath, 'plain text not pdf\n');
  const good = JSON.parse(fs.readFileSync(submission, 'utf8'));
  delete good.values.sub_name;
  good.portalId = badPortal;
  good.files = {
    ...good.files,
    score: { name: 'e2e-not-score.txt', path: txtPath },
  };
  fs.writeFileSync(badSub, JSON.stringify(good));

  const r = spawnSync(
    'php',
    [harness, definition, badSub, env.artifactDirAbs, badPortal],
    {
      encoding: 'utf8',
      env: { ...process.env, DG_TEST_MODE: '1' },
      cwd: root,
    },
  );
  expect(r.status).not.toBe(0);
  const sheet = path.join(env.artifactDirAbs, 'sheets', `${badPortal}.jsonl`);
  expect(fs.existsSync(sheet)).toBeFalsy();
});
