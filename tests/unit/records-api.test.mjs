import test from 'node:test';
import assert from 'node:assert/strict';
import { File } from 'node:buffer';
import { getRecords, recallPacket, replacePacketFile } from '../../src/wizard/recordsApi.js';

const config = { restRoot: 'https://example.test/wp-json/dragongate/v1/', portalId: 42, nonce: 'rest-nonce' };

test('packet replacement sends actual multipart bytes and preserves the destination response', async (t) => {
	const file = new File(['replacement bytes'], 'score.pdf');
	t.mock.method(globalThis, 'fetch', async (url, options) => {
		assert.equal(url, `${config.restRoot}portals/42/packets/application-1/replace`);
		assert.equal(options.method, 'POST');
		assert.equal(options.credentials, 'same-origin');
		assert.equal(options.headers['X-WP-Nonce'], config.nonce);
		assert.equal(options.headers['Content-Type'], undefined);
		assert.ok(options.body instanceof FormData);
		assert.equal(options.body.get('field_id'), 'score');
		assert.equal(await options.body.get('file').text(), 'replacement bytes');
		return new Response(JSON.stringify({ replacedField: 'score', replacementDestination: 'https://drive.test/file' }));
	});
	assert.deepEqual(await replacePacketFile({ ...config, applicationId: 'application-1', fieldId: 'score', file }), {
		replacedField: 'score', replacementDestination: 'https://drive.test/file',
	});
});

test('packet recall has no payload and returns the recalled packet', async (t) => {
	t.mock.method(globalThis, 'fetch', async (url, options) => {
		assert.equal(url, `${config.restRoot}portals/42/packets/application-1/recall`);
		assert.equal(options.method, 'POST');
		assert.equal(options.headers['X-WP-Nonce'], config.nonce);
		assert.equal(options.body, undefined);
		return new Response(JSON.stringify({ status: 'recalled' }));
	});
	assert.deepEqual(await recallPacket({ ...config, applicationId: 'application-1' }), { status: 'recalled' });
});

test('packet API errors retain the server message verbatim', async (t) => {
	t.mock.method(globalThis, 'fetch', async () => new Response(JSON.stringify({ message: 'Drive refused these bytes.' }), { status: 409 }));
	await assert.rejects(replacePacketFile({ ...config, applicationId: 'app', fieldId: 'score', file: new File(['x'], 'x.pdf') }), { message: 'Drive refused these bytes.' });
	await assert.rejects(recallPacket({ ...config, applicationId: 'app' }), { message: 'Drive refused these bytes.' });
});

test('records preserve server authorization flags for current, recalled, and log-only rows', async (t) => {
	const records = [
		{ applicationId: 'current', canReplace: true, canRecall: true },
		{ applicationId: 'recalled', canReplace: false, canRecall: true },
		{ applicationId: 'logged', canReplace: false, canRecall: false },
	];
	t.mock.method(globalThis, 'fetch', async () => new Response(JSON.stringify({ portalId: 42, total: 3, records })));
	assert.deepEqual(await getRecords(config), { portalId: 42, total: 3, records });
});
