/**
 * Marketing buttons: hover must not interpolate a gradient to a solid
 * (that drop-out is the flash on dragongateportals.com).
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const homeCss = fs.readFileSync(path.join(root, 'site/public/css/home.css'), 'utf8');
const siteCss = fs.readFileSync(path.join(root, 'site/public/css/site.css'), 'utf8');
const homeTokens = fs.readFileSync(path.join(root, 'site/public/css/ds/colors.css'), 'utf8');
const siteTokens = fs.readFileSync(path.join(root, 'site/public/css/tokens.css'), 'utf8');

/**
 * @param {string} sheet
 * @param {string} selector
 */
function rule(sheet, selector) {
	const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
	const match = sheet.match(new RegExp(`${escaped}\\s*\\{([^}]+)\\}`));
	assert.ok(match, `missing rule ${selector}`);
	return match[1];
}

test('home .btn does not transition background (gradient cannot interpolate)', () => {
	const btn = rule(homeCss, '.btn');
	assert.match(btn, /transition/);
	assert.doesNotMatch(btn, /transition:\s*all\b/);
	assert.doesNotMatch(btn, /transition:[^;]*\bbackground\b/);
});

test('home primary hover keeps a gradient, never a solid accent', () => {
	const hover = rule(homeCss, '.btn-primary:hover');
	assert.doesNotMatch(hover, /--accent-hover/);
	assert.doesNotMatch(hover, /background:\s*#[0-9a-fA-F]{3,8}/);
	if (/background\s*:/.test(hover)) {
		assert.match(hover, /ember-flow|linear-gradient/);
	}
});

test('inner-page .btn does not transition all or lift on hover', () => {
	const btn = rule(siteCss, '.btn');
	assert.doesNotMatch(btn, /transition:\s*all\b/);
	assert.doesNotMatch(btn, /transition:[^;]*\bbackground\b/);
	const hover = rule(siteCss, '.btn-primary:hover');
	assert.doesNotMatch(hover, /translateY/);
});

test('ember-flow-hover is a darker sibling of ember-flow', () => {
	assert.match(homeTokens, /--ember-flow-hover:\s*linear-gradient/);
	assert.match(siteTokens, /--ember-flow-hover:\s*linear-gradient/);
});
