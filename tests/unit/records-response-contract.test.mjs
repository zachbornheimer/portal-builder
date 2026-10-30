import test from 'node:test';
import assert from 'node:assert/strict';
import { getRecords, listInvites, searchInviteCandidates } from '../../src/wizard/recordsApi.js';

const config = { restRoot: 'https://example.test/wp-json/dragongate/v1/', portalId: 42, nonce: 'rest-nonce' };
const records = [
	{ applicationId: 'application-2', name: 'Second applicant', canReplace: true, canRecall: true },
	{ applicationId: 'application-1', name: 'First applicant', canReplace: false, canRecall: false },
];

test('records GET preserves the real REST envelope and sends the bootstrap nonce', async (t) => {
	t.mock.method(globalThis, 'fetch', async (url, options) => {
		assert.equal(url, `${config.restRoot}portals/42/records?limit=5`);
		assert.equal(options.method, 'GET');
		assert.equal(options.credentials, 'same-origin');
		assert.equal(options.headers['X-WP-Nonce'], config.nonce);
		return Response.json({ portalId: 42, total: 2, records });
	});
	assert.deepEqual(await getRecords(config), { portalId: 42, total: 2, records });
});

test('only an explicitly empty records envelope represents no submissions', async (t) => {
	t.mock.method(globalThis, 'fetch', async () => Response.json({ portalId: 42, total: 0, records: [] }));
	assert.deepEqual(await getRecords(config), { portalId: 42, total: 0, records: [] });
});

for (const [body, detail] of [
	[{}, 'Expected records array; received missing.'],
	[null, 'Expected response object; received null.'],
	[[], 'Expected response object; received array.'],
	[{ data: { portalId: 42, total: 2, records } }, 'Expected records array; received missing.'],
	[{ portalId: 42, total: 2 }, 'Expected records array; received missing.'],
	[{ portalId: 42, total: 2, records: {} }, 'Expected records array; received object.'],
	[{ portalId: 42, total: 2, records: [] }, 'Inconsistent counts: total 2, records length 0.'],
	[{ portalId: 42, total: 0, records }, 'Inconsistent counts: total 0, records length 2.'],
	[{ portalId: 42, total: -1, records: [] }, 'Expected non-negative integer total; received -1.'],
	[{ portalId: 42, total: '2', records }, 'Expected non-negative integer total; received string.'],
	[{ portalId: 42, total: 2.5, records }, 'Expected non-negative integer total; received 2.5.'],
	[{ portalId: 42, records }, 'Expected non-negative integer total; received missing.'],
	[{ portalId: 43, total: 2, records }, 'Expected portalId 42; received 43.'],
	[{ total: 2, records }, 'Expected portalId 42; received missing.'],
	[{ portalId: 42, total: 1, records: [null] }, 'Expected records[0] object; received null.'],
	[{ portalId: 42, total: 1, records: ['not a record'] }, 'Expected records[0] object; received string.'],
	[{ portalId: 42, total: 1, records: [{}] }, 'Expected records[0].applicationId non-blank string; received missing.'],
	[{ portalId: 42, total: 1, records: [{ applicationId: '' }] }, 'Expected records[0].applicationId non-blank string; received blank string.'],
]) {
	test(`malformed records response explains its shape: ${detail}`, async (t) => {
		t.mock.method(globalThis, 'fetch', async () => Response.json(body));
		await assert.rejects(getRecords(config), { message: `Records GET returned an invalid response. ${detail}` });
	});
}

test('malformed response diagnostics do not disclose applicant strings or row payloads', async (t) => {
	const privateValue = 'Private Applicant <private@example.test>';
	for (const body of [
		privateValue,
		{ portalId: privateValue, total: 1, records },
		{ portalId: 42, total: privateValue, records },
		{ portalId: 42, total: 1, records: privateValue },
		{ portalId: 42, total: 1, records: [privateValue] },
		{ portalId: 42, total: 1, records: [{ name: privateValue, applicationId: { email: privateValue } }] },
	]) {
		t.mock.method(globalThis, 'fetch', async () => Response.json(body));
		await assert.rejects(getRecords(config), (error) => {
			assert.match(error.message, /^Records GET returned an invalid response\. Expected/);
			assert.doesNotMatch(error.message, /Private Applicant|private@example\.test/);
			assert.doesNotMatch(error.message, /application-1|application-2/);
			return true;
		});
	}
});

test('a paged records response retains total and accepts a string portal configuration id', async (t) => {
	t.mock.method(globalThis, 'fetch', async (url) => {
		assert.ok(url.endsWith('/records?limit=1'));
		return Response.json({ portalId: 42, total: 2, records: records.slice(0, 1) });
	});
	assert.deepEqual(await getRecords({ ...config, portalId: '42', limit: 1 }), {
		portalId: 42, total: 2, records: records.slice(0, 1),
	});
});

for (const api of [getRecords, listInvites, searchInviteCandidates]) {
	test(`${api.name} rejects absent configuration without making a request`, async (t) => {
		const fetch = t.mock.method(globalThis, 'fetch', async () => assert.fail('must not fetch without credentials'));
		for (const missing of [{ portalId: 0 }, { portalId: undefined }, { restRoot: '' }, { nonce: '' }, { nonce: '  ' }]) {
			await assert.rejects(api({ ...config, ...missing }), /Missing portal id or REST credentials/);
		}
		assert.equal(fetch.mock.callCount(), 0);
	});

	test(`${api.name} preserves WordPress permission and nonce errors`, async (t) => {
		for (const message of ['Cookie check failed', 'You cannot manage this portal.']) {
			t.mock.method(globalThis, 'fetch', async () => Response.json({ message }, { status: 403 }));
			await assert.rejects(api(config), { message });
		}
	});

	test(`${api.name} rejects invalid JSON and network failure instead of reporting empty`, async (t) => {
		t.mock.method(globalThis, 'fetch', async () => new Response('<html>Sign in</html>'));
		await assert.rejects(api(config), /returned non-JSON/);
		t.mock.method(globalThis, 'fetch', async () => { throw new Error('Network unavailable'); });
		await assert.rejects(api(config), { message: 'Network unavailable' });
	});
}

for (const [api, key, label] of [[listInvites, 'invites', 'Invites GET'], [searchInviteCandidates, 'candidates', 'Invite candidates GET']]) {
	test(`${api.name} accepts the real list envelope but rejects missing or malformed lists`, async (t) => {
		t.mock.method(globalThis, 'fetch', async () => Response.json({ [key]: [] }));
		assert.deepEqual(await api(config), []);
		for (const [body, detail] of [
			[{}, `Expected ${key} array; received missing.`],
			[{ [key]: null }, `Expected ${key} array; received null.`],
			[{ [key]: [null] }, `Expected ${key}[0] object; received null.`],
		]) {
			t.mock.method(globalThis, 'fetch', async () => Response.json(body));
			await assert.rejects(api(config), { message: `${label} returned an invalid response. ${detail}` });
		}
	});
}
