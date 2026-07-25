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

/** Closed for public: forceClosed + past deadline (belt and suspenders). */
function closedDefinition() {
	return {
		...baseDefinition,
		title: 'E2E Closed Preview Portal',
		publish: {
			deadline: '2020-01-01T00:00:00',
			timezone: 'America/New_York',
			applicationFee: null,
			forceClosed: true,
		},
	};
}

/**
 * Build public/preview paths from seed linkPath (pretty permalink).
 * Avoids ?p= → /portal/slug redirects that strip preview=true.
 */
function publicPortalPaths(seeded: { id: string; linkPath?: string; slug?: string }) {
	const base =
		seeded.linkPath ||
		(seeded.slug ? `/portal/${seeded.slug}/` : `/?p=${seeded.id}&post_type=portal`);
	const normalized = base.endsWith('/') || base.includes('?') ? base : `${base}/`;
	const preview = normalized.includes('?')
		? `${normalized}&preview=true`
		: `${normalized}?preview=true`;
	return { publicPath: normalized, previewPath: preview };
}

async function putDefinition(page: import('@playwright/test').Page, portalId: string, definition: unknown) {
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
	const body = JSON.parse(text);
	expect(body?.definition?.publish?.deadline).toBeTruthy();
	// Round-trip GET so we know meta is readable before public view.
	const get = await page.request.get(
		`/wp-json/dragongate/v1/portals/${portalId}/definition`,
		{ headers: { 'X-WP-Nonce': nonce } }
	);
	const getBody = await get.json();
	expect(get.ok()).toBeTruthy();
	expect(getBody?.definition?.publish?.deadline).toBe(
		(definition as { publish: { deadline: string } }).publish.deadline
	);
	return body;
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
		// Pretty permalink required: ?p= → /portal/slug redirects drop preview=true.
		expect(
			seeded.linkPath || seeded.slug,
			'seed must return linkPath or slug for preview query preservation'
		).toBeTruthy();
		const { publicPath, previewPath } = publicPortalPaths(seeded);

		try {
			await putDefinition(page, portalId, closedDefinition());

			// --- Anonymous / logged-out: closed message, no form ---
			const anon = await browser.newContext();
			const anonPage = await anon.newPage();
			const anonRes = await anonPage.goto(publicPath, {
				waitUntil: 'domcontentloaded',
				timeout: 90_000,
			});
			// 200 OK expected on pretty permalink; allow soft redirects.
			expect(
				anonRes === null ||
					(anonRes.status() >= 200 && anonRes.status() < 400)
			).toBeTruthy();

			await expect(anonPage.locator('[data-dg-portal-state="closed"]')).toBeVisible({
				timeout: 45_000,
			});
			await expect(anonPage.locator('body')).toContainText(CLOSED_COPY);
			await expect(anonPage.locator('[data-dg-render="definition"]')).toHaveCount(0);
			await expect(anonPage.locator('[data-dg-preview="true"]')).toHaveCount(0);
			await expect(anonPage.locator('[data-dg-field-id]')).toHaveCount(0);

			const anonHtml = await anonPage.content();
			await anon.close();

			// --- Editor with ?preview=true: banner + definition form markers ---
			// Prefer commit so slow secondary assets don't eat the budget.
			const previewRes = await page.goto(previewPath, {
				waitUntil: 'commit',
				timeout: 90_000,
			});
			// If markers missing, dump request HTML for diagnosis (redirects strip preview).
			try {
				await page.waitForSelector(
					'[data-dg-preview="true"], [data-dg-render="definition"]',
					{ timeout: 45_000 }
				);
			} catch (err) {
				const dumpRes = await page.request.get(previewPath);
				const dumpHtml = await dumpRes.text();
				fs.mkdirSync(env.artifactDirAbs, { recursive: true });
				const dumpPath = path.join(
					env.artifactDirAbs,
					`closed-preview-fail-${portalId}.json`
				);
				fs.writeFileSync(
					dumpPath,
					JSON.stringify(
						{
							ok: false,
							portalId,
							publicPath,
							previewPath,
							finalUrl: page.url(),
							gotoStatus: previewRes?.status() ?? null,
							requestStatus: dumpRes.status(),
							requestUrl: dumpRes.url(),
							htmlSnippet: dumpHtml.slice(0, 4000),
							hasPreview: /data-dg-preview="true"/.test(dumpHtml),
							hasDefinition: /data-dg-render="definition"/.test(dumpHtml),
							hasClosed: /data-dg-portal-state="closed"/.test(dumpHtml),
							at: new Date().toISOString(),
						},
						null,
						2
					)
				);
				throw err;
			}

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
						seedLinkPath: seeded.linkPath ?? null,
						seedSlug: seeded.slug ?? null,
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
