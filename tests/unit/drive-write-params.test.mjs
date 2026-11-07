/**
 * FileStore Drive writes on submit send supportsAllDrives.
 *
 * Shared Drive folders 403 without the flag on files->create.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const harness = path.join(root, 'tests/support/php-drive-write-params.php');
function runHarness() {
	const r = spawnSync('php', [harness], { encoding: 'utf8' });
	const out = (r.stdout || '') + (r.stderr || '');
	let data = null;
	try {
		const start = out.indexOf('{');
		data = start >= 0 ? JSON.parse(out.slice(start)) : null;
	} catch {
		data = null;
	}
	return { code: r.status, out, data };
}

function assertCreateSendsAllDrives(label, captured) {
	assert.ok(captured, `${label}: missing captured create options`);
	assert.equal(
		captured.hasSupportsAllDrives,
		true,
		`${label}: supportsAllDrives missing from files->create options (keys=${JSON.stringify(captured.keys)})`,
	);
	assert.equal(
		captured.supportsAllDrives,
		true,
		`${label}: supportsAllDrives must be true, got ${JSON.stringify(captured.supportsAllDrives)}`,
	);
}

test('gsuite-filestore submodule is replaced by root composer google/apiclient and class-zysys-file-store.php', () => {
	assert.equal(fs.existsSync(path.join(root, '.gitmodules')), false, '.gitmodules must be removed');
	assert.equal(fs.existsSync(path.join(root, 'gsuite-filestore')), false, 'gsuite-filestore directory must be removed');
	assert.equal(
		fs.existsSync(path.join(root, 'includes/adapters/class-zysys-file-store.php')),
		true,
		'includes/adapters/class-zysys-file-store.php must exist',
	);
	const composerJson = JSON.parse(fs.readFileSync(path.join(root, 'composer.json'), 'utf8'));
	assert.ok(
		composerJson.require && typeof composerJson.require['google/apiclient'] === 'string',
		'composer.json require must include google/apiclient',
	);
	const composerLock = JSON.parse(fs.readFileSync(path.join(root, 'composer.lock'), 'utf8'));
	const lockedNames = Array.isArray(composerLock.packages)
		? composerLock.packages.map((pkg) => pkg && pkg.name)
		: [];
	assert.equal(lockedNames.includes('google/apiclient'), true, 'composer.lock must include google/apiclient');
});

test('every FileStore files->create on submit sends supportsAllDrives and uploads bytes in memory', () => {
	const { code, out, data } = runHarness();
	assert.equal(code, 0, out);
	assert.ok(data, out);
	assert.equal(data.ok, true, out);
	assert.equal(data.createCount, 2, `expected folder + upload creates, got ${data.createCount}`);
	assertCreateSendsAllDrives('create_drive_subfolder', data.folder);
	assertCreateSendsAllDrives('store_drive_file', data.upload);
	assert.equal(data.folder.fields, 'id');
	assert.equal(data.upload.uploadType, 'multipart');

	assert.ok(data.bytesUpload && data.bytesUpload.create, 'missing bytesUpload capture');
	assertCreateSendsAllDrives('store_drive_bytes', data.bytesUpload.create);
	assert.equal(data.bytesUpload.create.name, 'score.pdf');
	assert.equal(data.bytesUpload.create.mimeType, 'application/pdf');
	assert.equal(data.bytesUpload.create.data, '%PDF-1.4\n%in-memory\n');

	assert.ok(data.googleStore, 'missing googleStore capture');
	assert.equal(data.googleStore.tempWrites, 0, 'Portal_Google_Store::store_file must not write temp files when store_drive_bytes exists');
	assert.equal(data.googleStore.tempMkdirs, 0, 'Portal_Google_Store::store_file must not create temp dirs when store_drive_bytes exists');
	assert.equal(data.googleStore.createCount, 2);
	assertCreateSendsAllDrives('Portal_Google_Store subfolder', data.googleStore.subfolder);
	assertCreateSendsAllDrives('Portal_Google_Store file upload', data.googleStore.file);
	assert.equal(data.googleStore.file.name, 'score.pdf');
	assert.equal(data.googleStore.file.mimeType, 'application/pdf');
	assert.equal(data.googleStore.file.data, '%PDF-1.4\n%direct-bytes\n');
});

test('Zysys_FileStore::configure refreshes expired array-credential tokens and sets update_access_token', () => {
	const { code, out, data } = runHarness();
	assert.equal(code, 0, out);
	assert.ok(data && data.tokenRefresh, out);
	assert.equal(data.tokenRefresh.fetchedWith, 'refresh-token-123');
	assert.equal(typeof data.tokenRefresh.updateAccessToken, 'string');
	assert.equal(data.tokenRefresh.updateAccessToken, data.tokenRefresh.updatedAccessToken);
	const refreshed = JSON.parse(data.tokenRefresh.updateAccessToken);
	assert.equal(refreshed.access_token, 'refreshed-access-token-xyz');
	assert.equal(refreshed.refresh_token, 'refresh-token-123');
	assert.equal(data.tokenRefresh.freshUpdateToken, false);
	assert.equal(data.tokenRefresh.freshUpdatedToken, false);
});
