/**
 * WordPress REST helpers for authenticated e2e (nonce + cookie jar from page).
 */
const REST_NONCE_URL = '/wp-admin/admin-ajax.php?action=rest-nonce';
const NONCE_MIN_LEN = 5;

/**
 * Fetch a usable X-WP-Nonce from an authenticated admin page.
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<string>}
 */
export async function fetchRestNonce(page) {
	// Prefer wpApiSettings when present on admin screens
	const fromWindow = await page.evaluate(() => {
		// @ts-expect-error WP global
		const n = window.wpApiSettings?.nonce;
		return typeof n === 'string' && n.length > 0 ? n : null;
	});
	if (fromWindow) return fromWindow;

	const text = await page.evaluate(async (url) => {
		const res = await fetch(url, { credentials: 'same-origin' });
		return res.text();
	}, REST_NONCE_URL);

	const nonce = String(text || '').trim();
	if (nonce.length < NONCE_MIN_LEN) {
		throw new Error(`fetchRestNonce: short/empty nonce (${nonce.length} chars)`);
	}
	return nonce;
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} path absolute path starting with /wp-json/
 * @param {{ method?: string, data?: unknown, nonce?: string }} [opts]
 */
export async function restJson(page, path, opts = {}) {
	const method = (opts.method || 'GET').toUpperCase();
	const nonce = opts.nonce || (await fetchRestNonce(page));
	const headers = {
		'X-WP-Nonce': nonce,
	};
	if (opts.data !== undefined) {
		headers['Content-Type'] = 'application/json';
	}

	const response = await page.request.fetch(path, {
		method,
		headers,
		data: opts.data,
	});

	let body = null;
	const raw = await response.text();
	try {
		body = raw ? JSON.parse(raw) : null;
	} catch {
		body = raw;
	}

	return {
		ok: response.ok(),
		status: response.status(),
		body,
		nonce,
	};
}
