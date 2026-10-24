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
test("audio, image, and pdf previews load through one open", async () => {
  const browser = await chromium.launch({ headless: true });
  try {
    const page = await browser.newPage();
    await page.setContent("<!doctype html><html><body></body></html>");
    const source = fs.readFileSync(path.join(root, "assets/file-preview.js"), "utf8");
    await page.addScriptTag({ content: source, type: "module" });
    await page.waitForFunction(
      () => window.FilePreview && typeof window.FilePreview.open === "function",
      null,
      { timeout: 3000 },
    );
    const kinds = [
      { kind: "audio", tag: "AUDIO" },
      { kind: "image", tag: "IMG" },
      { kind: "iframe", tag: "IFRAME" },
    ];
    for (const item of kinds) {
      await page.evaluate((kind) => {
        document.body.replaceChildren();
        window.DGPreview = {
          open(options) {
            const media = document.createElement(kind === "audio" ? "audio" : kind === "image" ? "img" : "iframe");
            media.className = "dg-file-preview-media";
            media.src = options.url;
            document.body.appendChild(media);
          },
        };
        window.FilePreview.open({
          url: "https://files.test/piece",
          kind: kind,
          fetchImpl: () => new Promise(() => {}),
        });
      }, item.kind);
      await page.waitForFunction(
        (tag) => {
          const note = document.querySelector(".dg-file-preview-loading");
          const media = document.querySelector(".dg-file-preview-media");
          return note && note.textContent === "Loading…" && media && media.tagName === tag;
        },
        item.tag,
        { timeout: 1000 },
      );
      if (item.kind === "audio") {
        const preload = await page.locator("audio").evaluate((el) => el.preload);
        assert.equal(preload, "auto");
      }
    }
  } finally {
    await browser.close();
  }
});
