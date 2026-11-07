/**
 * Open must show the preview before the readability fetch settles.
 * A hung proof is the pause the applicant feels on a large recording.
 */
import { chromium } from "@playwright/test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../..");
const formScript = path.join(root, "assets/definition-form.js");

const PAGE = `<!doctype html>
<html><body>
<div class="dg-file" data-dg-field-type="recording_file">
  <input type="file" id="clip" accept="audio/mpeg,.mp3" />
  <button type="button" data-dg-file-open>Open</button>
  <p class="dg-file-error"></p>
</div>
<script>
  window.__openCalls = 0;
  window.__proofSettled = false;
  window.DGPreview = {
    open: function () { window.__openCalls += 1; },
  };
  window.FilePreview = {
    open: function () {
      window.__openCalls += 1;
      return new Promise(function (resolve, reject) {
        window.__resolveProof = function () {
          window.__proofSettled = true;
          resolve({ ready: true });
        };
        window.__rejectProof = function () {
          window.__proofSettled = true;
          reject(new Error("file preview: not readable (400)"));
        };
      });
    },
  };
</script>
</body></html>`;

test("Open shows the preview while the readability fetch is still pending", async () => {
  const browser = await chromium.launch({ headless: true });
  try {
    const page = await browser.newPage();
    await page.setContent(PAGE);
    await page.addScriptTag({ path: formScript });
    await page.locator("#clip").setInputFiles({
      name: "excerpt.mp3",
      mimeType: "audio/mpeg",
      buffer: Buffer.from("ID3fake-recording"),
    });
    await page.locator("[data-dg-file-open]").click();
    await page.waitForFunction(() => window.__openCalls === 1, null, { timeout: 500 });
    const pending = await page.evaluate(() => ({
      openCalls: window.__openCalls,
      proofSettled: window.__proofSettled,
      opened: document.querySelector(".dg-file").classList.contains("is-opened"),
    }));
    assert.equal(pending.openCalls, 1);
    assert.equal(pending.proofSettled, false);
    assert.equal(pending.opened, false);
    await page.evaluate(() => window.__resolveProof());
    await page.waitForFunction(
      () => document.querySelector(".dg-file").classList.contains("is-opened"),
      null,
      { timeout: 500 },
    );
  } finally {
    await browser.close();
  }
});
test("openPreview arms every kind before the readability promise", () => {
  const src = fs.readFileSync(path.join(root, "assets/file-preview.js"), "utf8");
  const open = src.slice(src.indexOf("function openPreview"), src.indexOf("export var FilePreview"));
  const armAt = open.indexOf("armPreview()");
  const proveAt = open.indexOf("return proveReadable");
  assert.ok(armAt > 0, "openPreview must arm the media");
  assert.ok(proveAt > armAt, "arming must happen before the readability promise");
  assert.match(src, /function armPreview/);
  assert.match(src, /Loading…/);
  assert.doesNotMatch(src, /kind === ["']audio["'][\s\S]{0,80}showLoading/);
});
