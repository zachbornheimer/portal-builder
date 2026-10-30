/**
 * Portal records & invitations REST facade (dragongate/v1).
 */
import { joinUrl } from './definitionApi.js';

/**
 * @typedef {{ restRoot: string, nonce: string, portalId: number|string }} RecordsApiConfig
 */

/** @param {RecordsApiConfig} cfg @param {string} path */
function portalUrl(cfg, path) {
	if (
		!Number.isSafeInteger(Number(cfg?.portalId)) || Number(cfg.portalId) <= 0 ||
		typeof cfg?.restRoot !== 'string' || !cfg.restRoot.trim() ||
		typeof cfg?.nonce !== 'string' || !cfg.nonce.trim()
	) {
		throw new Error('Missing portal id or REST credentials.');
	}
	return joinUrl(cfg.restRoot, `portals/${cfg.portalId}/${path}`);
}

/** Describe response shape without exposing string contents or applicant data. */
function responseType(value) {
	if (value === undefined) return 'missing';
	if (value === null) return 'null';
	return Array.isArray(value) ? 'array' : typeof value;
}

/** A list response must not turn a malformed success into an empty state. */
function responseRows(body, key, label) {
	if (!body || typeof body !== 'object' || Array.isArray(body)) {
		throw new Error(`${label} returned an invalid response. Expected response object; received ${responseType(body)}.`);
	}
	const rows = body[key];
	if (!Array.isArray(rows)) {
		throw new Error(`${label} returned an invalid response. Expected ${key} array; received ${responseType(rows)}.`);
	}
	const invalidIndex = rows.findIndex((row) => !row || typeof row !== 'object' || Array.isArray(row));
	if (invalidIndex !== -1) {
		throw new Error(`${label} returned an invalid response. Expected ${key}[${invalidIndex}] object; received ${responseType(rows[invalidIndex])}.`);
	}
	return rows;
}

/**
 * Fetch and parse a JSON REST response in the definitionApi style.
 *
 * @param {string} url
 * @param {{ method?: string, nonce: string, body?: unknown, label: string }} opts
 */
async function requestJson(url, opts) {
	const multipart = opts.body instanceof FormData;
	const res = await fetch(url, {
		method: opts.method || 'GET',
		credentials: 'same-origin',
		headers: {
			'X-WP-Nonce': opts.nonce,
			Accept: 'application/json',
			...(opts.body !== undefined && !multipart ? { 'Content-Type': 'application/json' } : {}),
		},
		body: multipart ? opts.body : opts.body !== undefined ? JSON.stringify(opts.body) : undefined,
	});
	const text = await res.text();
	let body;
	try {
		body = text ? JSON.parse(text) : {};
	} catch {
		throw new Error(`${opts.label} returned non-JSON (${res.status})`);
	}
	if (!res.ok) {
		throw new Error(body?.message || `${opts.label} failed (${res.status})`);
	}
	return body;
}

/**
 * Most recent submission records, newest first.
 *
 * @param {RecordsApiConfig & { limit?: number }} cfg
 * @returns {Promise<{ portalId: number, total: number, records: object[] }>}
 */
export async function getRecords(cfg) {
	const limit = Number(cfg.limit) > 0 ? Number(cfg.limit) : 5;
	const url = portalUrl(cfg, `records?limit=${limit}`);
	const body = await requestJson(url, { nonce: cfg.nonce, label: 'Records GET' });
	const records = responseRows(body, 'records', 'Records GET');
	if (body.portalId !== Number(cfg.portalId)) {
		const received = typeof body.portalId === 'number' ? body.portalId : responseType(body.portalId);
		throw new Error(`Records GET returned an invalid response. Expected portalId ${Number(cfg.portalId)}; received ${received}.`);
	}
	if (!Number.isSafeInteger(body.total) || body.total < 0) {
		const received = typeof body.total === 'number' ? body.total : responseType(body.total);
		throw new Error(`Records GET returned an invalid response. Expected non-negative integer total; received ${received}.`);
	}
	if (body.total < records.length || (body.total > 0 && records.length === 0)) {
		throw new Error(`Records GET returned an invalid response. Inconsistent counts: total ${body.total}, records length ${records.length}.`);
	}
	const invalidIndex = records.findIndex((row) => typeof row.applicationId !== 'string' || !row.applicationId.trim());
	if (invalidIndex !== -1) {
		const identity = records[invalidIndex].applicationId;
		const received = typeof identity === 'string' ? 'blank string' : responseType(identity);
		throw new Error(`Records GET returned an invalid response. Expected records[${invalidIndex}].applicationId non-blank string; received ${received}.`);
	}
	return { portalId: body.portalId, total: body.total, records };
}

/**
 * Upload replacement bytes directly to the packet destination (not staging).
 * @param {RecordsApiConfig & { applicationId: string, fieldId: string, file: File }} cfg
 */
export async function replacePacketFile(cfg) {
	const body = new FormData();
	body.append('field_id', cfg.fieldId);
	body.append('file', cfg.file);
	const url = portalUrl(cfg, `packets/${encodeURIComponent(cfg.applicationId)}/replace`);
	return requestJson(url, { method: 'POST', nonce: cfg.nonce, body, label: 'File replacement' });
}

/**
 * Recall a packet; the route needs no request body.
 * @param {RecordsApiConfig & { applicationId: string }} cfg
 */
export async function recallPacket(cfg) {
	const url = portalUrl(cfg, `packets/${encodeURIComponent(cfg.applicationId)}/recall`);
	return requestJson(url, { method: 'POST', nonce: cfg.nonce, label: 'Packet recall' });
}

/**
 * Existing invitations for the portal.
 *
 * @param {RecordsApiConfig} cfg
 * @returns {Promise<object[]>}
 */
export async function listInvites(cfg) {
	const url = portalUrl(cfg, 'invites');
	const body = await requestJson(url, { nonce: cfg.nonce, label: 'Invites GET' });
	return responseRows(body, 'invites', 'Invites GET');
}

/**
 * Create (or resend) an invitation.
 *
 * @param {RecordsApiConfig & { email: string, name?: string, userId?: number, expiresInDays?: number }} cfg
 * @returns {Promise<{ invite: object, resent?: boolean }>}
 */
export async function createInvite(cfg) {
	const url = portalUrl(cfg, 'invites');
	/** @type {Record<string, unknown>} */
	const payload = { email: cfg.email };
	if (cfg.name) payload.name = cfg.name;
	if (cfg.userId) payload.userId = cfg.userId;
	if (cfg.expiresInDays) payload.expiresInDays = cfg.expiresInDays;
	return requestJson(url, {
		method: 'POST',
		nonce: cfg.nonce,
		body: payload,
		label: 'Invite create',
	});
}

/**
 * Resend an existing invitation.
 *
 * @param {RecordsApiConfig & { inviteId: number|string }} cfg
 * @returns {Promise<{ invite: object }>}
 */
export async function resendInvite(cfg) {
	const url = portalUrl(cfg, `invites/${cfg.inviteId}/resend`);
	return requestJson(url, {
		method: 'POST',
		nonce: cfg.nonce,
		body: {},
		label: 'Invite resend',
	});
}

/**
 * Revoke an invitation.
 *
 * @param {RecordsApiConfig & { inviteId: number|string }} cfg
 * @returns {Promise<{ revoked: boolean }>}
 */
export async function revokeInvite(cfg) {
	const url = portalUrl(cfg, `invites/${cfg.inviteId}`);
	return requestJson(url, { method: 'DELETE', nonce: cfg.nonce, label: 'Invite revoke' });
}

/**
 * Search eligible WordPress users for an invitation.
 *
 * @param {RecordsApiConfig & { search?: string, limit?: number }} cfg
 * @returns {Promise<object[]>}
 */
export async function searchInviteCandidates(cfg) {
	const params = new URLSearchParams();
	if (cfg.search) params.set('search', cfg.search);
	params.set('limit', String(cfg.limit > 0 ? cfg.limit : 20));
	const url = portalUrl(cfg, `invite-candidates?${params}`);
	const body = await requestJson(url, { nonce: cfg.nonce, label: 'Invite candidates GET' });
	return responseRows(body, 'candidates', 'Invite candidates GET');
}
