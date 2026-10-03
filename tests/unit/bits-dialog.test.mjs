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
\treturn fs.readFileSync(path.join(root, rel), 'utf8');
}

test('package.json lists bits-ui as a runtime dependency', () => {
\tconst pkg = JSON.parse(read('package.json'));
\tassert.ok(pkg.dependencies && pkg.dependencies['bits-ui'], 'bits-ui missing from dependencies');
});

test('ConfirmModal imports Bits Dialog and has no hand-rolled dialog chrome', () => {
\tconst src = read('src/ConfirmModal.svelte');
\tassert.match(src, /from ["']bits-ui["']/);
\tassert.match(src, /Dialog/);
\tassert.doesNotMatch(src, /role=["']dialog["']/);
\tassert.doesNotMatch(src, /svelte:window/);
\tassert.doesNotMatch(src, /event\\.key === ['"]Escape['"]/);
});

test('parent-driven Bits close still cancels when onOpenChange(true) never ran', () => {
\tassert.equal(shouldDispatchCancel(false, false), true);
\tassert.equal(shouldDispatchCancel(false, true), false);
\tassert.equal(shouldDispatchCancel(true, false), false);
\tconst src = read('src/ConfirmModal.svelte');
\tassert.match(src, /shouldDispatchCancel/);
\tassert.doesNotMatch(src, /wasOpen/);
});

test('reopen after confirm clears confirmed from isOpen so the next dismiss cancels', () => {
\tlet confirmed = false;
\tconfirmed = confirmedForOpen(true, confirmed);
\tassert.equal(confirmed, false);
\tconfirmed = true;
\tassert.equal(shouldDispatchCancel(false, confirmed), false);
\tconfirmed = confirmedForOpen(true, confirmed);
\tassert.equal(confirmed, false);
\tassert.equal(shouldDispatchCancel(false, confirmed), true);
\tconst src = read('src/ConfirmModal.svelte');
\tassert.match(src, /confirmedForOpen/);
\tassert.match(src, /\$effect(?:\.pre)?/);
\tassert.doesNotMatch(src, /if\s*\(\s*next\s*\)\s*\{\s*confirmed\s*=\s*false/);
\tconst openChange = src.slice(src.indexOf('function handleOpenChange'));
\tassert.doesNotMatch(openChange, /confirmed\s*=/);
});

test('DeleteConfirmModal still binds the same ConfirmModal props and events', () => {
\tconst src = read('src/DeleteConfirmModal.svelte');
\tassert.match(src, /import ConfirmModal from ['"]\.\/ConfirmModal\.svelte['"]/);
\tassert.match(src, /\{isOpen\}/);
\tassert.match(src, /\{title\}/);
\tassert.match(src, /\{message\}/);
\tassert.match(src, /confirmText=/);
\tassert.match(src, /cancelText=/);
\tassert.match(src, /on:confirm=/);
\tassert.match(src, /on:cancel=/);
});

test('public-render enqueues dragongate-public and prints the preview mount', () => {
\tconst php = read('includes/Definition/class-portal-public-render.php');
\tassert.match(php, /assets\/dist\/dragongate-public\.js/);
\tassert.match(php, /data-dg-preview-root/);
\tassert.match(php, /dg-file-preview/);
\tassert.match(php, /dg-public-preview/);
\tassert.match(php, /dg-definition-form/);
\tassert.match(php, /'dg-file-preview', 'dg-public-preview', 'dg-definition-form'/);
\tassert.doesNotMatch(php, /dragongate-portal\.js/);
});

test('definition-form opens DGPreview only after proveReadable; missing island is an error', () => {
\tconst js = read('assets/definition-form.js');
\tassert.match(js, /proveReadable/);
\tassert.match(js, /globalThis\.DGPreview\.open/);
\tassert.match(js, /function previewReady\s*\(/);
\tassert.match(
\t\tjs,
\t\t/proveReadable\s*\([\s\S]*?\)\s*\.then\s*\(\s*function\(\)\s*\{[\s\S]*?globalThis\.DGPreview\.open/,
\t);
\tassert.match(js, /if\s*\(\s*!previewReady\(\)\s*\)/);
\tassert.match(js, /onError\(\)/);
\tassert.doesNotMatch(js, /PREVIEW_DIALOG_ID/);
\tassert.doesNotMatch(js, /ensurePreviewDialog/);
\tassert.doesNotMatch(js, /trapPreviewFocus/);
\tassert.doesNotMatch(js, /paintPreview/);
\tassert.doesNotMatch(js, /createElement\(\s*['"]div['"]\s*\)/);
});

test('public island sets DGPreview and does not import WizardShell', () => {
\tconst entry = read('src/public/index.js');
\tconst dialog = read('src/public/PreviewDialog.svelte');
\tconst vite = read('vite.config.js');
\tassert.match(entry, /globalThis\.DGPreview/);
\tassert.match(entry, /data-dg-preview-root/);
\tassert.doesNotMatch(entry, /from ['"].*WizardShell/);
\tassert.match(dialog, /from ["']bits-ui["']/);
\tassert.match(dialog, /Confirm this file/);
\tassert.match(vite, /['"]dragongate-public['"]\s*:\s*['"]src\/public\/index\.js['"]/);
});

test('built public bundle exposes DGPreview and does not contain WizardShell', () => {
\tconst publicJs = path.join(root, 'assets/dist/dragongate-public.js');
\tassert.ok(fs.existsSync(publicJs), 'assets/dist/dragongate-public.js missing — run npm run build');
\tconst built = fs.readFileSync(publicJs, 'utf8');
\tassert.ok(built.length > 0, 'dragongate-public.js is empty');
\tassert.match(built, /DGPreview/);
\tassert.doesNotMatch(built, /WizardShell/);
\tconst distDir = path.join(root, 'assets/dist');
\tfor (const name of fs.readdirSync(distDir).filter((file) => file.endsWith('.js'))) {
\t\tconst source = fs.readFileSync(path.join(distDir, name), 'utf8');
\t\tassert.doesNotMatch(source, /WizardShell/, `${name} must not contain WizardShell`);
\t}
});

test('audio confirm preview uses a definite height so controls are not 0px', () => {
\tconst css = read('assets/definition-form.css');
\tconst block = css.match(/audio\.dg-file-preview-media\s*\{([^}]*)\}/);
\tassert.ok(block, 'audio preview rule');
\tassert.doesNotMatch(block[1], /height:\s*auto/);
\tassert.match(block[1], /height:\s*54px/);
\tconst dialog = read('src/public/PreviewDialog.svelte');
\tassert.match(dialog, /<audio[^>]*controls/);
\tassert.match(dialog, /kind === KIND_AUDIO/);
});
