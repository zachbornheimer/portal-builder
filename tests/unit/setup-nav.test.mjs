/**
 * Drives shipped setup-submenu highlight predicates (PHP CLI harness).
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
];

function runCases() {
	fs.mkdirSync(path.dirname(casesPath), { recursive: true });
	fs.writeFileSync(casesPath, JSON.stringify(CASES));
	const r = spawnSync('php', [harness, casesPath], { encoding: 'utf8' });
	assert.equal(r.status, 0, (r.stdout || '') + (r.stderr || ''));
	return JSON.parse((r.stdout || '').trim());
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
