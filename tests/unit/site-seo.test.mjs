/**
 * Marketing site: unique title + description, robots, sitemap of public pages.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const pub = path.join(root, 'site/public');

const PAGES = [
	'index.html',
	'product/index.html',
	'features/index.html',
	'hosts/index.html',
	'about/index.html',
	'waitlist/index.html',
	'terms/index.html',
	'privacy/index.html',
	'subprocessors/index.html',
];

test('every marketing HTML page has a unique title and description', () => {
	const titles = [];
	const descriptions = [];
	for (const rel of PAGES) {
		const html = fs.readFileSync(path.join(pub, rel), 'utf8');
		const title = html.match(/<title>([^<]+)<\/title>/);
		const desc = html.match(/<meta name="description" content="([^"]+)"/);
		assert.ok(title, `${rel} has <title>`);
		assert.ok(desc, `${rel} has meta description`);
		titles.push(title[1]);
		descriptions.push(desc[1]);
	}
	assert.equal(new Set(titles).size, titles.length, 'titles unique');
	assert.equal(new Set(descriptions).size, descriptions.length, 'descriptions unique');
});

test('robots.txt and sitemap.xml list the public pages', () => {
	const robots = fs.readFileSync(path.join(pub, 'robots.txt'), 'utf8');
	const sitemap = fs.readFileSync(path.join(pub, 'sitemap.xml'), 'utf8');
	assert.match(robots, /Sitemap:/);
	assert.match(robots, /Allow: \//);
	for (const slug of [
		'dragongateportals.com/',
		'product/',
		'features/',
		'hosts/',
		'about/',
		'waitlist/',
		'terms/',
		'privacy/',
		'subprocessors/',
	]) {
		assert.match(sitemap, new RegExp(slug.replace('/', '\\/')));
	}
});
