import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

const cfg = JSON.parse(
	fs.readFileSync(
		fs.existsSync('tests/config/env.local.json')
			? 'tests/config/env.local.json'
			: 'tests/config/env.example.json',
		'utf8'
	)
);

test.describe('harness smoke', () => {
	test('auto-login reaches dashboard and Portals menu', async ({ page }) => {
		await page.goto(cfg.autoLoginUrl, { waitUntil: 'domcontentloaded' });
		// LocalWP may land on dashboard or already-authenticated redirect
		await page.waitForURL(/wp-admin/, { timeout: 30_000 });
		await expect(page.locator('body')).toHaveClass(/wp-admin/);

		// Portals CPT in admin menu
		const portals = page.locator('#adminmenu a[href*="post_type=portal"]').first();
		await expect(portals).toBeVisible({ timeout: 15_000 });

		await portals.click();
		await page.waitForURL(/post_type=portal/);
		await expect(page.locator('h1, .wp-heading-inline').first()).toContainText(/Portal/i);

		// Durable evidence: write a small run marker
		const artifactDir = path.resolve(cfg.artifactDir || 'tests/.artifacts');
		fs.mkdirSync(artifactDir, { recursive: true });
		const marker = path.join(artifactDir, `smoke-${Date.now()}.json`);
		fs.writeFileSync(
			marker,
			JSON.stringify(
				{
					ok: true,
					url: page.url(),
					title: await page.title(),
					at: new Date().toISOString(),
				},
				null,
				2
			)
		);
		expect(fs.existsSync(marker)).toBeTruthy();
	});
});
