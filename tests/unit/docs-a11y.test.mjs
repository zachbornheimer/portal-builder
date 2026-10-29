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

test('records console and recall controls have accessible names', () => {
	const step = fs.readFileSync(
		path.join(root, 'src/wizard/steps/RecordsStep.svelte'),
		'utf8',
	);
	const render = fs.readFileSync(
		path.join(root, 'includes/Definition/class-portal-public-render.php'),
		'utf8',
	);
	assert.match(step, /onclick=\{sendInvite\}/);
	assert.match(step, /'Send invite'/);
	assert.match(step, /aria-label=\{`Copy invite link for /);
	assert.match(step, /aria-label=\{`Resend invite to /);
	assert.match(step, /aria-label=\{`Revoke invite for /);
	assert.match(step, /onclick=\{showAll\}>\s*Show all\s*<\/button>/);
	assert.match(step, /<th scope="col">Applicant<\/th>/);
	assert.match(step, /<th scope="col">WordPress user<\/th>/);
	assert.match(step, /<th scope="col">Submitted<\/th>/);
	assert.match(step, /<th scope="col">Application<\/th>/);
	assert.match(step, /<th scope="col">Status<\/th>/);
	assert.match(step, /<th scope="col">Sheet<\/th>/);
	assert.match(step, /<th scope="col">Drive<\/th>/);
	assert.match(step, /<th scope="col">Anonymize<\/th>/);
	assert.match(step, /<th scope="col">Mail<\/th>/);
	assert.match(step, /<th scope="col">Error code<\/th>/);
	assert.match(render, /aria-labelledby="dg-applicant-packets-title"/);
	assert.match(render, /aria-label="Recall submission/);
	assert.match(render, /aria-label="Replace file for submission/);
	assert.match(render, /aria-label="%2\$s"|aria-label="Spam check"/);
});
