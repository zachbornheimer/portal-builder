/**
 * Waitlist join: normalize, validate, store once.
 * KV is injected — this module does not talk to Cloudflare.
 */

export const WAITLIST_PATH = '/api/waitlist';
export const WAITLIST_HEALTH_PATH = '/api/waitlist/health';

const MAX_EMAIL_LENGTH = 254;
const MAX_USER_AGENT_LENGTH = 512;
const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

const JSON_HEADERS = {
	'content-type': 'application/json; charset=utf-8',
	'cache-control': 'no-store',
};

const MESSAGE = {
	invalidEmail: 'Enter a valid email address.',
	invalidJson: 'Send JSON with an email field.',
	alreadyOnList: 'already on the list',
	joined: "You're on the list.",
	methodNotAllowed: 'Method not allowed.',
	notFound: 'Not found.',
};

/**
 * @param {unknown} raw
 * @returns {string}
 */
export function normalizeEmail(raw) {
	if (typeof raw !== 'string') {
		return '';
	}
	return raw.trim().toLowerCase();
}

/**
 * @param {string} email already normalized
 * @returns {boolean}
 */
export function emailIsValid(email) {
	return (
		email.length > 0 &&
		email.length <= MAX_EMAIL_LENGTH &&
		EMAIL_PATTERN.test(email)
	);
}

/**
 * @typedef {{ get: (key: string) => Promise<string | null>, put: (key: string, value: string) => Promise<void> }} Store
 * @typedef {{ email?: unknown, ts: string, ua: string }} JoinInput
 * @typedef {{ status: number, body: Record<string, unknown> }} JoinResult
 */

/**
 * @param {Store} store
 * @param {JoinInput} input
 * @returns {Promise<JoinResult>}
 */
export async function joinWaitlist(store, input) {
	const email = normalizeEmail(input.email);
	if (!emailIsValid(email)) {
		return {
			status: 400,
			body: { ok: false, code: 'invalid_email', error: MESSAGE.invalidEmail },
		};
	}

	const existing = await store.get(email);
	if (existing !== null && existing !== undefined) {
		return {
			status: 200,
			body: { ok: true, already: true, message: MESSAGE.alreadyOnList },
		};
	}

	await store.put(
		email,
		JSON.stringify({
			email,
			ts: input.ts,
			ua: clipUserAgent(input.ua),
		}),
	);

	return {
		status: 200,
		body: { ok: true, already: false, message: MESSAGE.joined },
	};
}

/**
 * @param {Request} request
 * @param {Store} store
 * @param {{ now: () => string }} [clock]
 * @returns {Promise<Response>}
 */
export async function handleWaitlistRequest(request, store, clock = defaultClock) {
	const path = pathnameWithoutTrailingSlash(new URL(request.url).pathname);

	if (path === WAITLIST_HEALTH_PATH) {
		if (request.method !== 'GET') {
			return jsonResponse(405, {
				ok: false,
				code: 'method_not_allowed',
				error: MESSAGE.methodNotAllowed,
			});
		}
		return jsonResponse(200, { ok: true });
	}

	if (path === WAITLIST_PATH) {
		if (request.method !== 'POST') {
			return jsonResponse(405, {
				ok: false,
				code: 'method_not_allowed',
				error: MESSAGE.methodNotAllowed,
			});
		}
		return respondToJoin(request, store, clock);
	}

	return jsonResponse(404, {
		ok: false,
		code: 'not_found',
		error: MESSAGE.notFound,
	});
}

/**
 * @param {Request} request
 * @param {Store} store
 * @param {{ now: () => string }} clock
 * @returns {Promise<Response>}
 */
async function respondToJoin(request, store, clock) {
	let payload;
	try {
		payload = await request.json();
	} catch {
		return jsonResponse(400, {
			ok: false,
			code: 'invalid_json',
			error: MESSAGE.invalidJson,
		});
	}

	const result = await joinWaitlist(store, {
		email: payload && typeof payload === 'object' ? payload.email : undefined,
		ts: clock.now(),
		ua: request.headers.get('user-agent') ?? '',
	});
	return jsonResponse(result.status, result.body);
}

/**
 * @param {string} pathname
 * @returns {string}
 */
export function pathnameWithoutTrailingSlash(pathname) {
	if (pathname.length > 1 && pathname.endsWith('/')) {
		return pathname.slice(0, -1);
	}
	return pathname;
}

/**
 * @param {string} ua
 * @returns {string}
 */
function clipUserAgent(ua) {
	if (typeof ua !== 'string') {
		return '';
	}
	return ua.slice(0, MAX_USER_AGENT_LENGTH);
}

/**
 * @param {number} status
 * @param {Record<string, unknown>} body
 * @returns {Response}
 */
function jsonResponse(status, body) {
	return new Response(JSON.stringify(body), { status, headers: JSON_HEADERS });
}

const defaultClock = {
	now: () => new Date().toISOString(),
};
