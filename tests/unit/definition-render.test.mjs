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
	assert.match(data.html, /data-dg-render="definition"/);
	assert.match(data.html, /Title of Work/);
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
	assert.match(data.html, /Piece Name\*/);
	assert.match(data.html, /name="sub_piece"/);
	assert.match(data.html, /data-dg-render="definition"/);
});
