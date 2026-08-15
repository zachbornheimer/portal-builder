/**
 * Waitlist handler: valid join, reject bad email, idempotent duplicate.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import {
	emailIsValid,
	handleWaitlistRequest,
	joinWaitlist,
	normalizeEmail,
} from '../src/waitlist.js';

const FIXED_NOW = '2026-08-15T14:32:01Z';
const CLOCK = { now: () => FIXED_NOW };
const JOIN_URL = 'https://dragongateportals.com/api/waitlist';
const HEALTH_URL = 'https://dragongateportals.com/api/waitlist/health';

function memoryStore() {
	const rows = new Map();
	return {
		rows,
		async get(key) {
			return rows.has(key) ? rows.get(key) : null;
		},
		async put(key, value) {
			rows.set(key, value);
		},
	};
}

function joinRequest(body, headers = {}) {
	return new Request(JOIN_URL, {
		method: 'POST',
		headers: {
			'content-type': 'application/json',
			'user-agent': 'DragonGate-Test/1.0',
			...headers,
		},
		body: typeof body === 'string' ? body : JSON.stringify(body),
	});
}

test('normalizeEmail trims and lowercases', () => {
	assert.equal(normalizeEmail('  ZACH@ZYSYS.ORG  '), 'zach@zysys.org');
	assert.equal(normalizeEmail(null), '');
	assert.equal(normalizeEmail(12), '');
});

test('emailIsValid accepts a real address and rejects junk', () => {
	assert.equal(emailIsValid('zach@zysys.org'), true);
	assert.equal(emailIsValid('not-an-email'), false);
	assert.equal(emailIsValid(''), false);
	assert.equal(emailIsValid('missing-tld@host'), false);
});

test('POST valid email stores one JSON record and returns 200', async () => {
	const store = memoryStore();
	const response = await handleWaitlistRequest(
		joinRequest({ email: 'zach@zysys.org' }),
		store,
		CLOCK,
	);
	assert.equal(response.status, 200);
	const body = await response.json();
	assert.equal(body.ok, true);
	assert.equal(body.already, false);
	assert.equal(store.rows.size, 1);
	const stored = JSON.parse(store.rows.get('zach@zysys.org'));
	assert.deepEqual(stored, {
		email: 'zach@zysys.org',
		ts: FIXED_NOW,
		ua: 'DragonGate-Test/1.0',
	});
});

test('POST not-an-email, empty, and missing field are 4xx and write nothing', async () => {
	const cases = [{ email: 'not-an-email' }, { email: '' }, {}, { email: '   ' }];
	for (const payload of cases) {
		const store = memoryStore();
		const response = await handleWaitlistRequest(joinRequest(payload), store, CLOCK);
		assert.equal(response.status >= 400 && response.status < 500, true, JSON.stringify(payload));
		assert.equal(store.rows.size, 0, JSON.stringify(payload));
	}
});

test('same normalized email twice is 200 already on the list with a single key', async () => {
	const store = memoryStore();
	const first = await handleWaitlistRequest(
		joinRequest({ email: 'Zach@Zysys.org' }),
		store,
		CLOCK,
	);
	const second = await handleWaitlistRequest(
		joinRequest({ email: '  zach@zysys.org  ' }),
		store,
		CLOCK,
	);
	assert.equal(first.status, 200);
	assert.equal((await first.json()).already, false);
	assert.equal(second.status, 200);
	const replay = await second.json();
	assert.equal(replay.ok, true);
	assert.equal(replay.already, true);
	assert.match(String(replay.message), /already on the list/i);
	assert.equal(store.rows.size, 1);
	assert.equal(store.rows.has('zach@zysys.org'), true);
});

test('GET health returns 200', async () => {
	const store = memoryStore();
	const response = await handleWaitlistRequest(
		new Request(HEALTH_URL, { method: 'GET' }),
		store,
		CLOCK,
	);
	assert.equal(response.status, 200);
	assert.deepEqual(await response.json(), { ok: true });
	assert.equal(store.rows.size, 0);
});

test('joinWaitlist writes the normalized key only', async () => {
	const store = memoryStore();
	const result = await joinWaitlist(store, {
		email: 'Elena.Varga@Example.com',
		ts: FIXED_NOW,
		ua: 'test',
	});
	assert.equal(result.status, 200);
	assert.equal(store.rows.has('elena.varga@example.com'), true);
});
