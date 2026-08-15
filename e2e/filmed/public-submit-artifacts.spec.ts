/**
 * Film a logged-out applicant submit and prove sheet + Drive artifacts.
 *
 *   npm run test:e2e:video
 *
 * Video lands in tests/.artifacts/videos/last-public-submit.webm (or .mp4).
 * HTTP submit needs test mode inside LocalWP PHP (DG_TEST_MODE, option
 * dg_test_mode, or marker tests/.artifacts/.dg-test-mode on the plugin realpath).
 */
import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { loadEnv } from '../../tests/support/load-env.mjs';
import { ensureAdminSession } from '../../tests/support/admin-session.mjs';
import { seedPortal } from '../../tests/support/seed.mjs';
import { cleanupPortal } from '../../tests/support/cleanup.mjs';
import { restJson } from '../../tests/support/wp-rest.mjs';
import { copyLastVideo, copyVideoToStable } from '../../tests/support/copy-last-video.mjs';
import {
	TEST_MODE_HELP,
	enableTestMode,
	phpArtifactDir,
} from '../../tests/support/enable-test-mode.mjs';

const env = loadEnv();
const definition = JSON.parse(
	fs.readFileSync('tests/fixtures/portals/herbolzheimer.definition.json', 'utf8'),
);
const scorePath = path.resolve('tests/fixtures/files/sample-score.pdf');
const recordingPath = path.resolve('tests/fixtures/files/sample-recording.mp3');

const DEFINITION_ROUTE = (id: string) =>
	`/wp-json/dragongate/v1/portals/${id}/definition`;

const APPLICANT = {
	title: 'Ms',
	name: 'Elena Varga',
	affiliation: 'ISJAC',
	address: '184 Bergen Street',
	city: 'Brooklyn',
	zip: '11217',
	phone: '+1-718-555-0142',
};

function publicPortalPath(seeded: { id: string; linkPath?: string; slug?: string }) {
	const base =
		seeded.linkPath ||
		(seeded.slug ? `/portal/${seeded.slug}/` : `/?p=${seeded.id}&post_type=portal`);
	return base.endsWith('/') || base.includes('?') ? base : `${base}/`;
}

function lastSheetRow(sheetPath: string) {
	const lines = fs.readFileSync(sheetPath, 'utf8').trim().split('\n').filter(Boolean);
	if (lines.length === 0) {
		throw new Error(`sheet is empty: ${sheetPath}`);
	}
	return JSON.parse(lines[lines.length - 1]!);
}

async function selectOrInject(
	_page: import('@playwright/test').Page,
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

	// Open uses window.open(blob). A blocked popup would location.assign away.
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

test('logged-out applicant submit writes sheet and drive artifacts', async ({
	browser,
	page,
}, testInfo) => {
	await ensureAdminSession(page, { env });
	const seeded = await seedPortal(page, {
		env,
		skipLogin: true,
		label: 'filmed-submit',
	});
	const portalId = seeded.id;
	const workTitle = `Filmed Passacaglia ${portalId}`;
	const email = `filmed.applicant+${portalId}@example.com`;
	const publicPath = publicPortalPath(seeded);
	const artifactDir = phpArtifactDir(env);
	const videoDestDir = path.join(env.artifactDirAbs, 'videos');
	let filmedVideoSource: string | null = null;

	try {
		await test.step('publish herbolzheimer definition and enable test mode', async () => {
			const put = await restJson(page, DEFINITION_ROUTE(portalId), {
				method: 'POST',
				data: { definition },
			});
			expect(put.ok, JSON.stringify(put.body)).toBeTruthy();
			expect(put.body?.definition?.fields?.[0]?.type).toBe('applicant_pack');

			const portalGet = await restJson(page, `/wp-json/wp/v2/portal/${portalId}`, {
				method: 'GET',
			});
			expect(portalGet.ok, JSON.stringify(portalGet.body)).toBeTruthy();
			expect(portalGet.body?.status).toBe('publish');

			const enabled = enableTestMode(env);
			expect(fs.existsSync(enabled.markerPath)).toBeTruthy();
		});

		const applicant = await browser.newContext({
			baseURL: env.baseUrl,
			recordVideo: { dir: testInfo.outputDir },
		});
		const publicPage = await applicant.newPage();

		try {
			await test.step('open public form as logged-out applicant', async () => {
				const res = await publicPage.goto(publicPath, {
					waitUntil: 'domcontentloaded',
					timeout: 90_000,
				});
				expect(
					res === null || (res.status() >= 200 && res.status() < 400),
					`public HTTP ${res?.status()}`,
				).toBeTruthy();

				await expect(publicPage.locator('[data-dg-render="definition"]')).toBeVisible({
					timeout: 45_000,
				});
				await expect(publicPage.locator('form.dg-portal-submit-form')).toBeVisible();
				await expect(publicPage.getByLabel('Title of Work')).toBeVisible();
			});

			await test.step('fill applicant pack and work title', async () => {
				const form = publicPage.locator('form.dg-portal-submit-form');
				await form.locator('#sub_title').fill(APPLICANT.title);
				await form.locator('#sub_name').fill(APPLICANT.name);
				await form.locator('#sub_email').fill(email);
				await form.locator('#sub_inst_affil').fill(APPLICANT.affiliation);
				await form.locator('#sub_address_first_part').fill(APPLICANT.address);
				await form.locator('#sub_city').fill(APPLICANT.city);
				await selectOrInject(
					publicPage,
					form.locator('select[name="sub_country"]'),
					'US',
					'United States',
				);
				const state = form.locator('select[name="sub_state"]');
				await expect
					.poll(async () => state.locator('option').count(), { timeout: 15_000 })
					.toBeGreaterThan(1);
				await selectOrInject(publicPage, state, 'NC', 'North Carolina');
				await form.locator('#sub_zip').fill(APPLICANT.zip);
				await form.locator('#sub_phone').fill(APPLICANT.phone);
				await form.getByLabel('Title of Work').fill(workTitle);
			});

			await test.step('attach score and recording, then confirm each file', async () => {
				await confirmFileCard(publicPage, 'score', /Full Score/, scorePath);
				await confirmFileCard(publicPage, 'recording', /Recording/, recordingPath);
			});

			await test.step('submit and wait for success', async () => {
				await publicPage.getByRole('button', { name: 'Submit application' }).click();

				const success = publicPage.locator('[data-dg-submit-status="success"]');
				const error = publicPage.locator('[data-dg-submit-status="error"]');
				await expect(success.or(error)).toBeVisible({ timeout: 60_000 });

				if (await error.count()) {
					const copy = await error.innerText();
					throw new Error(
						`HTTP submit failed (test mode off or validation).\n${copy}\n\n${TEST_MODE_HELP}`,
					);
				}

				await expect(success).toBeVisible();
				await expect(publicPage.getByText(/submitted successfully/i)).toBeVisible();
			});
		} finally {
			const applicantFilm = publicPage.video();
			await applicant.close().catch(() => {});
			try {
				let stable: string | null = null;
				if (applicantFilm) {
					const recorded = await applicantFilm.path();
					if (recorded && fs.existsSync(recorded)) {
						filmedVideoSource = recorded;
						stable = copyVideoToStable(recorded, videoDestDir);
					}
				}
				if (!stable) {
					stable = copyLastVideo({
						searchDir: testInfo.outputDir,
						destDir: videoDestDir,
					});
				}
				if (stable) {
					console.log(`filmed video: ${stable}`);
				}
			} catch (err) {
				console.warn('filmed video copy skipped:', (err as Error).message);
			}
		}

		await test.step('assert sheet row and drive files for this portal', async () => {
			const sheet = path.join(artifactDir, 'sheets', `${portalId}.jsonl`);
			const driveDir = path.join(artifactDir, 'drive', portalId);
			expect(
				fs.existsSync(sheet),
				`missing sheet ${sheet}\n${TEST_MODE_HELP}`,
			).toBeTruthy();
			expect(
				fs.existsSync(driveDir),
				`missing drive dir ${driveDir}\n${TEST_MODE_HELP}`,
			).toBeTruthy();

			const row = lastSheetRow(sheet);
			expect(row.sub_email).toBe(email);
			expect(row.work_title ?? row.sub_work_title).toBe(workTitle);

			const driveFiles = fs.readdirSync(driveDir);
			expect(driveFiles.length, `empty drive dir ${driveDir}`).toBeGreaterThan(0);
			expect(driveFiles.some((name) => /score/i.test(name) && name.endsWith('.pdf'))).toBeTruthy();
			expect(driveFiles.some((name) => /recording/i.test(name) && name.endsWith('.mp3'))).toBeTruthy();

			fs.mkdirSync(env.artifactDirAbs, { recursive: true });
			fs.writeFileSync(
				path.join(env.artifactDirAbs, `filmed-submit-${portalId}.json`),
				JSON.stringify(
					{
						ok: true,
						portalId,
						publicPath,
						sheet,
						driveDir,
						driveFiles,
						workTitle,
						email,
						videoDir: videoDestDir,
						videoSource: filmedVideoSource,
						at: new Date().toISOString(),
					},
					null,
					2,
				),
			);
		});
	} finally {
		try {
			if (!page.isClosed()) {
				await cleanupPortal(page, portalId, { env, skipLogin: true });
			}
		} catch {
			// best-effort
		}
	}
});
