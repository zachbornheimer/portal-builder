import { test } from '@playwright/test';
test.skip(true, 'Superseded by definition-api.spec.ts');
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

test.describe('definition persist', () => {
	test('create portal, PUT definition, GET matches fixture fields', async ({
		page,
		request,
	}) => {
		await page.goto(cfg.autoLoginUrl, { waitUntil: 'domcontentloaded' });
		await page.waitForURL(/wp-admin/);

		// Create portal via classic new post UI
		const title = `dg-e2e-def-${Date.now()}`;
		await page.goto('/wp-admin/post-new.php?post_type=portal');
		await page.locator('#title').fill(title);

		// Publish (classic editor often stays on post.php?post=ID without full navigation)
		await page.locator('#publish').click();
		await page.waitForSelector('#message.updated, #post-ID, input#post_ID', {
			timeout: 45_000,
		});
		const portalId =
			(await page.locator('input#post_ID').inputValue()) ||
			page.url().match(/post=(\d+)/)?.[1];
		expect(portalId).toBeTruthy();

		// REST nonce from admin
		const nonce = await page.evaluate(() => {
			// @ts-expect-error WP admin
			return (window as unknown as { wpApiSettings?: { nonce?: string } }).wpApiSettings
				?.nonce;
		});
		// Fallback: fetch from wp-admin
		let apiNonce = nonce;
		if (!apiNonce) {
			await page.goto('/wp-admin/admin-ajax.php?action=rest-nonce').catch(() => {});
			const html = await page.content();
			// try cookies path via page request to rest index
			const r = await page.request.get('/wp-json/');
			// WordPress often needs X-WP-Nonce from wpApiSettings on post.php
			await page.goto(`/wp-admin/post.php?post=${portalId}&action=edit`);
			apiNonce = await page.evaluate(() => {
				const el = document.getElementById('_wpnonce') as HTMLInputElement | null;
				return (
					// @ts-expect-error
					window.wpApiSettings?.nonce ||
					document.querySelector('script.wp-api-request')?.textContent ||
					el?.value
				);
			});
			// Last resort: create nonce via wp-admin inline
			apiNonce =
				apiNonce ||
				(await page.evaluate(async () => {
					const res = await fetch('/wp-admin/admin-ajax.php?action=rest-nonce', {
						credentials: 'same-origin',
					});
					return res.text();
				}));
		}

		expect(apiNonce && String(apiNonce).length > 4).toBeTruthy();

		const put = await page.request.post(
			`/wp-json/dragongate/v1/portals/${portalId}/definition`,
			{
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': String(apiNonce).trim(),
				},
				data: { definition },
			}
		);
		const putBody = await put.json();
		expect(put.ok(), JSON.stringify(putBody)).toBeTruthy();
		expect(putBody.definition.fields[0].type).toBe('applicant_pack');

		const get = await page.request.get(
			`/wp-json/dragongate/v1/portals/${portalId}/definition`,
			{
				headers: { 'X-WP-Nonce': String(apiNonce).trim() },
			}
		);
		const getBody = await get.json();
		expect(get.ok()).toBeTruthy();
		expect(getBody.definition.fields.map((f: { id: string }) => f.id)).toEqual(
			definition.fields.map((f: { id: string }) => f.id)
		);

		// Trash cleanup
		await page.goto(`/wp-admin/post.php?post=${portalId}&action=trash`);
	});
});
