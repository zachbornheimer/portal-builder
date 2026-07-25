import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { loadEnv } from '../../tests/support/load-env.mjs';
import { ensureAdminSession } from '../../tests/support/admin-session.mjs';
import { seedPortal } from '../../tests/support/seed.mjs';
import { cleanupPortal } from '../../tests/support/cleanup.mjs';
import { restJson } from '../../tests/support/wp-rest.mjs';

const env = loadEnv();
const definition = JSON.parse(
	fs.readFileSync('tests/fixtures/portals/herbolzheimer.definition.json', 'utf8')
);

const DEFINITION_ROUTE = (id: string) =>
	`/wp-json/dragongate/v1/portals/${id}/definition`;

test('public form renders from herbolzheimer definition', async ({ page }) => {
	test.setTimeout(120_000);
	await ensureAdminSession(page, { env });

	const seeded = await seedPortal(page, {
		env,
		skipLogin: true,
		label: 'herbolzheimer-render',
	});

	try {
		// Land on edit so REST nonce is available, then PUT definition.
		await page.goto(seeded.editUrl, {
			waitUntil: 'domcontentloaded',
			timeout: 90_000,
		});

		const put = await restJson(page, DEFINITION_ROUTE(seeded.id), {
			method: 'POST',
			data: { definition },
		});
		expect(put.ok, JSON.stringify(put.body)).toBeTruthy();
		expect(put.body?.definition?.fields?.[0]?.type).toBe('applicant_pack');

		// Resolve public URL from REST link or slug.
		const portalGet = await restJson(page, `/wp-json/wp/v2/portal/${seeded.id}`, {
			method: 'GET',
		});
		expect(portalGet.ok, JSON.stringify(portalGet.body)).toBeTruthy();
		const publicUrl =
			portalGet.body?.link ||
			`/?p=${seeded.id}`;

		await page.goto(publicUrl, {
			waitUntil: 'domcontentloaded',
			timeout: 90_000,
		});

		const formRoot = page.locator('[data-dg-render="definition"]');
		await expect(formRoot).toBeVisible({ timeout: 30_000 });
		await expect(formRoot).toContainText('Title of Work');
		await expect(formRoot).toContainText('Full Score');
		await expect(formRoot).toContainText('Recording');
		await expect(formRoot.locator('label', { hasText: 'Title of Work' })).toBeVisible();
		await expect(formRoot.locator('input[name="sub_work_title"]')).toBeVisible();
		await expect(formRoot.locator('input[name="sub_score"][type="file"]')).toBeVisible();
		await expect(formRoot.locator('input[name="sub_recording"][type="file"]')).toBeVisible();

		fs.mkdirSync(env.artifactDirAbs, { recursive: true });
		const artifact = path.join(
			env.artifactDirAbs,
			`render-herbolzheimer-${seeded.id}.json`
		);
		fs.writeFileSync(
			artifact,
			JSON.stringify(
				{
					ok: true,
					portalId: seeded.id,
					publicUrl: page.url(),
					hasDefinitionRoot: true,
					at: new Date().toISOString(),
				},
				null,
				2
			)
		);
		expect(fs.existsSync(artifact)).toBeTruthy();
	} finally {
		await cleanupPortal(page, seeded.id, { env, skipLogin: true });
	}
});
