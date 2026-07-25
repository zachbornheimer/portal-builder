/**
 * Remove e2e-seeded portals (REST force-delete + registry).
 *
 * Usage from tests:
 *   import { cleanupPortal, cleanupTestPortals } from '../tests/support/cleanup.mjs';
 *   await cleanupPortal(page, portalId);
 *   await cleanupTestPortals(page);
 *
 * CLI:
 *   node tests/support/cleanup.mjs
 *   node tests/support/cleanup.mjs --id=123
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { loadEnv } from './load-env.mjs';
import { ensureAdminSession } from './admin-session.mjs';
import { restJson } from './wp-rest.mjs';

const SEED_REGISTRY_FILE = 'seeded-portals.json';
const PORTAL_COLLECTION = '/wp-json/wp/v2/portal';
const SEARCH_PER_PAGE = 100;

/**
 * @param {string} artifactDirAbs
 * @returns {{ portals: Array<{ id: string, title: string }> }}
 */
export function readSeedRegistry(artifactDirAbs) {
	const registryPath = path.join(artifactDirAbs, SEED_REGISTRY_FILE);
	if (!fs.existsSync(registryPath)) {
		return { portals: [] };
	}
	try {
		const data = JSON.parse(fs.readFileSync(registryPath, 'utf8'));
		return { portals: Array.isArray(data.portals) ? data.portals : [] };
	} catch {
		return { portals: [] };
	}
}

/**
 * @param {string} artifactDirAbs
 * @param {string} portalId
 */
function removeFromRegistry(artifactDirAbs, portalId) {
	const registryPath = path.join(artifactDirAbs, SEED_REGISTRY_FILE);
	const registry = readSeedRegistry(artifactDirAbs);
	registry.portals = registry.portals.filter((p) => String(p.id) !== String(portalId));
	fs.mkdirSync(artifactDirAbs, { recursive: true });
	fs.writeFileSync(registryPath, JSON.stringify(registry, null, 2));
}

/**
 * Permanently delete a portal by id (force=true skips trash).
 *
 * @param {import('@playwright/test').Page} page
 * @param {string|number} portalId
 * @param {{ env?: ReturnType<typeof loadEnv>, skipLogin?: boolean }} [opts]
 */
export async function cleanupPortal(page, portalId, opts = {}) {
	const env = opts.env || loadEnv();
	const id = String(portalId);

	if (!opts.skipLogin) {
		await ensureAdminSession(page, { env });
	}

	if (!/wp-admin/.test(page.url())) {
		await page.goto('/wp-admin/', { waitUntil: 'domcontentloaded' });
	}

	const { ok, status, body } = await restJson(
		page,
		`${PORTAL_COLLECTION}/${id}?force=true`,
		{ method: 'DELETE' },
	);

	// 404 = already gone — treat as success for idempotent cleanup
	if (!ok && status !== 404) {
		throw new Error(
			`cleanupPortal REST failed HTTP ${status}: ${JSON.stringify(body)}`,
		);
	}

	removeFromRegistry(env.artifactDirAbs, id);
	return { id, removed: true, status };
}

/**
 * Extract rendered title string from a WP REST post object.
 * @param {unknown} post
 */
function postTitle(post) {
	if (!post || typeof post !== 'object') return '';
	const t = /** @type {{ title?: { rendered?: string } | string }} */ (post).title;
	if (typeof t === 'string') return t;
	if (t && typeof t.rendered === 'string') {
		// WP entity-encodes; decode basic entities for prefix match
		return t.rendered
			.replace(/&#(\d+);/g, (_, n) => String.fromCharCode(Number(n)))
			.replace(/&amp;/g, '&')
			.replace(/&lt;/g, '<')
			.replace(/&gt;/g, '>')
			.replace(/&quot;/g, '"');
	}
	return '';
}

/**
 * Remove all portals matching testPortalPrefix (search + registry).
 *
 * @param {import('@playwright/test').Page} page
 * @param {{ env?: ReturnType<typeof loadEnv>, skipLogin?: boolean, prefix?: string }} [opts]
 */
export async function cleanupTestPortals(page, opts = {}) {
	const env = opts.env || loadEnv();
	const prefix = opts.prefix || env.testPortalPrefix;

	if (!opts.skipLogin) {
		await ensureAdminSession(page, { env });
	}

	if (!/wp-admin/.test(page.url())) {
		await page.goto('/wp-admin/', { waitUntil: 'domcontentloaded' });
	}

	const removed = [];
	const registryIds = readSeedRegistry(env.artifactDirAbs).portals.map((p) => String(p.id));

	// Search published + any status via search=
	const searchPath =
		`${PORTAL_COLLECTION}?search=${encodeURIComponent(prefix)}` +
		`&per_page=${SEARCH_PER_PAGE}&status=publish,draft,pending,private,trash`;

	const { ok, body } = await restJson(page, searchPath, { method: 'GET' });
	const foundIds = [];
	if (ok && Array.isArray(body)) {
		for (const post of body) {
			const title = postTitle(post);
			if (title.startsWith(prefix) || String(post.slug || '').startsWith(prefix)) {
				foundIds.push(String(post.id));
			}
		}
	}

	const unique = [...new Set([...registryIds, ...foundIds])];
	for (const id of unique) {
		await cleanupPortal(page, id, { env, skipLogin: true });
		removed.push(id);
	}

	return { removed, prefix };
}

/** CLI entry. */
async function mainCli() {
	const { chromium } = await import('@playwright/test');
	const env = loadEnv();
	const idArg = process.argv.find((a) => a.startsWith('--id='));
	const browser = await chromium.launch({ headless: true });
	const page = await browser.newPage({ baseURL: env.baseUrl });
	try {
		let result;
		if (idArg) {
			const id = idArg.split('=')[1];
			result = await cleanupPortal(page, id, { env });
		} else {
			result = await cleanupTestPortals(page, { env });
		}
		console.log(JSON.stringify({ ok: true, ...result }, null, 2));
	} finally {
		await browser.close();
	}
}

const isDirectRun =
	process.argv[1] &&
	path.resolve(process.argv[1]) === path.resolve(fileURLToPath(import.meta.url));

if (isDirectRun) {
	mainCli().catch((err) => {
		console.error('cleanup FAIL:', err.message || err);
		process.exit(1);
	});
}
