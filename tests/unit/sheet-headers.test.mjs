/**
 * Dest-column sheet headers: named values follow fieldDest, not field order.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const destHarness = path.join(root, 'tests/support/php-sheet-headers.php');
const pipelineHarness = path.join(root, 'tests/support/php-submit-pipeline.php');
const definitionPath = path.join(
	root,
	'tests/fixtures/portals/herbolzheimer.definition.json',
);
const submissionPath = path.join(
	root,
	'tests/fixtures/portals/herbolzheimer.submission.json',
);
const artifactDir = path.join(root, 'tests/.artifacts/sheet-headers');

const FOLDER_URL_PREFIX = 'https://drive.google.com/drive/folders/';
const FILE_URL_PREFIX = 'https://drive.google.com/file/d/';

const destDefinition = {
	version: 1,
	title: 'Header Dest',
	fields: [
		{
			id: 'work_title',
			type: 'short_text',
			label: 'Title of Work',
			required: true,
		},
	],
	mapping: {
		sheets: [
			{
				id: 'sheet_housekeeping',
				name: 'Housekeeping',
				role: 'housekeeping',
				spreadsheetId: 'sheet_hk_fixture_not_prod',
			},
		],
		drive: [],
		fieldDest: {
			work_title: 'sheet:Housekeeping:Title',
			files: 'sheet:Housekeeping:Files',
			applicationId: 'sheet:Housekeeping:Application ID',
		},
	},
	publish: {
		deadline: null,
		timezone: 'America/New_York',
		applicationFee: null,
		forceClosed: false,
	},
	options: { anonymize: false, skipHeader: false },
};

const destRow = {
	work_title: 'Symphony No. 1',
	files: `${FOLDER_URL_PREFIX}folder_fake_1`,
	applicationId: 'dg_test',
};

const headerMapping = {
	sheets: [
		{
			id: 'sheet_housekeeping',
			name: 'Housekeeping',
			role: 'housekeeping',
			spreadsheetId: 'sheet_hk_fixture_not_prod',
		},
		{
			id: 'sheet_adjudicator',
			name: 'Adjudicator',
			role: 'adjudicator',
			spreadsheetId: 'sheet_adj_fixture_not_prod',
		},
	],
	drive: [
		{
			id: 'drive_submissions',
			name: 'Submissions',
			folderId: 'drive_sub_fixture_not_prod',
		},
	],
	fieldDest: {
		work_title: 'sheet:Housekeeping:Title',
		score: 'drive:Submissions|sheet:Housekeeping:Score Link',
		recording: 'drive:Submissions|sheet:Housekeeping:Rec Link',
		files: 'sheet:Housekeeping:Files',
		applicationId: 'sheet:Housekeeping:Application ID',
	},
};

/**
 * @param {string} name
 * @param {unknown} data
 */
function writeJson(name, data) {
	const dest = path.join(artifactDir, name);
	fs.mkdirSync(path.dirname(dest), { recursive: true });
	fs.writeFileSync(dest, `${JSON.stringify(data, null, 2)}\n`);
	return dest;
}

/**
 * @param {string[]} args
 */
function runDest(args) {
	const r = spawnSync('php', [destHarness, ...args], {
		encoding: 'utf8',
		env: { ...process.env, DG_TEST_MODE: '1' },
	});
	return {
		code: r.status,
		out: (r.stdout || '') + (r.stderr || ''),
		stdout: r.stdout || '',
	};
}

function parseJson(text) {
	const start = text.indexOf('{') === -1 ? text.indexOf('[') : text.indexOf('{');
	assert.notEqual(start, -1, `no JSON in: ${text}`);
	return JSON.parse(text.slice(start));
}

test('reordered dest headers place Title / Files / Application ID by dest name', () => {
	const defFile = writeJson('dest-definition.json', destDefinition);
	const rowFile = writeJson('dest-row.json', destRow);
	const namedRun = runDest(['named', defFile, rowFile, 'Housekeeping']);
	assert.equal(namedRun.code, 0, namedRun.out);
	const named = parseJson(namedRun.stdout);
	assert.equal(named.Title, 'Symphony No. 1');
	assert.equal(named.Files, destRow.files);
	assert.equal(named['Application ID'], 'dg_test');
	assert.equal(named['Title of Work'], undefined);

	const headersA = ['Files', 'Title', 'Application ID'];
	const headersB = ['Application ID', 'Files', 'Title'];
	const headersAFile = writeJson('headers-a.json', headersA);
	const headersBFile = writeJson('headers-b.json', headersB);
	const namedFile = writeJson('named.json', named);

	const alignA = runDest(['align', namedFile, headersAFile]);
	assert.equal(alignA.code, 0, alignA.out);
	assert.deepEqual(parseJson(alignA.stdout), [
		destRow.files,
		'Symphony No. 1',
		'dg_test',
	]);

	const alignB = runDest(['align', namedFile, headersBFile]);
	assert.equal(alignB.code, 0, alignB.out);
	assert.deepEqual(parseJson(alignB.stdout), [
		'dg_test',
		destRow.files,
		'Symphony No. 1',
	]);

	const cellsA = runDest([
		'cells',
		defFile,
		rowFile,
		'Housekeeping',
		headersAFile,
	]);
	assert.equal(cellsA.code, 0, cellsA.out);
	assert.deepEqual(parseJson(cellsA.stdout), [
		destRow.files,
		'Symphony No. 1',
		'dg_test',
	]);
});

test('empty sibling dest does not blank a shared Title column', () => {
	const def = {
		...destDefinition,
		fields: [
			{
				id: 'paper_title',
				type: 'short_text',
				label: 'Paper title',
			},
			{
				id: 'large_title',
				type: 'short_text',
				label: 'Large title',
			},
		],
		mapping: {
			...destDefinition.mapping,
			fieldDest: {
				paper_title: 'sheet:Housekeeping:Title of Work or Presentation',
				large_title: 'sheet:Housekeeping:Title of Work or Presentation',
			},
		},
	};
	const defFile = writeJson('shared-title-def.json', def);
	const rowFile = writeJson('shared-title-row.json', {
		large_title: 'Fanfare for Brass',
	});
	const namedRun = runDest(['named', defFile, rowFile, 'Housekeeping']);
	assert.equal(namedRun.code, 0, namedRun.out);
	const named = parseJson(namedRun.stdout);
	assert.equal(named['Title of Work or Presentation'], 'Fanfare for Brass');
});

test('missing dest header is appended after existing headers', () => {
	const namedFile = writeJson('extend-named.json', {
		Title: 'Symphony No. 1',
		Files: destRow.files,
		'Application ID': 'dg_test',
	});
	const headersFile = writeJson('extend-headers.json', ['Title']);
	const { code, out, stdout } = runDest(['extend', headersFile, namedFile]);
	assert.equal(code, 0, out);
	assert.deepEqual(parseJson(stdout), ['Title', 'Files', 'Application ID']);
});

test('cells_for_sheet without headers stays field-order', () => {
	const defFile = writeJson('dest-definition.json', destDefinition);
	const rowFile = writeJson('dest-row.json', destRow);
	const { code, out, stdout } = runDest([
		'cells',
		defFile,
		rowFile,
		'Housekeeping',
	]);
	assert.equal(code, 0, out);
	assert.deepEqual(parseJson(stdout), [
		'Symphony No. 1',
		destRow.files,
		'dg_test',
	]);
});

test('live google fake aligns add_row to seeded dest headers', () => {
	const mappingFile = writeJson('header-mapping.json', headerMapping);
	const seeded = [
		'Files',
		'Rec Link',
		'Application ID',
		'Title',
		'Score Link',
	];
	const env = { ...process.env };
	delete env.DG_TEST_MODE;
	env.DG_ARTIFACT_DIR = artifactDir;
	const r = spawnSync(
		'php',
		[
			pipelineHarness,
			definitionPath,
			submissionPath,
			artifactDir,
			'42',
			'--via-for-post',
			'--live-google',
			`--mapping=${mappingFile}`,
			`--seed-headers=${JSON.stringify(seeded)}`,
			'--open-state=open',
		],
		{ encoding: 'utf8', env },
	);
	const text = (r.stdout || '') + (r.stderr || '');
	assert.equal(r.status, 0, text);
	const data = parseJson(text);
	assert.equal(data.ok, true);
	const rows = data.googleStore?.cells || [];
	assert.ok(rows.length >= 1, `expected add_row, got ${JSON.stringify(rows)}`);
	const cells = rows[rows.length - 1];
	assert.equal(cells.length, seeded.length, JSON.stringify(cells));
	assert.equal(cells[3], 'Symphony No. 1', `Title at index 3: ${JSON.stringify(cells)}`);
	assert.match(
		String(cells[0]),
		new RegExp(`^${FOLDER_URL_PREFIX.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`),
		`Files should be a folder URL, got ${cells[0]}`,
	);
	assert.match(
		String(cells[4]),
		new RegExp(`^${FILE_URL_PREFIX.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`),
		`Score Link should be a file URL, got ${cells[4]}`,
	);
	const scoreLink = data.drivePaths?.score || '';
	assert.match(String(scoreLink), /\/file\/d\//, `score path ${scoreLink}`);
});
