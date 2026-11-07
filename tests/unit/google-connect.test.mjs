/**
 * Drives shipped post-activate Google-connect notice + checklist (PHP CLI harness).
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const harness = path.join(root, 'tests/support/php-google-connect.php');
const casesPath = path.join(root, 'tests/.artifacts/google-connect-cases.json');

const TEST_OK = '2026-08-16T14:32:01Z';

const CASES = [
	{
		name: 'activate, no dg_google_test_ok, admin dashboard',
		probe: 'activate',
		is_admin: true,
		pagenow: 'index.php',
		query: {},
	},
	{
		name: 'activate, no dg_google_test_ok, admin plugins',
		probe: 'activate',
		is_admin: true,
		pagenow: 'plugins.php',
		query: {},
	},
	{
		name: 'activate, no dg_google_test_ok, admin portals list',
		probe: 'activate',
		is_admin: true,
		pagenow: 'edit.php',
		query: { post_type: 'portal' },
	},
	{
		name: 'activate, no dg_google_test_ok, admin settings',
		probe: 'activate',
		is_admin: true,
		pagenow: 'edit.php',
		query: { post_type: 'portal', page: 'portal-default-settings' },
	},
	{
		name: 'activate, no dg_google_test_ok, setup wizard',
		probe: 'activate',
		is_admin: true,
		pagenow: 'admin.php',
		query: { page: 'dg-portal-setup' },
	},
	{
		name: 'activate, no dg_google_test_ok, public form',
		probe: 'activate',
		is_admin: false,
		pagenow: 'index.php',
		query: {},
	},
	{
		name: 'activate, dg_google_test_ok set, admin dashboard',
		probe: 'activate',
		is_admin: true,
		pagenow: 'index.php',
		query: {},
		test_ok: TEST_OK,
	},
	{
		name: 'activate, no dg_google_test_ok, about checklist',
		probe: 'activate',
		is_admin: true,
		pagenow: 'admin.php',
		query: { page: 'dgp-about' },
	},
	{
		name: 'activate, no dg_google_test_ok, settings checklist',
		probe: 'activate',
		is_admin: true,
		pagenow: 'edit.php',
		query: { post_type: 'portal', page: 'portal-default-settings' },
	},
	{
		name: 'activate, dg_google_test_ok set, about checklist',
		probe: 'activate',
		is_admin: true,
		pagenow: 'admin.php',
		query: { page: 'dgp-about' },
		test_ok: TEST_OK,
	},
	{
		name: 'checklist copy after activate',
		probe: 'checklist_copy',
	},
	{
		name: 'activate calls on_activate',
		probe: 'activate_source',
	},
];

function runCases() {
	fs.mkdirSync(path.dirname(casesPath), { recursive: true });
	fs.writeFileSync(casesPath, JSON.stringify(CASES));
	const r = spawnSync('php', [harness, casesPath], { encoding: 'utf8' });
	assert.equal(r.status, 0, (r.stdout || '') + (r.stderr || ''));
	return JSON.parse((r.stdout || '').trim());
}

function row(rows, namePart) {
	const found = rows.find((r) => r.name.includes(namePart));
	assert.ok(found, `missing harness row: ${namePart}`);
	return found;
}

test('after activate with no dg_google_test_ok, notice shows on dashboard, Plugins, Portals, Settings', () => {
	const rows = runCases();
	for (const part of [
		'admin dashboard',
		'admin plugins',
		'admin portals list',
		'admin settings',
	]) {
		const r = row(rows, `no dg_google_test_ok, ${part}`);
		assert.equal(r.notice, true, `${part} must show the connect-Google notice after activate`);
		assert.equal(r.pending, true, `${part} must record connect-pending on activate`);
		assert.equal(r.test_ok, false);
	}
});

test('after activate with no dg_google_test_ok, notice stays off the setup wizard and public form', () => {
	const rows = runCases();
	const setup = row(rows, 'setup wizard');
	const pub = row(rows, 'public form');
	assert.equal(setup.notice, false, 'setup wizard must not show the connect-Google notice');
	assert.equal(pub.notice, false, 'public form must not show the connect-Google notice');
});

test('dg_google_test_ok dismisses the notice after activate', () => {
	const rows = runCases();
	const r = row(rows, 'dg_google_test_ok set, admin dashboard');
	assert.equal(r.notice, false, 'successful test write must dismiss the notice');
	assert.equal(r.test_ok, true);
});

test('About and Settings show the checklist until the test write succeeds', () => {
	const rows = runCases();
	const about = row(rows, 'about checklist');
	const settings = row(rows, 'settings checklist');
	const dismissed = row(rows, 'dg_google_test_ok set, about checklist');
	assert.equal(about.checklist, true, 'About must show the in-page checklist after activate');
	assert.equal(about.notice, false, 'About must not use a WP admin notice');
	assert.equal(settings.checklist, true, 'Settings must show the in-page checklist after activate');
	assert.equal(dismissed.checklist, false, 'test write must hide the About checklist');
});

test('checklist copy names credentials, share folder/sheet, test write, From email, and brand', () => {
	const rows = runCases();
	const copy = rows.find((r) => r.probe === 'checklist_copy');
	assert.ok(copy, 'checklist_copy probe missing');
	const items = Array.isArray(copy.items) ? copy.items.map(String) : [];
	const markup = String(copy.markup || '');
	const noticeHtml = String(copy.notice_html || '');
	const joined = `${items.join('\n')}\n${markup}`;
	assert.match(joined, /credentials/i);
	assert.match(joined, /share folder\/sheet/i);
	assert.match(joined, /test write/i);
	assert.match(joined, /From email/i);
	assert.match(joined, /brand/i);
	assert.doesNotMatch(markup, /\bnotice\b/);
	assert.match(noticeHtml, /portal-default-settings/);
	assert.doesNotMatch(noticeHtml, /is-dismissible/);
});

test('portal_plugin_activate calls the shipped connect on_activate', () => {
	const rows = runCases();
	const src = rows.find((r) => r.probe === 'activate_source');
	assert.ok(src, 'activate_source probe missing');
	assert.equal(src.calls_on_activate, true);
});
