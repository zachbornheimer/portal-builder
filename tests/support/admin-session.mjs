/**
 * Admin session facade — auto-login via LocalWP URL from harness env.
 */
import { loadEnv } from './load-env.mjs';

const ADMIN_URL_PATTERN = /wp-admin/;
const DEFAULT_LOGIN_TIMEOUT_MS = 45_000;
const MAX_LOGIN_ATTEMPTS = 3;

/**
 * Navigate to auto-login URL and wait until an admin session is established.
 * LocalWP often redirects mid-navigation (ERR_ABORTED); we retry.
 *
 * @param {import('@playwright/test').Page} page
 * @param {{ timeoutMs?: number, env?: ReturnType<typeof loadEnv> }} [opts]
 */
export async function ensureAdminSession(page, opts = {}) {
	const env = opts.env || loadEnv();
	const timeoutMs = opts.timeoutMs ?? DEFAULT_LOGIN_TIMEOUT_MS;
	const loginUrl = env.autoLoginUrl || `${env.baseUrl.replace(/\/$/, '')}/wp-admin/`;
	const adminHome = `${env.baseUrl.replace(/\/$/, '')}/wp-admin/`;

	let lastError;
	for (let attempt = 1; attempt <= MAX_LOGIN_ATTEMPTS; attempt++) {
		try {
			await page.goto(loginUrl, {
				waitUntil: 'domcontentloaded',
				timeout: timeoutMs,
			}).catch(async (err) => {
				// Redirect races often surface as ERR_ABORTED; if we already landed on wp-admin, continue.
				if (/ERR_ABORTED/i.test(String(err)) && ADMIN_URL_PATTERN.test(page.url())) {
					return;
				}
				throw err;
			});

			if (!ADMIN_URL_PATTERN.test(page.url())) {
				await page.waitForURL(ADMIN_URL_PATTERN, { timeout: timeoutMs });
			}

			// Confirm session cookie is usable by hitting admin home
			if (!/wp-admin\/?$|wp-admin\/index\.php|wp-admin\/\?/.test(page.url())) {
				await page.goto(adminHome, {
					waitUntil: 'domcontentloaded',
					timeout: timeoutMs,
				}).catch(() => {});
			}

			if (ADMIN_URL_PATTERN.test(page.url())) {
				return env;
			}
			lastError = new Error(`login attempt ${attempt}: landed on ${page.url()}`);
		} catch (err) {
			lastError = err;
			// brief pause before retry
			await page.waitForTimeout(500 * attempt);
		}
	}

	throw new Error(
		`ensureAdminSession failed after ${MAX_LOGIN_ATTEMPTS} attempts: ${lastError?.message || lastError}`,
	);
}

/**
 * Capture storage state after auto-login (for Playwright projects / re-use).
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} outPath absolute path for storageState JSON
 */
export async function writeAdminStorageState(page, outPath) {
	await ensureAdminSession(page);
	await page.context().storageState({ path: outPath });
	return outPath;
}
