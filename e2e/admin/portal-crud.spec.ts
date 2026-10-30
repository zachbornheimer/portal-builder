import { test, expect, type Page, type Route, type Locator } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { loadEnv } from '../../tests/support/load-env.mjs';
import { ensureAdminSession } from '../../tests/support/admin-session.mjs';
import { seedPortal } from '../../tests/support/seed.mjs';
import { restJson } from '../../tests/support/wp-rest.mjs';
import { cleanupPortal } from '../../tests/support/cleanup.mjs';

const env = loadEnv();

const PORTAL_LIST = '/wp-admin/edit.php?post_type=portal';
const PORTAL_TRASH_LIST = '/wp-admin/edit.php?post_status=trash&post_type=portal';
const PORTAL_REST = '/wp-json/wp/v2/portal';

const NAV_ATTEMPTS = 4;
const NAV_TIMEOUT_MS = 60_000;
const UI_TIMEOUT_MS = 30_000;
const RETRY_PAUSE_MS = 1_000;
/** REST is fast when LocalWP is healthy; keep budget tight so hangs fail fast. */
const REST_TIMEOUT_MS = 45_000;

/**
 * Navigate with retries — LocalWP under load often ERR_ABORTED on first try.
 */
async function gotoWithRetry(page: Page, url: string): Promise<void> {
	let lastError: unknown;
	for (let attempt = 1; attempt <= NAV_ATTEMPTS; attempt++) {
		try {
			await page.goto(url, {
				waitUntil: 'domcontentloaded',
				timeout: NAV_TIMEOUT_MS,
			});
			return;
		} catch (err) {
			lastError = err;
			const message = String(err);
			const pathOnly = url.split('?')[0] ?? url;
			if (/ERR_ABORTED/i.test(message) && page.url().includes(pathOnly)) {
				return;
			}
			if (/has been closed|Target page/i.test(message)) {
				throw err;
			}
			await page.waitForTimeout(RETRY_PAUSE_MS * attempt);
		}
	}
	throw new Error(
		`gotoWithRetry failed for ${url} after ${NAV_ATTEMPTS} attempts: ${
			(lastError as Error)?.message || lastError
		}`,
	);
}

/**
 * Admin list URL for portals, optionally filtered by search term + status.
 */
function portalListUrl(opts: {
	search?: string;
	status?: 'all' | 'trash' | 'publish';
}): string {
	const params = new URLSearchParams({ post_type: 'portal' });
	if (opts.status === 'trash') {
		params.set('post_status', 'trash');
	} else if (opts.status === 'publish') {
		params.set('post_status', 'publish');
	} else if (opts.status === 'all') {
		params.set('post_status', 'all');
	}
	if (opts.search) {
		params.set('s', opts.search);
	}
	return `/wp-admin/edit.php?${params.toString()}`;
}

/**
 * Open list (or trash) filtered by unique title so pagination cannot hide the row.
 */
async function openListRow(
	page: Page,
	portalId: string,
	opts: { search: string; status?: 'all' | 'trash' | 'publish' },
): Promise<void> {
	const urls = [
		portalListUrl({ search: opts.search, status: opts.status }),
		// Fallbacks without search / alternate status
		opts.status === 'trash' ? PORTAL_TRASH_LIST : PORTAL_LIST,
		portalListUrl({ search: opts.search, status: 'all' }),
	];
	let lastError: unknown;
	for (const listUrl of urls) {
		for (let attempt = 1; attempt <= NAV_ATTEMPTS; attempt++) {
			try {
				await gotoWithRetry(page, listUrl);
				const row = page.locator(`#post-${portalId}`);
				if ((await row.count()) > 0) {
					await expect(row).toBeVisible({ timeout: UI_TIMEOUT_MS });
					return;
				}
			} catch (err) {
				lastError = err;
				if (/has been closed|Target page/i.test(String(err))) throw err;
				await page.waitForTimeout(RETRY_PAUSE_MS * attempt);
			}
		}
	}
	throw new Error(
		`portal ${portalId} not found on list (search=${opts.search} status=${
			opts.status ?? 'default'
		}): ${(lastError as Error)?.message || lastError || 'no row'}`,
	);
}

/**
 * Edit portal title via authenticated REST.
 * Block-editor Save is flaky under LocalWP headless load; REST is the durable write path.
 */
async function editPortalTitle(
	page: Page,
	portalId: string,
	title: string,
): Promise<void> {
	const { ok, status, body } = await restJson(page, `${PORTAL_REST}/${portalId}`, {
		method: 'POST',
		data: { title },
		timeoutMs: REST_TIMEOUT_MS,
		attempts: 2,
	});
	if (!ok) {
		throw new Error(
			`editPortalTitle ${portalId} failed HTTP ${status}: ${JSON.stringify(body)}`,
		);
	}
}

/**
 * Soft-trash portal via REST DELETE.
 */
async function trashPortal(page: Page, portalId: string): Promise<void> {
	const { ok, status, body } = await restJson(page, `${PORTAL_REST}/${portalId}`, {
		method: 'DELETE',
		timeoutMs: REST_TIMEOUT_MS,
		attempts: 2,
	});
	if (!ok) {
		throw new Error(
			`trashPortal ${portalId} failed HTTP ${status}: ${JSON.stringify(body)}`,
		);
	}
	const bodyStatus =
		body && typeof body === 'object' && 'status' in body
			? String((body as { status?: string }).status)
			: '';
	if (bodyStatus && bodyStatus !== 'trash') {
		throw new Error(
			`trashPortal ${portalId} expected status=trash, got ${bodyStatus}`,
		);
	}
}

function titleFromRestBody(body: unknown): string {
	if (!body || typeof body !== 'object') return '';
	const title = (body as { title?: unknown }).title;
	if (typeof title === 'string') return title;
	if (title && typeof title === 'object') {
		const t = title as { raw?: string; rendered?: string };
		return String(t.raw ?? t.rendered ?? '');
	}
	return '';
}

test.describe('portal admin CRUD matrix', () => {
	test.describe.configure({ retries: 1 });

	test('create → edit → trash via direct action', async ({ page }) => {
		// Login + heavy admin list pages need headroom under LocalWP.
		test.setTimeout(240_000);

		await ensureAdminSession(page, { env, timeoutMs: 60_000 });

		const stamp = Date.now();
		const title = `${env.testPortalPrefix}crud-${stamp}`;
		const titleEdited = `${title}-edited`;

		// CREATE — authenticated REST seed (show_in_rest portal CPT)
		const seeded = await seedPortal(page, {
			env,
			skipLogin: true,
			title,
			label: 'crud',
		});
		const portalId = seeded.id;
		expect(portalId).toMatch(/^\d+$/);

		// EDIT title via REST
		await editPortalTitle(page, portalId, titleEdited);

		// Confirm edit stuck (REST)
		const check = await restJson(
			page,
			`${PORTAL_REST}/${portalId}?context=edit`,
			{ method: 'GET', timeoutMs: REST_TIMEOUT_MS, attempts: 2 },
		);
		expect(check.ok, JSON.stringify(check.body)).toBeTruthy();
		expect(titleFromRestBody(check.body)).toContain('edited');

		// LIST — search by unique edited title so pagination cannot hide the row
		await openListRow(page, portalId, {
			search: titleEdited,
			status: 'publish',
		});
		const listRow = page.locator(`#post-${portalId}`);
		await expect(listRow.locator('.row-title').first()).toContainText(
			/edited/,
			{ timeout: UI_TIMEOUT_MS },
		);

		// TRASH via REST
		await trashPortal(page, portalId);

		// Confirm in trash list (search by title)
		await openListRow(page, portalId, {
			search: titleEdited,
			status: 'trash',
		});

		// Artifact
		fs.mkdirSync(env.artifactDirAbs, { recursive: true });
		const artifactPath = path.join(
			env.artifactDirAbs,
			`portal-crud-${stamp}.json`,
		);
		const payload = {
			ok: true,
			portalId,
			title: titleEdited,
			at: new Date().toISOString(),
		};
		fs.writeFileSync(artifactPath, JSON.stringify(payload, null, 2));
		expect(fs.existsSync(artifactPath)).toBeTruthy();
	});
});

const recordsTest = test.extend<{ portalId: string }>({
	portalId: async ({ page }, use) => {
		const seeded = await seedPortal(page, { env, label: 'records-invites' });
		await page.route(`**/portals/${seeded.id}/records?*`, (route) => route.fulfill({
			json: { portalId: Number(seeded.id), total: 0, records: [] },
		}));
		await page.route(`**/portals/${seeded.id}/invites`, (route) => route.fulfill({ json: { invites: [] } }));
		try {
			await use(seeded.id);
		} finally {
			await cleanupPortal(page, seeded.id, { env });
		}
	},
});

recordsTest.describe('records and invitations', () => {
recordsTest.setTimeout(120_000);
// Match the visual-proof desktop viewport; this site's third-party admin toolbar
// wraps over the final wizard tabs at the default 1280px browser-test width.
recordsTest.use({ viewport: { width: 1440, height: 900 } });

async function openRecords(page: Page, portalId: string) {
	await page.goto(`/wp-admin/admin.php?page=dg-portal-setup&portal_id=${portalId}`, { waitUntil: 'domcontentloaded' });
	await page.locator('[data-step="records"]').click();
	await expect(page.getByRole('heading', { name: 'Submissions and invitations' })).toBeVisible();
}

// REST interceptions below are deliberate UI fixtures, not evidence of live store contents.
recordsTest('a delayed two-record response renders both submissions with the bootstrap nonce', async ({ page, portalId }) => {
	let pendingRoute!: Route;
	await page.route(`**/portals/${portalId}/records?*`, (route) => { pendingRoute = route; });
	await openRecords(page, portalId);
	await expect(page.getByText('Loading submissions…')).toBeVisible();
	await expect(page.getByText('No submissions yet.', { exact: true })).toHaveCount(0);
	await expect.poll(() => Boolean(pendingRoute)).toBe(true);
	const route = pendingRoute;
	const nonce = await page.locator('[data-portal-wizard]').getAttribute('data-rest-nonce');
	expect(nonce).toBeTruthy();
	expect(route.request().headers()['x-wp-nonce']).toBe(nonce);
	await route.fulfill({ json: {
		portalId: Number(portalId), total: 2,
		records: [
			{ applicationId: 'fixture-2', name: 'Second applicant', status: 'current' },
			{ applicationId: 'fixture-1', name: 'First applicant', status: 'logged' },
		],
	} });
	const table = page.getByRole('table', { name: 'Recent submissions' });
	await expect(table.getByRole('row')).toHaveCount(3);
	await expect(table).toContainText('Second applicant');
	await expect(table).toContainText('First applicant');
	await expect(page.getByText('Showing all 2.')).toBeVisible();
	await expect(page.getByText('No submissions yet.', { exact: true })).toHaveCount(0);
});

for (const failure of [
	{ name: 'nonce rejection', status: 403, body: { message: 'Cookie check failed' }, message: 'Cookie check failed' },
	{ name: 'malformed success', status: 200, body: {}, message: 'Records GET returned an invalid response.' },
	{ name: 'inconsistent count', status: 200, body: { total: 2, records: [] }, message: 'Records GET returned an invalid response.' },
]) {
	recordsTest(`${failure.name} renders an alert, never the empty state`, async ({ page, portalId }) => {
		await page.route(`**/portals/${portalId}/records?*`, (route) => route.fulfill({
			status: failure.status, json: { portalId: Number(portalId), ...failure.body },
		}));
		await openRecords(page, portalId);
		await expect(page.getByRole('alert').filter({ hasText: failure.message })).toBeVisible();
		await expect(page.getByText('No submissions yet.', { exact: true })).toHaveCount(0);
	});
}

recordsTest('missing bootstrap nonce renders honest errors without calling records', async ({ page, portalId }) => {
	let recordsRequests = 0;
	await page.route(`**/portals/${portalId}/records?*`, (route) => {
		recordsRequests++;
		return route.fulfill({ json: { portalId: Number(portalId), total: 0, records: [] } });
	});
	await page.route(`/wp-admin/admin.php?page=dg-portal-setup&portal_id=${portalId}`, async (route) => {
		const response = await route.fetch();
		const html = await response.text();
		expect(html).toMatch(/data-rest-nonce="[^"]+"/);
		await route.fulfill({ response, body: html.replace(/data-rest-nonce="[^"]*"/g, 'data-rest-nonce=""') });
	}, { times: 1 });
	await openRecords(page, portalId);
	await expect(page.locator('[data-portal-wizard]')).toHaveAttribute('data-rest-nonce', '');
	await expect(page.getByRole('alert').filter({ hasText: 'Missing portal id or REST credentials.' })).toHaveCount(2);
	await expect(page.getByText('No submissions yet.', { exact: true })).toHaveCount(0);
	expect(recordsRequests).toBe(0);
});

recordsTest('an explicitly empty feed alone renders no submissions', async ({ page, portalId }) => {
	await openRecords(page, portalId);
	await expect(page.getByText('No submissions yet.', { exact: true })).toBeVisible();
	await expect(page.getByRole('alert')).toHaveCount(0);
});

const candidates = [
	{ userId: 101, name: 'Ada Applicant', login: 'ada', email: 'ada@example.test', hasSubmission: false },
	{ userId: 102, name: 'Bea Applicant', login: 'bea', email: 'bea@example.test', hasSubmission: true },
];

recordsTest('candidate options support keyboard navigation and selection, and expiry stays compact', async ({ page, portalId }) => {
	await page.route(`**/portals/${portalId}/invite-candidates?*`, (route) => route.fulfill({ json: { candidates } }));
	await openRecords(page, portalId);
	const search = page.getByRole('combobox', { name: 'Find a WordPress user' });
	await search.fill('Applicant');
	const list = page.getByRole('listbox', { name: 'WordPress users' });
	await expect(list.getByRole('option')).toHaveCount(2);
	await expect(list.getByRole('button')).toHaveCount(0);
	await search.press('ArrowDown');
	await search.press('Home');
	await expect(search).toHaveAttribute('aria-activedescendant', (await list.getByRole('option').first().getAttribute('id'))!);
	await search.press('End');
	await expect(search).toHaveAttribute('aria-activedescendant', (await list.getByRole('option').last().getAttribute('id'))!);
	await search.press('ArrowUp');
	await expect(search).toHaveAttribute('aria-activedescendant', (await list.getByRole('option').first().getAttribute('id'))!);
	await search.press('Escape');
	await expect(list).toHaveCount(0);
	await expect(search).toBeFocused();
	await search.press('ArrowDown');
	await search.press('End');
	await search.press('Enter');
	await expect(page.getByLabel('Email', { exact: true })).toHaveValue('bea@example.test');
	await expect(page.getByText('WordPress user selected:', { exact: false })).toContainText('Bea Applicant');
	await expect(list).toHaveCount(0);
	await search.press('Tab');
	await expect(page.getByLabel('Email', { exact: true })).toBeFocused();

	const expiry = page.getByRole('combobox', { name: 'Invite expires in' });
	await expect(expiry).toHaveValue('14');
	await expiry.selectOption('30');
	const geometry = await expiry.evaluate((element) => ({
		width: element.getBoundingClientRect().width,
		parentWidth: element.parentElement!.getBoundingClientRect().width,
		appearance: getComputedStyle(element).appearance,
	}));
	expect(geometry.width).toBeLessThan(geometry.parentWidth / 2);
	expect(geometry.appearance).toBe('auto');

	let invitePayload: unknown;
	await page.route(`**/portals/${portalId}/invites`, async (route) => {
		if (route.request().method() !== 'POST') return route.fulfill({ json: { invites: [] } });
		invitePayload = route.request().postDataJSON();
		return route.fulfill({ json: { invite: { id: 'fixture-invite' } } });
	});
	await page.getByRole('button', { name: 'Send invite', exact: true }).click();
	await expect(page.getByRole('status').filter({ hasText: 'Invite sent.' })).toBeVisible();
	expect(invitePayload).toEqual({ email: 'bea@example.test', userId: 102, expiresInDays: 30 });
});

recordsTest('a late candidate search cannot replace newer options; pointer selection fills email', async ({ page, portalId }) => {
	let oldRoute!: Route;
	await page.route(`**/portals/${portalId}/invite-candidates?*`, (route) => {
		if (new URL(route.request().url()).searchParams.get('search') === 'Ada') {
			oldRoute = route;
			return;
		}
		return route.fulfill({ json: { candidates: [candidates[1]] } });
	});
	await openRecords(page, portalId);
	const search = page.getByRole('combobox', { name: 'Find a WordPress user' });
	await search.fill('Ada');
	await expect.poll(() => Boolean(oldRoute)).toBe(true);
	await search.fill('Bea');
	const bea = page.getByRole('option', { name: /Bea Applicant/ });
	await expect(bea).toBeVisible();
	await oldRoute.fulfill({ json: { candidates: [candidates[0]] } });
	await expect(page.getByRole('option', { name: /Ada Applicant/ })).toHaveCount(0);
	await bea.click();
	await expect(page.getByLabel('Email', { exact: true })).toHaveValue('bea@example.test');
});

recordsTest('existing invitation actions retain names and semantics without button chrome', async ({ page, portalId }) => {
	const invite = { id: 'fixture-invite', email: 'ada@example.test', name: 'Ada Applicant', status: 'pending', url: 'https://example.test/invite/fixture' };
	await page.route(`**/portals/${portalId}/invites`, (route) => route.fulfill({ json: { invites: [invite] } }));
	await openRecords(page, portalId);
	for (const name of ['Copy invite link for ada@example.test', 'Resend invite to ada@example.test', 'Revoke invite for ada@example.test']) {
		const action = page.getByRole('button', { name, exact: true });
		await expect(action).toBeVisible();
		await action.focus();
		await expect(action).toBeFocused();
		const style = await action.evaluate((element) => ({ border: getComputedStyle(element).borderTopWidth, background: getComputedStyle(element).backgroundColor }));
		expect(style.border).toBe('0px');
		expect(style.background).toBe('rgba(0, 0, 0, 0)');
	}
	await page.route(`**/portals/${portalId}/invites/fixture-invite/resend`, (route) => route.fulfill({ json: { invite } }));
	const resend = page.waitForRequest((request) => request.url().endsWith('/invites/fixture-invite/resend') && request.method() === 'POST');
	await page.getByRole('button', { name: 'Resend invite to ada@example.test', exact: true }).press('Enter');
	await resend;
	await expect(page.getByRole('button', { name: 'Revoke invite for ada@example.test', exact: true })).toBeEnabled();
	await page.route(`**/portals/${portalId}/invites/fixture-invite`, (route) => route.fulfill({ json: { revoked: true } }));
	const revoke = page.waitForRequest((request) => request.url().endsWith('/invites/fixture-invite') && request.method() === 'DELETE');
	await page.getByRole('button', { name: 'Revoke invite for ada@example.test', exact: true }).press('Space');
	await revoke;
});
});

async function expectCheckedAppearance(control: Locator) {
	await expect(control).toBeChecked();
	await expect.poll(async () => control.evaluate((element) => {
		const styles = getComputedStyle(element);
		const swatch = document.createElement('span');
		element.parentElement!.append(swatch);
		const normalize = (color: string) => {
			swatch.style.color = color;
			return getComputedStyle(swatch).color;
		};
		const ember = normalize('var(--ember)');
		// An animated border can serialize opaque color as rgba(..., 1).
		const matches = {
			background: normalize(styles.backgroundColor) === ember,
			border: normalize(styles.borderTopColor) === ember,
		};
		swatch.remove();
		return matches;
	})).toEqual({ background: true, border: true });
	await expect(control).not.toHaveAttribute('name', '');
}

test('Required and Free for members toggle real state, survive save, and paint checked state', async ({ page }) => {
	test.setTimeout(180_000);
	await page.setViewportSize({ width: 1440, height: 900 });
	await ensureAdminSession(page, { env, timeoutMs: 60_000 });
	const seeded = await seedPortal(page, { env, skipLogin: true, label: 'checkbox-controls' });
	try {
		const definition = JSON.parse(fs.readFileSync('tests/fixtures/portals/generic-host.definition.json', 'utf8'));
		definition.title = seeded.title;
		definition.options.freeForMembers = false;
		const seededDefinition = await restJson(page, `/wp-json/dragongate/v1/portals/${seeded.id}/definition`, {
			method: 'POST', data: { definition },
		});
		expect(seededDefinition.ok, JSON.stringify(seededDefinition.body)).toBeTruthy();
		await page.goto(`/wp-admin/admin.php?page=dg-portal-setup&portal_id=${seeded.id}`);
		await page.locator('[data-step="build"]').click();
		await page.locator('[data-field-id="work_title"] .dg-field-row-main').click();
		const required = page.getByRole('checkbox', { name: 'Required', exact: true });
		await expectCheckedAppearance(required);
		await page.locator('label[for="dg-field-required"]').click();
		await expect(required).not.toBeChecked();
		await required.focus();
		await required.press('Space');
		await expectCheckedAppearance(required);
		await expect(required).not.toHaveCSS('box-shadow', 'none');
		await required.uncheck();

		await page.locator('[data-step="publish"]').click();
		const freeMembers = page.getByRole('checkbox', { name: 'Free for members', exact: true });
		await expect(freeMembers).not.toBeChecked();
		await page.locator('label[for="dg-free-members"]').click();
		await expectCheckedAppearance(freeMembers);
		await page.locator('[data-dg-save-draft]').click();
		await expect(page.locator('[data-dg-save-draft]')).toContainText('Saved');
		const saved = await restJson(page, `/wp-json/dragongate/v1/portals/${seeded.id}/definition`, { method: 'GET' });
		expect(saved.ok).toBeTruthy();
		expect(saved.body.definition.options.freeForMembers).toBe(true);
		expect(saved.body.definition.fields.find((field: { id: string }) => field.id === 'work_title').required).toBe(false);

		await page.reload();
		await page.locator('[data-step="publish"]').click();
		await expectCheckedAppearance(freeMembers);
		await freeMembers.uncheck();
		await page.locator('[data-step="build"]').click();
		await page.locator('[data-step="publish"]').click();
		await expect(freeMembers).not.toBeChecked();

		const defaults = await page.locator('[data-dg-site-defaults]').evaluate((element) => JSON.parse(element.textContent || '{}'));
		await page.locator('.dg-default-row').filter({ has: freeMembers }).getByRole('button', { name: 'Use site default', exact: true }).click();
		await expect(freeMembers).toBeChecked({ checked: Boolean(defaults.freeForMembers) });
		await expect(page.locator('.dg-default-row').filter({ has: freeMembers })).toContainText('Using site default');

		await page.getByRole('checkbox', { name: 'Anonymize files for adjudicators', exact: true }).check();
		await page.locator('#dg-anonymize-ack').fill('Custom certification');
		await page.getByRole('checkbox', { name: 'Do not store the original if anonymize fails', exact: true }).check();
		for (const width of [1280, 390]) {
			await page.setViewportSize({ width, height: 900 });
			const rows = page.locator('.dg-default-row');
			await expect(rows).toHaveCount(4);
			for (const row of await rows.all()) {
				await expect(row).toHaveCSS('display', 'flex');
				await expect(row).toHaveCSS('align-items', 'center');
				await expect(row).toHaveCSS('flex-wrap', 'wrap');
				expect(await row.evaluate((element) => element.scrollWidth <= element.clientWidth + 1)).toBe(true);
			}
		}
	} finally {
		await cleanupPortal(page, seeded.id, { env });
	}
});

test('Default Settings aligns every control row and keeps native checkbox toggles', async ({ page }) => {
	test.setTimeout(120_000);
	await ensureAdminSession(page, { env, timeoutMs: 60_000 });
	await page.goto('/wp-admin/edit.php?post_type=portal&page=portal-default-settings');
	const settings = page.locator('.dg-default-settings');
	await expect(settings.getByRole('heading', { name: 'Default Settings', exact: true })).toBeVisible();
	const checkboxes = settings.locator('input[type="checkbox"]');
	expect(await checkboxes.count()).toBeGreaterThanOrEqual(5);
	for (const checkbox of await checkboxes.all()) {
		expect(await checkbox.getAttribute('name')).toBeTruthy();
		const wasChecked = await checkbox.isChecked();
		await checkbox.click();
		await expect(checkbox).toBeChecked({ checked: !wasChecked });
		await checkbox.press('Space');
		await expect(checkbox).toBeChecked({ checked: wasChecked });
		await expect(checkbox.locator('..')).toHaveCSS('align-items', 'center');
	}
	for (const width of [1280, 390]) {
		await page.setViewportSize({ width, height: 900 });
		const rows = settings.locator('.form-table > tbody > tr, .dg-settings-control-row, .dg-settings-check-row, p.submit');
		expect(await rows.count()).toBeGreaterThan(30);
		for (const row of await rows.all()) {
			await expect(row).toHaveCSS('display', 'flex');
			await expect(row).toHaveCSS('align-items', 'center');
			expect(await row.evaluate((element) => element.scrollWidth <= element.clientWidth + 1)).toBe(true);
		}
	}
	// No Save or Google test click: auditing layout must not mutate real site options.
});
