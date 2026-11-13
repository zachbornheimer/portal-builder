/**
 * Portal → site → built-in resolve. Drives the shipped PHP resolve(), not a copy.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import {
	blankDefinition,
	defaultOptions,
	dualWritePublish,
	normalizeLoaded,
} from '../../src/wizard/definitionModel.js';

const root = process.cwd();
const harness = path.join(root, 'tests/support/php-site-defaults.php');
const packetMetaHarness = path.join(root, 'tests/support/php-packet-meta.php');
const validateHarness = path.join(root, 'tests/support/php-validate-definition.php');
const artifactDir = path.join(root, 'tests/.artifacts/site-defaults');
const BUILTIN_ENDPOINT = 'https://api.allintersections.com';
const BUILTIN_ANONYMIZE_ACK =
	'I certify that my scores and recordings exclude any information that might identify the composer but do include title of work, instrumentation, and duration.';
const BUILTIN_GUIDELINES_LINK_LABEL = 'Link to Guidelines';
const GUIDELINES_URL = 'https://example.org/call';

/**
 * @param {object} payload
 */
function resolve(payload) {
	fs.mkdirSync(artifactDir, { recursive: true });
	const file = path.join(artifactDir, `${payload.name || 'case'}.json`);
	fs.writeFileSync(file, JSON.stringify(payload));
	const r = spawnSync('php', [harness, file], { encoding: 'utf8' });
	const out = `${r.stdout || ''}${r.stderr || ''}`;
	let data = null;
	try {
		data = JSON.parse((r.stdout || '').trim());
	} catch {
		data = null;
	}
	return { code: r.status, out, data };
}

/**
 * @param {object} payload
 */
function renderPacketMeta(payload) {
	fs.mkdirSync(artifactDir, { recursive: true });
	const file = path.join(artifactDir, `${payload.name || 'packet-meta'}.json`);
	fs.writeFileSync(file, JSON.stringify(payload));
	const r = spawnSync('php', [packetMetaHarness, file], { encoding: 'utf8' });
	return { code: r.status, html: r.stdout || '', err: r.stderr || '' };
}

test('portal endpoint null + site endpoint set → resolved endpoint is the site URL', () => {
	const { code, out, data } = resolve({
		name: 'site-endpoint',
		definition: { options: { anonymizeEndpoint: null } },
		site: { anonymizeEndpoint: 'https://anon.example.org' },
	});
	assert.equal(code, 0, out);
	assert.equal(data.anonymizeEndpoint, 'https://anon.example.org');
});

test('portal endpoint set → portal wins', () => {
	const { code, out, data } = resolve({
		name: 'portal-endpoint',
		definition: { options: { anonymizeEndpoint: 'https://portal.example.org' } },
		site: { anonymizeEndpoint: 'https://anon.example.org' },
	});
	assert.equal(code, 0, out);
	assert.equal(data.anonymizeEndpoint, 'https://portal.example.org');
});

test('both endpoints empty/null → All Intersections', () => {
	const { code, out, data } = resolve({
		name: 'builtin-endpoint',
		definition: { options: { anonymizeEndpoint: null } },
		site: { anonymizeEndpoint: '' },
	});
	assert.equal(code, 0, out);
	assert.equal(data.anonymizeEndpoint, BUILTIN_ENDPOINT);
});

test('portal anonymize null + site true → enabled', () => {
	const { code, out, data } = resolve({
		name: 'inherit-anonymize-on',
		definition: { options: { anonymize: null } },
		site: { anonymize: true },
	});
	assert.equal(code, 0, out);
	assert.equal(data.anonymize, true);
});

test('portal anonymize false + site true → disabled', () => {
	const { code, out, data } = resolve({
		name: 'override-anonymize-off',
		definition: { options: { anonymize: false } },
		site: { anonymize: true },
	});
	assert.equal(code, 0, out);
	assert.equal(data.anonymize, false);
});

test('portal anonymizeFailClosed null + site true → enabled', () => {
	const { code, out, data } = resolve({
		name: 'inherit-fail-closed-on',
		definition: { options: { anonymizeFailClosed: null } },
		site: { anonymizeFailClosed: true },
	});
	assert.equal(code, 0, out);
	assert.equal(data.anonymizeFailClosed, true);
});

test('portal anonymizeFailClosed false + site true → disabled', () => {
	const { code, out, data } = resolve({
		name: 'override-fail-closed-off',
		definition: { options: { anonymizeFailClosed: false } },
		site: { anonymizeFailClosed: true },
	});
	assert.equal(code, 0, out);
	assert.equal(data.anonymizeFailClosed, false);
});

test('both anonymizeFailClosed empty/null → off', () => {
	const { code, out, data } = resolve({
		name: 'builtin-fail-closed',
		definition: { options: { anonymizeFailClosed: null } },
		site: {},
	});
	assert.equal(code, 0, out);
	assert.equal(data.anonymizeFailClosed, false);
});

test('empty portal timezone inherits site then America/New_York', () => {
	const siteTz = resolve({
		name: 'site-tz',
		definition: { publish: { timezone: null } },
		site: { timezone: 'America/Chicago' },
	});
	assert.equal(siteTz.code, 0, siteTz.out);
	assert.equal(siteTz.data.timezone, 'America/Chicago');

	const builtin = resolve({
		name: 'builtin-tz',
		definition: { publish: { timezone: '' } },
		site: { timezone: '' },
	});
	assert.equal(builtin.code, 0, builtin.out);
	assert.equal(builtin.data.timezone, 'America/New_York');
});

test('blank definition and normalizeLoaded keep inherit (null) for bools and URLs', () => {
	const blank = blankDefinition('Portal');
	assert.equal(blank.options.anonymize, null);
	assert.equal(blank.options.anonymizeFailClosed, null);
	assert.equal(blank.options.freeForMembers, null);
	assert.equal(blank.options.anonymizeEndpoint, null);
	assert.equal(blank.options.guidelinesUrl, null);
	assert.equal(blank.options.guidelinesLinkLabel, null);
	assert.equal(blank.publish.timezone, null);

	const loaded = normalizeLoaded({
		title: 'Legacy',
		fields: [],
		options: {},
		publish: {},
	});
	assert.equal(loaded.options.anonymize, null);
	assert.equal(loaded.options.freeForMembers, null);
	assert.equal(loaded.options.anonymizeEndpoint, null);
	assert.equal(loaded.options.anonymizeApiKey, null);

	const cleared = normalizeLoaded({
		title: 'Cleared',
		fields: [],
		options: {
			anonymizeEndpoint: '',
			anonymizeApiKey: '',
			guidelinesUrl: '',
			guidelinesLinkLabel: '',
		},
		publish: { timezone: '' },
	});
	assert.equal(cleared.options.anonymizeEndpoint, null);
	assert.equal(cleared.options.anonymizeApiKey, null);
	assert.equal(cleared.options.guidelinesUrl, null);
	assert.equal(cleared.options.guidelinesLinkLabel, null);
	assert.equal(cleared.publish.timezone, null);
	assert.equal(dualWritePublish({ timezone: '' }).timezone, null);
	assert.equal(defaultOptions().anonymize, null);
	assert.equal(defaultOptions().anonymizeFailClosed, null);
});

test('setup screen and wizard mount site defaults on the same channel as accessCatalog', () => {
	const setup = fs.readFileSync(
		path.join(root, 'includes/class-portal-setup-screen.php'),
		'utf8',
	);
	const index = fs.readFileSync(path.join(root, 'src/index.js'), 'utf8');
	const settings = fs.readFileSync(
		path.join(root, 'includes/class-portal-settings.php'),
		'utf8',
	);
	assert.match(setup, /data-dg-site-defaults/);
	assert.match(setup, /Portal_Site_Defaults::for_wizard/);
	assert.match(index, /data-dg-site-defaults/);
	assert.match(index, /siteDefaults/);
	assert.match(settings, /dg_default_anonymize/);
	assert.match(settings, /dg_default_anonymize_endpoint/);
	assert.match(settings, /dg_default_anonymize_api_key/);
	assert.match(settings, /dg_default_anonymize_ack/);
	assert.match(settings, /dg_default_anonymize_fail_closed/);
	assert.match(settings, /dg_default_guidelines_url/);
	assert.match(settings, /dg_default_guidelines_link_label/);
	assert.match(settings, /dg_default_free_for_members/);
	const publishStep = fs.readFileSync(
		path.join(root, 'src/wizard/steps/PublishStep.svelte'),
		'utf8',
	);
	assert.match(publishStep, /guidelinesLinkLabel/);
	assert.match(publishStep, /Using site default/);
	assert.match(publishStep, /BUILTIN_GUIDELINES_LINK_LABEL|Link to Guidelines/);
	assert.match(settings, /dg_default_timezone/);
	assert.match(settings, /dg_default_brand/);
	assert.match(settings, /White label/);
	assert.match(settings, /docs\/design\/ANONYMIZER\.md/);
	assert.match(settings, /dg_login_url/);
	assert.match(settings, /dg_join_url/);
	const optionsFacade = fs.readFileSync(
		path.join(root, 'includes/class-portal-options.php'),
		'utf8',
	);
	assert.match(optionsFacade, /LEGACY_PREFIX\s*=\s*'pb_'/);
	assert.match(optionsFacade, /CANONICAL_PREFIX\s*=\s*'dg_'/);
	assert.match(settings, /Sign-in URL/);
	assert.match(settings, /Membership URL/);
	assert.match(settings, /sanitize_path_or_url/);
});

test('builtin login path is /login and login_url appends redirect_to', () => {
	const bare = resolve({ name: 'login-builtin', action: 'login_url' });
	assert.equal(bare.code, 0, bare.out);
	assert.equal(bare.data.builtin_login, '/login');
	assert.match(bare.data.login_url, /https:\/\/example\.test\/login$/);
	assert.doesNotMatch(bare.data.login_url, /wp-login\.php/);

	const withRedirect = resolve({
		name: 'login-redirect',
		action: 'login_url',
		redirect: 'https://example.test/portal/42/',
	});
	assert.equal(withRedirect.code, 0, withRedirect.out);
	assert.match(withRedirect.data.login_url, /https:\/\/example\.test\/login\?redirect_to=/);
	assert.match(
		decodeURIComponent(withRedirect.data.login_url),
		/https:\/\/example\.test\/portal\/42\//,
	);
	assert.doesNotMatch(withRedirect.data.login_url, /wp-login\.php/);
});

test('stored login and join options override builtins; join builtin is /membership', () => {
	const login = resolve({
		name: 'login-override',
		action: 'login_url',
		options: { pb_login_url: '/signin' },
		redirect: 'https://example.test/here',
	});
	assert.equal(login.code, 0, login.out);
	assert.match(login.data.login_url, /https:\/\/example\.test\/signin\?redirect_to=/);

	const abs = resolve({
		name: 'login-abs',
		action: 'login_url',
		options: { pb_login_url: 'https://auth.example.test/in' },
	});
	assert.equal(abs.code, 0, abs.out);
	assert.equal(abs.data.login_url, 'https://auth.example.test/in');

	const join = resolve({ name: 'join-builtin', action: 'join_url' });
	assert.equal(join.code, 0, join.out);
	assert.equal(join.data.builtin_join, '/membership');
	assert.equal(join.data.join_url, 'https://example.test/membership');

	const joinOver = resolve({
		name: 'join-override',
		action: 'join_url',
		options: { pb_join_url: 'https://example.test/join-now' },
	});
	assert.equal(joinOver.code, 0, joinOver.out);
	assert.equal(joinOver.data.join_url, 'https://example.test/join-now');

	const dgWins = resolve({
		name: 'login-dg-wins',
		action: 'login_url',
		options: { pb_login_url: '/old', dg_login_url: '/new' },
	});
	assert.equal(dgWins.code, 0, dgWins.out);
	assert.match(dgWins.data.login_url, /https:\/\/example\.test\/new$/);
});

test('portal brand empty + empty site → brand is null', () => {
	const missing = resolve({
		name: 'brand-missing',
		definition: { options: {} },
		site: {},
	});
	assert.equal(missing.code, 0, missing.out);
	assert.equal(missing.data.brand, null);

	const emptied = resolve({
		name: 'brand-empty',
		definition: { options: { brand: null } },
		site: { brand: null },
	});
	assert.equal(emptied.code, 0, emptied.out);
	assert.equal(emptied.data.brand, null);
});

test('portal brand null + site brand set → site brand wins', () => {
	const siteBrand = {
		preset: 'isjac',
		ink: '#020726',
		paper: '#FFFAFC',
		accent: '#15526F',
	};
	const { code, out, data } = resolve({
		name: 'brand-site',
		definition: { options: { brand: null } },
		site: { brand: siteBrand },
	});
	assert.equal(code, 0, out);
	assert.deepEqual(data.brand, siteBrand);
});

test('portal brand set → portal wins over site brand', () => {
	const { code, out, data } = resolve({
		name: 'brand-portal',
		definition: {
			options: { brand: { preset: 'custom', ink: '#111111' } },
		},
		site: { brand: { preset: 'isjac', ink: '#020726' } },
	});
	assert.equal(code, 0, out);
	assert.equal(data.brand.preset, 'custom');
	assert.equal(data.brand.ink, '#111111');
});

test('portal anonymizeAck null + site text set → site text', () => {
	const { code, out, data } = resolve({
		name: 'site-ack',
		definition: { options: { anonymizeAck: null } },
		site: { anonymizeAck: 'Site certification text for tests.' },
	});
	assert.equal(code, 0, out);
	assert.equal(data.anonymizeAck, 'Site certification text for tests.');
});

test('portal anonymizeAck set → portal wins', () => {
	const { code, out, data } = resolve({
		name: 'portal-ack',
		definition: { options: { anonymizeAck: 'Portal certification override.' } },
		site: { anonymizeAck: 'Site certification text for tests.' },
	});
	assert.equal(code, 0, out);
	assert.equal(data.anonymizeAck, 'Portal certification override.');
});

test('both anonymizeAck empty/null → builtin sentence', () => {
	const { code, out, data } = resolve({
		name: 'builtin-ack',
		definition: { options: { anonymizeAck: null } },
		site: { anonymizeAck: '' },
	});
	assert.equal(code, 0, out);
	assert.equal(data.anonymizeAck, BUILTIN_ANONYMIZE_ACK);
});

test('blank definition and normalizeLoaded keep anonymizeAck as inherit-null', () => {
	assert.equal(defaultOptions().anonymizeAck, null);
	const blank = blankDefinition('Portal');
	assert.equal(blank.options.anonymizeAck, null);
	const loaded = normalizeLoaded({
		title: 'Legacy',
		fields: [],
		options: {},
		publish: {},
	});
	assert.equal(loaded.options.anonymizeAck, null);
	const cleared = normalizeLoaded({
		title: 'Cleared',
		fields: [],
		options: { anonymizeAck: '' },
		publish: {},
	});
	assert.equal(cleared.options.anonymizeAck, null);
});

test('portal guidelinesLinkLabel set → portal wins', () => {
	const { code, out, data } = resolve({
		name: 'portal-guidelines-label',
		definition: { options: { guidelinesLinkLabel: 'Read our call' } },
		site: { guidelinesLinkLabel: 'Site Guidelines' },
	});
	assert.equal(code, 0, out);
	assert.equal(data.guidelinesLinkLabel, 'Read our call');
});

test('empty portal guidelinesLinkLabel inherits site', () => {
	const { code, out, data } = resolve({
		name: 'site-guidelines-label',
		definition: { options: { guidelinesLinkLabel: null } },
		site: { guidelinesLinkLabel: 'Site Guidelines' },
	});
	assert.equal(code, 0, out);
	assert.equal(data.guidelinesLinkLabel, 'Site Guidelines');
});

test('empty portal and site guidelinesLinkLabel uses built-in, not Read the call', () => {
	const { code, out, data } = resolve({
		name: 'builtin-guidelines-label',
		definition: { options: { guidelinesLinkLabel: '' } },
		site: { guidelinesLinkLabel: '' },
	});
	assert.equal(code, 0, out);
	assert.equal(data.guidelinesLinkLabel, BUILTIN_GUIDELINES_LINK_LABEL);
	assert.notEqual(data.guidelinesLinkLabel, 'Read the call →');
});

test('blank definition and normalizeLoaded keep guidelinesLinkLabel as inherit-null', () => {
	assert.equal(defaultOptions().guidelinesLinkLabel, null);
	const blank = blankDefinition('Portal');
	assert.equal(blank.options.guidelinesLinkLabel, null);
	const loaded = normalizeLoaded({
		title: 'Legacy',
		fields: [],
		options: {},
		publish: {},
	});
	assert.equal(loaded.options.guidelinesLinkLabel, null);
	const cleared = normalizeLoaded({
		title: 'Cleared',
		fields: [],
		options: { guidelinesLinkLabel: '' },
		publish: {},
	});
	assert.equal(cleared.options.guidelinesLinkLabel, null);
});

test('PHP definition normalize persists guidelinesLinkLabel and empties to inherit-null', () => {
	fs.mkdirSync(artifactDir, { recursive: true });
	const withText = path.join(artifactDir, 'php-guidelines-label.json');
	fs.writeFileSync(
		withText,
		JSON.stringify({
			version: 1,
			fields: [{ id: 'work_title', type: 'short_text', label: 'Title' }],
			options: { guidelinesLinkLabel: 'Portal call link' },
		}),
	);
	const on = spawnSync('php', [validateHarness, withText], { encoding: 'utf8' });
	assert.equal(on.status, 0, `${on.stdout || ''}${on.stderr || ''}`);
	const onData = JSON.parse((on.stdout || '').trim());
	assert.equal(onData.definition.options.guidelinesLinkLabel, 'Portal call link');

	const empty = path.join(artifactDir, 'php-guidelines-label-empty.json');
	fs.writeFileSync(
		empty,
		JSON.stringify({
			version: 1,
			fields: [{ id: 'work_title', type: 'short_text', label: 'Title' }],
			options: { guidelinesLinkLabel: '' },
		}),
	);
	const off = spawnSync('php', [validateHarness, empty], { encoding: 'utf8' });
	assert.equal(off.status, 0, `${off.stdout || ''}${off.stderr || ''}`);
	const offData = JSON.parse((off.stdout || '').trim());
	assert.equal(offData.definition.options.guidelinesLinkLabel, null);
});

test('packet-meta default label is Link to Guidelines, not Read the call, and opens in a new tab', () => {
	const { code, html, err } = renderPacketMeta({
		name: 'packet-meta-default',
		definition: { options: { guidelinesUrl: GUIDELINES_URL } },
		site: {},
	});
	assert.equal(code, 0, err || html);
	assert.match(html, /class="dg-meta-link"/);
	assert.match(html, /Link to Guidelines/);
	assert.doesNotMatch(html, /Read the call/);
	assert.match(html, /target="_blank"/);
	assert.match(html, /rel="noopener noreferrer"/);
});

test('packet-meta uses portal label over site, else site, else built-in', () => {
	const portal = renderPacketMeta({
		name: 'packet-meta-portal',
		definition: {
			options: {
				guidelinesUrl: GUIDELINES_URL,
				guidelinesLinkLabel: 'Portal call link',
			},
		},
		site: { guidelinesLinkLabel: 'Site Guidelines' },
	});
	assert.equal(portal.code, 0, portal.err || portal.html);
	assert.match(portal.html, /Portal call link/);
	assert.doesNotMatch(portal.html, /Site Guidelines/);
	assert.doesNotMatch(portal.html, /Read the call/);

	const site = renderPacketMeta({
		name: 'packet-meta-site',
		definition: { options: { guidelinesUrl: GUIDELINES_URL, guidelinesLinkLabel: '' } },
		site: { guidelinesLinkLabel: 'Site Guidelines' },
	});
	assert.equal(site.code, 0, site.err || site.html);
	assert.match(site.html, /Site Guidelines/);
	assert.doesNotMatch(site.html, /Link to Guidelines/);

	const builtin = renderPacketMeta({
		name: 'packet-meta-builtin',
		definition: { options: { guidelinesUrl: GUIDELINES_URL, guidelinesLinkLabel: null } },
		site: { guidelinesLinkLabel: '' },
	});
	assert.equal(builtin.code, 0, builtin.err || builtin.html);
	assert.match(builtin.html, /Link to Guidelines/);
	assert.doesNotMatch(builtin.html, /Read the call/);
});
