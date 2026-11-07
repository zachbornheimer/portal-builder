/**
 * Film login + membership gate on the live 2027 CFS portal (plugin 0.1.0).
 *
 *   ISJAC_MEMBER_USER=… ISJAC_MEMBER_PASS=… \
 *   ISJAC_COMMUNITY_USER=… ISJAC_COMMUNITY_PASS=… \
 *   npx playwright test --project=filmed e2e/filmed/cfs-2027-prod-access.spec.ts
 */
import { test, expect, type Page } from '@playwright/test';

const PORTAL_PATH = '/portal/2027-call-for-scores-and-papers';
const PORTAL_URL = 'https://isjac.org/portal/2027-call-for-scores-and-papers';

test.use({
	baseURL: 'https://isjac.org',
	video: 'on',
	screenshot: 'on',
	trace: 'on',
});

test.describe.configure({ mode: 'serial' });
test.setTimeout(180_000);

async function openPortal(page: Page) {
	const res = await page.goto(PORTAL_PATH, {
		waitUntil: 'domcontentloaded',
		timeout: 90_000,
	});
	expect(res === null || (res !== null && res.status() < 400)).toBeTruthy();
}

async function assertPlugin010(page: Page) {
	const html = await page.content();
	expect(html, 'public assets must advertise plugin 0.1.0').toContain('ver=0.1.0');
	expect(html).toContain('portal-builder-0.0.4a');
}

async function wpLogin(page: Page, user: string, pass: string) {
	await page.goto(
		`/wp-login.php?redirect_to=${encodeURIComponent(PORTAL_URL)}`,
		{ waitUntil: 'domcontentloaded', timeout: 90_000 },
	);
	await page.locator('#user_login').fill(user);
	await page.locator('#user_pass').fill(pass);
	await page.locator('#wp-submit').click();
	await page.waitForLoadState('domcontentloaded');
}

test('guest sees restricted login gate on 0.1.0 and Sign in returns to the portal', async ({
	page,
}) => {
	await openPortal(page);
	await assertPlugin010(page);
	const gate = page.locator('[data-dg-portal-state="restricted"]');
	await expect(gate).toBeVisible();
	await expect(gate).toHaveAttribute('data-dg-closed-reason', 'login');
	await expect(gate).toContainText(/paid ISJAC members|Sign in to apply|Community membership is not enough/i);
	const signIn = page.getByRole('link', { name: /^Sign in$/ });
	await expect(signIn).toBeVisible();
	const href = await signIn.getAttribute('href');
	expect(href).toContain('/login');
	expect(href).not.toContain('wp-login.php');
	expect(href).toContain('redirect_to=');
	expect(decodeURIComponent(href || '')).toContain(PORTAL_PATH);
	await expect(page.locator('form.dg-portal-submit-form')).toHaveCount(0);
});

test('community / wrong-tier member sees the paid-membership message', async ({
	page,
}) => {
	const user = process.env.ISJAC_COMMUNITY_USER || '';
	const pass = process.env.ISJAC_COMMUNITY_PASS || '';
	test.skip(!user || !pass, 'set ISJAC_COMMUNITY_USER and ISJAC_COMMUNITY_PASS');
	await wpLogin(page, user, pass);
	await openPortal(page);
	await assertPlugin010(page);
	const gate = page.locator('[data-dg-portal-state="restricted"]');
	await expect(gate).toBeVisible();
	await expect(gate).toHaveAttribute('data-dg-closed-reason', 'membership');
	await expect(gate).toContainText(/paid ISJAC members|This portal is for members|Community membership is not enough/i);
	await expect(page.locator('form.dg-portal-submit-form')).toHaveCount(0);
});

test('paid member reaches the open application form on 0.1.0', async ({ page }) => {
	const user = process.env.ISJAC_MEMBER_USER || '';
	const pass = process.env.ISJAC_MEMBER_PASS || '';
	test.skip(!user || !pass, 'set ISJAC_MEMBER_USER and ISJAC_MEMBER_PASS');
	await wpLogin(page, user, pass);
	await openPortal(page);
	await assertPlugin010(page);
	await expect(page.locator('[data-dg-portal-state="restricted"]')).toHaveCount(0);
	await expect(page.locator('form.dg-portal-submit-form')).toBeVisible({ timeout: 45_000 });
	await expect(page.getByRole('heading', { name: /2027 Call for Scores/i })).toBeVisible();
});
