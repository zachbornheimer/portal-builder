/**
 * Staff audit/edit/replace and applicant list/recall drive the shipped packet store.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const harness = path.join(root, 'tests/support/php-packet-console.php');
const dir = path.join(root, 'tests/.artifacts/packet-console');

function run() {
	fs.rmSync(dir, { recursive: true, force: true });
	fs.mkdirSync(dir, { recursive: true });
	const scenario = path.join(dir, 'scenario.json');
	fs.writeFileSync(scenario, JSON.stringify({ dir, email: 'alex@example.com' }));
	const r = spawnSync('php', [harness, scenario], { encoding: 'utf8' });
	assert.equal(r.status, 0, `${r.stdout || ''}${r.stderr || ''}`);
	return JSON.parse((r.stdout || '').trim());
}

test('successful submit produces a per-user trail row the applicant can list', () => {
	const data = run();
	assert.equal(data.listCount, 1);
	assert.equal(data.listOnlyMine, true);
	assert.equal(data.ownerCanTouch, true);
	assert.equal(data.strangerDenied, true);
});

test('approved staff can replace a file through the dest adapter; guests cannot', () => {
	const data = run();
	assert.equal(data.staffOk, true);
	assert.equal(data.guestDenied, true);
	assert.equal(data.replaced, true);
	assert.equal(data.deniedReplace, true);
	assert.equal(data.driveWrites.length, 1);
	assert.equal(data.driveWrites[0].field, 'score');
	assert.equal(data.driveWrites[0].bytes, 'NEW-BYTES');
});

test('applicant recall marks the packet so it is no longer current', () => {
	const data = run();
	assert.equal(data.recalled, true);
	assert.equal(data.notCurrent, true);
	assert.equal(data.noTouchAfter, true);
});
