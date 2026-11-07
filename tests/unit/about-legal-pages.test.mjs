/**
 * About may only claim shipped behavior. Marketing must ship terms
 * and a subprocessors page, linked from every Privacy footer.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const aboutPath = path.join(root, 'includes/class-portal-about.php');
const termsPath = path.join(root, 'site/public/terms/index.html');
const subprocessorsPath = path.join(root, 'site/public/subprocessors/index.html');
const sitePublic = path.join(root, 'site/public');

const aboutPhp = fs.readFileSync(aboutPath, 'utf8');

const FORBIDDEN_ABOUT = [
	{ name: 'Clear data retention and export paths', re: /clear data retention and export paths/i },
	{ name: 'data retention', re: /data retention/i },
	{ name: 'export paths', re: /export paths/i },
	{ name: 'admins review in WP', re: /admins review in WP/i },
	{ name: 'review in WP', re: /review in WP/i },
	{ name: 'applications inbox', re: /applications inbox/i },
];

const REQUIRED_PROCESSORS = [
	{ name: 'Google (customer project)', re: /google\s*\(\s*customer project\s*\)/i },
	{ name: 'All Intersections', re: /all intersections/i },
	{ name: 'WordPress host', re: /wordpress host/i },
];

/**
 * @param {string} dir
 * @returns {string[]}
 */
function htmlFiles(dir) {
	const found = [];
	for (const name of fs.readdirSync(dir)) {
		const full = path.join(dir, name);
		if (fs.statSync(full).isDirectory()) {
			found.push(...htmlFiles(full));
			continue;
		}
		if (name.endsWith('.html')) {
			found.push(full);
		}
	}
	return found;
}

/**
 * @param {string} html
 * @returns {string}
 */
function footerNav(html) {
	const match = html.match(/<nav aria-label="Footer">([\s\S]*?)<\/nav>/);
	return match ? match[1] : '';
}

test('About PHP does not claim retention, export, or a WP applications inbox', () => {
	const hits = FORBIDDEN_ABOUT.filter((item) => item.re.test(aboutPhp)).map((item) => item.name);
	assert.deepEqual(hits, [], `About still claims unshipped behavior: ${hits.join(', ')}`);
});

test('terms page exists as HTML on the marketing site tokens', () => {
	assert.ok(fs.existsSync(termsPath), 'site/public/terms/index.html is missing');
	const html = fs.readFileSync(termsPath, 'utf8');
	assert.match(html, /<!DOCTYPE html>/i, 'terms page is not HTML');
	assert.match(html, /\/css\/tokens\.css/, 'terms page must load tokens.css');
	assert.match(html, /\/css\/site\.css/, 'terms page must load site.css');
	assert.doesNotMatch(html, /stripe|checkout|pricing/i, 'terms page must not add pricing or checkout');
});

test('subprocessors page names Google (customer project), All Intersections, and WordPress host', () => {
	assert.ok(fs.existsSync(subprocessorsPath), 'site/public/subprocessors/index.html is missing');
	const html = fs.readFileSync(subprocessorsPath, 'utf8');
	assert.match(html, /<!DOCTYPE html>/i, 'subprocessors page is not HTML');
	assert.match(html, /\/css\/tokens\.css/, 'subprocessors page must load tokens.css');
	assert.match(html, /\/css\/site\.css/, 'subprocessors page must load site.css');
	const missing = REQUIRED_PROCESSORS.filter((item) => !item.re.test(html)).map((item) => item.name);
	assert.deepEqual(missing, [], `subprocessors page missing processors: ${missing.join(', ')}`);
	assert.doesNotMatch(html, /stripe|checkout|pricing/i, 'subprocessors page must not add pricing or checkout');
});

test('every footer that links Privacy also links terms and subprocessors', () => {
	const pages = htmlFiles(sitePublic);
	const privacyFooters = [];
	const missing = [];
	for (const file of pages) {
		const html = fs.readFileSync(file, 'utf8');
		const nav = footerNav(html);
		if (!nav || !/href="\/privacy"/.test(nav)) {
			continue;
		}
		privacyFooters.push(path.relative(root, file));
		if (!/href="\/terms"/.test(nav)) {
			missing.push(`${path.relative(root, file)} footer missing /terms`);
		}
		if (!/href="\/subprocessors"/.test(nav)) {
			missing.push(`${path.relative(root, file)} footer missing /subprocessors`);
		}
	}
	assert.ok(privacyFooters.length > 0, 'no footer linking /privacy was found');
	assert.deepEqual(missing, [], missing.join('; '));
});
