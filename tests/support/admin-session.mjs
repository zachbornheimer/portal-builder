/**
 * Admin session facade — form login (adminUser/adminPass) or LocalWP auto-login URL.
 */
import { loadEnv } from './load-env.mjs';

const ADMIN_URL_PATTERN = /wp-admin/;
const LOGIN_URL_PATTERN = /wp-login\.php|\/login/;
const DEFAULT_LOGIN_TIMEOUT_MS = 45_000;
const MAX_LOGIN_ATTEMPTS = 3;

/**
 * WordPress form login when harness env provides adminUser + adminPass.
 *
 * @param {import('@playwright/test').Page} page
 * @param {ReturnType<typeof loadEnv>} env
 * @param {number} timeoutMs
 */
async function formLogin(page, env, timeoutMs) {
	const base = env.baseUrl.replace(/\/$/, '');
	const adminHome = `${base}/wp-admin/`;
	const loginPage = `${base}/wp-login.php?redirect_to=${encodeURIComponent(adminHome)}`;

	await page.goto(loginPage, { waitUntil: 'domcontentloaded', timeout: timeoutMs });

	// Already authenticated — WP may bounce to admin.
	if (ADMIN_URL_PATTERN.test(page.url()) && !LOGIN_URL_PATTERN.test(page.url())) {
		if (await page.locator('#wpadminbar').count()) {
			return;
		}
	}

	const userField = page.locator('#user_login, input[name="log"]').first();
	const passField = page.locator('#user_pass, input[name="pwd"]').first();
	const submit = page.locator('#wp-submit, input[type="submit"][name="wp-submit"], button[type="submit"]').first();

	await userField.waitFor({ state: 'visible', timeout: timeoutMs });
	await userField.fill(String(env.adminUser));
	await passField.fill(String(env.adminPass));
	await submit.click();

	// Wait for either admin shell or a redirect finish.
	await page.waitForLoadState('domcontentloaded', { timeout: timeoutMs }).catch(() => {});
	if (!ADMIN_URL_PATTERN.test(page.url()) || LOGIN_URL_PATTERN.test(page.url())) {
		await page.goto(adminHome, { waitUntil: 'domcontentloaded', timeout: timeoutMs });
	}
	await page.locator('#wpadminbar, #adminmenu').first().waitFor({
		state: 'visible',
		timeout: timeoutMs,
	});
}

/**
 * Navigate to auto-login URL and wait until an admin session is established.
 * Prefers adminUser/adminPass form login when configured; otherwise autoLoginUrl.
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
	const useForm = Boolean(env.adminUser && env.adminPass);

	let lastError;
	for (let attempt = 1; attempt <= MAX_LOGIN_ATTEMPTS; attempt++) {
		try {
			if (useForm) {
				await formLogin(page, env, timeoutMs);
			} else {
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
			}

			// Confirm session cookie is usable by hitting admin home
			if (!/wp-admin\/?$|wp-admin\/index\.php|wp-admin\/\?/.test(page.url())) {
				await page.goto(adminHome, {
					waitUntil: 'domcontentloaded',
					timeout: timeoutMs,
				}).catch(() => {});
			}

			// Reject login screen masquerading as success
			if (ADMIN_URL_PATTERN.test(page.url()) && !LOGIN_URL_PATTERN.test(page.url())) {
				const bodyClass = await page.locator('body').getAttribute('class').catch(() => '');
				if (bodyClass && /wp-admin|folded|auto-fold/.test(bodyClass)) {
					return env;
				}
				// Some admin screens omit those classes; presence of #wpadminbar is enough.
				if (await page.locator('#wpadminbar').count()) {
					return env;
				}
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
