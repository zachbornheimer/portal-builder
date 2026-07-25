/**
 * Seed a portal CPT for e2e via WP REST (show_in_rest).
 * Titles always use testPortalPrefix so cleanup can find them.
 *
 * Usage from tests:
 *   import { seedPortal } from '../tests/support/seed.mjs';
 *   const { id, title } = await seedPortal(page);
 *
 * CLI:
 *   node tests/support/seed.mjs
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { loadEnv } from './load-env.mjs';
import { ensureAdminSession } from './admin-session.mjs';
import { restJson } from './wp-rest.mjs';

const SEED_REGISTRY_FILE = 'seeded-portals.json';
const PORTAL_COLLECTION = '/wp-json/wp/v2/portal';

/**
 * Build a unique portal title under the harness prefix.
 * @param {string} prefix
 * @param {string} [label]
 */
export function buildSeedTitle(prefix, label = 'seed') {
	const safeLabel =
		String(label)
			.replace(/[^a-zA-Z0-9_-]+/g, '-')
			.replace(/^-|-$/g, '') || 'seed';
	return `${prefix}${safeLabel}-${Date.now()}`;
}

/**
 * @param {string} artifactDirAbs
 * @param {{ id: string, title: string }} entry
 */
function appendSeedRegistry(artifactDirAbs, entry) {
	fs.mkdirSync(artifactDirAbs, { recursive: true });
	const registryPath = path.join(artifactDirAbs, SEED_REGISTRY_FILE);
	let registry = { portals: [] };
	if (fs.existsSync(registryPath)) {
		try {
			registry = JSON.parse(fs.readFileSync(registryPath, 'utf8'));
			if (!Array.isArray(registry.portals)) registry.portals = [];
		} catch {
			registry = { portals: [] };
		}
	}
	registry.portals.push({
		id: entry.id,
		title: entry.title,
		at: new Date().toISOString(),
	});
	fs.writeFileSync(registryPath, JSON.stringify(registry, null, 2));
	return registryPath;
}

/**
 * Pathname (+ trailing slash) from a WP REST `link` URL for pretty-permalink e2e.
 * Query-form `?p=` redirects strip `preview=true`; public tests need this path.
 *
 * @param {string|undefined} link Absolute or site-relative portal URL from REST.
 * @returns {string|undefined}
 */
export function linkPathFromRestLink(link) {
	if (!link || typeof link !== 'string') {
		return undefined;
	}
	try {
		const pathname = link.includes('://')
			? new URL(link).pathname
			: link.startsWith('/')
				? link.split('?')[0]
				: `/${link.split('?')[0]}`;
		if (!pathname || pathname === '/') {
			return undefined;
		}
		return pathname.endsWith('/') ? pathname : `${pathname}/`;
	} catch {
		return undefined;
	}
}

/**
 * Create a published portal via REST.
 *
 * @param {import('@playwright/test').Page} page
 * @param {{ title?: string, label?: string, env?: ReturnType<typeof loadEnv>, skipLogin?: boolean }} [opts]
 * @returns {Promise<{ id: string, title: string, editUrl: string, slug?: string, link?: string, linkPath?: string }>}
 */
export async function seedPortal(page, opts = {}) {
	const env = opts.env || loadEnv();
	const title = opts.title || buildSeedTitle(env.testPortalPrefix, opts.label);

	if (!title.startsWith(env.testPortalPrefix)) {
		throw new Error(
			`seed title must start with ${env.testPortalPrefix}, got ${JSON.stringify(title)}`,
		);
	}

	if (!opts.skipLogin) {
		await ensureAdminSession(page, { env });
	}

	// Land on any admin screen so rest-nonce cookie path is valid
	if (!/wp-admin/.test(page.url())) {
		await page.goto('/wp-admin/', { waitUntil: 'domcontentloaded' });
	}

	const { ok, status, body } = await restJson(page, PORTAL_COLLECTION, {
		method: 'POST',
		data: {
			title,
			status: 'publish',
		},
	});

	if (!ok || !body?.id) {
		throw new Error(
			`seedPortal REST failed HTTP ${status}: ${JSON.stringify(body)}`,
		);
	}

	const id = String(body.id);
	const editUrl = `/wp-admin/post.php?post=${id}&action=edit`;
	const slug = typeof body.slug === 'string' && body.slug ? body.slug : undefined;
	const link = typeof body.link === 'string' && body.link ? body.link : undefined;
	const linkPath = linkPathFromRestLink(link);
	appendSeedRegistry(env.artifactDirAbs, { id, title });

	return { id, title, editUrl, slug, link, linkPath };
}

/** CLI entry: seed one portal and print JSON. */
async function mainCli() {
	const { chromium } = await import('@playwright/test');
	const env = loadEnv();
	const browser = await chromium.launch({ headless: true });
	const page = await browser.newPage({ baseURL: env.baseUrl });
	try {
		const result = await seedPortal(page, { env });
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
		console.error('seed FAIL:', err.message || err);
		process.exit(1);
	});
}
