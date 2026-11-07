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

/** Default REST call budget — LocalWP + heavy plugins can exceed actionTimeout. */
const DEFAULT_REST_TIMEOUT_MS = 45_000;
const REST_ATTEMPTS = 2;
const REST_RETRY_PAUSE_MS = 1_000;

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} path absolute path starting with /wp-json/
 * @param {{ method?: string, data?: unknown, nonce?: string, timeoutMs?: number, attempts?: number }} [opts]
 */
export async function restJson(page, path, opts = {}) {
	const method = (opts.method || 'GET').toUpperCase();
	const timeout = opts.timeoutMs ?? DEFAULT_REST_TIMEOUT_MS;
	const attempts = opts.attempts ?? REST_ATTEMPTS;
	let nonce = opts.nonce || (await fetchRestNonce(page));
	let lastError;

	for (let attempt = 1; attempt <= attempts; attempt++) {
		const headers = {
			'X-WP-Nonce': nonce,
		};
		if (opts.data !== undefined) {
			headers['Content-Type'] = 'application/json';
		}

		try {
			const response = await page.request.fetch(path, {
				method,
				headers,
				data: opts.data,
				timeout,
			});

			let body = null;
			const raw = await response.text();
			try {
				body = raw ? JSON.parse(raw) : null;
			} catch {
				body = raw;
			}

			// Retry soft auth failures with a fresh nonce (session cookie still good).
			const code = body && typeof body === 'object' ? body.code : null;
			if (
				!response.ok() &&
				attempt < attempts &&
				(response.status() === 401 ||
					response.status() === 403 ||
					code === 'rest_cookie_invalid_nonce')
			) {
				nonce = await fetchRestNonce(page);
				await page.waitForTimeout(REST_RETRY_PAUSE_MS * attempt);
				continue;
			}

			return {
				ok: response.ok(),
				status: response.status(),
				body,
				nonce,
			};
		} catch (err) {
			lastError = err;
			const msg = String(err);
			if (/has been closed|Target page/i.test(msg)) throw err;
			if (attempt < attempts) {
				// Refresh nonce in case the hung request left state mid-flight.
				try {
					nonce = await fetchRestNonce(page);
				} catch {
					/* keep previous nonce */
				}
				await page.waitForTimeout(REST_RETRY_PAUSE_MS * attempt);
				continue;
			}
		}
	}

	throw new Error(
		`restJson ${method} ${path} failed after ${attempts} attempts: ${
			lastError?.message || lastError
		}`,
	);
}
