import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { loadEnv } from '../../tests/support/load-env.mjs';
import { ensureAdminSession } from '../../tests/support/admin-session.mjs';
import { seedPortal } from '../../tests/support/seed.mjs';
import { cleanupPortal } from '../../tests/support/cleanup.mjs';

const env = loadEnv();
const definition = JSON.parse(
	fs.readFileSync('tests/fixtures/portals/herbolzheimer.definition.json', 'utf8')
);

/** Throwaway portal seeded for this spec — never put/get against real portals. */
let seededPortalId: string | undefined;

test('REST definition put/get on seeded portal', async ({ page }) => {
	test.setTimeout(120_000);
	await ensureAdminSession(page, { env, timeoutMs: 60_000 });

	// Seed our own dg-e2e-def-api-* throwaway (portal-crud's seed path).
	const seeded = await seedPortal(page, {
		env,
		skipLogin: true,
		label: 'def-api',
	});
	seededPortalId = seeded.id;
	expect(seeded.id).toMatch(/^\d+$/);

	// Row link: the plugin routes portal rows to the setup screen (portal_id=<id>).
	await page.goto(
		`/wp-admin/edit.php?post_type=portal&post_status=publish&s=${encodeURIComponent(seeded.title)}`,
		{ waitUntil: 'domcontentloaded' }
	);
	const row = page.locator(`#post-${seeded.id} .row-title`).first();
	await expect(row).toBeVisible({ timeout: 30_000 });
	const href = await row.getAttribute('href');
	expect(href).toBeTruthy();
	const idFromHref = href!.match(/portal_id=(\d+)/)?.[1];
	expect(idFromHref).toBeTruthy();
	expect(idFromHref).toBe(seeded.id);

	// The setup screen replaced the post.php editor and embeds the wp_rest nonce.
	await page.goto(`/wp-admin/admin.php?page=dg-portal-setup&portal_id=${seeded.id}`, {
		waitUntil: 'domcontentloaded',
		timeout: 90_000,
	});
	const nonce = await page.evaluate(async () => {
		const fromWizard = document
			.querySelector('[data-rest-nonce]')
			?.getAttribute('data-rest-nonce');
		if (fromWizard) return fromWizard;
		// @ts-expect-error WP
		if (window.wpApiSettings?.nonce) return window.wpApiSettings.nonce as string;
		const res = await fetch('/wp-admin/admin-ajax.php?action=rest-nonce', {
			credentials: 'include',
		});
		return res.text();
	});
	expect(nonce && String(nonce).trim().length > 4).toBeTruthy();

	const put = await page.request.post(
		`/wp-json/dragongate/v1/portals/${seeded.id}/definition`,
		{
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': String(nonce),
			},
			data: { definition },
		}
	);
	const putText = await put.text();
	expect(put.ok(), putText).toBeTruthy();
	const putBody = JSON.parse(putText);
	expect(putBody.definition.fields[0].type).toBe('applicant_pack');

	const get = await page.request.get(
		`/wp-json/dragongate/v1/portals/${seeded.id}/definition`,
		{ headers: { 'X-WP-Nonce': String(nonce) } }
	);
	const getBody = await get.json();
	expect(get.ok()).toBeTruthy();
	expect(getBody.definition.fields.map((f: { id: string }) => f.id)).toEqual(
		definition.fields.map((f: { id: string }) => f.id)
	);

	fs.mkdirSync(env.artifactDirAbs, { recursive: true });
	fs.writeFileSync(
		path.join(env.artifactDirAbs, `definition-api-${seeded.id}.json`),
		JSON.stringify(
			{ ok: true, portalId: seeded.id, fieldCount: getBody.definition.fields.length },
			null,
			2
		)
	);
});

test.afterAll(async ({ browser }) => {
	if (!seededPortalId) return;
	const context = await browser.newContext({ baseURL: env.baseUrl });
	const page = await context.newPage();
	try {
		await cleanupPortal(page, seededPortalId, { env });
	} finally {
		await context.close();
	}
});
