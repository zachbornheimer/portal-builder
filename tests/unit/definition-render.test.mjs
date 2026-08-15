/**
 * Spawns PHP CLI renderer harness (no full WordPress).
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const harness = path.join(root, 'tests/support/php-render-definition.php');
const fixture = path.join(root, 'tests/fixtures/portals/herbolzheimer.definition.json');

function runPhp(jsonPath) {
	const r = spawnSync('php', [harness, jsonPath], { encoding: 'utf8' });
	return { code: r.status, out: (r.stdout || '') + (r.stderr || '') };
}

test('herbolzheimer definition renders public form markers', () => {
	const { code, out } = runPhp(fixture);
	assert.equal(code, 0, out);
	const data = JSON.parse(out.trim());
	assert.equal(data.ok, true);
	assert.equal(data.checks.data_dg_render, true, 'data-dg-render=definition');
	assert.equal(data.checks.title_of_work, true, 'Title of Work label');
	assert.equal(data.checks.full_score, true, 'Full Score label');
	assert.equal(data.checks.recording, true, 'Recording label');
	assert.equal(data.checks.applicant_pack, true, 'applicant_pack block');
	assert.equal(data.checks.work_title_name, true, 'sub_work_title input name');
	assert.equal(data.checks.score_accept, true, 'PDF accept on score');
	assert.equal(data.checks.file_card, true, 'file enclosure card');
	assert.equal(data.checks.score_name, true, 'sub_score input name');
	assert.equal(data.checks.file_open, true, 'open-to-confirm control');
	assert.equal(data.checks.file_original, true, 'data-dg-file-original');
	assert.equal(data.checks.file_progress, true, 'data-dg-file-progress');
	assert.equal(data.checks.file_status, true, 'data-dg-file-status');
	assert.equal(data.checks.file_staged_input, true, 'data-dg-file-staged');
	assert.equal(data.checks.score_staged_name, true, 'sub_score_staged hidden input');
	assert.match(data.html, /data-dg-render="definition"/);
	assert.match(data.html, /Title of Work/);
	assert.match(data.html, /class="[^"]*dg-file/);
	assert.match(data.html, /name="sub_score"/);
	assert.match(data.html, /name="sub_score_staged"/);
	assert.match(data.html, /data-dg-file-original/);
	assert.match(data.html, /application\/pdf/);
});

test('definition-form.css paints error background on .dg-file.is-invalid', () => {
	const css = fs.readFileSync(path.join(root, 'assets/definition-form.css'), 'utf8');
	assert.match(css, /\.dg-file\.is-invalid\s*\{[^}]*background\s*:\s*var\(--error-100\)/s);
	assert.match(css, /data-dg-file-original|dg-file-progress|is-uploading|is-working|is-staged/);
});

test('definition-form.js stages via XHR and writes the staged token', () => {
	const js = fs.readFileSync(path.join(root, 'assets/definition-form.js'), 'utf8');
	assert.match(js, /XMLHttpRequest/);
	assert.match(js, /data-dg-file-staged/);
	assert.match(js, /is-uploading/);
	assert.match(js, /is-working/);
	assert.match(js, /is-staged/);
	assert.match(js, /Removing identifying information/);
	assert.match(js, /Finishing upload/);
	assert.match(js, /stageUrl/);
	assert.match(js, /upload\.addEventListener\(\s*['"]load['"]/);
	assert.match(js, /enterWorking/);
	assert.match(js, /requireStage|missingToken|staged\.value/);
});

test('empty fields definition renders empty string path (exit via empty)', () => {
	const emptyPath = path.join(root, 'tests/.artifacts/empty-fields-definition.json');
	fs.mkdirSync(path.dirname(emptyPath), { recursive: true });
	fs.writeFileSync(
		emptyPath,
		JSON.stringify({ version: 1, fields: [] })
	);
	const { code, out } = runPhp(emptyPath);
	assert.notEqual(code, 0, out);
	assert.match(out, /empty render|fields must be an array/i);
});

test('call-for-scores renders category radios, hidden scores panel, nested score_kind', () => {
	const cfs = path.join(root, 'tests/fixtures/portals/call-for-scores.definition.json');
	const { code, out } = runPhp(cfs);
	assert.equal(code, 0, out);
	const data = JSON.parse(out.trim());
	assert.match(data.html, /name="sub_category"/);
	assert.match(data.html, /name="sub_score_kind"/);
	assert.match(
		data.html,
		/class="dg-branch-children"[^>]*data-dg-branch-option="scores"[^>]*hidden|data-dg-branch-option="scores"[^>]*hidden/
	);
	assert.match(data.html, /data-dg-field-type="branch"/);
	assert.match(data.html, /Poster Sessions/);
	assert.match(data.html, /New Music Masterclass Workshop \(Large Ensemble\)/);
});

test('definition render uses dg- classes only — no portal-group / form-grid / form-group', () => {
	const cfs = path.join(root, 'tests/fixtures/portals/call-for-scores.definition.json');
	const { code, out } = runPhp(cfs);
	assert.equal(code, 0, out);
	const data = JSON.parse(out.trim());
	assert.doesNotMatch(data.html, /portal-group/);
	assert.doesNotMatch(data.html, /form-grid/);
	assert.doesNotMatch(data.html, /form-group/);
	assert.match(data.html, /dg-field--applicant_pack/);
	assert.match(data.html, /dg-choice-list/);
	const css = fs.readFileSync(path.join(root, 'assets/definition-form.css'), 'utf8');
	assert.doesNotMatch(css, /\.portal-group/);
});

test('group with short_text only renders label and input', () => {
	const p = path.join(root, 'tests/.artifacts/simple-render-definition.json');
	fs.writeFileSync(
		p,
		JSON.stringify({
			version: 1,
			fields: [
				{
					id: 'info',
					type: 'group',
					label: 'Info',
					children: [
						{ id: 'piece', type: 'short_text', label: 'Piece Name', required: true },
					],
				},
			],
		})
	);
	const { code, out } = runPhp(p);
	assert.equal(code, 0, out);
	const data = JSON.parse(out.trim());
	assert.match(data.html, /Piece Name/);
	assert.match(data.html, /class="dg-req"[^>]*>\*/);
	assert.match(data.html, /name="sub_piece"/);
	assert.match(data.html, /data-dg-render="definition"/);
});

const BUILTIN_ANONYMIZE_ACK =
	'I certify that my scores and recordings exclude any information that might identify the composer but do include title of work, instrumentation, and duration.';

test('anonymize true injects required certification checkbox with builtin text', () => {
	const p = path.join(root, 'tests/.artifacts/anon-ack-on-definition.json');
	fs.writeFileSync(
		p,
		JSON.stringify({
			version: 1,
			fields: [{ id: 'piece', type: 'short_text', label: 'Piece', required: true }],
			options: { anonymize: true },
		}),
	);
	const { code, out } = runPhp(p);
	assert.equal(code, 0, out);
	const data = JSON.parse(out.trim());
	assert.match(data.html, /name="sub_anonymize_ack"/);
	assert.match(data.html, new RegExp(BUILTIN_ANONYMIZE_ACK.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
	assert.match(data.html, /dg-field--disclaimer/);
	assert.match(data.html, /data-dg-field-id="anonymize_ack"/);
});

test('anonymize false does not inject anonymize_ack checkbox', () => {
	const p = path.join(root, 'tests/.artifacts/anon-ack-off-definition.json');
	fs.writeFileSync(
		p,
		JSON.stringify({
			version: 1,
			fields: [{ id: 'piece', type: 'short_text', label: 'Piece', required: true }],
			options: { anonymize: false },
		}),
	);
	const { code, out } = runPhp(p);
	assert.equal(code, 0, out);
	const data = JSON.parse(out.trim());
	assert.equal(data.html.includes('sub_anonymize_ack'), false);
	assert.equal(data.html.includes('anonymize_ack'), false);
});
