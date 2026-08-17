/**
 * Live CFS: confirm the three required disclosures.
 *
 *   ISJAC_MEMBER_USER=… ISJAC_MEMBER_PASS=… \
 *   npx playwright test --project=chromium e2e/filmed/cfs-2027-live-disclosures.spec.ts
 */
import { test, expect, type Page } from '@playwright/test';

const PORTAL_PATH = '/portal/2027-call-for-scores-and-papers';

test.use({ baseURL: 'https://isjac.org' });
test.setTimeout(60_000);

async function memberLogin(page: Page) {
	const user = process.env.ISJAC_MEMBER_USER || '';
	const pass = process.env.ISJAC_MEMBER_PASS || '';
	test.skip(!user || !pass, 'set ISJAC_MEMBER_USER and ISJAC_MEMBER_PASS');
	await page.goto(`/login?redirect_to=${encodeURIComponent(`https://isjac.org${PORTAL_PATH}`)}`, {
		waitUntil: 'domcontentloaded',
		timeout: 30_000,
	});
	await page.locator('#isjac-auth-email').fill(user);
	await page.getByRole('button', { name: /^Continue$/i }).click();
	await expect(page.locator('#isjac-auth-password-signin')).toBeVisible({ timeout: 15_000 });
	await page.locator('#isjac-auth-password-signin').fill(pass);
	await page.getByRole('button', { name: /sign in|log in|continue/i }).last().click();
	await page.waitForLoadState('domcontentloaded');
}

test('live form shows ownership, promo, and anonymize disclosures', async ({ page }) => {
	await memberLogin(page);
	if (!page.url().includes(PORTAL_PATH)) {
		await page.goto(PORTAL_PATH, { waitUntil: 'domcontentloaded', timeout: 30_000 });
	}
	await expect(page.locator('[data-testid="dg-form"], form.dg-form, [data-dg-form]').first()).toBeVisible({
		timeout: 20_000,
	});
	await expect(page.getByText(/this work is solely my own/i)).toBeVisible();
	await expect(page.getByText(/non-exclusive right to record/i)).toBeVisible();
	await expect(page.getByText(/exclude any information that might identify the composer/i)).toBeVisible();
	await expect(page.locator('.dg-field--disclaimer')).toHaveCount(3);
});
