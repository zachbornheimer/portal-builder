/**
 * Closed vs admin-preview for definition-based portals (plan 2.2–2.3).
 */
import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { loadEnv } from '../../tests/support/load-env.mjs';
import { ensureAdminSession } from '../../tests/support/admin-session.mjs';
import { seedPortal } from '../../tests/support/seed.mjs';
import { cleanupPortal } from '../../tests/support/cleanup.mjs';
import { fetchRestNonce } from '../../tests/support/wp-rest.mjs';

const env = loadEnv();
const baseDefinition = JSON.parse(
	fs.readFileSync('tests/fixtures/portals/herbolzheimer.definition.json', 'utf8')
);

const PREVIEW_BANNER = /Preview\s*[—–-]\s*not a live submission/i;
const CLOSED_COPY = /deadline has passed|portal is closed|not currently accepting/i;

function closedDefinition() {
	return {
		...baseDefinition,
		title: 'E2E Closed Preview Portal',
		publish: {
			deadline: '2020-01-01T00:00:00',
			timezone: 'America/New_York',
			applicationFee: null,
			forceClosed: false,
		},
	};
}

async function putDefinition(page: Page, portalId: string, definition: unknown) {
	const nonce = await fetchRestNonce(page);
	const put = await page.request.post(
		`/wp-json/dragongate/v1/portals/${portalId}/definition`,
		{
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': nonce,
			},
			data: { definition },
		}
	);
	const text = await put.text();
	expect(put.ok(), text).toBeTruthy();
	return JSON.parse(text);
}

test.describe('closed vs preview (definition-first)', () => {
	test('anonymous closed; editor preview shows banner + form markers', async ({
		browser,
		page,
	}) => {
		test.setTimeout(120_000);

		await ensureAdminSession(page, { env });
		const seeded = await seedPortal(page, { env, skipLogin: true, label: 'closed-preview' });
		const portalId = seeded.id;
		const publicPath = seeded.linkPath || `/?p=${portalId}`;

		try {
			await putDefinition(page, portalId, closedDefinition());
			expect(publicPath.length).toBeGreaterThan(1);

			// --- Anonymous / logged-out: closed message, no form ---
			const anon = await browser.newContext();
			const anonPage = await anon.newPage();
			await anonPage.goto(publicPath, { waitUntil: 'domcontentloaded', timeout: 60_000 });

			await expect(anonPage.locator('[data-dg-portal-state="closed"]')).toBeVisible({
				timeout: 30_000,
			});
			await expect(anonPage.locator('body')).toContainText(CLOSED_COPY);
			await expect(anonPage.locator('[data-dg-render="definition"]')).toHaveCount(0);
			await expect(anonPage.locator('[data-dg-preview="true"]')).toHaveCount(0);
			await expect(anonPage.locator('[data-dg-field-id]')).toHaveCount(0);

			const anonHtml = await anonPage.content();
			await anon.close();

			// --- Editor with ?preview=true: banner + definition form markers ---
			const previewPath =
				publicPath.includes('?') ? `${publicPath}&preview=true` : `${publicPath}?preview=true`;
			await page.goto(previewPath, { waitUntil: 'domcontentloaded', timeout: 60_000 });

			await expect(page.locator('[data-dg-preview="true"]')).toBeVisible({
				timeout: 30_000,
			});
			await expect(page.locator('body')).toContainText(PREVIEW_BANNER);
			await expect(page.locator('[data-dg-render="definition"]')).toBeVisible();
			await expect(page.locator('[data-dg-field-id="applicant"]')).toBeVisible();
			await expect(page.locator('[data-dg-field-id="work_title"]')).toBeVisible();
			await expect(page.locator('[data-dg-portal-state="closed"]')).toHaveCount(0);

			const previewHtml = await page.content();

			fs.mkdirSync(env.artifactDirAbs, { recursive: true });
			const artifact = path.join(
				env.artifactDirAbs,
				`closed-preview-${portalId}.json`
			);
			fs.writeFileSync(
				artifact,
				JSON.stringify(
					{
						ok: true,
						portalId,
						publicPath,
						previewPath,
						anonHasClosed: /data-dg-portal-state="closed"/.test(anonHtml),
						previewHasBanner: /data-dg-preview="true"/.test(previewHtml),
						previewHasDefinition: /data-dg-render="definition"/.test(previewHtml),
						at: new Date().toISOString(),
					},
					null,
					2
				)
			);
			expect(fs.existsSync(artifact)).toBeTruthy();
		} finally {
			// Cleanup is best-effort — do not let a hung admin REST call fail the suite.
			try {
				await Promise.race([
					cleanupPortal(page, portalId, { env, skipLogin: true }),
					new Promise((resolve) => setTimeout(resolve, 15_000)),
				]);
			} catch {
				// ignore
			}
		}
	});
});
