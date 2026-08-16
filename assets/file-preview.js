/**
 * FilePreview — prove a confirm file is readable before the card is marked opened.
 *
 * Iframe `load` is display only. Chrome's PDF viewer (especially Playwright
 * Chromium) often never fires that event, so readiness is GET + non-empty
 * body, and for iframe/pdf a `%PDF` magic or Content-Type application/pdf.
 */
var PDF_MAGIC = [0x25, 0x50, 0x44, 0x46]
var PDF_TYPE = 'application/pdf'
var KIND_IFRAME = 'iframe'
var KIND_PDF = 'pdf'

function missingUrl(url) {
  return url == null || String(url).trim() === ''
}

function contentTypeOf(response) {
  if (!response || !response.headers || typeof response.headers.get !== 'function') {
    return ''
  }
  return String(response.headers.get('content-type') || '').toLowerCase()
}

function hasPdfType(type) {
  return String(type || '').indexOf(PDF_TYPE) !== -1
}

function hasPdfMagic(bytes) {
  if (!bytes || bytes.length < PDF_MAGIC.length) {
    return false
  }
  for (var i = 0; i < PDF_MAGIC.length; i += 1) {
    if (bytes[i] !== PDF_MAGIC[i]) {
      return false
    }
  }
  return true
}

function needsPdfProof(kind) {
  return kind === KIND_IFRAME || kind === KIND_PDF
}

function resolveFetch(fetchImpl) {
  if (typeof fetchImpl === 'function') {
    return fetchImpl
  }
  if (typeof globalThis.fetch === 'function') {
    return globalThis.fetch.bind(globalThis)
  }
  return null
}

async function readBytes(response) {
  if (response && typeof response.arrayBuffer === 'function') {
    var buffer = await response.arrayBuffer()
    return new Uint8Array(buffer || 0)
  }
  if (response && typeof response.text === 'function') {
    var text = await response.text()
    return new TextEncoder().encode(text || '')
  }
  return new Uint8Array()
}

function decideReadable(kind, response, bytes) {
  if (!response || !response.ok) {
    var status = response && response.status ? response.status : 0
    return { ready: false, reason: 'not readable (' + status + ')' }
  }
  if (!bytes || !bytes.length) {
    return { ready: false, reason: 'empty body' }
  }
  if (needsPdfProof(kind) && !hasPdfType(contentTypeOf(response)) && !hasPdfMagic(bytes)) {
    return { ready: false, reason: 'not a pdf' }
  }
  return { ready: true }
}

export async function proveReadable(options) {
  var opts = options || {}
  var url = opts.url
  var kind = opts.kind || KIND_IFRAME
  var fetchImpl = resolveFetch(opts.fetchImpl)
  if (missingUrl(url)) {
    throw new Error('file preview: missing url')
  }
  if (!fetchImpl) {
    throw new Error('file preview: fetch unavailable')
  }
  var response
  try {
    response = await fetchImpl(url)
  } catch (err) {
    throw new Error('file preview: fetch failed')
  }
  var bytes = await readBytes(response)
  var decision = decideReadable(kind, response, bytes)
  if (!decision.ready) {
    throw new Error('file preview: ' + decision.reason)
  }
  return { ready: true }
}

export var FilePreview = { proveReadable: proveReadable }

if (typeof globalThis !== 'undefined') {
  globalThis.FilePreview = FilePreview
}
