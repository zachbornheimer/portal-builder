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
const artifactDir = path.join(root, 'tests/.artifacts/site-defaults');
const BUILTIN_ENDPOINT = 'https://api.allintersections.com';
const BUILTIN_ANONYMIZE_ACK =
	'I certify that my scores and recordings exclude any information that might identify the composer but do include title of work, instrumentation, and duration.';

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
	assert.equal(blank.options.freeForMembers, null);
	assert.equal(blank.options.anonymizeEndpoint, null);
	assert.equal(blank.options.guidelinesUrl, null);
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
		options: { anonymizeEndpoint: '', anonymizeApiKey: '', guidelinesUrl: '' },
		publish: { timezone: '' },
	});
	assert.equal(cleared.options.anonymizeEndpoint, null);
	assert.equal(cleared.options.anonymizeApiKey, null);
	assert.equal(cleared.options.guidelinesUrl, null);
	assert.equal(cleared.publish.timezone, null);
	assert.equal(dualWritePublish({ timezone: '' }).timezone, null);
	assert.equal(defaultOptions().anonymize, null);
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
	assert.match(settings, /pb_default_anonymize/);
	assert.match(settings, /pb_default_anonymize_endpoint/);
	assert.match(settings, /pb_default_anonymize_api_key/);
	assert.match(settings, /pb_default_anonymize_ack/);
	assert.match(settings, /pb_default_guidelines_url/);
	assert.match(settings, /pb_default_free_for_members/);
	assert.match(settings, /pb_default_timezone/);
	assert.match(settings, /docs\/design\/ANONYMIZER\.md/);
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
