/**
 * Portal definition REST facade (dragongate/v1).
 */

/**
 * @param {{ restRoot: string, nonce: string, portalId: number|string }} cfg
 */
export async function getDefinition(cfg) {
	const url = joinUrl(cfg.restRoot, `portals/${cfg.portalId}/definition`);
	const controller = new AbortController();
	const timer = setTimeout(() => controller.abort(), 6000);
	try {
		const res = await fetch(url, {
			method: 'GET',
			credentials: 'same-origin',
			signal: controller.signal,
			headers: {
				'X-WP-Nonce': cfg.nonce,
				Accept: 'application/json',
			},
		});
		const text = await res.text();
		let body;
		try {
			body = text ? JSON.parse(text) : {};
		} catch {
			throw new Error(`Definition GET returned non-JSON (${res.status})`);
		}
		// Auto-draft / new posts often have no definition yet (404 or empty).
		if (res.status === 404 || res.status === 400) {
			return null;
		}
		if (!res.ok) {
			throw new Error(body?.message || `Definition GET failed (${res.status})`);
		}
		return body.definition ?? null;
	} catch (e) {
		if (e instanceof Error && e.name === 'AbortError') {
			return null;
		}
		throw e;
	} finally {
		clearTimeout(timer);
	}
}

/**
 * @param {{ restRoot: string, nonce: string, portalId: number|string, definition: object }} cfg
 */
export async function saveDefinition(cfg) {
	const url = joinUrl(cfg.restRoot, `portals/${cfg.portalId}/definition`);
	const res = await fetch(url, {
		method: 'POST',
		credentials: 'same-origin',
		headers: {
			'X-WP-Nonce': cfg.nonce,
			'Content-Type': 'application/json',
			Accept: 'application/json',
		},
		body: JSON.stringify({ definition: cfg.definition }),
	});
	const text = await res.text();
	let body;
	try {
		body = text ? JSON.parse(text) : {};
	} catch {
		throw new Error(`Definition save returned non-JSON (${res.status})`);
	}
	if (!res.ok) {
		const msg =
			body?.message ||
			(Array.isArray(body?.data?.params) ? JSON.stringify(body.data) : null) ||
			`Definition save failed (${res.status})`;
		throw new Error(typeof msg === 'string' ? msg : JSON.stringify(msg));
	}
	return body.definition;
}

/**
 * List published portals for clone cards (WP core REST).
 * @param {{ wpRestRoot: string, nonce: string }} cfg
 */
export async function listPortalTemplates(cfg) {
	const url = joinUrl(
		cfg.wpRestRoot,
		'wp/v2/portal?per_page=12&status=publish&_fields=id,title',
	);
	const res = await fetch(url, {
		method: 'GET',
		credentials: 'same-origin',
		headers: {
			'X-WP-Nonce': cfg.nonce,
			Accept: 'application/json',
		},
	});
	if (!res.ok) {
		return [];
	}
	const rows = await res.json();
	if (!Array.isArray(rows)) {
		return [];
	}
	return rows.map((r) => ({
		id: r.id,
		title: r.title?.rendered ? decodeEntities(r.title.rendered) : `Portal ${r.id}`,
	}));
}

/**
 * Update WP portal post title / status (core REST).
 * @param {{ wpRestRoot: string, nonce: string, portalId: number|string, title?: string, status?: string }} cfg
 */
export async function updatePortalPost(cfg) {
	const url = joinUrl(cfg.wpRestRoot, `wp/v2/portal/${cfg.portalId}`);
	/** @type {Record<string, string>} */
	const body = {};
	if (cfg.title != null && String(cfg.title).trim() !== '') {
		body.title = String(cfg.title).trim();
	}
	if (cfg.status) {
		body.status = cfg.status;
	}
	const res = await fetch(url, {
		method: 'POST',
		credentials: 'same-origin',
		headers: {
			'X-WP-Nonce': cfg.nonce,
			'Content-Type': 'application/json',
			Accept: 'application/json',
		},
		body: JSON.stringify(body),
	});
	const text = await res.text();
	let data;
	try {
		data = text ? JSON.parse(text) : {};
	} catch {
		throw new Error(`Portal update returned non-JSON (${res.status})`);
	}
	if (!res.ok) {
		throw new Error(data?.message || `Portal update failed (${res.status})`);
	}
	return data;
}

/**
 * Join a REST root and a path without dropping or doubling the slash.
 * Empty base falls back to `/wp-json/`.
 *
 * @param {string} [base]
 * @param {string} [path]
 * @returns {string}
 */
export function joinUrl(base, path) {
	const root = String(base || '/wp-json/').replace(/\/+$/, '');
	const suffix = String(path || '').replace(/^\/+/, '');
	return `${root}/${suffix}`;
}

/**
 * @param {string} html
 */
function decodeEntities(html) {
	const el = document.createElement('textarea');
	el.innerHTML = html;
	return el.value;
}
