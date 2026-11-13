/**
 * ZYS-614 / ZYS-618 / ZYS-619 / ZYS-631: public submit never dies raw,
 * staged/tmp purge after 24h, uploads leave the ABSPATH web root,
 * and public POST never enters Portal_Submission.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const harness = path.join(root, 'tests/support/php-public-submit-hardening.php');
const artifactDir = path.join(root, 'tests/.artifacts/public-submit-hardening');
const scenarioDir = path.join(artifactDir, 'scenarios');

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

const SECRET =
  'Google JSON /var/www/html/wp-content/uploads/secret.json {"error":"invalid_grant"}';

test('ZYS-614: public submit catch does not wp_die a raw exception', () => {
  const { code, out, data } = runScenario({
    entry: 'public_failure',
    secret: SECRET,
  });
  assert.equal(code, 0, out);
  assert.ok(data, out);
  assert.equal(data.ok, true, out);
  assert.equal(
    data.publicCatchDiesRaw,
    false,
    'handle_submissions catch still wp_dies $e->getMessage()',
  );
  assert.equal(
    data.publicCatchRethrows,
    false,
    'handle_submissions catch still rethrows the raw Exception',
  );
  assert.equal(
    data.submissionDiesRaw,
    false,
    'Portal_Submission still wp_dies $e->getMessage()',
  );
  assert.equal(data.died, false, `wp_die was called: ${data.dieMessage || out}`);
  assert.equal(data.definedErrors, true, 'DG_DEFINITION_SUBMIT_ERRORS was not defined');
  assert.equal(data.publicCatchRecordsHuman, true, out);

  const human = String(data.humanMessage || '');
  assert.ok(human.length > 20, `human message too short: ${human}`);
  assert.match(human, /try again/i);
  assert.match(human, /contact/i);
  assert.doesNotMatch(human, /secret\.json/);
  assert.doesNotMatch(human, /invalid_grant/);
  assert.doesNotMatch(human, /\/var\/www\//);
  assert.doesNotMatch(human, /\{"error"/);

  const rendered = String(data.rendered || '');
  assert.match(rendered, /dg-submit-errors/);
  assert.match(rendered, /try again/i);
  assert.doesNotMatch(rendered, /secret\.json/);
  assert.doesNotMatch(rendered, /invalid_grant/);
  assert.doesNotMatch(rendered, /\/var\/www\//);

  const log = String(data.log || '');
  assert.match(log, /secret\.json/);
  assert.match(log, /invalid_grant/);
});

test('ZYS-614: ready_to_submit Exception does not attach the success filter', () => {
  const { code, out, data } = runScenario({
    entry: 'legacy_submit_failure',
    secret: SECRET,
  });
  assert.equal(code, 0, out);
  assert.ok(data, out);
  assert.equal(data.ok, true, out);
  assert.equal(
    data.unconditionalLegacySuccess,
    false,
    'handle_submissions still defines PB_RECEIPT_LINK after process_submission without checking the result',
  );
  assert.equal(data.hasFinishPublicSubmit, true, 'finish_public_submit is missing');
  assert.equal(data.completed, false, `process_submission completed=${data.completed}`);
  assert.equal(data.outcome, 'error', `outcome=${data.outcome}`);
  assert.equal(data.died, false, `wp_die: ${data.dieMessage || out}`);
  assert.equal(data.successFilterAttached, false, 'pb_post_submitted_content_filter was attached');
  assert.equal(data.receiptDefined, false, 'PB_RECEIPT_LINK was defined on failure');
  assert.equal(data.definedErrors, true, 'DG_DEFINITION_SUBMIT_ERRORS was not defined');
  const human = String(data.humanMessage || '');
  assert.match(human, /try again/i);
  assert.doesNotMatch(human, /secret\.json/);
  const rendered = String(data.rendered || '');
  assert.match(rendered, /dg-submit-errors/);
  assert.doesNotMatch(rendered, /submitted successfully/i);
  assert.doesNotMatch(rendered, /secret\.json/);
});

test('ZYS-618: cleanup hook deletes only staged/tmp files older than 24h', () => {
  const now = 1_800_000_000;
  const { code, out, data } = runScenario({
    entry: 'purge',
    now,
    ttl: 86400,
  });
  assert.equal(code, 0, out);
  assert.ok(data, out);
  assert.equal(data.ok, true, out);
  assert.equal(data.oldStagedGone, true, 'old staged file should be purged');
  assert.equal(data.freshStaged, true, 'fresh staged file should remain');
  assert.equal(data.oldTmpGone, true, 'old tmp file should be purged');
  assert.equal(data.freshTmp, true, 'fresh tmp file should remain');
  assert.equal(data.legacyGone, true, 'legacy tmp file should be purged');
  assert.equal(data.acceptedKept, true, 'permanent/accepted file must not be deleted');

  const scheduled = runScenario({ entry: 'schedule' });
  assert.equal(scheduled.code, 0, scheduled.out);
  assert.ok(scheduled.data, scheduled.out);
  assert.equal(scheduled.data.ok, true, scheduled.out);
  assert.ok(scheduled.data.hook, 'cleanup hook name missing');
  assert.ok(
    scheduled.data.scheduled && scheduled.data.scheduled[scheduled.data.hook],
    `hook ${scheduled.data.hook} was not scheduled`,
  );
  assert.ok(
    Array.isArray(scheduled.data.cleared) &&
      scheduled.data.cleared.includes(scheduled.data.hook),
    'deactivate did not clear the cleanup hook',
  );

  const inspect = runScenario({ entry: 'inspect' });
  assert.equal(inspect.code, 0, inspect.out);
  assert.equal(inspect.data?.schedulesCleanup, true, inspect.out);
  assert.equal(inspect.data?.clearsCleanup, true, inspect.out);
});

test('ZYS-631: ready_to_submit POST without a definition never enters Portal_Submission', () => {
  const { code, out, data } = runScenario({
    entry: 'ready_to_submit_no_definition',
  });
  assert.equal(code, 0, out);
  assert.ok(data, out);
  assert.equal(data.ok, true, out);
  assert.equal(
    data.sourceConstructsLegacy,
    false,
    'handle_submissions still has new Portal_Submission',
  );
  assert.equal(
    data.sourceProcessesLegacy,
    false,
    'handle_submissions still calls process_submission',
  );
  assert.equal(
    data.constructedLegacy,
    false,
    'ready_to_submit POST constructed Portal_Submission',
  );
  assert.equal(
    data.processedLegacy,
    false,
    'ready_to_submit POST called process_submission',
  );
  assert.equal(data.died, false, `wp_die: ${data.dieMessage || out}`);
  assert.equal(data.definedErrors, true, 'DG_DEFINITION_SUBMIT_ERRORS was not defined');
  assert.equal(data.receiptDefined, false, 'PB_RECEIPT_LINK was defined without a definition');
  const human = String(data.humanMessage || '');
  assert.match(human, /this portal is not configured/i);
  const rendered = String(data.rendered || '');
  assert.match(rendered, /dg-submit-errors/);
  assert.match(rendered, /this portal is not configured/i);
});

test('success packet uses submission id and packet copy, not submitted successfully', () => {
  const { code, out, data } = runScenario({
    entry: 'render_success',
    result: {
      portalId: '42',
      receipt_url: 'https://example.test/receipt/app-99',
      row: { applicationId: 'app-99' },
    },
  });
  assert.equal(code, 0, out);
  assert.ok(data, out);
  const html = String(data.html || '');
  assert.match(html, /data-dg-submit-status="success"/);
  assert.match(html, /Your packet is in\./);
  assert.match(html, /data-dg-app-id="app-99"/);
  assert.match(html, />app-99</);
  assert.match(html, /data-dg-receipt-link/);
  assert.match(html, /View your receipt/);
  assert.doesNotMatch(html, /submitted successfully/i);
  assert.doesNotMatch(html, /data-dg-app-id="42"/);
});

test('ZYS-619: tmp and permanent uploads resolve under uploads, not ABSPATH web root', () => {
  const { code, out, data } = runScenario({ entry: 'upload_roots' });
  assert.equal(code, 0, out);
  assert.ok(data, out);
  assert.equal(data.ok, true, out);
  assert.equal(data.abspathTmpConcat, false, 'PB_TMP_UPLOADS_DIR still concatenates ABSPATH');
  assert.equal(
    data.abspathStoreConcat,
    false,
    'PB_PERMANENT_UPLOADS_DIR still concatenates ABSPATH',
  );
  assert.equal(data.tmpIsLegacy, false, `tmp still ${data.tmpDir}`);
  assert.equal(data.storeIsLegacy, false, `store still ${data.storeDir}`);
  assert.equal(data.tmpUnderUploads, true, `tmp ${data.tmpDir} not under ${data.uploadsBasedir}`);
  assert.equal(
    data.storeUnderUploads,
    true,
    `store ${data.storeDir} not under ${data.uploadsBasedir}`,
  );
  assert.equal(
    data.stagedUnderUploads,
    true,
    `staged ${data.stagedDir} not under ${data.uploadsBasedir}`,
  );
  assert.doesNotMatch(String(data.tmpDir || ''), /\/tmp-uploads\/?$/);
  assert.doesNotMatch(String(data.storeDir || ''), /\/file-storage\/?$/);
  assert.equal(data.denyTmpExists, true, `missing deny beside tmp: ${data.denyTmp}`);
  assert.equal(data.denyStoreExists, true, `missing deny beside store: ${data.denyStore}`);
  assert.equal(data.denyStagedExists, true, `missing deny beside staged: ${data.denyStaged}`);
  assert.notEqual(
    path.resolve(String(data.stagedAbsPath || '')),
    path.resolve(String(data.legacyPublicPath || '')),
    'staged file still maps to ABSPATH/tmp-uploads public path',
  );
  assert.equal(data.stagedPublicUrl, '/tmp-uploads/aabbccddeeff00112233445566778899/content');
  assert.ok(
    !String(data.stagedAbsPath || '').endsWith(
      path.join('tmp-uploads', 'aabbccddeeff00112233445566778899', 'content'),
    ),
    'staged absolute path is still a public /tmp-uploads URL path',
  );
});
