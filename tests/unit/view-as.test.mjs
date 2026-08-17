/**
 * View-as: staff overlay, permission, submit reject.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { normalizeAccess } from '../../src/wizard/definitionModel.js';

const root = process.cwd();
const harness = path.join(root, 'tests/support/php-view-as.php');
const validateHarness = path.join(root, 'tests/support/php-validate-definition.php');

/**
 * @param {object} payload
 */
function viewAs(payload) {
	const file = path.join(root, 'tests/.artifacts/view-as.json');
	fs.mkdirSync(path.dirname(file), { recursive: true });
	fs.writeFileSync(file, JSON.stringify(payload));
	const r = spawnSync('php', [harness, file], { encoding: 'utf8' });
	assert.equal(r.status, 0, (r.stdout || '') + (r.stderr || ''));
	return JSON.parse((r.stdout || '').trim());
}

test('logged_out persona is denied login on a members portal', () => {
	const out = viewAs({
		call: 'decide',
		persona: 'logged_out',
		access: { audience: 'members', membershipPlanIds: ['17214'] },
		options: {},
	});
	assert.equal(out.allowed, false);
	assert.equal(out.reason, 'login');
});

test('plan persona is allowed when the plan is in the members list', () => {
	const out = viewAs({
		call: 'decide',
		persona: 'plan:17214',
		access: { audience: 'members', membershipPlanIds: ['17214', '17276'] },
		options: {},
	});
	assert.equal(out.allowed, true);
});

test('logged_in persona without a plan is denied membership when Woo is present', () => {
	const out = viewAs({
		call: 'decide',
		persona: 'logged_in',
		access: { audience: 'members', membershipPlanIds: ['17214'] },
		options: {},
	});
	assert.equal(out.allowed, false);
	assert.equal(out.reason, 'membership');
});

test('unprivileged query is ignored so the real member stays the applicant', () => {
	const real = {
		logged_in: true,
		plan_ids: ['17214'],
		meta: {},
		membership_provider: true,
		roles: ['subscriber'],
		capabilities: ['read'],
	};
	const out = viewAs({
		call: 'applicant',
		query: 'logged_out',
		wp: { logged_in: true, user_id: 7, roles: ['subscriber'] },
		site_roles: ['administrator'],
		definition: {
			version: 1,
			fields: [{ id: 'work_title', type: 'short_text', label: 'Title' }],
			access: { viewAsRoles: [] },
		},
		real_applicant: real,
		stub_woo: true,
		stub_woo_plans: ['17214'],
	});
	assert.equal(out.is_active, false);
	assert.equal(out.applicant.logged_in, true);
	assert.deepEqual(out.applicant.plan_ids, ['17214']);
});

test('view-as admission rejects a live submission', () => {
	const out = viewAs({
		call: 'submit_decide',
		is_preview: false,
		is_open: true,
		is_view_as: true,
	});
	assert.equal(out.code, 'dg_submission_view_as');
});

test('normalizeAccess keeps viewAsRoles in JS and PHP', () => {
	const js = normalizeAccess({ viewAsRoles: ['editor'] });
	assert.deepEqual(js.viewAsRoles, ['editor']);

	const file = path.join(root, 'tests/.artifacts/view-as-roles-persist.json');
	fs.mkdirSync(path.dirname(file), { recursive: true });
	fs.writeFileSync(
		file,
		JSON.stringify({
			version: 1,
			title: 'View as',
			fields: [{ id: 'work_title', type: 'short_text', label: 'Title' }],
			access: { viewAsRoles: ['editor'] },
		}),
	);
	const r = spawnSync('php', [validateHarness, file], { encoding: 'utf8' });
	assert.equal(r.status, 0, (r.stdout || '') + (r.stderr || ''));
	const data = JSON.parse((r.stdout || '').trim());
	assert.deepEqual(data.definition.access.viewAsRoles, ['editor']);
});

test('empty site roles resolve to administrator', () => {
	const out = viewAs({ call: 'sanitize_roles', roles: [] });
	assert.deepEqual(out, ['administrator']);
});

test('persona catalog labels include Logged Out and a named plan', () => {
	const out = viewAs({
		call: 'bar_items',
		catalog: { membershipPlans: [{ id: '17214', name: 'Student Member' }] },
	});
	const labels = out.map((item) => item.label);
	assert.ok(labels.includes('Logged Out'));
	assert.ok(labels.includes('Student Member'));
});
