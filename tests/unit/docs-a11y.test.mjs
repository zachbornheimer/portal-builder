/**
 * README documents console + ZIP; new console markup has accessible names.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();

test('README walks Google setup, ZIP upgrade, and the packet console', () => {
	const readme = fs.readFileSync(path.join(root, 'README.md'), 'utf8');
	assert.match(readme, /Google Secret Key/);
	assert.match(readme, /Test write/);
	assert.match(readme, /_portal_definition/);
	assert.match(readme, /Submission packets/);
	assert.match(readme, /Your submissions for this portal/);
	assert.match(readme, /Recall/);
	assert.match(readme, /dg_edit_submissions/);
});

test('new packet console and recall controls have accessible names', () => {
	const setup = fs.readFileSync(
		path.join(root, 'includes/class-portal-setup-screen.php'),
		'utf8',
	);
	const render = fs.readFileSync(
		path.join(root, 'includes/Definition/class-portal-public-render.php'),
		'utf8',
	);
	assert.match(setup, /aria-labelledby="dg-packet-console-title"/);
	assert.match(setup, /<th scope="col">/);
	assert.match(render, /aria-labelledby="dg-applicant-packets-title"/);
	assert.match(render, /aria-label="Recall submission/);
	assert.match(render, /aria-label="%2\$s"|aria-label="Spam check"/);
});
