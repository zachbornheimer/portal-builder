import { test, expect, type Page } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { loadEnv } from '../../tests/support/load-env.mjs';
import { ensureAdminSession } from '../../tests/support/admin-session.mjs';
import { seedPortal } from '../../tests/support/seed.mjs';
import { restJson } from '../../tests/support/wp-rest.mjs';

const env = loadEnv();

const PORTAL_LIST = '/wp-admin/edit.php?post_type=portal';
const PORTAL_TRASH_LIST = '/wp-admin/edit.php?post_status=trash&post_type=portal';
const PORTAL_REST = '/wp-json/wp/v2/portal';

const NAV_ATTEMPTS = 4;
const NAV_TIMEOUT_MS = 60_000;
const UI_TIMEOUT_MS = 30_000;
const RETRY_PAUSE_MS = 1_000;
/** REST is fast when LocalWP is healthy; keep budget tight so hangs fail fast. */
const REST_TIMEOUT_MS = 45_000;

/**
 * Navigate with retries — LocalWP under load often ERR_ABORTED on first try.
 */
async function gotoWithRetry(page: Page, url: string): Promise<void> {
	let lastError: unknown;
	for (let attempt = 1; attempt <= NAV_ATTEMPTS; attempt++) {
		try {
			await page.goto(url, {
				waitUntil: 'domcontentloaded',
				timeout: NAV_TIMEOUT_MS,
			});
			return;
		} catch (err) {
			lastError = err;
			const message = String(err);
			const pathOnly = url.split('?')[0] ?? url;
			if (/ERR_ABORTED/i.test(message) && page.url().includes(pathOnly)) {
				return;
			}
			if (/has been closed|Target page/i.test(message)) {
				throw err;
			}
			await page.waitForTimeout(RETRY_PAUSE_MS * attempt);
		}
	}
	throw new Error(
		`gotoWithRetry failed for ${url} after ${NAV_ATTEMPTS} attempts: ${
			(lastError as Error)?.message || lastError
		}`,
	);
}

/**
 * Admin list URL for portals, optionally filtered by search term + status.
 */
function portalListUrl(opts: {
	search?: string;
	status?: 'all' | 'trash' | 'publish';
}): string {
	const params = new URLSearchParams({ post_type: 'portal' });
	if (opts.status === 'trash') {
		params.set('post_status', 'trash');
	} else if (opts.status === 'publish') {
		params.set('post_status', 'publish');
	} else if (opts.status === 'all') {
		params.set('post_status', 'all');
	}
	if (opts.search) {
		params.set('s', opts.search);
	}
	return `/wp-admin/edit.php?${params.toString()}`;
}

/**
 * Open list (or trash) filtered by unique title so pagination cannot hide the row.
 */
async function openListRow(
	page: Page,
	portalId: string,
	opts: { search: string; status?: 'all' | 'trash' | 'publish' },
): Promise<void> {
	const urls = [
		portalListUrl({ search: opts.search, status: opts.status }),
		// Fallbacks without search / alternate status
		opts.status === 'trash' ? PORTAL_TRASH_LIST : PORTAL_LIST,
		portalListUrl({ search: opts.search, status: 'all' }),
	];
	let lastError: unknown;
	for (const listUrl of urls) {
		for (let attempt = 1; attempt <= NAV_ATTEMPTS; attempt++) {
			try {
				await gotoWithRetry(page, listUrl);
				const row = page.locator(`#post-${portalId}`);
				if ((await row.count()) > 0) {
					await expect(row).toBeVisible({ timeout: UI_TIMEOUT_MS });
					return;
				}
			} catch (err) {
				lastError = err;
				if (/has been closed|Target page/i.test(String(err))) throw err;
				await page.waitForTimeout(RETRY_PAUSE_MS * attempt);
			}
		}
	}
	throw new Error(
		`portal ${portalId} not found on list (search=${opts.search} status=${
			opts.status ?? 'default'
		}): ${(lastError as Error)?.message || lastError || 'no row'}`,
	);
}

/**
 * Edit portal title via authenticated REST.
 * Block-editor Save is flaky under LocalWP headless load; REST is the durable write path.
 */
async function editPortalTitle(
	page: Page,
	portalId: string,
	title: string,
): Promise<void> {
	const { ok, status, body } = await restJson(page, `${PORTAL_REST}/${portalId}`, {
		method: 'POST',
		data: { title },
		timeoutMs: REST_TIMEOUT_MS,
		attempts: 2,
	});
	if (!ok) {
		throw new Error(
			`editPortalTitle ${portalId} failed HTTP ${status}: ${JSON.stringify(body)}`,
		);
	}
}

/**
 * Soft-trash portal via REST DELETE.
 */
async function trashPortal(page: Page, portalId: string): Promise<void> {
	const { ok, status, body } = await restJson(page, `${PORTAL_REST}/${portalId}`, {
		method: 'DELETE',
		timeoutMs: REST_TIMEOUT_MS,
		attempts: 2,
	});
	if (!ok) {
		throw new Error(
			`trashPortal ${portalId} failed HTTP ${status}: ${JSON.stringify(body)}`,
		);
	}
	const bodyStatus =
		body && typeof body === 'object' && 'status' in body
			? String((body as { status?: string }).status)
			: '';
	if (bodyStatus && bodyStatus !== 'trash') {
		throw new Error(
			`trashPortal ${portalId} expected status=trash, got ${bodyStatus}`,
		);
	}
}

function titleFromRestBody(body: unknown): string {
	if (!body || typeof body !== 'object') return '';
	const title = (body as { title?: unknown }).title;
	if (typeof title === 'string') return title;
	if (title && typeof title === 'object') {
		const t = title as { raw?: string; rendered?: string };
		return String(t.raw ?? t.rendered ?? '');
	}
	return '';
}

test.describe('portal admin CRUD matrix', () => {
	test.describe.configure({ retries: 1 });

	test('create → edit → trash via direct action', async ({ page }) => {
		// Login + heavy admin list pages need headroom under LocalWP.
		test.setTimeout(240_000);

		await ensureAdminSession(page, { env, timeoutMs: 60_000 });

		const stamp = Date.now();
		const title = `${env.testPortalPrefix}crud-${stamp}`;
		const titleEdited = `${title}-edited`;

		// CREATE — authenticated REST seed (show_in_rest portal CPT)
		const seeded = await seedPortal(page, {
			env,
			skipLogin: true,
			title,
			label: 'crud',
		});
		const portalId = seeded.id;
		expect(portalId).toMatch(/^\d+$/);

		// EDIT title via REST
		await editPortalTitle(page, portalId, titleEdited);

		// Confirm edit stuck (REST)
		const check = await restJson(
			page,
			`${PORTAL_REST}/${portalId}?context=edit`,
			{ method: 'GET', timeoutMs: REST_TIMEOUT_MS, attempts: 2 },
		);
		expect(check.ok, JSON.stringify(check.body)).toBeTruthy();
		expect(titleFromRestBody(check.body)).toContain('edited');

		// LIST — search by unique edited title so pagination cannot hide the row
		await openListRow(page, portalId, {
			search: titleEdited,
			status: 'publish',
		});
		const listRow = page.locator(`#post-${portalId}`);
		await expect(listRow.locator('.row-title').first()).toContainText(
			/edited/,
			{ timeout: UI_TIMEOUT_MS },
		);

		// TRASH via REST
		await trashPortal(page, portalId);

		// Confirm in trash list (search by title)
		await openListRow(page, portalId, {
			search: titleEdited,
			status: 'trash',
		});

		// Artifact
		fs.mkdirSync(env.artifactDirAbs, { recursive: true });
		const artifactPath = path.join(
			env.artifactDirAbs,
			`portal-crud-${stamp}.json`,
		);
		const payload = {
			ok: true,
			portalId,
			title: titleEdited,
			at: new Date().toISOString(),
		};
		fs.writeFileSync(artifactPath, JSON.stringify(payload, null, 2));
		expect(fs.existsSync(artifactPath)).toBeTruthy();
	});
});
