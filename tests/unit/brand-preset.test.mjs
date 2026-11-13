/**
 * Drives shipped Portal_Brand::preset / css — not a copy of the remap.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const harness = path.join(root, 'tests/support/php-brand.php');
const artifactDir = path.join(root, 'tests/.artifacts/brand');

/**
 * @param {object} payload
 */
function runBrand(payload) {
	fs.mkdirSync(artifactDir, { recursive: true });
	const file = path.join(artifactDir, `${payload.name || payload.action || 'case'}.json`);
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

test('ISJAC preset CSS remaps public-form tokens and has no .isjac- classes', () => {
	const { code, out, data } = runBrand({
		name: 'isjac-preset-css',
		action: 'preset-css',
		preset: 'isjac',
	});
	assert.equal(code, 0, out);
	assert.ok(data, out);
	const css = data.css;
	assert.match(css, /#020726/);
	assert.match(css, /#FFFAFC/);
	assert.match(css, /#15526F/);
	assert.match(css, /DM Sans/);
	assert.doesNotMatch(css, /\.isjac-/);
	assert.equal(data.has_ink, true);
	assert.equal(data.has_paper, true);
	assert.equal(data.has_accent, true);
	assert.equal(data.has_dm_sans, true);
	assert.equal(data.has_isjac_cls, false);
	assert.match(css, /--ember:#15526F/);
	assert.match(css, /--font-display/);
	assert.match(css, /--r-pill:8px/);
	assert.doesNotMatch(css, /--isjac-/);
	console.log('ISJAC CSS contains #15526F:', css.includes('#15526F'));
	console.log('ISJAC CSS contains DM Sans:', css.includes('DM Sans'));
	console.log('ISJAC CSS contains #020726:', css.includes('#020726'));
	console.log('ISJAC CSS contains #FFFAFC:', css.includes('#FFFAFC'));
	console.log('ISJAC CSS contains .isjac-:', css.includes('.isjac-'));
});

test('product preset emits no host CSS', () => {
	const { code, out, data } = runBrand({
		name: 'product-preset-css',
		action: 'preset-css',
		preset: 'product',
	});
	assert.equal(code, 0, out);
	assert.equal(data.css, '');
	assert.equal(data.is_host, false);
});

test('isjac sanitize fills mapped tokens so one color can change later', () => {
	const { code, out, data } = runBrand({
		name: 'isjac-sanitize',
		action: 'sanitize',
		raw: { preset: 'isjac', ink: '#111111' },
	});
	assert.equal(code, 0, out);
	assert.equal(data.brand.preset, 'isjac');
	assert.equal(data.brand.ink, '#111111');
	assert.equal(data.brand.paper, '#FFFAFC');
	assert.equal(data.brand.accent, '#15526F');
	assert.match(data.css, /#111111/);
	assert.match(data.css, /#FFFAFC/);
	assert.match(data.css, /DM Sans/);
});

test('public render prints host style id and settings own White label', () => {
	const render = fs.readFileSync(
		path.join(root, 'includes/Definition/class-portal-public-render.php'),
		'utf8',
	);
	const settings = fs.readFileSync(
		path.join(root, 'includes/class-portal-settings.php'),
		'utf8',
	);
	const brand = fs.readFileSync(
		path.join(root, 'includes/Definition/class-portal-brand.php'),
		'utf8',
	);
	assert.match(render, /<style id="dg-host-brand">/);
	assert.match(render, /dg-host-fonts/);
	assert.match(settings, /White label/);
	assert.match(settings, /pb_default_brand/);
	assert.match(settings, /Example host \(ISJAC guide\)/);
	assert.match(brand, /class Portal_Brand/);
	assert.doesNotMatch(brand, /\.isjac-/);
});
