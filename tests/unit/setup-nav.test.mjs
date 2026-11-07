/**
 * Drives shipped setup-submenu + edit-target / duplicate / legacy decisions (PHP CLI harness).
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const harness = path.join(root, 'tests/support/php-setup-nav.php');
const casesPath = path.join(root, 'tests/.artifacts/setup-nav-cases.json');

const CASES = [
	{
		name: 'empty draft + no definition highlights Add New',
		portal_id: 42,
		post_status: 'draft',
		post_title: 'New portal',
		has_definition: false,
	},
	{
		name: 'auto-draft + no definition highlights Add New',
		portal_id: 43,
		post_status: 'draft',
		post_title: 'Auto Draft',
		has_definition: false,
	},
	{
		name: 'titled draft without definition is the named portal row',
		portal_id: 26999,
		post_status: 'draft',
		post_title: 'Dohnányi International',
		has_definition: false,
	},
	{
		name: 'published portal is the named portal row',
		portal_id: 26999,
		post_status: 'publish',
		post_title: 'Dohnányi International',
		has_definition: false,
	},
	{
		name: 'draft with a definition is the named portal row',
		portal_id: 26999,
		post_status: 'draft',
		post_title: 'New portal',
		has_definition: true,
	},
	{
		name: 'action-less post.php is a portal edit',
		probe: 'edit_target',
		pagenow: 'post.php',
		query: { post: 42 },
		post_type: 'portal',
	},
	{
		name: 'post.php action=edit is a portal edit',
		probe: 'edit_target',
		pagenow: 'post.php',
		query: { post: 42, action: 'edit' },
		post_type: 'portal',
	},
	{
		name: 'dg_legacy=1 stays on post.php',
		probe: 'edit_target',
		pagenow: 'post.php',
		query: { post: 42, action: 'edit', dg_legacy: '1' },
		post_type: 'portal',
	},
	{
		name: 'post-new portal goes to setup',
		probe: 'edit_target',
		pagenow: 'post-new.php',
		query: { post_type: 'portal' },
		post_type: 'portal',
	},
	{
		name: 'trash is not an edit redirect',
		probe: 'edit_target',
		pagenow: 'post.php',
		query: { post: 42, action: 'trash' },
		post_type: 'portal',
	},
	{
		name: 'duplicate next URL is the setup URL',
		probe: 'duplicate_next_url',
		portal_id: 42,
	},
	{
		name: 'Legacy fields label is exact',
		probe: 'legacy_fields_label',
	},
	{
		name: 'Legacy fields URL is the stay-put hatch',
		probe: 'legacy_fields_url',
		portal_id: 42,
	},
	{
		name: 'wizard is not a post.php surface',
		probe: 'wizard_on_post_php',
	},
];

function runCases() {
	fs.mkdirSync(path.dirname(casesPath), { recursive: true });
	fs.writeFileSync(casesPath, JSON.stringify(CASES));
	const r = spawnSync('php', [harness, casesPath], { encoding: 'utf8' });
	assert.equal(r.status, 0, (r.stdout || '') + (r.stderr || ''));
	return JSON.parse((r.stdout || '').trim());
}

function rowsByProbe(rows, probe) {
	return rows.filter((row) => row.probe === probe);
}

test('fresh add-new drafts highlight Add New Portal, not All Portals', () => {
	const rows = runCases();
	const addNew = rows.filter((row) =>
		row.name.includes('highlights Add New')
	);
	assert.ok(addNew.length >= 2);
	for (const row of addNew) {
		assert.equal(row.submenu_file, 'dg-portal-setup');
		assert.equal(row.is_fresh, true);
		assert.notEqual(row.submenu_file, 'edit.php?post_type=portal');
	}
});

test('existing titled, published, or defined portals are not All Portals', () => {
	const rows = runCases();
	const existing = rows.filter((row) => row.name.includes('named portal row'));
	assert.equal(existing.length, 3);
	for (const row of existing) {
		assert.equal(row.submenu_file, 'dg-portal-setup&portal_id=26999');
		assert.equal(row.is_fresh, false);
		assert.notEqual(row.submenu_file, 'edit.php?post_type=portal');
	}
});

test('action-less post.php and action=edit resolve to setup, not the canvas', () => {
	const rows = rowsByProbe(runCases(), 'edit_target');
	const actionless = rows.find((row) => row.name.includes('action-less'));
	const withAction = rows.find((row) => row.name.includes('action=edit'));
	assert.ok(actionless, 'action-less case missing from harness');
	assert.ok(withAction, 'action=edit case missing from harness');
	assert.equal(actionless.kind, 'setup');
	assert.equal(actionless.portal_id, 42);
	assert.equal(withAction.kind, 'setup');
	assert.equal(withAction.portal_id, 42);
	assert.notEqual(actionless.kind, 'stay');
	assert.notEqual(actionless.kind, 'ignore');
});

test('dg_legacy=1 is the only portal post.php stay-put path', () => {
	const rows = rowsByProbe(runCases(), 'edit_target');
	const legacy = rows.find((row) => row.name.includes('dg_legacy=1'));
	const trash = rows.find((row) => row.name.includes('trash'));
	const postNew = rows.find((row) => row.name.includes('post-new'));
	assert.ok(legacy);
	assert.equal(legacy.kind, 'stay');
	assert.notEqual(legacy.kind, 'setup');
	assert.equal(trash.kind, 'ignore');
	assert.equal(postNew.kind, 'setup');
});

test('Duplicate next URL is the setup URL, not post.php?action=edit', () => {
	const rows = rowsByProbe(runCases(), 'duplicate_next_url');
	assert.equal(rows.length, 1);
	const url = rows[0].url;
	assert.match(url, /page=dg-portal-setup/);
	assert.match(url, /portal_id=42/);
	assert.doesNotMatch(url, /post\.php\?post=/);
	assert.doesNotMatch(url, /action=edit/);
});

test('Legacy fields submenu label and URL are the named hatch', () => {
	const rows = runCases();
	const label = rowsByProbe(rows, 'legacy_fields_label')[0];
	const dest = rowsByProbe(rows, 'legacy_fields_url')[0];
	assert.equal(label.label, 'Legacy fields');
	assert.match(dest.url, /dg_legacy=1/);
	assert.match(dest.url, /post=42/);
	assert.match(dest.slug, /dg_legacy=1/);
	assert.equal(dest.slug.includes('Legacy fields'), false);
});

test('wizard is not registered as a post.php surface', () => {
	const rows = rowsByProbe(runCases(), 'wizard_on_post_php');
	assert.equal(rows.length, 1);
	assert.equal(rows[0].registers, false);
});
