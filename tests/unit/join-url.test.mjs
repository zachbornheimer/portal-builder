/**
 * REST URL join — the same helper every definitionApi call uses.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { joinUrl } from '../../src/wizard/definitionApi.js';

const WP_REST_ROOT = 'http://localhost:10033/wp-json/';
const PORTAL_PATH = 'wp/v2/portal/26998';
const JOINED = 'http://localhost:10033/wp-json/wp/v2/portal/26998';
const BROKEN_CONCAT = `${WP_REST_ROOT.replace(/\/$/, '')}${PORTAL_PATH}`;

test('trailing-slash wpRestRoot plus portal path keeps the slash', () => {
	assert.equal(joinUrl(WP_REST_ROOT, PORTAL_PATH), JOINED);
	assert.notEqual(joinUrl(WP_REST_ROOT, PORTAL_PATH), BROKEN_CONCAT);
});

test('leading-slash path does not double the slash', () => {
	assert.equal(joinUrl(WP_REST_ROOT, `/${PORTAL_PATH}`), JOINED);
});

test('base without trailing slash still joins', () => {
	assert.equal(joinUrl('http://localhost:10033/wp-json', PORTAL_PATH), JOINED);
});

test('empty base falls back to /wp-json/', () => {
	assert.equal(joinUrl('', PORTAL_PATH), `/wp-json/${PORTAL_PATH}`);
	assert.equal(joinUrl(undefined, PORTAL_PATH), `/wp-json/${PORTAL_PATH}`);
});
