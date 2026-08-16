/**
 * ZYS-627: applicationFee is a public label, never a charge.
 * Drives the shipped packet-meta renderer and shipped submit sources.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const packetMetaHarness = path.join(root, 'tests/support/php-packet-meta.php');
const artifactDir = path.join(root, 'tests/.artifacts/application-fee-label');

const FEE_AMOUNT = '25';
const INFORMATIONAL_FEE_COPY =
	/Hosts may collect this outside DragonGate|Not charged in this form/;
const CHARGE_CLAIM =
	/has been charged|was charged|fee collected|pay now|your card/i;

const SUBMIT_CALLERS = [
	{
		name: 'process_submission',
		file: 'includes/class-portal-submission.php',
		fn: 'process_submission',
	},
	{
		name: 'handle_submissions',
		file: 'portal-builder.php',
		fn: 'handle_submissions',
	},
	{
		name: 'Portal_Submission_Pipeline',
		file: 'includes/Submission/class-portal-submission-pipeline.php',
		fn: null,
	},
];

const PAYMENT_CALL = /should_collect_payment\s*\(|->collect_payment\s*\(/;

const PUBLISH_STEP = path.join(root, 'src/wizard/steps/PublishStep.svelte');
const PUBLISH_BUNDLE = path.join(root, 'assets/dist/dragongate-portal.js');
const CHECKOUT_IMPLY = /stripe|checkout|charg(?:e|ing) a card/i;

/**
 * @param {object} payload
 */
function renderPacketMeta(payload) {
	fs.mkdirSync(artifactDir, { recursive: true });
	const file = path.join(artifactDir, `${payload.name || 'packet-meta'}.json`);
	fs.writeFileSync(file, JSON.stringify(payload));
	const r = spawnSync('php', [packetMetaHarness, file], { encoding: 'utf8' });
	return { code: r.status, html: r.stdout || '', err: r.stderr || '' };
}

/**
 * @param {string} source
 * @param {string} name
 * @returns {string}
 */
function extractPhpFunction(source, name) {
	const re = new RegExp(`function\\s+${name}\\s*\\(`);
	const match = re.exec(source);
	if (!match) {
		return '';
	}
	const brace = source.indexOf('{', match.index);
	if (brace < 0) {
		return '';
	}
	let depth = 0;
	for (let i = brace; i < source.length; i += 1) {
		const ch = source[i];
		if (ch === '{') {
			depth += 1;
		} else if (ch === '}') {
			depth -= 1;
			if (depth === 0) {
				return source.slice(brace, i + 1);
			}
		}
	}
	return source.slice(brace);
}

test('public packet-meta labels a set fee as not charged in this form', () => {
	const { code, html, err } = renderPacketMeta({
		name: 'fee-label',
		definition: { publish: { applicationFee: FEE_AMOUNT } },
	});
	assert.equal(code, 0, err || html);
	assert.match(html, new RegExp(`\\$${FEE_AMOUNT}|${FEE_AMOUNT}`));
	assert.match(html, INFORMATIONAL_FEE_COPY);
	assert.doesNotMatch(html, CHARGE_CLAIM);
});

test('submit paths do not call collect_payment or should_collect_payment', () => {
	for (const caller of SUBMIT_CALLERS) {
		const source = fs.readFileSync(path.join(root, caller.file), 'utf8');
		const body = caller.fn ? extractPhpFunction(source, caller.fn) : source;
		assert.ok(body.length > 0, `missing ${caller.name} in ${caller.file}`);
		assert.equal(
			PAYMENT_CALL.test(body),
			false,
			`${caller.name} still invokes the payment stub:\n${body.slice(0, 400)}`,
		);
	}
});

test('Publish step fee copy is label-only and does not imply checkout', () => {
	const svelte = fs.readFileSync(PUBLISH_STEP, 'utf8');
	const feeAt = svelte.indexOf('for="dg-fee"');
	assert.ok(feeAt >= 0, 'Publish step is missing the application fee field');
	const feeBlock = svelte.slice(feeAt, feeAt + 900);
	assert.match(feeBlock, INFORMATIONAL_FEE_COPY);
	assert.doesNotMatch(feeBlock, CHECKOUT_IMPLY);

	const bundle = fs.readFileSync(PUBLISH_BUNDLE, 'utf8');
	assert.match(bundle, INFORMATIONAL_FEE_COPY);
	assert.doesNotMatch(bundle, CHECKOUT_IMPLY);
});
