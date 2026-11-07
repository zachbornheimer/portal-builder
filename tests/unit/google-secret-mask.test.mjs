/**
 * ZYS-632: GET settings HTML after save must not contain the raw Google secret.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import path from 'node:path';

const root = process.cwd();
const harness = path.join(root, 'tests/support/php-secret-field.php');
const SECRET = 'oauth-client-json-super-secret-pem-ABCD';

function run(payload) {
	const r = spawnSync('php', [harness, JSON.stringify(payload)], { encoding: 'utf8' });
	assert.equal(r.status, 0, `${r.stdout || ''}${r.stderr || ''}`);
	return JSON.parse((r.stdout || '').trim());
}

test('GET settings HTML after save does not contain the raw Google client secret', () => {
	const data = run({ stored: SECRET, incoming: '' });
	assert.equal(data.set, true);
	assert.equal(data.lastFour, '••••ABCD');
	assert.equal(data.kept, SECRET);
	assert.doesNotMatch(data.html, /super-secret-pem-value/);
	assert.doesNotMatch(data.html, /authorized_user/);
	assert.match(data.html, /Key set/);
	assert.match(data.html, /••••ABCD/);
	assert.match(data.html, /Leave blank to keep the saved value/);
});

test('pasting a new secret rotates; blank incoming keeps the old value', () => {
	const rotated = run({ stored: SECRET, incoming: '{"client_secret":"next"}' });
	assert.equal(rotated.kept, '{"client_secret":"next"}');
	const kept = run({ stored: SECRET, incoming: '   ' });
	assert.equal(kept.kept, SECRET);
});
