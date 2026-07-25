import { test, expect } from '@playwright/test';
import fs from 'node:fs';

const cfg = JSON.parse(
	fs.readFileSync(
		fs.existsSync('tests/config/env.local.json')
			? 'tests/config/env.local.json'
			: 'tests/config/env.example.json',
		'utf8'
	)
);
const definition = JSON.parse(
	fs.readFileSync('tests/fixtures/portals/herbolzheimer.definition.json', 'utf8')
);

test('REST definition put/get on existing portal edit screen', async ({ page }) => {
	test.setTimeout(120_000);
	await page.goto(cfg.autoLoginUrl, { waitUntil: 'domcontentloaded' });
	await page.waitForURL(/wp-admin/, { timeout: 60_000 });

	// Open first existing portal from list
	await page.goto('/wp-admin/edit.php?post_type=portal', { waitUntil: 'domcontentloaded' });
	const first = page.locator('#the-list .row-title').first();
	await expect(first).toBeVisible({ timeout: 30_000 });
	const href = await first.getAttribute('href');
	expect(href).toBeTruthy();
	const idFromHref = href!.match(/post=(\d+)/)?.[1];
	expect(idFromHref).toBeTruthy();
	const portalId = idFromHref!;

	// Load any wp-admin page that boots wpApiSettings for nonce
	await page.goto(`/wp-admin/post.php?post=${portalId}&action=edit`, {
		waitUntil: 'domcontentloaded',
		timeout: 90_000,
	});
	const nonce = await page.evaluate(async () => {
		// @ts-expect-error WP
		if (window.wpApiSettings?.nonce) return window.wpApiSettings.nonce as string;
		const res = await fetch('/wp-admin/admin-ajax.php?action=rest-nonce', {
			credentials: 'include',
		});
		return res.text();
	});
	expect(nonce && String(nonce).trim().length > 4).toBeTruthy();

	const put = await page.request.post(
		`/wp-json/dragongate/v1/portals/${portalId}/definition`,
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
		`/wp-json/dragongate/v1/portals/${portalId}/definition`,
		{ headers: { 'X-WP-Nonce': String(nonce) } }
	);
	const getBody = await get.json();
	expect(get.ok()).toBeTruthy();
	expect(getBody.definition.fields.map((f: { id: string }) => f.id)).toEqual(
		definition.fields.map((f: { id: string }) => f.id)
	);

	fs.mkdirSync(cfg.artifactDir || 'tests/.artifacts', { recursive: true });
	fs.writeFileSync(
		`${cfg.artifactDir || 'tests/.artifacts'}/definition-api-${portalId}.json`,
		JSON.stringify({ ok: true, portalId, fieldCount: getBody.definition.fields.length }, null, 2)
	);
});
