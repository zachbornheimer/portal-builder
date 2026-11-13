/**
 * FileStore Drive writes on submit send supportsAllDrives.
 *
 * Shared Drive folders 403 without the flag on files->create.
 */
<<<<<<< Updated upstream
import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
||||||| Stash base
import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import path from 'node:path';
=======
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import path from "node:path";
import test from "node:test";
>>>>>>> Stashed changes

const root = process.cwd();
<<<<<<< Updated upstream
const harness = path.join(root, 'tests/support/php-drive-write-params.php');
const filestoreAutoload = path.join(root, 'gsuite-filestore/vendor/autoload.php');
||||||| Stash base
const harness = path.join(root, 'tests/support/php-drive-write-params.php');
=======
const harness = path.join(root, "tests/support/php-drive-write-params.php");
>>>>>>> Stashed changes

function runHarness() {
  const r = spawnSync("php", [harness], { encoding: "utf8" });
  const out = (r.stdout || "") + (r.stderr || "");
  let data = null;
  try {
    const start = out.indexOf("{");
    data = start >= 0 ? JSON.parse(out.slice(start)) : null;
  } catch {
    data = null;
  }
  return { code: r.status, out, data };
}

function assertCreateSendsAllDrives(label, captured) {
  assert.ok(captured, `${label}: missing captured create options`);
  assert.equal(
    captured.hasSupportsAllDrives,
    true,
    `${label}: supportsAllDrives missing from files->create options (keys=${JSON.stringify(captured.keys)})`,
  );
  assert.equal(
    captured.supportsAllDrives,
    true,
    `${label}: supportsAllDrives must be true, got ${JSON.stringify(captured.supportsAllDrives)}`,
  );
}

<<<<<<< Updated upstream
test('every FileStore files->create on submit sends supportsAllDrives', (t) => {
	if (!fs.existsSync(filestoreAutoload)) {
		t.skip('gsuite-filestore vendor missing (submodule not checked out in CI)');
		return;
	}
	const { code, out, data } = runHarness();
	assert.equal(code, 0, out);
	assert.ok(data, out);
	assert.equal(data.ok, true, out);
	assert.equal(data.createCount, 2, `expected folder + upload creates, got ${data.createCount}`);
	assertCreateSendsAllDrives('create_drive_subfolder', data.folder);
	assertCreateSendsAllDrives('store_drive_file', data.upload);
	assert.equal(data.folder.fields, 'id');
	assert.equal(data.upload.uploadType, 'multipart');
||||||| Stash base
test('every FileStore files->create on submit sends supportsAllDrives', () => {
	const { code, out, data } = runHarness();
	assert.equal(code, 0, out);
	assert.ok(data, out);
	assert.equal(data.ok, true, out);
	assert.equal(data.createCount, 2, `expected folder + upload creates, got ${data.createCount}`);
	assertCreateSendsAllDrives('create_drive_subfolder', data.folder);
	assertCreateSendsAllDrives('store_drive_file', data.upload);
	assert.equal(data.folder.fields, 'id');
	assert.equal(data.upload.uploadType, 'multipart');
=======
test("every FileStore files->create on submit sends supportsAllDrives", () => {
  const { code, out, data } = runHarness();
  assert.equal(code, 0, out);
  assert.ok(data, out);
  assert.equal(data.ok, true, out);
  assert.equal(data.createCount, 2, `expected folder + upload creates, got ${data.createCount}`);
  assertCreateSendsAllDrives("create_drive_subfolder", data.folder);
  assertCreateSendsAllDrives("store_drive_file", data.upload);
  assert.equal(data.folder.fields, "id");
  assert.equal(data.upload.uploadType, "multipart");
>>>>>>> Stashed changes
});
