/**
 * First-hour README must name shipped admin/wizard controls and
 * must not send operators to a WordPress applications inbox.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const readme = fs.readFileSync(path.join(root, 'README.md'), 'utf8');

const FORBIDDEN = [
	/manage applications/i,
	/custom post types/i,
	/view and manage submissions from the wordpress admin/i,
	/click test write/i,
];

const REQUIRED = [
	{ name: 'Requires PHP 8.0', re: /requires php 8\.0|php 8\.0/i },
	{ name: 'Requires WordPress 6.4', re: /wordpress 6\.4|requires at least:?\s*6\.4/i },
	{ name: 'OAuth client JSON', re: /oauth client json/i },
	{ name: 'access token', re: /access token/i },
	{ name: 'Google Secret Key', re: /google secret key/i },
	{ name: 'Google Access Key', re: /google access key/i },
	{ name: 'not a service account', re: /not a service[- ]account/i },
	{ name: 'Add New Portal', re: /add new portal/i },
	{ name: 'Start wizard step', re: /\bstart\b/i },
	{ name: 'Build form wizard step', re: /build form/i },
	{ name: 'Map data wizard step', re: /map data/i },
	{ name: 'dest names / headers', re: /headers?.{0,40}dest names?|dest names?.{0,40}headers?/i },
	{ name: 'Publish wizard step', re: /\bpublish\b/i },
	{ name: 'public form URL', re: /public form|public url/i },
	{ name: 'Google Sheet destination', re: /google sheet/i },
	{ name: 'Google Drive destination', re: /google drive/i },
	{ name: 'not a WP applications inbox', re: /not a wordpress applications inbox|not a wp applications inbox/i },
];

const SHIPPED_LABELS = [
	{
		label: 'Portals',
		source: 'includes/class-portal-post-type.php',
		sourceRe: /menu_name'\s*=>\s*__\(\s*'Portals'/,
		readmeRe: /\bPortals\b/,
	},
	{
		label: 'Add New Portal',
		source: 'includes/class-portal-post-type.php',
		sourceRe: /__\(\s*'Add New Portal'/,
		readmeRe: /Add New Portal/,
	},
	{
		label: 'Default Settings',
		source: 'includes/class-portal-settings.php',
		sourceRe: /__\(\s*'Default Settings'/,
		readmeRe: /Default Settings/,
	},
	{
		label: 'Google API Setup',
		source: 'includes/class-portal-settings.php',
		sourceRe: /__\(\s*'Google API Setup'/,
		readmeRe: /Google API Setup/,
	},
	{
		label: 'Google Secret Key',
		source: 'includes/class-portal-settings.php',
		sourceRe: /__\(\s*'Google Secret Key'/,
		readmeRe: /Google Secret Key/,
	},
	{
		label: 'Google Access Key',
		source: 'includes/class-portal-settings.php',
		sourceRe: /__\(\s*'Google Access Key'/,
		readmeRe: /Google Access Key/,
	},
	{
		label: 'Start',
		source: 'src/wizard/steps.js',
		sourceRe: /label:\s*'Start'/,
		readmeRe: /\*\*Start\*\*/,
	},
	{
		label: 'Build form',
		source: 'src/wizard/steps.js',
		sourceRe: /label:\s*'Build form'/,
		readmeRe: /\*\*Build form\*\*/,
	},
	{
		label: 'Map data',
		source: 'src/wizard/steps.js',
		sourceRe: /label:\s*'Map data'/,
		readmeRe: /\*\*Map data\*\*/,
	},
	{
		label: 'Publish',
		source: 'src/wizard/steps.js',
		sourceRe: /label:\s*'Publish'/,
		readmeRe: /\*\*Publish\*\*/,
	},
	{
		label: 'Save Portal',
		source: 'src/wizard/steps/PublishStep.svelte',
		sourceRe: /Save Portal/,
		readmeRe: /Save Portal/,
	},
	{
		label: 'Public form',
		source: 'src/wizard/steps/PublishStep.svelte',
		sourceRe: />Public form</,
		readmeRe: /Public form/,
	},
];

test('README first-hour path does not send operators to a WP applications inbox', () => {
	for (const re of FORBIDDEN) {
		assert.doesNotMatch(readme, re, `forbidden phrase still present: ${re}`);
	}
});

test('README first-hour path names Google keys, wizard steps, dest headers, and Sheet+Drive', () => {
	const missing = REQUIRED.filter((item) => !item.re.test(readme)).map((item) => item.name);
	assert.deepEqual(missing, [], `README missing first-hour facts: ${missing.join(', ')}`);
});

test('README first-hour controls are labels that exist in this checkout', () => {
	const missing = [];
	for (const item of SHIPPED_LABELS) {
		const source = fs.readFileSync(path.join(root, item.source), 'utf8');
		if (!item.sourceRe.test(source)) {
			missing.push(`source missing ${item.label} in ${item.source}`);
		}
		if (!item.readmeRe.test(readme)) {
			missing.push(`README missing shipped label ${item.label}`);
		}
	}
	assert.deepEqual(missing, [], missing.join('; '));
});
