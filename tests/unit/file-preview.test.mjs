/**
 * FilePreview.proveReadable — bytes are proof; iframe `load` is display only.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { proveReadable } from '../../assets/file-preview.js';

const PDF_URL = 'https://files.test/bio.pdf';
const PDF_BYTES = '%PDF-1.4\n%âãÏÓ\n1 0 obj\n<<>>\nendobj\n';
const JUNK_BYTES = '<!doctype html><title>not a pdf</title>';

function bytesOf(text) {
	return new TextEncoder().encode(text);
}

function fetchOf({ ok = true, status = 200, type = '', body = '' } = {}) {
	const bytes = typeof body === 'string' ? bytesOf(body) : body;
	return async function fetchImpl(url) {
		assert.ok(url, 'fetchImpl received a url');
		return {
			ok,
			status,
			headers: {
				get(name) {
					return String(name).toLowerCase() === 'content-type' ? type : null;
				},
			},
			async arrayBuffer() {
				return bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength);
			},
		};
	};
}

function neverLoadMedia() {
	return {
		addEventListener() {
			/* Chrome PDF iframe: load never fires. */
		},
	};
}

test('readable %PDF bytes become ready when the iframe never fires load', { timeout: 500 }, async () => {
	const result = await proveReadable({
		url: PDF_URL,
		kind: 'iframe',
		fetchImpl: fetchOf({ type: 'application/octet-stream', body: PDF_BYTES }),
		media: neverLoadMedia(),
	});
	assert.equal(result.ready, true);
});

test('application/pdf Content-Type becomes ready without a media load', async () => {
	const result = await proveReadable({
		url: PDF_URL,
		kind: 'iframe',
		fetchImpl: fetchOf({ type: 'application/pdf', body: 'not-magic-but-typed' }),
	});
	assert.equal(result.ready, true);
});

test('404 is an error and is not ready', async () => {
	await assert.rejects(
		() =>
			proveReadable({
				url: PDF_URL,
				kind: 'iframe',
				fetchImpl: fetchOf({ ok: false, status: 404, type: 'application/pdf', body: PDF_BYTES }),
			}),
		/not readable|404/i,
	);
});

test('empty body is an error and is not ready', async () => {
	await assert.rejects(
		() =>
			proveReadable({
				url: PDF_URL,
				kind: 'iframe',
				fetchImpl: fetchOf({ type: 'application/pdf', body: '' }),
			}),
		/empty/i,
	);
});

test('non-PDF junk is an error and is not ready', async () => {
	await assert.rejects(
		() =>
			proveReadable({
				url: PDF_URL,
				kind: 'iframe',
				fetchImpl: fetchOf({ type: 'text/html', body: JUNK_BYTES }),
			}),
		/not a pdf/i,
	);
});

test('missing url is an error and is not ready', async () => {
	await assert.rejects(() => proveReadable({ kind: 'iframe', fetchImpl: fetchOf() }), /missing url/i);
});

test('empty url is an error and is not ready', async () => {
	await assert.rejects(
		() => proveReadable({ url: '   ', kind: 'iframe', fetchImpl: fetchOf() }),
		/missing url/i,
	);
});

test('readable image becomes ready from fetch proof without element load', async () => {
	const result = await proveReadable({
		url: 'https://files.test/headshot.png',
		kind: 'image',
		fetchImpl: fetchOf({ type: 'image/png', body: '\x89PNG' }),
	});
	assert.equal(result.ready, true);
});

test('readable audio becomes ready from fetch proof without element load', async () => {
	const result = await proveReadable({
		url: 'https://files.test/excerpt.mp3',
		kind: 'audio',
		fetchImpl: fetchOf({ type: 'audio/mpeg', body: 'ID3\x04fake' }),
	});
	assert.equal(result.ready, true);
});
