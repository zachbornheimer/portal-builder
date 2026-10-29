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
	// Whitespace-tolerant: formatters reflow attributes and text across lines.
	assert.match(step, /onclick\s*=\s*\{\s*sendInvite\s*\}/);
	assert.match(step, /'Send invite'/);
	assert.match(step, /aria-label\s*=\s*\{\s*`Copy invite link for /);
	assert.match(step, /aria-label\s*=\s*\{\s*`Resend invite to /);
	assert.match(step, /aria-label\s*=\s*\{\s*`Revoke invite for /);
	assert.match(step, /onclick\s*=\s*\{\s*showAll\s*\}[^>]*?>\s*Show all\s*<\/button>/);
	assert.match(step, /<th\s+scope="col">\s*Applicant\s*<\/th>/);
	assert.match(step, /<th\s+scope="col">\s*WordPress user\s*<\/th>/);
	assert.match(step, /<th\s+scope="col">\s*Submitted\s*<\/th>/);
	assert.match(step, /<th\s+scope="col">\s*Application\s*<\/th>/);
	assert.match(step, /<th\s+scope="col">\s*Status\s*<\/th>/);
	assert.match(step, /<th\s+scope="col">\s*Sheet\s*<\/th>/);
	assert.match(step, /<th\s+scope="col">\s*Drive\s*<\/th>/);
	assert.match(step, /<th\s+scope="col">\s*Anonymize\s*<\/th>/);
	assert.match(step, /<th\s+scope="col">\s*Mail\s*<\/th>/);
	assert.match(step, /<th\s+scope="col">\s*Error code\s*<\/th>/);
	assert.match(render, /aria-labelledby\s*=\s*"dg-applicant-packets-title"/);
	assert.match(render, /aria-label\s*=\s*"Recall submission/);
	assert.match(render, /aria-label\s*=\s*"Replace file for submission/);
	assert.match(render, /aria-label\s*=\s*"%2\$s"|aria-label\s*=\s*"Spam check"/);
});
