/**
 * Short local view-as walk. One clip per test.
 *
 *   npx playwright test --project=filmed e2e/filmed/cfs-2027-local-view-as.spec.ts
 *
 * Videos: ~/Downloads/dragongate-cfs-2027-local-0.1.5/
 */
import { test, expect, type Page } from '@playwright/test';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { ensureAdminSession } from '../../tests/support/admin-session.mjs';

const PORTAL_PATH = '/portal/2027-call-for-scores-and-papers';
const OUT_DIR = path.join(os.homedir(), 'Downloads', 'dragongate-cfs-2027-local-0.1.5');

test.use({
	baseURL: 'http://localhost:10033',
	video: 'on',
	screenshot: 'on',
	trace: 'on',
});

test.setTimeout(45_000);

function copyClip(testInfo: { title: string; attachments: { name: string; path?: string; contentType: string }[] }) {
	const video = testInfo.attachments.find(
		(item) => item.path && item.contentType.startsWith('video/'),
	);
	if (!video?.path || !fs.existsSync(video.path)) {
		return;
	}
	fs.mkdirSync(OUT_DIR, { recursive: true });
	const slug = testInfo.title.replace(/[^\w.-]+/g, '-').replace(/^-|-$/g, '').slice(0, 72);
	const dest = path.join(OUT_DIR, `${slug}${path.extname(video.path)}`);
	fs.copyFileSync(video.path, dest);
	console.log(`clip ${dest} bytes=${fs.statSync(dest).size}`);
}

test.afterEach(async ({}, testInfo) => {
	copyClip(testInfo);
});

async function openAsAdmin(page: Page, extra = '') {
	await ensureAdminSession(page);
	const res = await page.goto(`${PORTAL_PATH}${extra}`, {
		waitUntil: 'domcontentloaded',
		timeout: 30_000,
	});
	expect(res === null || (res !== null && res.status() < 400)).toBeTruthy();
}

test('01-view-as-bar-present', async ({ page }) => {
	await openAsAdmin(page);
	await expect(page.locator('#wp-admin-bar-dg-view-as')).toBeVisible({ timeout: 15_000 });
	await expect(page.locator('#wp-admin-bar-dg-view-as-logged_out')).toBeAttached();
});

test('02-view-as-logged-out-shows-gate', async ({ page }) => {
	await openAsAdmin(page, '?dg_view_as=logged_out');
	await expect(page.getByTestId('dg-view-as-banner')).toContainText('Viewing as');
	await expect(page.locator('[data-dg-portal-state="restricted"]')).toBeVisible();
	await expect(page.getByTestId('dg-submit')).toHaveCount(0);
});

test('03-view-as-student-shows-form-no-submit', async ({ page }) => {
	await openAsAdmin(page, '?dg_view_as=plan:17214');
	await expect(page.getByTestId('dg-view-as-banner')).toContainText('Submissions are off');
	await expect(page.locator('form.dg-form, [data-testid="dg-form"]').first()).toBeVisible({ timeout: 15_000 });
	await expect(page.getByTestId('dg-submit')).toHaveCount(0);
});
