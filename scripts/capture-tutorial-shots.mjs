/**
 * Capture a tutorial-quality screenshot set from LocalWP (live UI).
 *
 * Usage (from repo root):
 *   node scripts/capture-tutorial-shots.mjs
 *
 * Writes PNGs + manifest to docs/design/tutorial/ and tests/.artifacts/tutorial/.
 */
import { chromium } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { loadEnv } from '../tests/support/load-env.mjs';
import { ensureAdminSession } from '../tests/support/admin-session.mjs';
import { seedPortal } from '../tests/support/seed.mjs';
import { cleanupPortal } from '../tests/support/cleanup.mjs';
import { restJson } from '../tests/support/wp-rest.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '..');

const VIEWPORT = { width: 1440, height: 900 };
const SHOT_TIMEOUT_MS = 90_000;

const env = loadEnv();
const definitionOpen = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'tests/fixtures/portals/herbolzheimer.definition.json'), 'utf8')
);
const definitionClosed = {
	...definitionOpen,
	title: 'Tutorial — Closed Portal',
	publish: {
		deadline: '2020-01-01T00:00:00',
		timezone: 'America/New_York',
		applicationFee: null,
		forceClosed: true,
	},
};

const outDocs = path.join(ROOT, 'docs/design/tutorial');
const outArtifacts = path.join(env.artifactDirAbs || path.join(ROOT, 'tests/.artifacts'), 'tutorial');
for (const dir of [outDocs, outArtifacts]) {
	fs.mkdirSync(dir, { recursive: true });
}

/** @type {{ id: string, file: string, title: string, caption: string }[]} */
const shots = [];

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} id e.g. "01-admin-portals"
 * @param {string} title
 * @param {string} caption
 * @param {{ fullPage?: boolean }} [opts]
 */
/** Hide chrome that steals attention from the product surface. */
async function declutter(page) {
	await page.addStyleTag({
		content: `
			#query-monitor, #query-monitor-main, #qm-icon-container,
			#wpadminbar .ab-top-secondary,
			.notice, .update-nag, .updated, .error,
			div[class*="foogallery"], .fg-trial, .fg-notice,
			#wpfooter, .components-snackbar-list,
			.yoast, #wpseo-metabox, .rank-math-metabox,
			#local-wp-menu, .localwp-indicator {
				display: none !important;
			}
			html { scroll-behavior: auto !important; }
		`,
	}).catch(() => {});
	// Dismiss remaining notice close buttons if still painted.
	await page.evaluate(() => {
		document
			.querySelectorAll(
				'.notice-dismiss, .components-notice__dismiss, button[aria-label="Dismiss this notice"]'
			)
			.forEach((el) => {
				try {
					/** @type {HTMLElement} */ (el).click();
				} catch {
					/* ignore */
				}
			});
	}).catch(() => {});
	await page.waitForTimeout(200);
}

async function snap(page, id, title, caption, opts = {}) {
	const file = `${id}.png`;
	const destDocs = path.join(outDocs, file);
	const destArt = path.join(outArtifacts, file);
	await declutter(page);
	const buf = await page.screenshot({
		fullPage: opts.fullPage ?? false,
		type: 'png',
		animations: 'disabled',
	});
	fs.writeFileSync(destDocs, buf);
	fs.writeFileSync(destArt, buf);
	shots.push({ id, file, title, caption });
	console.log(`  ✓ ${file} — ${title}`);
}

/**
 * Rename a seeded portal to a human-readable title (prefix only required at seed).
 * @param {import('@playwright/test').Page} page
 * @param {string} portalId
 * @param {string} title
 */
async function renamePortal(page, portalId, title) {
	const res = await restJson(page, `/wp-json/wp/v2/portal/${portalId}`, {
		method: 'POST',
		data: { title },
	});
	if (!res.ok) {
		throw new Error(`renamePortal ${portalId}: ${JSON.stringify(res.body)}`);
	}
	return res.body;
}

function publicPaths(seeded) {
	const base =
		seeded.linkPath ||
		(seeded.slug ? `/portal/${seeded.slug}/` : `/?p=${seeded.id}&post_type=portal`);
	const normalized = base.endsWith('/') || base.includes('?') ? base : `${base}/`;
	const preview = normalized.includes('?')
		? `${normalized}&preview=true`
		: `${normalized}?preview=true`;
	return { publicPath: normalized, previewPath: preview };
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} portalId
 * @param {unknown} definition
 */
async function putDefinition(page, portalId, definition) {
	const put = await restJson(page, `/wp-json/dragongate/v1/portals/${portalId}/definition`, {
		method: 'POST',
		data: { definition },
	});
	if (!put.ok) {
		throw new Error(`definition PUT failed: ${JSON.stringify(put.body)}`);
	}
	return put.body;
}

async function gotoRetry(page, url, timeout = SHOT_TIMEOUT_MS) {
	let last;
	for (let i = 1; i <= 4; i++) {
		try {
			await page.goto(url, { waitUntil: 'domcontentloaded', timeout });
			await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {});
			return;
		} catch (err) {
			last = err;
			if (/ERR_ABORTED/i.test(String(err)) && page.url().includes(url.split('?')[0])) {
				return;
			}
			await page.waitForTimeout(800 * i);
		}
	}
	throw last;
}

async function main() {
	console.log('Tutorial screenshot capture');
	console.log(`  baseUrl: ${env.baseUrl}`);
	console.log(`  out: ${outDocs}`);

	const browser = await chromium.launch({ headless: true });
	const context = await browser.newContext({
		viewport: VIEWPORT,
		deviceScaleFactor: 1,
		baseURL: env.baseUrl,
	});
	const page = await context.newPage();
	page.setDefaultTimeout(SHOT_TIMEOUT_MS);
	page.setDefaultNavigationTimeout(SHOT_TIMEOUT_MS);

	/** @type {string[]} */
	const portalIds = [];

	try {
		// --- 01 Admin home ---
		await ensureAdminSession(page, { env });
		await gotoRetry(page, '/wp-admin/');
		await page.locator('#wpadminbar, #adminmenu').first().waitFor({ state: 'visible' });
		await snap(
			page,
			'01-admin-dashboard',
			'WordPress admin dashboard',
			'Starting point after login. DragonGate lives under the Portals custom post type.'
		);

		// --- 02 Portal list ---
		await gotoRetry(page, '/wp-admin/edit.php?post_type=portal');
		await page.locator('#posts-filter, .wp-list-table, h1').first().waitFor({ state: 'visible' });
		await snap(
			page,
			'02-admin-portal-list',
			'Portal list (admin)',
			'All portals. Create, search, edit, and trash from this screen.'
		);

		// Seed two portals: open form + closed for public states
		const openPortal = await seedPortal(page, {
			env,
			skipLogin: true,
			label: 'tutorial-open',
		});
		portalIds.push(openPortal.id);
		await renamePortal(page, openPortal.id, 'Herbolzheimer Composition Prize');

		const closedPortal = await seedPortal(page, {
			env,
			skipLogin: true,
			label: 'tutorial-closed',
		});
		portalIds.push(closedPortal.id);
		await renamePortal(page, closedPortal.id, 'Herbolzheimer Prize (Closed)');

		// --- 03 Edit screen (open portal, before definition) ---
		await gotoRetry(page, openPortal.editUrl);
		await page.locator('#poststuff, .block-editor, #title, .editor-styles-wrapper, h1').first()
			.waitFor({ state: 'visible', timeout: 60_000 })
			.catch(() => {});
		await page.waitForTimeout(1_000);
		await snap(
			page,
			'03-admin-portal-edit',
			'Portal editor',
			'Classic/block edit screen for a portal post. Title and publish controls; definition is attached via REST.'
		);

		// Attach open definition (human titles for public H1 when definition drives title)
		await putDefinition(page, openPortal.id, {
			...definitionOpen,
			title: 'Herbolzheimer Composition Prize',
		});
		await putDefinition(page, closedPortal.id, {
			...definitionClosed,
			title: 'Herbolzheimer Prize (Closed)',
		});

		// Re-fetch links after rename (slug may have changed)
		const openMeta = await restJson(page, `/wp-json/wp/v2/portal/${openPortal.id}`, {
			method: 'GET',
		});
		const closedMeta = await restJson(page, `/wp-json/wp/v2/portal/${closedPortal.id}`, {
			method: 'GET',
		});
		if (openMeta.body?.link) {
			openPortal.link = openMeta.body.link;
			openPortal.slug = openMeta.body.slug;
			openPortal.linkPath = openMeta.body.link.includes('://')
				? new URL(openMeta.body.link).pathname
				: openMeta.body.link;
			if (openPortal.linkPath && !openPortal.linkPath.endsWith('/')) {
				openPortal.linkPath += '/';
			}
		}
		if (closedMeta.body?.link) {
			closedPortal.link = closedMeta.body.link;
			closedPortal.slug = closedMeta.body.slug;
			closedPortal.linkPath = closedMeta.body.link.includes('://')
				? new URL(closedMeta.body.link).pathname
				: closedMeta.body.link;
			if (closedPortal.linkPath && !closedPortal.linkPath.endsWith('/')) {
				closedPortal.linkPath += '/';
			}
		}

		// Refresh list filtered to tutorial portals by friendly title
		await gotoRetry(
			page,
			'/wp-admin/edit.php?post_type=portal&s=' + encodeURIComponent('Herbolzheimer')
		);
		await page.waitForTimeout(500);
		await snap(
			page,
			'04-admin-portal-list-seeded',
			'Portal list with tutorial portals',
			'After seeding + rename: open and closed Herbolzheimer portals in the list.'
		);

		// --- 05 REST definition round-trip view via edit (optional visual) ---
		// Navigate to definition endpoint in browser is JSON — skip; use public instead.

		const { publicPath: openPublic, previewPath: openPreview } = publicPaths(openPortal);
		const { publicPath: closedPublic, previewPath: closedPreview } = publicPaths(closedPortal);

		// --- 05 Public open form (anonymous) ---
		const anon = await browser.newContext({
			viewport: VIEWPORT,
			deviceScaleFactor: 1,
			baseURL: env.baseUrl,
		});
		const anonPage = await anon.newPage();
		anonPage.setDefaultTimeout(SHOT_TIMEOUT_MS);
		anonPage.setDefaultNavigationTimeout(SHOT_TIMEOUT_MS);

		await gotoRetry(anonPage, openPublic);
		// Prefer definition root; fall back to body
		await anonPage
			.locator('[data-dg-render="definition"], form, main, article')
			.first()
			.waitFor({ state: 'visible', timeout: 45_000 })
			.catch(() => {});
		await anonPage.waitForTimeout(500);
		await snap(
			anonPage,
			'05-public-form-open',
			'Public application form (open)',
			'Anonymous visitor sees the definition-rendered form: applicant pack, work title, score, recording.'
		);
		// Full-page for long forms
		await snap(
			anonPage,
			'05b-public-form-open-full',
			'Public application form (full page)',
			'Same open form, full scroll height — useful for layout review.',
			{ fullPage: true }
		);

		// --- 06 Closed public ---
		await gotoRetry(anonPage, closedPublic);
		await anonPage
			.locator('[data-dg-portal-state="closed"], body')
			.first()
			.waitFor({ state: 'visible', timeout: 45_000 })
			.catch(() => {});
		await anonPage.waitForTimeout(400);
		await snap(
			anonPage,
			'06-public-closed',
			'Public view when portal is closed',
			'Deadline passed / forceClosed: visitor sees closed copy, not the live form.'
		);

		await anon.close();

		// --- 07 Editor preview of closed portal ---
		// Use admin session so preview=true grants editor preview
		await gotoRetry(page, closedPreview);
		await page
			.locator('[data-dg-preview="true"], [data-dg-render="definition"], body')
			.first()
			.waitFor({ state: 'visible', timeout: 45_000 })
			.catch(() => {});
		await page.waitForTimeout(500);
		await snap(
			page,
			'07-editor-preview-closed',
			'Editor preview of a closed portal',
			'Logged-in editor with ?preview=true: preview banner + definition form (not a live submission).'
		);
		await snap(
			page,
			'07b-editor-preview-closed-full',
			'Editor preview (full page)',
			'Full-page editor preview for layout review of banner + form stack.',
			{ fullPage: true }
		);

		// --- 08 Open portal as editor (public URL, logged in) ---
		await gotoRetry(page, openPublic);
		await page
			.locator('[data-dg-render="definition"], form, body')
			.first()
			.waitFor({ state: 'visible', timeout: 45_000 })
			.catch(() => {});
		await page.waitForTimeout(400);
		await snap(
			page,
			'08-editor-view-open-form',
			'Open form while logged in as editor',
			'Same open public URL with admin cookies — confirms form render for staff spot-checks.'
		);

		// --- 09 Trash list (after we soft-trash one? skip trash to keep portals for review)
		// Capture "Add New" empty-ish state instead
		await gotoRetry(page, '/wp-admin/post-new.php?post_type=portal');
		await page.waitForTimeout(1_200);
		await snap(
			page,
			'09-admin-new-portal',
			'Add new portal',
			'Create flow entry: new portal post editor before definition is attached.'
		);

		// Manifest + HTML gallery
		const manifest = {
			ok: true,
			capturedAt: new Date().toISOString(),
			baseUrl: env.baseUrl,
			viewport: VIEWPORT,
			portalIds,
			openPublic,
			closedPublic,
			shots,
		};
		const manifestJson = JSON.stringify(manifest, null, 2);
		fs.writeFileSync(path.join(outDocs, 'manifest.json'), manifestJson);
		fs.writeFileSync(path.join(outArtifacts, 'manifest.json'), manifestJson);

		const galleryHtml = buildGallery(manifest);
		fs.writeFileSync(path.join(outDocs, 'index.html'), galleryHtml);
		fs.writeFileSync(path.join(outArtifacts, 'index.html'), galleryHtml);

		writeComparePage(outDocs);

		console.log(`\nCaptured ${shots.length} shots → ${outDocs}`);
		console.log(`Open: file://${outDocs}/index.html`);
		console.log(`Compare: file://${outDocs}/compare.html`);
	} finally {
		// Leave tutorial portals for human evaluation; only clean if CAPTURE_CLEANUP=1
		if (process.env.CAPTURE_CLEANUP === '1') {
			for (const id of portalIds) {
				try {
					await cleanupPortal(page, id, { env, skipLogin: true });
					console.log(`  cleaned portal ${id}`);
				} catch (e) {
					console.warn(`  cleanup ${id}: ${e.message || e}`);
				}
			}
		} else {
			console.log(`  leaving portals for review: ${portalIds.join(', ')}`);
		}
		await browser.close();
	}
}

/**
 * @param {{ shots: typeof shots, capturedAt: string, baseUrl: string, viewport: { width: number, height: number } }} manifest
 */
function buildGallery(manifest) {
	const cards = manifest.shots
		.map(
			(s) => `
    <figure class="card" id="${escapeHtml(s.id)}">
      <a href="${escapeHtml(s.file)}" target="_blank" rel="noopener">
        <img src="${escapeHtml(s.file)}" alt="${escapeHtml(s.title)}" loading="lazy" />
      </a>
      <figcaption>
        <strong>${escapeHtml(s.id)}</strong>
        <span class="title">${escapeHtml(s.title)}</span>
        <p>${escapeHtml(s.caption)}</p>
      </figcaption>
    </figure>`
		)
		.join('\n');

	return `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>DragonGate tutorial screenshots</title>
  <style>
    :root {
      --bg: #0f1218;
      --panel: #171b24;
      --text: #e8ecf4;
      --muted: #9aa3b5;
      --accent: #c9a227;
      --border: #2a3142;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "Iowan Old Style", "Palatino Linotype", Palatino, Georgia, serif;
      background: var(--bg);
      color: var(--text);
      line-height: 1.45;
    }
    header {
      padding: 2rem 1.5rem 1rem;
      border-bottom: 1px solid var(--border);
      max-width: 1200px;
      margin: 0 auto;
    }
    header h1 {
      margin: 0 0 0.35rem;
      font-weight: 600;
      letter-spacing: 0.02em;
      font-size: 1.75rem;
    }
    header p { margin: 0.25rem 0; color: var(--muted); font-size: 0.95rem; }
    header code { color: var(--accent); font-family: ui-monospace, monospace; font-size: 0.85rem; }
    .grid {
      display: grid;
      grid-template-columns: 1fr;
      gap: 2rem;
      max-width: 1200px;
      margin: 0 auto;
      padding: 1.5rem;
    }
    .card {
      margin: 0;
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: 10px;
      overflow: hidden;
    }
    .card img {
      display: block;
      width: 100%;
      height: auto;
      background: #000;
      border-bottom: 1px solid var(--border);
    }
    figcaption { padding: 1rem 1.15rem 1.25rem; }
    figcaption strong {
      display: inline-block;
      font-family: ui-monospace, monospace;
      font-size: 0.75rem;
      color: var(--accent);
      margin-right: 0.5rem;
    }
    figcaption .title { font-size: 1.05rem; }
    figcaption p { margin: 0.5rem 0 0; color: var(--muted); font-size: 0.95rem; }
    footer {
      max-width: 1200px;
      margin: 0 auto 2rem;
      padding: 0 1.5rem;
      color: var(--muted);
      font-size: 0.85rem;
    }
  </style>
</head>
<body>
  <header>
    <h1>DragonGate — tutorial screenshot set</h1>
    <p>Live captures from LocalWP for design / UX evaluation (not Claude Design mockups).</p>
    <p>Captured <code>${escapeHtml(manifest.capturedAt)}</code> · <code>${escapeHtml(manifest.baseUrl)}</code> · ${manifest.viewport.width}×${manifest.viewport.height}</p>
  </header>
  <div class="grid">
${cards}
  </div>
  <footer>
    Source: <code>scripts/capture-tutorial-shots.mjs</code> · PNGs also under <code>tests/.artifacts/tutorial/</code>
  </footer>
</body>
</html>
`;
}

/** @param {string} s */
function escapeHtml(s) {
	return String(s)
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;');
}

/**
 * Side-by-side mockup (Claude Design) vs live LocalWP captures.
 * @param {string} outDir
 */
function writeComparePage(outDir) {
	const pairs = [
		{
			step: 'Start',
			mockup: '../mockups/01-start.png',
			live: '03-admin-portal-edit.png',
			note: 'Wizard Start step — mockup shows template cards; live has placeholder + block editor chrome.',
		},
		{
			step: 'Build form',
			mockup: '../mockups/02-build-form.png',
			live: '05b-public-form-open-full.png',
			note: 'Mockup is admin field builder; live shot is public definition-rendered form (closest shipping surface).',
		},
		{
			step: 'Map data',
			mockup: '../mockups/03-map-data.png',
			live: '03-admin-portal-edit.png',
			note: 'Map step not yet interactive in live wizard — same editor shell for reference.',
		},
		{
			step: 'Publish',
			mockup: '../mockups/04-publish.png',
			live: '06-public-closed.png',
			note: 'Mockup is publish settings; live shows closed public outcome of deadline/forceClosed.',
		},
		{
			step: 'Open form',
			mockup: null,
			live: '05-public-form-open.png',
			note: 'No design mockup for public form yet — live viewport only.',
		},
		{
			step: 'Editor preview',
			mockup: null,
			live: '07-editor-preview-closed.png',
			note: 'Preview banner + form for closed portal (staff path).',
		},
	];

	const rows = pairs
		.map((p) => {
			const mockCell = p.mockup
				? `<a href="${escapeHtml(p.mockup)}" target="_blank" rel="noopener"><img src="${escapeHtml(p.mockup)}" alt="Mockup ${escapeHtml(p.step)}" /></a>`
				: `<div class="empty">No mockup</div>`;
			const liveCell = p.live
				? `<a href="${escapeHtml(p.live)}" target="_blank" rel="noopener"><img src="${escapeHtml(p.live)}" alt="Live ${escapeHtml(p.step)}" /></a>`
				: `<div class="empty">No live shot</div>`;
			return `
    <section class="pair">
      <header>
        <h2>${escapeHtml(p.step)}</h2>
        <p>${escapeHtml(p.note)}</p>
      </header>
      <div class="cols">
        <figure>
          <figcaption>Design mockup</figcaption>
          ${mockCell}
        </figure>
        <figure>
          <figcaption>Live LocalWP</figcaption>
          ${liveCell}
        </figure>
      </div>
    </section>`;
		})
		.join('\n');

	const html = `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>DragonGate — mockup vs live</title>
  <style>
    :root {
      --bg: #0f1218;
      --panel: #171b24;
      --text: #e8ecf4;
      --muted: #9aa3b5;
      --accent: #c9a227;
      --border: #2a3142;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "Iowan Old Style", "Palatino Linotype", Palatino, Georgia, serif;
      background: var(--bg);
      color: var(--text);
      line-height: 1.45;
    }
    .top {
      max-width: 1400px;
      margin: 0 auto;
      padding: 2rem 1.5rem 1rem;
      border-bottom: 1px solid var(--border);
    }
    .top h1 { margin: 0 0 0.35rem; font-size: 1.75rem; font-weight: 600; }
    .top p { margin: 0.25rem 0; color: var(--muted); }
    .top a { color: var(--accent); }
    .pair {
      max-width: 1400px;
      margin: 0 auto;
      padding: 1.75rem 1.5rem;
      border-bottom: 1px solid var(--border);
    }
    .pair h2 { margin: 0 0 0.35rem; font-size: 1.25rem; color: var(--accent); }
    .pair > header p { margin: 0 0 1rem; color: var(--muted); font-size: 0.95rem; }
    .cols {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1rem;
    }
    @media (max-width: 900px) {
      .cols { grid-template-columns: 1fr; }
    }
    figure {
      margin: 0;
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: 10px;
      overflow: hidden;
    }
    figcaption {
      padding: 0.55rem 0.85rem;
      font-family: ui-monospace, monospace;
      font-size: 0.75rem;
      color: var(--muted);
      border-bottom: 1px solid var(--border);
      text-transform: uppercase;
      letter-spacing: 0.06em;
    }
    img { display: block; width: 100%; height: auto; background: #000; }
    .empty {
      min-height: 200px;
      display: grid;
      place-items: center;
      color: var(--muted);
      font-family: ui-monospace, monospace;
      font-size: 0.85rem;
    }
  </style>
</head>
<body>
  <div class="top">
    <h1>DragonGate — mockup vs live</h1>
    <p>Claude Design mockups (left) against LocalWP tutorial captures (right).</p>
    <p><a href="index.html">Tutorial gallery</a> · <a href="../mockups/dragongate-mockups.html">Mockup deck</a></p>
  </div>
${rows}
</body>
</html>
`;
	fs.writeFileSync(path.join(outDir, 'compare.html'), html);
	const art = path.join(env.artifactDirAbs || path.join(ROOT, 'tests/.artifacts'), 'tutorial');
	fs.mkdirSync(art, { recursive: true });
	fs.writeFileSync(path.join(art, 'compare.html'), html);
}

main().catch((err) => {
	console.error(err);
	process.exit(1);
});
