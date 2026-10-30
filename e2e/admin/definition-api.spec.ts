import { test, expect, type Page } from '@playwright/test';
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

const TOP_LEVEL_ACTION = 'Add next field at top level';
const TOP_LEVEL_STATUS =
	'Already adding new fields at the top level. Choose a field type to add one.';

const buildTest = test.extend<{ buildPage: Page }>({
	buildPage: async ({ page }, use) => {
		const seeded = await seedPortal(page, { env, label: 'build-top-level' });
		try {
			await page.goto(`/wp-admin/admin.php?page=dg-portal-setup&portal_id=${seeded.id}`, {
				waitUntil: 'domcontentloaded',
			});
			await page.getByRole('button', { name: 'Start blank', exact: true }).click();
			await expect(page.getByRole('heading', { name: 'Build the form', exact: true })).toBeVisible();
			await use(page);
		} finally {
			await cleanupPortal(page, seeded.id, { env, skipLogin: true });
		}
	},
});

buildTest('top-level insertion explains why it is disabled and ignores keyboard activation', async ({ buildPage: page }) => {
	const topLevel = page.getByRole('button', { name: TOP_LEVEL_ACTION, exact: true });
	const tree = page.locator('[data-dg-field-list]');
	await expect(topLevel).toBeDisabled();
	await expect(topLevel).toHaveAccessibleDescription(/Already adding new fields at the top level/);
	await expect(page.locator('#dg-root-insert-status')).toHaveText(TOP_LEVEL_STATUS);
	await expect(tree.locator('[data-field-id]')).toHaveCount(0);

	// ARIA-disabled deliberately remains focusable: users can hear the reason,
	// while this same element remains a working drop target.
	await topLevel.focus();
	await topLevel.press('Enter');
	await topLevel.press('Space');
	await expect(topLevel).toBeFocused();
	await expect(topLevel).toBeDisabled();
	await expect(tree.locator('[data-field-id]')).toHaveCount(0);

	await page.locator('#dg-type-palette').getByRole('button', { name: 'Short text', exact: true }).click();
	await expect(tree.locator(':scope > [data-field-id="short_text"]')).toHaveCount(1);
});

for (const container of [
	{ paletteName: 'Group', id: 'group', label: 'Group', rowName: /^Group Section$/ },
	{ paletteName: 'Branch', id: 'application_category', label: 'Application Category', rowName: /^When: Option A/ },
]) {
	buildTest(`choosing top level exits a ${container.paletteName.toLowerCase()} insertion target without moving existing fields`, async ({ buildPage: page }) => {
		const palette = page.locator('#dg-type-palette');
		const tree = page.locator('[data-dg-field-list]');
		const topLevel = page.getByRole('button', { name: TOP_LEVEL_ACTION, exact: true });
		const inspector = page.getByRole('complementary', { name: 'Field inspector' });

		await palette.getByRole('button', { name: container.paletteName, exact: true }).click();
		await expect(topLevel).toBeEnabled();
		await palette.getByRole('button', { name: 'Short text', exact: true }).click();
		await expect(tree.locator(':scope > [data-field-id]')).toHaveCount(1);
		await expect(tree.locator(':scope ul [data-field-id="short_text"]')).toHaveCount(1);
		await tree.getByRole('button', { name: container.rowName }).click();
		await expect(inspector.getByRole('textbox', { name: 'Label', exact: true })).toHaveValue(container.label);

		await topLevel.click();

		await expect(topLevel).toBeDisabled();
		await expect(page.locator('#dg-root-insert-status')).toHaveText(TOP_LEVEL_STATUS);
		await expect(page.locator('#dg-root-insert-status')).toHaveAttribute('role', 'status');
		await expect(inspector.getByRole('textbox', { name: 'Label', exact: true })).toHaveValue(container.label);
		await expect(tree.locator(`:scope > [data-field-id="${container.id}"]`)).toHaveCount(1);
		await expect(tree.locator(':scope > [data-field-id]')).toHaveCount(1);
		await expect(tree.locator(':scope ul [data-field-id="short_text"]')).toHaveCount(1);
		if (container.paletteName === 'Branch') {
			await expect(inspector.getByRole('button', { name: 'Add new field', exact: true })).toBeDisabled();
			await expect(inspector.getByText(/Adding to “Option A”/)).toHaveCount(0);
		}

		await palette.getByRole('button', { name: 'Short text', exact: true }).click();
		await expect(tree.locator(':scope > [data-field-id="short_text_2"]')).toHaveCount(1);
		await expect(tree.locator(':scope ul [data-field-id="short_text"]')).toHaveCount(1);
	});
}

buildTest('top-level drops still move existing fields and add field types while clicking is disabled', async ({ buildPage: page }) => {
	const palette = page.locator('#dg-type-palette');
	const tree = page.locator('[data-dg-field-list]');
	const topLevel = page.getByRole('button', { name: TOP_LEVEL_ACTION, exact: true });
	await palette.getByRole('button', { name: 'Group', exact: true }).click();
	await palette.getByRole('button', { name: 'Short text', exact: true }).click();
	await expect(tree.locator(':scope ul [data-field-id="short_text"]')).toHaveCount(1);
	await topLevel.click();
	await expect(topLevel).toBeDisabled();

	await tree.locator('[data-field-id="short_text"]').dragTo(topLevel);
	await expect(tree.locator(':scope > [data-field-id="short_text"]')).toHaveCount(1);
	await expect(tree.locator(':scope ul [data-field-id="short_text"]')).toHaveCount(0);
	await expect(tree.locator(':scope > [data-field-id]')).toHaveCount(2);

	await palette.getByRole('button', { name: 'Short text', exact: true }).dragTo(topLevel);
	await expect(tree.locator(':scope > [data-field-id="short_text_2"]')).toHaveCount(1);
	await expect(tree.locator(':scope > [data-field-id]')).toHaveCount(3);
	await expect(topLevel).toBeDisabled();
});
