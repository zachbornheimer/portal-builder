/**
 * Live 2027 Call for Scores on LocalWP — real sheet + Drive, not test mode.
 *
 *   npx playwright test --project=chromium e2e/public/cfs-2027-live.spec.ts
 *
 * Hits http://localhost:10033/portal/2027-call-for-scores-and-papers
 */
import { test, expect } from '@playwright/test';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const PORTAL_PATH = '/portal/2027-call-for-scores-and-papers';
const SHEET_ID = '1w0EJ_kF2F3T4N9rr5kMCgPsVdFERc_-wGVF_8PGeH40';
const DRIVE_BASE = '1xQnOvBcqpKqhSvAOCpsyKtZk502nM-XG';
const FOLDER_URL_PREFIX = 'https://drive.google.com/drive/folders/';
const FILE_URL_PREFIX = 'https://drive.google.com/file/d/';

const scorePdf = path.resolve('tests/fixtures/files/valid-score.pdf');
const recording = path.resolve('tests/fixtures/files/sample-recording.mp3');
const reader = path.resolve('tests/support/read-google-last-row.php');

const stamp = Date.now().toString();

test.describe.configure({ mode: 'serial' });
test.setTimeout(360_000);

test.beforeAll(() => {
	const marker = path.resolve('tests/.artifacts/.dg-test-mode');
	if (fs.existsSync(marker)) {
		fs.unlinkSync(marker);
	}
});

async function selectOrInject(
	select: import('@playwright/test').Locator,
	value: string,
	label: string,
) {
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
	await select.evaluate(
		(el, pair) => {
			const opt = document.createElement('option');
			opt.value = pair.value;
			opt.textContent = pair.label;
			el.appendChild(opt);
		},
		{ value, label },
	);
	await select.selectOption(value);
}

async function confirmFileCard(
	page: import('@playwright/test').Page,
	fieldId: string,
	fileLabel: RegExp,
	filePath: string,
) {
	const card = page.locator(`[data-dg-field-id="${fieldId}"]`);
	await expect(card).toBeVisible();
	await card.getByLabel(fileLabel).setInputFiles(filePath);
	await expect(card).toHaveClass(/is-ready/);
	await page.evaluate(() => {
		const existing = window.open;
		if (existing && existing.name === 'dgFilmedOpen') {
			return;
		}
		const stub = function filmedOpen() {
			return { closed: false, close() {}, focus() {} };
		};
		stub.name = 'dgFilmedOpen';
		window.open = stub;
	});
	await card.getByRole('button', { name: /Open to confirm/i }).click();
	await expect(card).toHaveClass(/is-opened/);
	const confirm = card.getByRole('checkbox');
	await expect(confirm).toBeEnabled();
	await confirm.check();
	await expect(card).toHaveClass(/is-confirmed/);
}

async function fillApplicant(
	page: import('@playwright/test').Page,
	name: string,
	email: string,
) {
	const form = page.locator('form.dg-portal-submit-form');
	await form.locator('#sub_title').fill('Mx');
	await form.locator('#sub_name').fill(name);
	await form.locator('#sub_email').fill(email);
	await form.locator('#sub_inst_affil').fill('ISJAC Playwright');
	await form.locator('#sub_address_first_part').fill('100 Test Street');
	await form.locator('#sub_city').fill('Raleigh');
	await selectOrInject(form.locator('select[name="sub_country"]'), 'US', 'United States');
	const state = form.locator('select[name="sub_state"]');
	await expect.poll(async () => state.locator('option').count(), { timeout: 15_000 }).toBeGreaterThan(1);
	await selectOrInject(state, 'NC', 'North Carolina');
	await form.locator('#sub_zip').fill('27601');
	await form.locator('#sub_phone').fill('+1-919-555-0199');
}

async function openPublicForm(page: import('@playwright/test').Page) {
	const res = await page.goto(PORTAL_PATH, {
		waitUntil: 'domcontentloaded',
		timeout: 90_000,
	});
	expect(res === null || (res.status() >= 200 && res.status() < 400), `HTTP ${res?.status()}`).toBeTruthy();
	const closed = page.locator('[data-dg-portal-state="closed"]');
	if (await closed.count()) {
		throw new Error(`portal is closed: ${await closed.innerText()}`);
	}
	await expect(page.locator('form.dg-portal-submit-form')).toBeVisible({ timeout: 45_000 });
	await expect(page.getByRole('heading', { name: /2027 Call for Scores/i })).toBeVisible();
}

/**
 * Check the auto-injected anonymize certification when present.
 * Absent when anonymize is off — do not fail.
 */
async function acceptAnonymizeAck(page: import('@playwright/test').Page) {
	const ack = page.getByLabel(/I certify that my scores and recordings/i);
	if (await ack.count()) {
		await ack.check();
	}
}

function readSheet() {
	const r = spawnSync('php', [reader, `--sheet=${SHEET_ID}`], { encoding: 'utf8' });
	const text = (r.stdout || '') + (r.stderr || '');
	if (r.status !== 0) {
		throw new Error(`read sheet failed: ${text}`);
	}
	return JSON.parse(text.trim()) as {
		headers: string[];
		last: string[];
		named: Record<string, string>;
	};
}

function readDrive(folderId: string) {
	const r = spawnSync('php', [reader, `--drive-children=${folderId}`], { encoding: 'utf8' });
	const text = (r.stdout || '') + (r.stderr || '');
	if (r.status !== 0) {
		throw new Error(`read drive failed: ${text}`);
	}
	return JSON.parse(text.trim()) as {
		id: string;
		names: string[];
		folders: string[];
		files: string[];
	};
}

function folderIdFromUrl(url: string) {
	const i = url.indexOf(FOLDER_URL_PREFIX);
	if (i !== 0) {
		return '';
	}
	return url.slice(FOLDER_URL_PREFIX.length).split(/[/?#]/)[0] || '';
}

test('scores large-ensemble submit lands on the 2027 sheet and a top-level Drive folder', async ({
	browser,
}) => {
	const ctx = await browser.newContext();
	const page = await ctx.newPage();
	const title = `Playwright Large ${stamp}`;
	const email = `cfs.scores+${stamp}@example.com`;

	try {
		await openPublicForm(page);
		await fillApplicant(page, 'Playwright Scores', email);
		await confirmFileCard(page, 'bio', /Bio/, scorePdf);

		await page.getByLabel(/Scores\/Recordings/i).check();
		await expect(page.getByLabel(/Large Ensemble/i)).toBeVisible();
		await page.getByLabel(/Large Ensemble/i).check();
		const titleInput = page.locator('#sub_large_title');
		await expect(titleInput).toBeEnabled();
		await titleInput.fill(title);
		await confirmFileCard(page, 'large_score', /^Score/, scorePdf);
		await confirmFileCard(page, 'large_rec', /Recording/, recording);

		await acceptAnonymizeAck(page);
		await page.getByRole('button', { name: 'Submit application' }).click({
			timeout: 240_000,
			noWaitAfter: true,
		});
		const success = page.locator('[data-dg-submit-status="success"]');
		const error = page.locator('[data-dg-submit-status="error"]');
		await expect(success.or(error)).toBeVisible({ timeout: 240_000 });
		if (await error.count()) {
			throw new Error(`submit failed: ${await error.innerText()}`);
		}
		await expect(success).toBeVisible();
	} finally {
		await ctx.close();
	}

	const sheet = readSheet();
	const named = sheet.named;
	expect(named['Application ID'], JSON.stringify(named)).toBeTruthy();
	expect(String(named['Application Category'] || '')).toMatch(/scores/i);
	expect(String(named['Select a Category'] || '')).toMatch(/large/i);
	expect(String(named['Title of Work or Presentation'] || '')).toContain('Playwright Large');
	expect(String(named.Files || '')).toMatch(new RegExp(`^${FOLDER_URL_PREFIX}`));
	expect(String(named['Score Link'] || '')).toContain(FILE_URL_PREFIX);

	const appId = named['Application ID'];
	const base = readDrive(DRIVE_BASE);
	expect(base.folders, JSON.stringify(base)).toContain(appId);

	const appFolder = folderIdFromUrl(named.Files);
	expect(appFolder).toBeTruthy();
	const kids = readDrive(appFolder);
	expect(kids.files.some((n) => n.toLowerCase().endsWith('.pdf')), JSON.stringify(kids)).toBeTruthy();
	console.log(`cfs_scores_ok app=${appId} files=${named.Files}`);
});

test('poster submit writes a second top-level Drive folder', async ({ browser }) => {
	const before = readSheet();
	const beforeApp = before.named['Application ID'] || '';
	const beforeFiles = before.named.Files || '';

	const ctx = await browser.newContext();
	const page = await ctx.newPage();
	const email = `cfs.poster+${stamp}@example.com`;

	try {
		await openPublicForm(page);
		await fillApplicant(page, 'Playwright Poster', email);
		await confirmFileCard(page, 'bio', /Bio/, scorePdf);
		await page.getByLabel(/Poster Sessions/i).check();
		await expect(page.locator('[data-dg-field-id="poster_description"]')).toBeVisible();
		await confirmFileCard(page, 'poster_description', /Brief Description/, scorePdf);

		await acceptAnonymizeAck(page);
		await page.getByRole('button', { name: 'Submit application' }).click({
			timeout: 240_000,
			noWaitAfter: true,
		});
		const success = page.locator('[data-dg-submit-status="success"]');
		const error = page.locator('[data-dg-submit-status="error"]');
		await expect(success.or(error)).toBeVisible({ timeout: 240_000 });
		if (await error.count()) {
			throw new Error(`poster submit failed: ${await error.innerText()}`);
		}
	} finally {
		await ctx.close();
	}

	const sheet = readSheet();
	const named = sheet.named;
	expect(named['Application ID']).toBeTruthy();
	expect(named['Application ID']).not.toBe(beforeApp);
	expect(String(named['Application Category'] || '')).toMatch(/poster/i);
	expect(String(named.Files || '')).toMatch(new RegExp(`^${FOLDER_URL_PREFIX}`));
	expect(named.Files).not.toBe(beforeFiles);

	const appId = named['Application ID'];
	const base = readDrive(DRIVE_BASE);
	expect(base.folders, JSON.stringify(base)).toContain(appId);
	console.log(`cfs_poster_ok app=${appId} files=${named.Files} base_folders=${base.folders.length}`);
});

function latestMailPayload() {
	const dir = path.resolve('tests/.artifacts/mail');
	if (!fs.existsSync(dir)) {
		return null;
	}
	const files = fs
		.readdirSync(dir)
		.filter((name) => name.endsWith('.json'))
		.map((name) => {
			const full = path.join(dir, name);
			return { full, mtime: fs.statSync(full).mtimeMs };
		})
		.sort((a, b) => b.mtime - a.mtime);
	if (!files.length) {
		return null;
	}
	return JSON.parse(fs.readFileSync(files[0].full, 'utf8')) as {
		to?: string;
		subject?: string;
		body?: string;
		tokens?: Record<string, string>;
		wpMail?: boolean;
		error?: string;
	};
}

test('papers submit writes dest row, Drive folder, and a receipt URL without email=', async ({
	browser,
}) => {
	const before = readSheet();
	const beforeApp = before.named['Application ID'] || '';
	const beforeFiles = before.named.Files || '';

	const ctx = await browser.newContext();
	const page = await ctx.newPage();
	const title = `Playwright Papers ${stamp}`;
	const email = `cfs.papers+${stamp}@example.com`;

	try {
		await openPublicForm(page);
		await fillApplicant(page, 'Playwright Papers', email);
		await confirmFileCard(page, 'bio', /Bio/, scorePdf);
		await page.getByLabel(/Research\/Analysis Papers/i).check();
		const titleInput = page.locator('#sub_paper_title');
		await expect(titleInput).toBeVisible();
		await titleInput.fill(title);
		await confirmFileCard(page, 'paper_abstract', /Abstract/, scorePdf);

		await acceptAnonymizeAck(page);
		await page.getByRole('button', { name: 'Submit application' }).click({
			timeout: 240_000,
			noWaitAfter: true,
		});
		const success = page.locator('[data-dg-submit-status="success"]');
		const error = page.locator('[data-dg-submit-status="error"]');
		await expect(success.or(error)).toBeVisible({ timeout: 240_000 });
		if (await error.count()) {
			throw new Error(`papers submit failed: ${await error.innerText()}`);
		}
		await expect(success).toBeVisible();
	} finally {
		await ctx.close();
	}

	const sheet = readSheet();
	const named = sheet.named;
	expect(named['Application ID'], JSON.stringify(named)).toBeTruthy();
	expect(named['Application ID']).not.toBe(beforeApp);
	expect(String(named['Application Category'] || '')).toMatch(/papers/i);
	expect(String(named['Title of Work or Presentation'] || '')).toContain('Playwright Papers');
	expect(String(named.Files || '')).toMatch(new RegExp(`^${FOLDER_URL_PREFIX}`));
	expect(named.Files).not.toBe(beforeFiles);

	const appId = named['Application ID'];
	const base = readDrive(DRIVE_BASE);
	expect(base.folders, JSON.stringify(base)).toContain(appId);

	const mail = latestMailPayload();
	expect(mail, 'expected a captured mail JSON after papers submit').toBeTruthy();
	const blob = `${mail?.subject || ''}\n${mail?.body || ''}\n${JSON.stringify(mail?.tokens || {})}`;
	expect(blob).toMatch(/dg-receipt=1/);
	expect(blob).not.toMatch(/email=/i);
	console.log(
		`cfs_papers_ok app=${appId} files=${named.Files} date=${named['Date Received'] || ''} receipt=${named['Application Receipt Link'] || ''} wpMail=${mail?.wpMail === true}`,
	);
});

type TScoreKindWalk = {
	kindId: string;
	radio: RegExp;
	titleSelector: string;
	scoreField: string;
	recField: string;
	category: RegExp;
	logKey: string;
	emailLocal: string;
	name: string;
};

const SCORE_KIND_WALKS: TScoreKindWalk[] = [
	{
		kindId: 'small',
		radio: /Small Ensemble/i,
		titleSelector: '#sub_small_title',
		scoreField: 'small_score',
		recField: 'small_rec',
		category: /small/i,
		logKey: 'cfs_small_ok',
		emailLocal: 'cfs.small',
		name: 'Playwright Small',
	},
	{
		kindId: 'arrangement',
		radio: /Arrangement/i,
		titleSelector: '#sub_arrangement_title',
		scoreField: 'arrangement_score',
		recField: 'arrangement_rec',
		category: /arrangement/i,
		logKey: 'cfs_arrangement_ok',
		emailLocal: 'cfs.arrangement',
		name: 'Playwright Arrangement',
	},
	{
		kindId: 'first_takes',
		radio: /First Takes/i,
		titleSelector: '#sub_first_takes_title',
		scoreField: 'first_takes_score',
		recField: 'first_takes_rec',
		category: /first_takes|first takes/i,
		logKey: 'cfs_first_takes_ok',
		emailLocal: 'cfs.firsttakes',
		name: 'Playwright First Takes',
	},
	{
		kindId: 'student',
		radio: /Student\/Young Artist|Student/i,
		titleSelector: '#sub_student_title',
		scoreField: 'student_score',
		recField: 'student_rec',
		category: /student/i,
		logKey: 'cfs_student_ok',
		emailLocal: 'cfs.student',
		name: 'Playwright Student',
	},
];

for (const walk of SCORE_KIND_WALKS) {
	test(`scores ${walk.kindId} submit lands on the 2027 sheet and a top-level Drive folder`, async ({
		browser,
	}) => {
		const before = readSheet();
		const beforeApp = before.named['Application ID'] || '';
		const beforeFiles = before.named.Files || '';

		const ctx = await browser.newContext();
		const page = await ctx.newPage();
		const title = `${walk.name} ${stamp}`;
		const email = `${walk.emailLocal}+${stamp}@example.com`;

		try {
			await openPublicForm(page);
			await fillApplicant(page, walk.name, email);
			await confirmFileCard(page, 'bio', /Bio/, scorePdf);

			await page.getByLabel(/Scores\/Recordings/i).check();
			await expect(page.getByLabel(walk.radio)).toBeVisible();
			await page.getByLabel(walk.radio).check();
			const titleInput = page.locator(walk.titleSelector);
			await expect(titleInput).toBeEnabled();
			await titleInput.fill(title);
			await confirmFileCard(page, walk.scoreField, /^Score/, scorePdf);
			await confirmFileCard(page, walk.recField, /Recording/, recording);

			await acceptAnonymizeAck(page);
			await page.getByRole('button', { name: 'Submit application' }).click({
				timeout: 240_000,
				noWaitAfter: true,
			});
			const success = page.locator('[data-dg-submit-status="success"]');
			const error = page.locator('[data-dg-submit-status="error"]');
			await expect(success.or(error)).toBeVisible({ timeout: 240_000 });
			if (await error.count()) {
				throw new Error(`${walk.kindId} submit failed: ${await error.innerText()}`);
			}
			await expect(success).toBeVisible();
		} finally {
			await ctx.close();
		}

		const sheet = readSheet();
		const named = sheet.named;
		expect(named['Application ID'], JSON.stringify(named)).toBeTruthy();
		expect(named['Application ID']).not.toBe(beforeApp);
		expect(String(named['Application Category'] || '')).toMatch(/scores/i);
		expect(String(named['Select a Category'] || '')).toMatch(walk.category);
		expect(String(named['Title of Work or Presentation'] || '')).toContain(walk.name);
		expect(String(named.Files || '')).toMatch(new RegExp(`^${FOLDER_URL_PREFIX}`));
		expect(named.Files).not.toBe(beforeFiles);

		const appId = named['Application ID'];
		const base = readDrive(DRIVE_BASE);
		expect(base.folders, JSON.stringify(base)).toContain(appId);
		console.log(`${walk.logKey} app=${appId} files=${named.Files}`);
	});
}
