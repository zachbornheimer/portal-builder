/**
 * FilePreview — prove a confirm file is readable before the card is marked opened.
 *
 * Iframe `load` is display only. Chrome's PDF viewer (especially Playwright
 * Chromium) often never fires that event, so readiness is GET + non-empty
 * body, and for iframe/pdf a `%PDF` magic or Content-Type application/pdf.
 *
 * Staged REST must return raw bytes (serve_raw_request). A JSON body means the
 * binary serve path failed — treat as not readable (Safari/Chrome Open confirm).
 */
var PDF_MAGIC = [0x25, 0x50, 0x44, 0x46];
var PDF_TYPE = "application/pdf";
var KIND_IFRAME = "iframe";
var KIND_PDF = "pdf";

function missingUrl(url) {
  return url == null || String(url).trim() === "";
}

function contentTypeOf(response) {
  if (!response || !response.headers || typeof response.headers.get !== "function") {
    return "";
  }
  return String(response.headers.get("content-type") || "").toLowerCase();
}

function hasPdfType(type) {
  return String(type || "").indexOf(PDF_TYPE) !== -1;
}

function hasPdfMagic(bytes) {
  if (!bytes || bytes.length < PDF_MAGIC.length) {
    return false;
  }
  for (var i = 0; i < PDF_MAGIC.length; i += 1) {
    if (bytes[i] !== PDF_MAGIC[i]) {
      return false;
    }
  }
  return true;
}

function needsPdfProof(kind) {
  return kind === KIND_IFRAME || kind === KIND_PDF;
}

function resolveFetch(fetchImpl) {
  if (typeof fetchImpl === "function") {
    return fetchImpl;
  }
  if (typeof globalThis.fetch === "function") {
    return globalThis.fetch.bind(globalThis);
  }
  return null;
}

async function readBytes(response) {
  if (response && typeof response.arrayBuffer === "function") {
    var buffer = await response.arrayBuffer();
    return new Uint8Array(buffer || 0);
  }
  if (response && typeof response.text === "function") {
    var text = await response.text();
    return new TextEncoder().encode(text || "");
  }
  return new Uint8Array();
}

function looksLikeJson(type, bytes) {
  if (String(type || "").indexOf("application/json") !== -1) {
    return true;
  }
  if (!bytes || !bytes.length) {
    return false;
  }
  // Skip UTF-8 BOM / whitespace then look for `{` / `[` (REST error or JSON envelope).
  var i = 0;
  if (bytes.length >= 3 && bytes[0] === 0xef && bytes[1] === 0xbb && bytes[2] === 0xbf) {
    i = 3;
  }
  while (i < bytes.length) {
    var c = bytes[i];
    if (c === 0x20 || c === 0x09 || c === 0x0a || c === 0x0d) {
      i += 1;
      continue;
    }
    return c === 0x7b || c === 0x5b; // { or [
  }
  return false;
}

function decideReadable(kind, response, bytes) {
  if (!response || !response.ok) {
    var status = response && response.status ? response.status : 0;
    return { ready: false, reason: "not readable (" + status + ")" };
  }
  if (!bytes || !bytes.length) {
    return { ready: false, reason: "empty body" };
  }
  if (looksLikeJson(contentTypeOf(response), bytes)) {
    return { ready: false, reason: "json instead of file bytes" };
  }
  if (needsPdfProof(kind) && !hasPdfType(contentTypeOf(response)) && !hasPdfMagic(bytes)) {
    return { ready: false, reason: "not a pdf" };
  }
  return { ready: true };
}

async function readProofBytes(response) {
  var body = response && response.body;
  if (body && typeof body.getReader === "function") {
    var reader = body.getReader();
    var chunk;
    try {
      chunk = await reader.read();
    } catch {
      throw new Error("file preview: fetch failed");
    }
    try {
      await reader.cancel();
    } catch {
      /* The first chunk is the proof. Cancelling the rest must not fail Open. */
    }
    if (!chunk || !chunk.value || !chunk.value.byteLength) {
      return new Uint8Array();
    }
    return chunk.value instanceof Uint8Array ? chunk.value : new Uint8Array(chunk.value);
  }
  return readBytes(response);
}

export async function proveReadable(options) {
  var opts = options || {};
  var url = opts.url;
  var kind = opts.kind || KIND_IFRAME;
  var fetchImpl = resolveFetch(opts.fetchImpl);
  if (missingUrl(url)) {
    throw new Error("file preview: missing url");
  }
  if (!fetchImpl) {
    throw new Error("file preview: fetch unavailable");
  }
  var response;
  try {
    // Same-origin staged REST; cookies/nonce sessions need credentials on Safari.
    // Read one chunk and cancel. A full-body wait is the pause before Open.
    response = await fetchImpl(url, { credentials: "same-origin" });
  } catch {
    throw new Error("file preview: fetch failed");
  }
  var bytes = await readProofBytes(response);
  var decision = decideReadable(kind, response, bytes);
  if (!decision.ready) {
    throw new Error("file preview: " + decision.reason);
  }
  return { ready: true };
}

var LOADING_LABEL = "Loading…";
var MEDIA_SELECTOR = ".dg-file-preview-media";
var ARM_TRIES = 40;
var ARM_WAIT_MS = 50;

function dialogOf(options) {
  if (options && options.dialog) {
    return options.dialog;
  }
  return globalThis.DGPreview;
}

function closeDialog(dialog) {
  if (dialog && typeof dialog.close === "function") {
    dialog.close();
    return;
  }
  var closeBtn = document.querySelector(".dg-file-preview-close");
  if (closeBtn && typeof closeBtn.click === "function") {
    closeBtn.click();
  }
}

function clearLoading(note) {
  if (note && note.parentNode) {
    note.parentNode.removeChild(note);
  }
}

function startEager(media) {
  if (!media || media.tagName !== "AUDIO") {
    return;
  }
  if (media.getAttribute("preload") === "auto") {
    return;
  }
  media.setAttribute("preload", "auto");
  media.preload = "auto";
  if (typeof media.load === "function") {
    media.load();
  }
}

function showLoading(media) {
  var body = media && media.parentNode;
  if (!body || body.querySelector(".dg-file-preview-loading")) {
    return body ? body.querySelector(".dg-file-preview-loading") : null;
  }
  var note = document.createElement("p");
  note.className = "dg-file-preview-loading";
  note.setAttribute("role", "status");
  note.textContent = LOADING_LABEL;
  body.insertBefore(note, media);
  function done() {
    clearLoading(note);
  }
  media.addEventListener("canplay", done);
  media.addEventListener("load", done);
  media.addEventListener("error", done);
  return note;
}

function armPreview() {
  function arm() {
    var media = document.querySelector(MEDIA_SELECTOR);
    if (!media) {
      return false;
    }
    startEager(media);
    showLoading(media);
    return true;
  }
  if (arm()) {
    return;
  }
  var tries = 0;
  var timer = setInterval(function() {
    tries += 1;
    if (arm() || tries >= ARM_TRIES) {
      clearInterval(timer);
    }
  }, ARM_WAIT_MS);
}

export function openPreview(options) {
  var opts = options || {};
  var dialog = dialogOf(opts);
  if (!dialog || typeof dialog.open !== "function") {
    return Promise.reject(new Error("file preview: dialog unavailable"));
  }
  if (missingUrl(opts.url)) {
    return Promise.reject(new Error("file preview: missing url"));
  }
  dialog.open({
    url: opts.url,
    kind: opts.kind || KIND_IFRAME,
    title: opts.title,
    returnFocus: opts.returnFocus,
    onClose: opts.onClose,
  });
  armPreview();
  return proveReadable(opts).then(function(result) {
    clearLoading(document.querySelector(".dg-file-preview-loading"));
    return result;
  }, function(err) {
    clearLoading(document.querySelector(".dg-file-preview-loading"));
    closeDialog(dialog);
    throw err;
  });
}

export var FilePreview = { proveReadable: proveReadable, open: openPreview };

if (typeof globalThis !== "undefined") {
  globalThis.FilePreview = FilePreview;
  try {
    globalThis.dispatchEvent(new Event("dg-file-preview-ready"));
  } catch {
    /* Event unsupported — definition-form still polls previewReady. */
  }
}
