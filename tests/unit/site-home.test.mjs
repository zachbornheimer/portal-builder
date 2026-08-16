/**
 * Home.dc.html shipped as static HTML/CSS — copy, routes, tokens, no React.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const htmlPath = path.join(root, 'site/public/index.html');
const cssPath = path.join(root, 'site/public/css/home.css');

const html = fs.readFileSync(htmlPath, 'utf8');
const css = fs.readFileSync(cssPath, 'utf8');

test('home title is unique and names the door that opens', () => {
	const title = html.match(/<title>([^<]+)<\/title>/);
	const desc = html.match(/<meta name="description" content="([^"]+)"/);
	assert.ok(title, 'home has <title>');
	assert.ok(desc, 'home has meta description');
	assert.match(title[1], /the door that opens/i);
	assert.ok(desc[1].length > 40, 'description is unique prose, not empty');
});

test('home keeps the design copy verbatim', () => {
	assert.match(html, /Applications, on paper terms/);
	assert.match(html, /<h1>[^<]*The door that opens for the right applicant\.<\/h1>/);
	assert.match(
		html,
		/DragonGate Portals is a WordPress plugin for building calls and intake portals\. Staff build a form, map it to Google Drive and Sheets, and publish a URL\. Applicants meet one paper packet and one submit\./,
	);
	assert.match(html, /Join the waitlist/);
	assert.match(html, /See how it works/);
	assert.match(
		html,
		/No applications inbox · Your Drive, your Sheet · Self-hosted on WordPress/,
	);
	assert.match(html, /CALL-2026-0417/);
	assert.match(html, /Open until Mar 14/);
	assert.match(html, /Your packet/);
	assert.match(html, /Fault Lines/);
	assert.match(html, /Composition/);
	assert.match(html, /Chamber/);
	assert.match(html, /fault-lines-score\.pdf/);
	assert.match(html, /Submit packet/);
	assert.match(html, /The quiet part/);
	assert.match(
		html,
		/No WordPress applications inbox\. No copy of your applicants living on our servers\./,
	);
	assert.match(html, /Build a form/);
	assert.match(html, /Map Drive and Sheets/);
	assert.match(html, /Publish a URL/);
	assert.match(html, /One submit, three destinations/);
	assert.match(html, /All features/);
	assert.match(html, /Be the first to open a portal\./);
});

test('home routes stay path-based and never point at design files', () => {
	assert.match(html, /href="\/waitlist"/);
	assert.match(html, /href="\/product"/);
	assert.match(html, /href="\/features"/);
	assert.doesNotMatch(html, /\.dc\.html/);
});

test('wordmark splits Portals onto the ember accent', () => {
	assert.match(
		html,
		/DragonGate[\s\S]{0,80}<span class="wordmark-portals">Portals<\/span>/,
	);
	assert.match(css, /\.wordmark-portals\s*\{[^}]*var\(--accent\)/);
});

test('header is sticky and footer names Product, Company, and Legal', () => {
	assert.match(html, /<header class="site-header">/);
	assert.match(css, /\.site-header\s*\{[^}]*position:\s*sticky/);
	assert.match(html, />Product</);
	assert.match(html, />Company</);
	assert.match(html, />Legal</);
});

test('footer keeps files in the host Drive', () => {
	assert.match(
		html,
		/Files stay in your Google Drive\. A row goes to your Sheet\. We never see your applicants\./,
	);
});

test('home does not load React, the design bundle, or CDN icon kits', () => {
	const banned = /_ds_bundle\.js|unpkg\.com|react|support\.js/i;
	assert.doesNotMatch(html, banned);
	assert.doesNotMatch(css, banned);
});

test('home loads design-system tokens and home.css only', () => {
	assert.match(html, /href="\/css\/ds\/colors\.css"/);
	assert.match(html, /href="\/css\/ds\/typography\.css"/);
	assert.match(html, /href="\/css\/ds\/spacing\.css"/);
	assert.match(html, /href="\/css\/home\.css"/);
	assert.doesNotMatch(html, /href="\/css\/site\.css"/);
	assert.doesNotMatch(html, /href="\/css\/tokens\.css"/);
});

test('home.css ships sticky header, frost, ember-flow, and a four-column footer', () => {
	assert.match(css, /position:\s*sticky/);
	assert.match(css, /backdrop-filter:\s*blur\(6px\)/);
	assert.match(css, /--ember-flow/);
	assert.match(
		css,
		/grid-template-columns:\s*1\.4fr\s+1fr\s+1fr\s+1fr/,
	);
});
