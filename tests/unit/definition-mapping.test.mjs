/**
 * Drives shipped parse helpers, default mapping, and title repair.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import {
	parseSpreadsheetId,
	parseFolderId,
	defaultMapping,
	ensureMapping,
	defaultFieldDest,
	fieldDestOptions,
	labelForDest,
	addSheet,
	addSheetsForBranchOptions,
	callForScoresTemplate,
	encodeDestParts,
	parseDestParts,
	removeSheet,
	repairStrippedUnicodeTitle,
	toggleSheetDest,
} from '../../src/wizard/definitionModel.js';

const SAMPLE_ID = '1a2B3c4D5e6F7g8H9i0Jklmnopqrstuvwx';

test('parseSpreadsheetId extracts id from a full Sheets URL', () => {
	assert.equal(
		parseSpreadsheetId(`https://docs.google.com/spreadsheets/d/${SAMPLE_ID}/edit#gid=0`),
		SAMPLE_ID
	);
});

test('parseSpreadsheetId accepts a bare spreadsheet id', () => {
	assert.equal(parseSpreadsheetId(SAMPLE_ID), SAMPLE_ID);
});

test('parseSpreadsheetId returns empty for junk', () => {
	assert.equal(parseSpreadsheetId(''), '');
	assert.equal(parseSpreadsheetId('short'), '');
	assert.equal(parseSpreadsheetId('https://example.com/not-a-sheet'), '');
	assert.equal(parseSpreadsheetId('https://docs.google.com/document/d/abc/edit'), '');
});

test('parseFolderId extracts id from a folders URL', () => {
	assert.equal(
		parseFolderId(`https://drive.google.com/drive/folders/${SAMPLE_ID}`),
		SAMPLE_ID
	);
});

test('parseFolderId extracts id from a Drive ?id= URL', () => {
	assert.equal(
		parseFolderId(`https://drive.google.com/open?id=${SAMPLE_ID}`),
		SAMPLE_ID
	);
});

test('parseFolderId accepts a bare folder id', () => {
	assert.equal(parseFolderId(SAMPLE_ID), SAMPLE_ID);
});

test('parseFolderId returns empty for junk', () => {
	assert.equal(parseFolderId(''), '');
	assert.equal(parseFolderId('nope'), '');
	assert.equal(parseFolderId('https://example.com/drive'), '');
});

test('defaultMapping returns housekeeping, adjudicator, and submissions drive', () => {
	const mapping = defaultMapping();
	assert.equal(mapping.sheets.length, 2);
	assert.equal(mapping.sheets[0].role, 'housekeeping');
	assert.equal(mapping.sheets[0].name, 'Housekeeping');
	assert.equal(mapping.sheets[0].id, 'sheet_housekeeping');
	assert.equal(mapping.sheets[0].spreadsheetId, '');
	assert.equal(mapping.sheets[1].role, 'adjudicator');
	assert.equal(mapping.sheets[1].name, 'Adjudicator');
	assert.equal(mapping.sheets[1].id, 'sheet_adjudicator');
	assert.equal(mapping.drive.length, 1);
	assert.equal(mapping.drive[0].id, 'drive_submissions');
	assert.equal(mapping.drive[0].name, 'Submissions');
	assert.equal(mapping.drive[0].folderId, '');
});

test('ensureMapping keeps an old un-roled sheet id and still offers both dests', () => {
	const mapping = ensureMapping({
		sheets: [{ id: 'sheet_main', name: 'All Records', spreadsheetId: SAMPLE_ID }],
		drive: [],
	});
	assert.equal(mapping.sheets.length, 2);
	assert.equal(mapping.sheets[0].spreadsheetId, SAMPLE_ID);
	assert.equal(mapping.sheets[0].id, 'sheet_main');
	const roles = mapping.sheets.map((s) => s.role);
	assert.ok(roles.includes('adjudicator') || mapping.sheets[1].id === 'sheet_adjudicator');
	assert.equal(mapping.sheets[1].spreadsheetId, '');
	assert.equal(mapping.drive[0].id, 'drive_submissions');
});

test('fieldDestOptions offers both sheet names, and Drive for file fields', () => {
	const mapping = defaultMapping();
	const textOpts = fieldDestOptions(
		{ id: 'work_title', type: 'short_text', label: 'Title of Work' },
		mapping
	);
	const labels = textOpts.map((o) => o.label).join('\n');
	assert.match(labels, /Housekeeping/);
	assert.match(labels, /Adjudicator/);
	assert.doesNotMatch(labels, /Drive folder only/);

	const fileOpts = fieldDestOptions(
		{ id: 'score', type: 'score_file', label: 'Full Score' },
		mapping
	);
	const fileLabels = fileOpts.map((o) => o.label).join('\n');
	assert.match(fileLabels, /Housekeeping/);
	assert.match(fileLabels, /Adjudicator/);
	assert.match(fileLabels, /Submissions \(Drive folder only\)/);
});

test('repairStrippedUnicodeTitle fixes Dohnu00e1nyi and leaves real titles alone', () => {
	assert.equal(repairStrippedUnicodeTitle('Dohnu00e1nyi'), 'Dohnányi');
	assert.equal(repairStrippedUnicodeTitle('Herbolzheimer Prize'), 'Herbolzheimer Prize');
	assert.equal(repairStrippedUnicodeTitle('Dohnányi'), 'Dohnányi');
});

test('defaultFieldDest for branch targets both Housekeeping and Adjudicator', () => {
	const mapping = defaultMapping();
	const branch = {
		id: 'category',
		type: 'branch',
		label: 'Application Category',
	};
	const dest = defaultFieldDest(branch, mapping);
	assert.match(dest, /Housekeeping/);
	assert.match(dest, /Adjudicator/);
	assert.equal(
		dest,
		'sheet:Housekeeping:Application Category|sheet:Adjudicator:Application Category',
	);

	const opts = fieldDestOptions(branch, mapping);
	const values = opts.map((o) => o.value);
	assert.ok(values.includes(dest), 'dest options include dual-sheet default');

	const dualLabel = labelForDest(dest);
	assert.match(dualLabel, /Housekeeping/);
	assert.match(dualLabel, /Adjudicator/);
	assert.match(dualLabel, /Application Category/);
});

test('addSheet appends an extra card; removeSheet cannot drop housekeeping', () => {
	const withExtra = addSheet(defaultMapping(), 'Poster Sessions');
	assert.equal(withExtra.sheets.length, 3);
	assert.equal(withExtra.sheets[2].name, 'Poster Sessions');
	assert.ok(!withExtra.sheets[2].role);
	const afterRemove = removeSheet(withExtra, withExtra.sheets[2].id);
	assert.equal(afterRemove.sheets.length, 2);
	const stillHouse = removeSheet(withExtra, 'sheet_housekeeping');
	assert.equal(stillHouse.sheets.length, 3);
});

test('toggleSheetDest and parseDestParts support three sheets', () => {
	let dest = toggleSheetDest('', 'All Submissions', 'Title');
	dest = toggleSheetDest(dest, 'Poster Sessions', 'Title');
	dest = toggleSheetDest(dest, 'For Adjudication', 'Title');
	const parts = parseDestParts(dest);
	assert.deepEqual(
		parts.sheets.map((s) => s.name),
		['All Submissions', 'Poster Sessions', 'For Adjudication'],
	);
	dest = toggleSheetDest(dest, 'Poster Sessions', 'Title');
	assert.deepEqual(
		parseDestParts(dest).sheets.map((s) => s.name),
		['All Submissions', 'For Adjudication'],
	);
	assert.equal(
		encodeDestParts({
			sheets: [{ name: 'A', column: 'Col' }],
			drive: 'SUBMISSION',
		}),
		'drive:SUBMISSION|sheet:A:Col',
	);
});

test('addSheetsForBranchOptions creates one extra sheet per option', () => {
	const branch = {
		id: 'category',
		type: 'branch',
		options: [
			{ id: 'poster', label: 'Poster Sessions' },
			{ id: 'papers', label: 'Research/Analysis Papers' },
			{ id: 'scores', label: 'Scores/Recordings' },
		],
	};
	const next = addSheetsForBranchOptions(defaultMapping(), branch);
	const names = next.sheets.map((s) => s.name);
	assert.ok(names.includes('Poster Sessions'));
	assert.ok(names.includes('Research/Analysis Papers'));
	assert.ok(names.includes('Scores/Recordings'));
	assert.equal(addSheetsForBranchOptions(next, branch).sheets.length, next.sheets.length);
});

test('callForScoresTemplate seeds fieldDest for category and score_kind on both sheets', () => {
	const def = callForScoresTemplate();
	const fd = def.mapping?.fieldDest;
	assert.ok(fd && typeof fd === 'object');
	assert.match(fd.category, /Housekeeping/);
	assert.match(fd.category, /Adjudicator/);
	assert.match(fd.score_kind, /Housekeeping/);
	assert.match(fd.score_kind, /Adjudicator/);
});
