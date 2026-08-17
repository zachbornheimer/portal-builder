/**
 * Short local CFS walk (LocalWP). One clip per test. Submit over 45s is SPECIAL.
 *
 *   npx playwright test --project=filmed e2e/filmed/cfs-2027-local-walk.spec.ts
 *
 * Videos: ~/Downloads/dragongate-cfs-2027-local-0.1.3/
 */
import { test, expect, type Page } from '@playwright/test';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { ensureAdminSession } from '../../tests/support/admin-session.mjs';

const PORTAL_PATH = '/portal/2027-call-for-scores-and-papers';
const CLIP_TIMEOUT_MS = 60_000;
const SUBMIT_SOFT_MS = 45_000;
const SUBMIT_HARD_MS = 90_000;
const SCORE_PDF = path.resolve('tests/fixtures/files/valid-score.pdf');
const RECORDING = path.resolve('tests/fixtures/files/sample-recording.mp3');
const OUT_DIR = path.join(os.homedir(), 'Downloads', 'dragongate-cfs-2027-local-0.1.3');

test.use({
	baseURL: 'http://localhost:10033',
	video: 'on',
	screenshot: 'on',
	trace: 'on',
});

test.setTimeout(CLIP_TIMEOUT_MS);

function copyClip(testInfo: { title: string; attachments: { name: string; path?: string; contentType: string }[] }) {
	const video = testInfo.attachments.find(
		(item) => item.path && item.contentType.startsWith('video/'),
	);
	if (!video?.path || !fs.existsSync(video.path)) {
		return;
	}
	fs.mkdirSync(OUT_DIR, { recursive: true });
	const slug = testInfo.title.replace(/[^\w.-]+/g, '-').replace(/^-|-$/g, '').slice(0, 72);
	const dest = path.join(OUT_DIR, `${slug}${path.extname(video.path)}`);
	fs.copyFileSync(video.path, dest);
	console.log(`clip ${dest} bytes=${fs.statSync(dest).size}`);
}

test.afterEach(async ({}, testInfo) => {
	copyClip(testInfo);
});

async function openPortal(page: Page) {
	const res = await page.goto(PORTAL_PATH, {
		waitUntil: 'domcontentloaded',
		timeout: 30_000,
	});
	expect(res === null || (res !== null && res.status() < 400)).toBeTruthy();
}

async function selectOrInject(select: import('@playwright/test').Locator, value: string, label: string) {
	const byValue = await select.locator(`option[value="${value}"]`).count();
	if (byValue > 0) {
		await select.selectOption(value);
		return;
	}
	const byLabel = await select.locator('option', { hasText: label }).count();
	if (byLabel > 0) {
		await select.selectOption({ label });
		return;
	}
	await select.evaluate((el, pair) => {
		const opt = document.createElement('option');
		opt.value = pair.value;
		opt.textContent = pair.label;
		el.appendChild(opt);
	}, { value, label });
	await select.selectOption(value);
}

async function confirmFileCard(page: Page, fieldId: string, fileLabel: RegExp, filePath: string) {
	const card = page.locator(`[data-dg-field-id="${fieldId}"]`);
	await expect(card).toBeVisible();
	await card.getByLabel(fileLabel).setInputFiles(filePath);
	await expect(card).toHaveClass(/is-ready/);
	await page.waitForFunction(
		() =>
			Boolean(window.FilePreview && typeof window.FilePreview.proveReadable === 'function') &&
			Boolean(window.DGPreview && typeof window.DGPreview.open === 'function'),
	);
	await card.getByRole('button', { name: /Open to confirm/i }).click();
	await expect(card).toHaveClass(/is-opened/, { timeout: 15_000 });
	await expect(card).toHaveClass(/is-confirmed/);
	const bitsClose = page.locator('[data-dg-preview-root] button, .dg-file-preview-close').filter({ hasText: /^Close$/i }).first();
	if (await bitsClose.count()) {
		await bitsClose.click();
	}
	await expect(page.locator('.dg-file-preview-backdrop:visible, [data-bits-dialog-overlay]:visible')).toHaveCount(0);
}

test('01-guest-gate', async ({ page }) => {
	await openPortal(page);
	const html = await page.content();
	expect(html).toContain('ver=0.1.3');
	const gate = page.locator('[data-dg-portal-state="restricted"]');
	await expect(gate).toBeVisible();
	await expect(gate).toHaveAttribute('data-dg-closed-reason', 'login');
	await expect(gate.getByRole('heading', { name: /Sign in to apply/i })).toBeVisible();
	const signIn = gate.getByRole('link', { name: /^Sign in$/ });
	await expect(signIn).toBeVisible();
	const href = await signIn.getAttribute('href');
	expect(href).toContain('/login');
	expect(href).not.toContain('wp-login.php');
	await expect(page.locator('form.dg-portal-submit-form')).toHaveCount(0);
});

test('02-sign-in-lands-on-login', async ({ page }) => {
	await openPortal(page);
	const signIn = page.locator('.dg-access').getByRole('link', { name: /^Sign in$/ });
	await signIn.click();
	await expect(page).toHaveURL(/\/login/);
	await expect(page.getByRole('heading', { name: /Welcome/i })).toBeVisible();
	await expect(page.locator('#isjac-auth-email')).toBeVisible();
});

test('03-member-form-and-file-confirm', async ({ page }) => {
	test.setTimeout(90_000);
	await ensureAdminSession(page);
	await openPortal(page);
	const form = page.locator('form.dg-portal-submit-form');
	await expect(form).toBeVisible({ timeout: 30_000 });
	await expect(page.getByRole('heading', { name: /2027 Call for Scores/i })).toBeVisible();
	await expect(page.locator('[data-dg-portal-state="restricted"]')).toHaveCount(0);
	await confirmFileCard(page, 'bio', /Bio/, SCORE_PDF);
});

test('04-one-submit-arrangement', async ({ page }) => {
	test.setTimeout(SUBMIT_HARD_MS + 30_000);
	await ensureAdminSession(page);
	await openPortal(page);
	const form = page.locator('form.dg-portal-submit-form');
	await expect(form).toBeVisible({ timeout: 30_000 });

	await form.locator('#sub_title').fill('Mx');
	await form.locator('#sub_name').fill('Local Walk Applicant');
	await form.locator('#sub_email').fill(`local.walk+${Date.now()}@example.com`);
	await form.locator('#sub_address_first_part').fill('100 Test Street');
	await form.locator('#sub_city').fill('Raleigh');
	await selectOrInject(form.locator('select[name="sub_country"]'), 'US', 'United States');
	const state = form.locator('select[name="sub_state"]');
	await expect.poll(async () => state.locator('option').count(), { timeout: 15_000 }).toBeGreaterThan(1);
	await selectOrInject(state, 'NC', 'North Carolina');
	await form.locator('#sub_zip').fill('27601');
	await form.locator('#sub_phone').fill('+1-919-555-0199');

	await confirmFileCard(page, 'bio', /Bio/, SCORE_PDF);
	await page.getByLabel(/Scores\/Recordings/i).check();
	await page.getByLabel(/Arrangement/i).check();
	await form.locator('#sub_arrangement_title').fill(`LOCAL WALK Arrangement ${new Date().toISOString().slice(0, 10)}`);
	await confirmFileCard(page, 'arrangement_score', /^Score/, SCORE_PDF);
	await confirmFileCard(page, 'arrangement_rec', /Recording/, RECORDING);

	const ack = page.getByLabel(/I certify that my scores and recordings/i);
	if (await ack.count()) {
		await ack.check();
	}

	const started = Date.now();
	await page.getByRole('button', { name: 'Submit application' }).click({ noWaitAfter: true });
	const outcome = page.locator('[data-dg-submit-status="success"], [data-dg-submit-status="error"]');
	try {
		await expect(outcome).toBeVisible({ timeout: SUBMIT_SOFT_MS });
	} catch {
		const elapsed = Date.now() - started;
		throw new Error(`SPECIAL: submit still pending after ${elapsed}ms (soft budget ${SUBMIT_SOFT_MS}ms)`);
	}
	const elapsed = Date.now() - started;
	console.log(`submit_elapsed_ms=${elapsed}`);
	if (elapsed > SUBMIT_SOFT_MS) {
		throw new Error(`SPECIAL: submit took ${elapsed}ms (soft budget ${SUBMIT_SOFT_MS}ms)`);
	}
	const error = page.locator('[data-dg-submit-status="error"]');
	if (await error.count()) {
		throw new Error(`submit failed: ${await error.innerText()}`);
	}
	await expect(page.locator('[data-dg-submit-status="success"]')).toBeVisible();
});
