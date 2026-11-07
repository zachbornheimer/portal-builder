import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { loadEnv } from '../tests/support/load-env.mjs';
import { ensureAdminSession } from '../tests/support/admin-session.mjs';
import { seedPortal } from '../tests/support/seed.mjs';
import { cleanupPortal } from '../tests/support/cleanup.mjs';

const env = loadEnv();
const PORTALS_MENU = '#adminmenu a[href*="post_type=portal"]';
const PORTALS_LIST = '/wp-admin/edit.php?post_type=portal';
const MENU_TIMEOUT_MS = 15_000;

test.describe('harness smoke', () => {
	test('login → Portals list → seed appears → cleanup removes it', async ({ page }) => {
		// 1. Auto-login
		await ensureAdminSession(page, { env });
		await expect(page.locator('body')).toHaveClass(/wp-admin/);

		// 2. Portals menu visible
		const portalsMenu = page.locator(PORTALS_MENU).first();
		await expect(portalsMenu).toBeVisible({ timeout: MENU_TIMEOUT_MS });
		await portalsMenu.click();
		await page.waitForURL(/post_type=portal/);
		await expect(page.locator('h1, .wp-heading-inline').first()).toContainText(/Portal/i);

		// 3. Seed a tagged portal
		const seeded = await seedPortal(page, { env, skipLogin: true, label: 'smoke' });
		expect(seeded.id).toMatch(/^\d+$/);
		expect(seeded.title.startsWith(env.testPortalPrefix)).toBeTruthy();

		// 4. Seed appears on list
		await page.goto(PORTALS_LIST, { waitUntil: 'domcontentloaded' });
		const row = page.locator(`#post-${seeded.id}`);
		await expect(row).toBeVisible({ timeout: MENU_TIMEOUT_MS });
		await expect(row.locator('.row-title').first()).toContainText(seeded.title);

		// 5. Cleanup removes it
		await cleanupPortal(page, seeded.id, { env, skipLogin: true });
		await page.goto(PORTALS_LIST, { waitUntil: 'domcontentloaded' });
		await expect(page.locator(`#post-${seeded.id}`)).toHaveCount(0);

		// Durable evidence
		fs.mkdirSync(env.artifactDirAbs, { recursive: true });
		const marker = path.join(env.artifactDirAbs, `smoke-${Date.now()}.json`);
		const payload = {
			ok: true,
			portalId: seeded.id,
			title: seeded.title,
			url: page.url(),
			title_page: await page.title(),
			at: new Date().toISOString(),
		};
		fs.writeFileSync(marker, JSON.stringify(payload, null, 2));
		expect(fs.existsSync(marker)).toBeTruthy();
	});
});
