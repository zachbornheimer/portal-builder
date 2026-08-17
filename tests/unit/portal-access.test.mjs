/**
 * Portal_Access::decide — login, membership, profile rules, fee waiver.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { defaultAccess, normalizeAccess } from '../../src/wizard/definitionModel.js';

const root = process.cwd();
const harness = path.join(root, 'tests/support/php-access-decide.php');

/**
 * @param {object} payload
 */
function decide(payload) {
	const file = path.join(root, 'tests/.artifacts/access-decide.json');
	fs.mkdirSync(path.dirname(file), { recursive: true });
	fs.writeFileSync(file, JSON.stringify(payload));
	const r = spawnSync('php', [harness, file], { encoding: 'utf8' });
	assert.equal(r.status, 0, (r.stdout || '') + (r.stderr || ''));
	return JSON.parse((r.stdout || '').trim());
}

test('anyone may apply when audience is anyone', () => {
	const out = decide({
		access: { audience: 'anyone' },
		options: {},
		applicant: { logged_in: false, plan_ids: [], meta: {} },
	});
	assert.equal(out.allowed, true);
	assert.equal(out.feeWaived, false);
});

test('logged_in audience denies guests and allows signed-in users', () => {
	const guest = decide({
		access: { audience: 'logged_in' },
		applicant: { logged_in: false, plan_ids: [], meta: {} },
	});
	assert.equal(guest.allowed, false);
	assert.equal(guest.reason, 'login');

	const user = decide({
		access: { audience: 'logged_in' },
		applicant: { logged_in: true, plan_ids: [], meta: {} },
	});
	assert.equal(user.allowed, true);
});

test('members audience requires a held plan; empty required list means any plan', () => {
	const woo = { membership_provider: true, meta: {} };
	const none = decide({
		access: { audience: 'members', membershipPlanIds: [] },
		applicant: { ...woo, logged_in: true, plan_ids: [] },
	});
	assert.equal(none.allowed, false);
	assert.equal(none.reason, 'membership');

	const any = decide({
		access: { audience: 'members', membershipPlanIds: [] },
		applicant: { ...woo, logged_in: true, plan_ids: ['17214'] },
	});
	assert.equal(any.allowed, true);

	const wrong = decide({
		access: { audience: 'members', membershipPlanIds: ['17276'] },
		applicant: { ...woo, logged_in: true, plan_ids: ['17214'] },
	});
	assert.equal(wrong.allowed, false);

	const right = decide({
		access: { audience: 'members', membershipPlanIds: ['17276', '17214'] },
		applicant: { ...woo, logged_in: true, plan_ids: ['17214'] },
	});
	assert.equal(right.allowed, true);
});

test('signed-in administrator bypasses members and profile gates; guests and editors do not', () => {
	const membersPortal = {
		audience: 'members',
		membershipPlanIds: ['17214', '17276'],
	};
	const woo = { membership_provider: true, plan_ids: [], meta: {} };

	const admin = decide({
		access: membersPortal,
		applicant: { ...woo, logged_in: true, roles: ['administrator'] },
	});
	assert.equal(admin.allowed, true);
	assert.equal(admin.feeWaived, true);

	const guest = decide({
		access: membersPortal,
		applicant: { ...woo, logged_in: false, roles: [] },
	});
	assert.equal(guest.allowed, false);
	assert.equal(guest.reason, 'login');

	const stuffedGuest = decide({
		access: membersPortal,
		applicant: { ...woo, logged_in: false, roles: ['administrator'] },
	});
	assert.equal(stuffedGuest.allowed, false);
	assert.equal(stuffedGuest.reason, 'login');

	const editor = decide({
		access: membersPortal,
		applicant: { ...woo, logged_in: true, roles: ['editor'] },
	});
	assert.equal(editor.allowed, false);
	assert.equal(editor.reason, 'membership');

	const adminDespiteProfile = decide({
		access: {
			...membersPortal,
			profileRules: [{ key: 'COUNTRY', op: 'eq', value: 'Canada' }],
		},
		applicant: { ...woo, logged_in: true, roles: ['Administrator'] },
	});
	assert.equal(adminDespiteProfile.allowed, true);
	assert.equal(adminDespiteProfile.feeWaived, true);
});

test('vanilla members allows signed-in users and gates a named WordPress role', () => {
	const vanilla = { membership_provider: false, plan_ids: [], meta: {} };

	const guest = decide({
		access: { audience: 'members' },
		applicant: { ...vanilla, logged_in: false },
	});
	assert.equal(guest.allowed, false);
	assert.equal(guest.reason, 'login');

	const signedIn = decide({
		access: { audience: 'members', roles: [] },
		applicant: { ...vanilla, logged_in: true, roles: ['subscriber'] },
	});
	assert.equal(signedIn.allowed, true);

	const editor = decide({
		access: { audience: 'members', roles: ['editor'] },
		applicant: { ...vanilla, logged_in: true, roles: ['editor'] },
	});
	assert.equal(editor.allowed, true);

	const subscriber = decide({
		access: { audience: 'members', roles: ['editor'] },
		applicant: { ...vanilla, logged_in: true, roles: ['subscriber'] },
	});
	assert.equal(subscriber.allowed, false);
	assert.equal(subscriber.reason, 'membership');

	const viaCap = decide({
		access: { audience: 'members', roles: ['edit_pages'] },
		applicant: {
			...vanilla,
			logged_in: true,
			roles: ['author'],
			capabilities: ['edit_pages'],
		},
	});
	assert.equal(viaCap.allowed, true);
});

test('profile rule country eq / institution alias / age lte', () => {
	const us = decide({
		access: {
			audience: 'anyone',
			profileRules: [{ key: 'COUNTRY', op: 'eq', value: 'United States of America' }],
		},
		applicant: { logged_in: true, plan_ids: [], meta: { COUNTRY: 'United States of America' } },
	});
	assert.equal(us.allowed, true);

	const alias = decide({
		access: {
			audience: 'anyone',
			profileRules: [{ key: 'INSTITUTION', op: 'eq', value: 'Berklee' }],
		},
		applicant: { logged_in: true, plan_ids: [], meta: { INSTITUTE: 'Berklee' } },
	});
	assert.equal(alias.allowed, true);

	const young = decide({
		access: {
			audience: 'anyone',
			profileRules: [{ key: 'age', op: 'lte', value: '26' }],
		},
		applicant: { logged_in: true, plan_ids: [], meta: { age: '22' } },
	});
	assert.equal(young.allowed, true);

	const old = decide({
		access: {
			audience: 'anyone',
			profileRules: [{ key: 'age', op: 'lte', value: '26' }],
		},
		applicant: { logged_in: true, plan_ids: [], meta: { age: '40' } },
	});
	assert.equal(old.allowed, false);
	assert.equal(old.reason, 'profile');
});

test('freeForMembers waives fee only for matching plans', () => {
	const waived = decide({
		access: { audience: 'anyone' },
		options: { freeForMembers: true, freeMembershipPlanIds: ['17214'] },
		applicant: { logged_in: true, plan_ids: ['17214'], meta: {} },
	});
	assert.equal(waived.allowed, true);
	assert.equal(waived.feeWaived, true);

	const paid = decide({
		access: { audience: 'anyone' },
		options: { freeForMembers: true, freeMembershipPlanIds: ['17214'] },
		applicant: { logged_in: true, plan_ids: ['17276'], meta: {} },
	});
	assert.equal(paid.feeWaived, false);

	const anyMember = decide({
		access: { audience: 'anyone' },
		options: { freeForMembers: true, freeMembershipPlanIds: [] },
		applicant: { logged_in: true, plan_ids: ['17276'], meta: {} },
	});
	assert.equal(anyMember.feeWaived, true);
});

test('normalizeAccess defaults to anyone and keeps a profile rule', () => {
	const empty = normalizeAccess(null);
	assert.deepEqual(empty, defaultAccess());
	const kept = normalizeAccess({
		audience: 'members',
		membershipPlanIds: [17214],
		roles: ['editor'],
		capabilities: ['edit_pages'],
		profileRules: [{ key: 'COUNTRY', op: 'eq', value: 'Canada' }],
		denyMessage: 'Members in Canada only.',
	});
	assert.equal(kept.audience, 'members');
	assert.deepEqual(kept.membershipPlanIds, ['17214']);
	assert.deepEqual(kept.roles, ['editor']);
	assert.deepEqual(kept.capabilities, ['edit_pages']);
	assert.equal(kept.profileRules[0].key, 'COUNTRY');
	assert.equal(kept.denyMessage, 'Members in Canada only.');
});

test('PHP definition validate keeps access.roles', () => {
	const file = path.join(root, 'tests/.artifacts/access-roles-persist.json');
	fs.mkdirSync(path.dirname(file), { recursive: true });
	fs.writeFileSync(
		file,
		JSON.stringify({
			version: 1,
			title: 'Role gate',
			fields: [{ id: 'work_title', type: 'short_text', label: 'Title' }],
			access: {
				audience: 'members',
				roles: ['editor'],
				capabilities: ['edit_pages'],
			},
		}),
	);
	const r = spawnSync('php', [path.join(root, 'tests/support/php-validate-definition.php'), file], {
		encoding: 'utf8',
	});
	assert.equal(r.status, 0, (r.stdout || '') + (r.stderr || ''));
	const data = JSON.parse((r.stdout || '').trim());
	assert.deepEqual(data.definition.access.roles, ['editor']);
	assert.deepEqual(data.definition.access.capabilities, ['edit_pages']);
});

test('settings and meta PHP use generic members wording, not Consortium', () => {
	const meta = fs.readFileSync(path.join(root, 'portal-builder.php'), 'utf8');
	const settings = fs.readFileSync(
		path.join(root, 'includes/class-portal-settings.php'),
		'utf8',
	);
	const publishStep = fs.readFileSync(
		path.join(root, 'src/wizard/steps/PublishStep.svelte'),
		'utf8',
	);
	const brand = fs.readFileSync(
		path.join(root, 'includes/Definition/class-portal-brand.php'),
		'utf8',
	);
	assert.doesNotMatch(meta, /Consortium/);
	assert.doesNotMatch(settings, /Consortium/);
	assert.doesNotMatch(publishStep, /consortium/i);
	assert.match(meta, /_portal_free_for_members/);
	assert.match(meta, /Free for members/);
	assert.match(settings, /dg_default_free_for_members/);
	assert.match(settings, /Free for members/);
	assert.match(publishStep, /Free for members/);
	assert.match(brand, /PRESET_ISJAC/);
	assert.doesNotMatch(publishStep, /Any active membership will be accepted/);
	assert.match(publishStep, /Signed-in users/);
	assert.match(publishStep, /WordPress role/);
});

/**
 * @param {object} payload
 */
function snapshotApplicant(payload) {
	const file = path.join(root, 'tests/.artifacts/access-applicant.json');
	fs.mkdirSync(path.dirname(file), { recursive: true });
	fs.writeFileSync(file, JSON.stringify({ call: 'applicant', ...payload }));
	const r = spawnSync('php', [harness, file], { encoding: 'utf8' });
	assert.equal(r.status, 0, (r.stdout || '') + (r.stderr || ''));
	return JSON.parse((r.stdout || '').trim());
}

test('live applicant snapshot without Woo reports no provider and the user role', () => {
	const snap = snapshotApplicant({
		wp: {
			logged_in: true,
			user_id: 7,
			roles: ['editor'],
			capabilities: { edit_pages: true, read: true },
		},
	});
	assert.equal(snap.logged_in, true);
	assert.equal(snap.membership_provider, false);
	assert.deepEqual(snap.roles, ['editor']);
	assert.ok(snap.capabilities.includes('edit_pages'));
	assert.deepEqual(snap.plan_ids, []);
});

test('live applicant snapshot with Woo stubs still gates plan IDs 17214 and 17276', () => {
	const snap = snapshotApplicant({
		wp: { logged_in: true, user_id: 7, roles: ['subscriber'] },
		stub_woo: true,
		stub_woo_plans: ['17214'],
	});
	assert.equal(snap.membership_provider, true);
	assert.deepEqual(snap.plan_ids, ['17214']);

	const allowed = decide({
		access: { audience: 'members', membershipPlanIds: ['17214'] },
		applicant: snap,
	});
	assert.equal(allowed.allowed, true);

	const denied = decide({
		access: { audience: 'members', membershipPlanIds: ['17276'] },
		applicant: snap,
	});
	assert.equal(denied.allowed, false);
	assert.equal(denied.reason, 'membership');
});
