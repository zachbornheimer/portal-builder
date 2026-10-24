import { expect, test } from "@playwright/test";
import path from "node:path";

/**
 * ZYS-1710. A Finder drop can hand the page a File whose bytes are readable
 * only during the drop turn. Copying that File with `new File([raw])` keeps
 * the empty shell. The overlay input also takes the drop and starts a second
 * upload, which aborts the first and paints "Upload failed".
 */
const STAGE = "https://portal.test/wp-json/dragongate/v1/portals/1/files";
const MAGIC = Buffer.from([0xff, 0xfb, 0x90, 0x64]);
const PICKED_BYTES = 4096;
const ANONYMIZED_BYTES = 1024;
const FORM_SCRIPT = path.resolve("assets/definition-form.js");

function cardMarkup() {
  return `<!doctype html>
<html>
<body>
<form class="dg-form">
  <div class="dg-field dg-file is-empty" data-dg-field-id="sub_rec" data-dg-field-type="recording_file" data-testid="dg-field-sub_rec">
    <label class="dg-field-label" for="sub_rec">Recording</label>
    <div class="dg-file-body">
      <div class="dg-file-pick">
        <input type="file" class="dg-file-input" id="sub_rec" name="sub_rec" accept="audio/mpeg,.mp3" />
        <input type="hidden" name="sub_rec_staged" value="" data-dg-file-staged />
        <span class="dg-file-name" data-dg-file-name></span>
        <span data-dg-file-size></span>
      </div>
      <p class="dg-file-error" role="alert"></p>
    </div>
  </div>
</form>
<script>
  window.dgPublicForm = {
    portalId: '1',
    nonce: 'test-nonce',
    stageUrl: '${STAGE}',
    anonymize: '1'
  }
</script>
</body>
</html>`;
}

test.beforeEach(async ({ page }) => {
  await page.setContent(cardMarkup());
  await page.addScriptTag({ path: FORM_SCRIPT });
});

test("dropped file stages once", async ({ page }) => {
  const posts = [];
  await page.route(STAGE, async (route) => {
    const body = route.request().postDataBuffer() ?? Buffer.alloc(0);
    posts.push(body);
    const kept = body.includes(MAGIC);
    await route.fulfill({
      status: kept ? 200 : 400,
      contentType: "application/json",
      body: kept
        ? JSON.stringify({
          token: "staged-token",
          url: "https://portal.test/files/Recording.mp3",
          storedName: "Recording.mp3",
          originalName: "recording.mp3",
          bytes: ANONYMIZED_BYTES,
        })
        : JSON.stringify({ code: "dg_empty_file", message: "Upload is empty." }),
    });
  });

  // Chromium assigns the drop to the overlay input and fires change again.
  // The product has to stop that or the second request aborts the real upload.
  await page.evaluate(() => {
    const input = document.querySelector(".dg-file-input");
    input.addEventListener("drop", () => {
      const empty = new File([], "recording.mp3", { type: "audio/mpeg" });
      const transfer = new DataTransfer();
      transfer.items.add(empty);
      input.files = transfer.files;
      input.dispatchEvent(new Event("change", { bubbles: true }));
    });
  });

  await page.evaluate((magic) => {
    const payload = new Uint8Array(4096);
    payload.set(magic);
    payload.fill(0x11, magic.length);
    const raw = new File([], "recording.mp3", { type: "audio/mpeg" });
    let live = true;
    Object.defineProperty(raw, "arrayBuffer", {
      configurable: true,
      value() {
        if (!live) {
          return Promise.resolve(new ArrayBuffer(0));
        }
        return Promise.resolve(payload.buffer.slice(0));
      },
    });
    const input = document.querySelector(".dg-file-input");
    const transfer = new DataTransfer();
    transfer.items.add(raw);
    input.dispatchEvent(
      new DragEvent("drop", { bubbles: true, cancelable: true, dataTransfer: transfer }),
    );
    queueMicrotask(() => {
      live = false;
    });
  }, [...MAGIC]);

  const card = page.getByTestId("dg-field-sub_rec");
  await expect
    .poll(async () => {
      const name = await card.locator("[data-dg-file-name]").textContent();
      if (name === "Recording.mp3") {
        return "staged";
      }
      return (await card.locator(".dg-file-error").textContent()) || "";
    })
    .toBe("staged");
  await expect(card.locator("[data-dg-file-size]")).toHaveText("4 KB");
  expect(posts, "a drop must stage once").toHaveLength(1);
  expect(posts[0].includes(MAGIC), "the staged body must be the dropped bytes").toBeTruthy();
});

test("staged recording shows the size that was picked", async ({ page }) => {
  await page.route(STAGE, async (route) => {
    await route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({
        token: "staged-token",
        url: "https://portal.test/files/Recording.mp3",
        storedName: "Recording.mp3",
        originalName: "recording.mp3",
        bytes: ANONYMIZED_BYTES,
      }),
    });
  });

  await page.locator(".dg-file-input").setInputFiles({
    name: "recording.mp3",
    mimeType: "audio/mpeg",
    buffer: Buffer.alloc(PICKED_BYTES, 0x11),
  });

  const card = page.getByTestId("dg-field-sub_rec");
  await expect(card.locator("[data-dg-file-name]")).toHaveText("Recording.mp3");
  await expect(card.locator("[data-dg-file-size]")).toHaveText("4 KB");
});
