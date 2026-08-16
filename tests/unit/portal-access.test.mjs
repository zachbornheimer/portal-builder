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
	const none = decide({
		access: { audience: 'members', membershipPlanIds: [] },
		applicant: { logged_in: true, plan_ids: [], meta: {} },
	});
	assert.equal(none.allowed, false);
	assert.equal(none.reason, 'membership');

	const any = decide({
		access: { audience: 'members', membershipPlanIds: [] },
		applicant: { logged_in: true, plan_ids: ['17214'], meta: {} },
	});
	assert.equal(any.allowed, true);

	const wrong = decide({
		access: { audience: 'members', membershipPlanIds: ['17276'] },
		applicant: { logged_in: true, plan_ids: ['17214'], meta: {} },
	});
	assert.equal(wrong.allowed, false);

	const right = decide({
		access: { audience: 'members', membershipPlanIds: ['17276', '17214'] },
		applicant: { logged_in: true, plan_ids: ['17214'], meta: {} },
	});
	assert.equal(right.allowed, true);
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
		profileRules: [{ key: 'COUNTRY', op: 'eq', value: 'Canada' }],
		denyMessage: 'Members in Canada only.',
	});
	assert.equal(kept.audience, 'members');
	assert.deepEqual(kept.membershipPlanIds, ['17214']);
	assert.equal(kept.profileRules[0].key, 'COUNTRY');
	assert.equal(kept.denyMessage, 'Members in Canada only.');
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
	assert.match(settings, /pb_default_free_for_members/);
	assert.match(settings, /Free for members/);
	assert.match(publishStep, /Free for members/);
	assert.match(brand, /PRESET_ISJAC/);
});
