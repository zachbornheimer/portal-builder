/**
 * Drives shipped Google API setup + field-help callbacks (PHP CLI harness).
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import path from 'node:path';

const root = process.cwd();
const harness = path.join(root, 'tests/support/php-google-api-setup.php');

const SERVICE_ACCOUNT = /service[- ]accounts?/gi;
const WARNING_NEAR_SA =
	/\b(do not|don't|not a |never |instead of|cannot|can't)\b/;

/**
 * Mentions that present a service account as a required setup path.
 *
 * @param {string} text
 * @returns {string[]}
 */
function requiredServiceAccountMentions(text) {
	const hits = [];
	for (const match of text.matchAll(SERVICE_ACCOUNT)) {
		const start = Math.max(0, match.index - 48);
		const window = text.slice(start, match.index + match[0].length + 24);
		if (!WARNING_NEAR_SA.test(window.toLowerCase())) {
			hits.push(window.replace(/\s+/g, ' ').trim());
		}
	}
	return hits;
}

/**
 * @returns {{ setup: string, section: string, secret: string, access: string, all: string }}
 */
function renderShippedCopy() {
	const result = spawnSync('php', [harness], { encoding: 'utf8' });
	assert.equal(result.status, 0, `${result.stdout || ''}${result.stderr || ''}`);
	const data = JSON.parse((result.stdout || '').trim());
	assert.equal(typeof data.setup, 'string');
	assert.equal(typeof data.section, 'string');
	assert.equal(typeof data.secret, 'string');
	assert.equal(typeof data.access, 'string');
	return {
		...data,
		all: `${data.setup}\n${data.section}\n${data.secret}\n${data.access}`,
	};
}

test('Google API setup copy does not require Storage Admin or a service account', () => {
	const { all } = renderShippedCopy();
	assert.doesNotMatch(all, /storage admin/i);
	assert.deepEqual(requiredServiceAccountMentions(all), []);
});

test('Google API setup and field help describe OAuth client JSON plus access token', () => {
	const { setup, secret, access } = renderShippedCopy();

	assert.match(setup, /google cloud/i);
	assert.match(setup, /project/i);
	assert.match(setup, /drive api/i);
	assert.match(setup, /sheets api/i);
	assert.match(setup, /oauth client/i);
	assert.match(setup, /\bdesktop\b/i);
	assert.match(setup, /\bweb\b/i);
	assert.match(setup, /google secret key/i);
	assert.match(setup, /client json/i);
	assert.match(setup, /access token/i);
	assert.match(setup, /google access key/i);
	assert.match(setup, /share/i);
	assert.match(setup, /drive folder/i);
	assert.match(setup, /sheet/i);
	assert.match(setup, /oauth consent|google identity|human/i);

	assert.match(secret, /oauth client json/i);
	assert.match(access, /oauth access token/i);
	assert.match(access, /filestore/i);
	assert.match(access, /refresh/i);
});
