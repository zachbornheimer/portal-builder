/**
 * Bits Dialog slice: admin ConfirmModal + public file-confirm island.
 * Dialog chrome lives in the Svelte island / enqueue, not createElement.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { shouldDispatchCancel, confirmedForOpen } from '../../src/confirm-dismiss.js';

const root = process.cwd();

function read(rel) {
	return fs.readFileSync(path.join(root, rel), 'utf8');
}

test('package.json lists bits-ui as a runtime dependency', () => {
	const pkg = JSON.parse(read('package.json'));
	assert.ok(pkg.dependencies && pkg.dependencies['bits-ui'], 'bits-ui missing from dependencies');
});

test('ConfirmModal imports Bits Dialog and has no hand-rolled dialog chrome', () => {
	const src = read('src/ConfirmModal.svelte');
	assert.match(src, /from ["']bits-ui["']/);
	assert.match(src, /Dialog/);
	assert.doesNotMatch(src, /role=["']dialog["']/);
	assert.doesNotMatch(src, /svelte:window/);
	assert.doesNotMatch(src, /event\.key === ['"]Escape['"]/);
});

test('parent-driven Bits close still cancels when onOpenChange(true) never ran', () => {
	assert.equal(shouldDispatchCancel(false, false), true);
	assert.equal(shouldDispatchCancel(false, true), false);
	assert.equal(shouldDispatchCancel(true, false), false);
	const src = read('src/ConfirmModal.svelte');
	assert.match(src, /shouldDispatchCancel/);
	assert.doesNotMatch(src, /wasOpen/);
});

test('reopen after confirm clears confirmed from isOpen so the next dismiss cancels', () => {
	let confirmed = false;
	confirmed = confirmedForOpen(true, confirmed);
	assert.equal(confirmed, false);
	confirmed = true;
	assert.equal(shouldDispatchCancel(false, confirmed), false);
	confirmed = confirmedForOpen(true, confirmed);
	assert.equal(confirmed, false);
	assert.equal(shouldDispatchCancel(false, confirmed), true);
	const src = read('src/ConfirmModal.svelte');
	assert.match(src, /confirmedForOpen/);
	assert.match(src, /\$effect(?:\.pre)?/);
	assert.doesNotMatch(src, /if\s*\(\s*next\s*\)\s*\{\s*confirmed\s*=\s*false/);
	const openChange = src.slice(src.indexOf('function handleOpenChange'));
	assert.doesNotMatch(openChange, /confirmed\s*=/);
});

test('DeleteConfirmModal still binds the same ConfirmModal props and events', () => {
	const src = read('src/DeleteConfirmModal.svelte');
	assert.match(src, /import ConfirmModal from ['"]\.\/ConfirmModal\.svelte['"]/);
	assert.match(src, /\{isOpen\}/);
	assert.match(src, /\{title\}/);
	assert.match(src, /\{message\}/);
	assert.match(src, /confirmText=/);
	assert.match(src, /cancelText=/);
	assert.match(src, /on:confirm=/);
	assert.match(src, /on:cancel=/);
});

test('public-render enqueues dragongate-public and prints the preview mount', () => {
	const php = read('includes/Definition/class-portal-public-render.php');
	assert.match(php, /assets\/dist\/dragongate-public\.js/);
	assert.match(php, /data-dg-preview-root/);
	assert.match(php, /dg-file-preview/);
	assert.match(php, /array\(\s*'dg-file-preview',\s*'dg-public-preview'\s*\)/);
	assert.doesNotMatch(php, /dragongate-portal\.js/);
});

test('definition-form opens DGPreview only after proveReadable; missing island is an error', () => {
	const js = read('assets/definition-form.js');
	assert.match(js, /proveReadable/);
	assert.match(js, /globalThis\.DGPreview\.open/);
	assert.match(js, /function previewReady\s*\(/);
	assert.match(
		js,
		/proveReadable\s*\([\s\S]*?\)\s*\.then\s*\(\s*function\s*\(\)\s*\{[\s\S]*?globalThis\.DGPreview\.open/,
	);
	assert.match(js, /if\s*\(\s*!previewReady\(\)\s*\)/);
	assert.match(js, /onError\(\)/);
	assert.doesNotMatch(js, /PREVIEW_DIALOG_ID/);
	assert.doesNotMatch(js, /ensurePreviewDialog/);
	assert.doesNotMatch(js, /trapPreviewFocus/);
	assert.doesNotMatch(js, /paintPreview/);
	assert.doesNotMatch(js, /createElement\(\s*['"]div['"]\s*\)/);
});

test('public island sets DGPreview and does not import WizardShell', () => {
	const entry = read('src/public/index.js');
	const dialog = read('src/public/PreviewDialog.svelte');
	const vite = read('vite.config.js');
	assert.match(entry, /globalThis\.DGPreview/);
	assert.match(entry, /data-dg-preview-root/);
	assert.doesNotMatch(entry, /from ['"].*WizardShell/);
	assert.match(dialog, /from ["']bits-ui["']/);
	assert.match(dialog, /Confirm this file/);
	assert.match(vite, /['"]dragongate-public['"]\s*:\s*['"]src\/public\/index\.js['"]/);
});

test('built public bundle exposes DGPreview and does not contain WizardShell', () => {
	const publicJs = path.join(root, 'assets/dist/dragongate-public.js');
	assert.ok(fs.existsSync(publicJs), 'assets/dist/dragongate-public.js missing — run npm run build');
	const built = fs.readFileSync(publicJs, 'utf8');
	assert.ok(built.length > 0, 'dragongate-public.js is empty');
	assert.match(built, /DGPreview/);
	assert.doesNotMatch(built, /WizardShell/);
	const distDir = path.join(root, 'assets/dist');
	for (const name of fs.readdirSync(distDir).filter((file) => file.endsWith('.js'))) {
		const source = fs.readFileSync(path.join(distDir, name), 'utf8');
		assert.doesNotMatch(source, /WizardShell/, `${name} must not contain WizardShell`);
	}
});
