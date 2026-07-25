import { test, expect, type Page } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { loadEnv } from '../../tests/support/load-env.mjs';
import { ensureAdminSession } from '../../tests/support/admin-session.mjs';
import { seedPortal } from '../../tests/support/seed.mjs';
import { restJson } from '../../tests/support/wp-rest.mjs';

const env = loadEnv();

const PORTAL_LIST = '/wp-admin/edit.php?post_type=portal';
const PORTAL_LIST_ALL = '/wp-admin/edit.php?post_type=portal&post_status=all';
const PORTAL_TRASH_LIST = '/wp-admin/edit.php?post_status=trash&post_type=portal';
const PORTAL_REST = '/wp-json/wp/v2/portal';

const NAV_ATTEMPTS = 5;
const NAV_TIMEOUT_MS = 90_000;
const UI_TIMEOUT_MS = 45_000;
const RETRY_PAUSE_MS = 1_500;

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
			// Page closed mid-flight under load — rethrow, let Playwright retry the test.
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
 * Find portal row on list (default or "all" status view).
 */
async function openListWithPortal(page: Page, portalId: string): Promise<void> {
	const candidates = [PORTAL_LIST, PORTAL_LIST_ALL];
	let lastError: unknown;
	for (const listUrl of candidates) {
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
		`portal ${portalId} not found on list views: ${
			(lastError as Error)?.message || lastError || 'no row'
		}`,
	);
}

/**
 * Edit portal title via authenticated REST (same admin session as UI).
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
	});
	if (!ok) {
		throw new Error(
			`editPortalTitle ${portalId} failed HTTP ${status}: ${JSON.stringify(body)}`,
		);
	}
}

test.describe('portal admin CRUD matrix', () => {
	test.describe.configure({ retries: 1 });

	test('create → edit → trash via direct action', async ({ page }) => {
		test.setTimeout(180_000);

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

		// EDIT title
		await editPortalTitle(page, portalId, titleEdited);

		// Confirm edit stuck
		const check = await restJson(page, `${PORTAL_REST}/${portalId}?context=edit`, {
			method: 'GET',
		});
		expect(check.ok, JSON.stringify(check.body)).toBeTruthy();
		const rawTitle =
			typeof check.body?.title === 'string'
				? check.body.title
				: check.body?.title?.raw ?? check.body?.title?.rendered ?? '';
		expect(String(rawTitle)).toContain('edited');

		// LIST — edited portal appears in admin
		await openListWithPortal(page, portalId);
		const listRow = page.locator(`#post-${portalId}`);
		await expect(listRow.locator('.row-title').first()).toContainText(
			/dg-e2e-crud|edited/,
			{ timeout: UI_TIMEOUT_MS },
		);

		// TRASH via list row action href (nonce-bearing submitdelete).
		// Do not hover — under LocalWP load rows can sit outside the viewport and
		// hover stalls; the href is already in the DOM.
		const trashHref = await page.evaluate((id) => {
			const row = document.querySelector(
				`#post-${id} a.submitdelete`,
			) as HTMLAnchorElement | null;
			return row?.getAttribute('href') ?? row?.href ?? null;
		}, portalId);
		expect(trashHref, 'trash row action href missing').toBeTruthy();
		// Relative href → absolute for goto
		const trashUrl = new URL(trashHref!, env.baseUrl).toString();
		await gotoWithRetry(page, trashUrl);

		// Confirm in trash list
		await gotoWithRetry(page, PORTAL_TRASH_LIST);
		await expect(page.locator(`#post-${portalId}`)).toBeVisible({
			timeout: UI_TIMEOUT_MS,
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
