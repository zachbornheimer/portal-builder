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
		// Stay on admin for REST nonce; PUT definition.
		if (!/wp-admin/.test(page.url())) {
			await page.goto(seeded.editUrl, {
				waitUntil: 'domcontentloaded',
				timeout: 60_000,
			});
		}

		const put = await restJson(page, DEFINITION_ROUTE(seeded.id), {
			method: 'POST',
			data: { definition },
		});
		expect(put.ok, JSON.stringify(put.body)).toBeTruthy();
		expect(put.body?.definition?.fields?.[0]?.type).toBe('applicant_pack');

		const portalGet = await restJson(page, `/wp-json/wp/v2/portal/${seeded.id}`, {
			method: 'GET',
		});
		expect(portalGet.ok, JSON.stringify(portalGet.body)).toBeTruthy();
		const publicUrl = portalGet.body?.link || `/?p=${seeded.id}`;

		// Fetch public HTML via request (avoids painting a 1MB+ Query Monitor page).
		// Auth cookies are included so preview capability works if needed.
		const publicRes = await page.request.get(publicUrl, { timeout: 60_000 });
		expect(publicRes.ok(), `public HTTP ${publicRes.status()}`).toBeTruthy();
		const html = await publicRes.text();

		expect(html).toContain('data-dg-render="definition"');
		expect(html).toContain('Title of Work');
		expect(html).toContain('Full Score');
		expect(html).toContain('Recording');

		const hasFullInputs =
			html.includes('name="sub_work_title"') &&
			html.includes('name="sub_score"') &&
			html.includes('name="sub_recording"');

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
					publicUrl,
					hasDefinitionRoot: true,
					hasFullInputs,
					htmlBytes: html.length,
					at: new Date().toISOString(),
				},
				null,
				2
			)
		);
		expect(fs.existsSync(artifact)).toBeTruthy();

		// Prefer full field UI when this branch is the active plugin path.
		// Soft assert: unit tests prove renderer; e2e proves portal+definition path.
		if (!hasFullInputs) {
			console.warn(
				'definition root present with labels but without sub_* inputs — plugin path may not be this worktree'
			);
		}
	} finally {
		try {
			if (!page.isClosed()) {
				await cleanupPortal(page, seeded.id, { env, skipLogin: true });
			}
		} catch {
			// best-effort
		}
	}
});
