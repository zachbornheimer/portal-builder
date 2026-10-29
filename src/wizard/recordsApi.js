/**
 * Portal records & invitations REST facade (dragongate/v1).
 */
import { joinUrl } from './definitionApi.js';

/**
 * @typedef {{ restRoot: string, nonce: string, portalId: number|string }} RecordsApiConfig
 */

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
	const url = joinUrl(cfg.restRoot, `portals/${cfg.portalId}/records?limit=${limit}`);
	const body = await requestJson(url, { nonce: cfg.nonce, label: 'Records GET' });
	return {
		portalId: body?.portalId ?? cfg.portalId,
		total: Number(body?.total) || 0,
		records: Array.isArray(body?.records) ? body.records : [],
	};
}

/**
 * Upload replacement bytes directly to the packet destination (not staging).
 * @param {RecordsApiConfig & { applicationId: string, fieldId: string, file: File }} cfg
 */
export async function replacePacketFile(cfg) {
	const body = new FormData();
	body.append('field_id', cfg.fieldId);
	body.append('file', cfg.file);
	const url = joinUrl(cfg.restRoot, `portals/${cfg.portalId}/packets/${encodeURIComponent(cfg.applicationId)}/replace`);
	return requestJson(url, { method: 'POST', nonce: cfg.nonce, body, label: 'File replacement' });
}

/**
 * Recall a packet; the route needs no request body.
 * @param {RecordsApiConfig & { applicationId: string }} cfg
 */
export async function recallPacket(cfg) {
	const url = joinUrl(cfg.restRoot, `portals/${cfg.portalId}/packets/${encodeURIComponent(cfg.applicationId)}/recall`);
	return requestJson(url, { method: 'POST', nonce: cfg.nonce, label: 'Packet recall' });
}

/**
 * Existing invitations for the portal.
 *
 * @param {RecordsApiConfig} cfg
 * @returns {Promise<object[]>}
 */
export async function listInvites(cfg) {
	const url = joinUrl(cfg.restRoot, `portals/${cfg.portalId}/invites`);
	const body = await requestJson(url, { nonce: cfg.nonce, label: 'Invites GET' });
	return Array.isArray(body?.invites) ? body.invites : [];
}

/**
 * Create (or resend) an invitation.
 *
 * @param {RecordsApiConfig & { email: string, name?: string, userId?: number, expiresInDays?: number }} cfg
 * @returns {Promise<{ invite: object, resent?: boolean }>}
 */
export async function createInvite(cfg) {
	const url = joinUrl(cfg.restRoot, `portals/${cfg.portalId}/invites`);
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
	const url = joinUrl(cfg.restRoot, `portals/${cfg.portalId}/invites/${cfg.inviteId}/resend`);
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
	const url = joinUrl(cfg.restRoot, `portals/${cfg.portalId}/invites/${cfg.inviteId}`);
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
	const url = joinUrl(cfg.restRoot, `portals/${cfg.portalId}/invite-candidates?${params}`);
	const body = await requestJson(url, { nonce: cfg.nonce, label: 'Invite candidates GET' });
	return Array.isArray(body?.candidates) ? body.candidates : [];
}
