/**
 * Branch field model: catalog, flatten, insert target, Call for Scores tree.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
	FIELD_TYPE_CATALOG,
	callForScoresTemplate,
	flattenMappableFields,
	insertField,
	moveField,
	moveFieldBefore,
	newField,
	setFieldHtml,
	isBranchOptionOpen,
	optionContainsFocus,
} from '../../src/wizard/definitionModel.js';

const ROOT = path.join(path.dirname(fileURLToPath(import.meta.url)), '../..');
const START_STEP_PATH = path.join(ROOT, 'src/wizard/steps/StartStep.svelte');
const HOST_NOUNS = /ISJAC|Herbolzheimer|Awards/i;
const TEXT_FIELD_TYPES = new Set(['short_text', 'long_text']);
const FILE_FIELD_TYPES = new Set(['file', 'score_file', 'recording_file', 'bio_file']);

/**
 * @param {string} markup
 */
function featuredStartCard(markup) {
	const match = markup.match(
		/<article class="dg-template-card dg-template-card--featured">[\s\S]*?<\/article>/,
	);
	assert.ok(match, 'Start step ships a featured template card');
	return match[0];
}

/**
 * @param {string} markup
 */
function startLead(markup) {
	const match = markup.match(/<p class="dg-wizard-lead">[\s\S]*?<\/p>/);
	assert.ok(match, 'Start step ships lead copy');
	return match[0];
}


test('FIELD_TYPE_CATALOG includes branch and note', () => {
	assert.ok(FIELD_TYPE_CATALOG.some((t) => t.type === 'branch' && t.label === 'Branch'));
	assert.ok(FIELD_TYPE_CATALOG.some((t) => t.type === 'static_html' && t.label === 'Note'));
});

test('newField branch has two options with unique ids', () => {
	const created = newField('branch', []);
	assert.equal(created.type, 'branch');
	assert.equal(created.required, true);
	assert.ok(Array.isArray(created.options));
	assert.equal(created.options.length, 2);
	assert.ok(created.options[0].id);
	assert.ok(created.options[1].id);
	assert.notEqual(created.options[0].id, created.options[1].id);
	assert.deepEqual(created.options[0].children, []);
	assert.deepEqual(created.options[1].children, []);
});

test('flattenMappableFields includes branch + option leaves + nested branch; skips static_html', () => {
	const fields = [
		{ id: 'applicant', type: 'applicant_pack', label: 'You', required: true },
		{
			id: 'category',
			type: 'branch',
			label: 'Category',
			required: true,
			options: [
				{
					id: 'poster',
					label: 'Poster',
					children: [
						{ id: 'poster_description', type: 'score_file', label: 'Desc', required: true },
						{ id: 'note_html', type: 'static_html', label: 'Note', html: '<p>hi</p>' },
					],
				},
				{
					id: 'scores',
					label: 'Scores',
					children: [
						{
							id: 'score_kind',
							type: 'branch',
							label: 'Kind',
							required: true,
							options: [
								{
									id: 'large',
									label: 'Large',
									children: [
										{ id: 'large_title', type: 'short_text', label: 'Title', required: true },
										{ id: 'large_score', type: 'score_file', label: 'Score', required: true },
									],
								},
							],
						},
					],
				},
			],
		},
	];
	const flat = flattenMappableFields(fields);
	const ids = flat.map((f) => f.id);
	assert.ok(ids.includes('applicant'));
	assert.ok(ids.includes('category'));
	assert.ok(ids.includes('poster_description'));
	assert.ok(ids.includes('score_kind'));
	assert.ok(ids.includes('large_title'));
	assert.ok(ids.includes('large_score'));
	assert.ok(!ids.includes('note_html'));
	assert.ok(!ids.includes('poster')); // option id is not a field
});

test('callForScoresTemplate has category branch, nested score_kind, unique score leaves', () => {
	const def = callForScoresTemplate();
	const root = def.fields;
	assert.ok(root.some((f) => f.id === 'applicant' && f.type === 'applicant_pack' && f.required));
	const bio = root.find((f) => f.id === 'bio');
	assert.ok(bio && bio.type === 'bio_file' && bio.required);

	const category = root.find((f) => f.id === 'category');
	assert.ok(category && category.type === 'branch' && category.required);
	const optIds = category.options.map((o) => o.id);
	assert.deepEqual(optIds, ['poster', 'papers', 'scores']);

	const poster = category.options.find((o) => o.id === 'poster');
	assert.equal(poster.label, 'Poster Sessions');
	const posterDesc = poster.children.find((c) => c.id === 'poster_description');
	assert.ok(posterDesc && posterDesc.type === 'score_file');
	assert.equal(posterDesc.label, 'Brief Description');
	assert.equal(posterDesc.fileSuffix, '_POSTER_DESC');
	assert.ok(!poster.children.some((c) => c.type === 'recording_file'));

	const papers = category.options.find((o) => o.id === 'papers');
	assert.equal(papers.label, 'Research/Analysis Papers');
	assert.ok(papers.children.some((c) => c.id === 'paper_title'));
	assert.ok(papers.children.some((c) => c.id === 'paper_abstract' && c.type === 'score_file'));

	const scores = category.options.find((o) => o.id === 'scores');
	assert.equal(scores.label, 'Scores/Recordings');
	assert.ok(scores.children.some((c) => c.id === 'scores_note' && c.type === 'static_html'));
	const scoreKind = scores.children.find((c) => c.id === 'score_kind');
	assert.ok(scoreKind && scoreKind.type === 'branch');
	assert.equal(scoreKind.label, 'Select a Category');
	const kindIds = scoreKind.options.map((o) => o.id);
	assert.deepEqual(kindIds, ['large', 'small', 'arrangement', 'first_takes', 'student']);
	assert.deepEqual(
		scoreKind.options.map((o) => o.label),
		[
			'New Music Masterclass Workshop (Large Ensemble)',
			'New Music Masterclass Workshop (Small Ensemble)',
			'New Music Masterclass Workshop (Arrangement)',
			'First Takes',
			'Student/Young Artist',
		],
	);

	const large = scoreKind.options.find((o) => o.id === 'large');
	assert.ok(large.children.some((c) => c.id === 'large_title'));
	assert.ok(large.children.some((c) => c.id === 'large_score' && c.fileSuffix === '_NMML_SCORE'));
	assert.ok(large.children.some((c) => c.id === 'large_rec' && c.fileSuffix === '_NMML_REC'));

	const student = scoreKind.options.find((o) => o.id === 'student');
	assert.ok(student.children.some((c) => c.id === 'student_score'));
	assert.ok(student.children.some((c) => c.id === 'student_rec'));
	assert.ok(student.children.some((c) => c.id === 'student_eligibility' && c.type === 'static_html'));
	assert.ok(!student.children.some((c) => c.id === 'student_enrollment'));

	const flat = flattenMappableFields(root);
	const flatIds = flat.map((f) => f.id);
	assert.ok(flatIds.includes('category'));
	assert.ok(flatIds.includes('score_kind'));
	assert.ok(flatIds.includes('poster_description'));
	assert.ok(flatIds.includes('paper_title'));
	assert.ok(flatIds.includes('large_score'));
	assert.ok(flatIds.includes('student_rec'));
	assert.equal(new Set(flatIds).size, flatIds.length, 'mappable ids unique');
});

test('insertField into a named option does not append to a sibling group', () => {
	const base = [
		{
			id: 'submission',
			type: 'group',
			label: 'Submission',
			children: [{ id: 'note', type: 'short_text', label: 'Note' }],
		},
		{
			id: 'category',
			type: 'branch',
			label: 'Category',
			required: true,
			options: [
				{ id: 'poster', label: 'Poster', children: [] },
				{ id: 'papers', label: 'Papers', children: [] },
			],
		},
	];
	const leaf = {
		id: 'poster_description',
		type: 'score_file',
		label: 'Session description',
		required: true,
	};
	const next = insertField(base, leaf, {
		kind: 'option',
		branchId: 'category',
		optionId: 'poster',
	});

	const group = next.find((f) => f.id === 'submission');
	assert.equal(group.children.length, 1);
	assert.equal(group.children[0].id, 'note');

	const branch = next.find((f) => f.id === 'category');
	const poster = branch.options.find((o) => o.id === 'poster');
	const papers = branch.options.find((o) => o.id === 'papers');
	assert.equal(poster.children.length, 1);
	assert.equal(poster.children[0].id, 'poster_description');
	assert.equal(papers.children.length, 0);
});

test('isBranchOptionOpen is true only for the focused path', () => {
	const insert = { kind: 'option', branchId: 'category', optionId: 'scores' };
	assert.equal(isBranchOptionOpen(insert, null, 'category', 'scores'), true);
	assert.equal(isBranchOptionOpen(insert, null, 'category', 'poster'), false);
	assert.equal(
		isBranchOptionOpen({ kind: 'root' }, 'category::poster', 'category', 'poster'),
		true,
	);
	assert.equal(isBranchOptionOpen({ kind: 'root' }, null, 'category', 'poster'), false);
});

test('isBranchOptionOpen expandAll keeps every path open', () => {
	assert.equal(
		isBranchOptionOpen({ kind: 'root' }, null, 'category', 'poster', [], null, true),
		true,
	);
});

test('isBranchOptionOpen keeps ancestor open when a nested option is focused', () => {
	const scoresChildren = [
		{
			id: 'score_kind',
			type: 'branch',
			options: [
				{
					id: 'large',
					label: 'Large',
					children: [{ id: 'large_title', type: 'short_text', label: 'Title' }],
				},
			],
		},
	];
	const nestedInsert = { kind: 'option', branchId: 'score_kind', optionId: 'large' };
	assert.equal(
		isBranchOptionOpen(nestedInsert, 'score_kind::large', 'category', 'scores', scoresChildren),
		true,
	);
	assert.equal(
		isBranchOptionOpen(nestedInsert, 'score_kind::large', 'category', 'poster', []),
		false,
	);
	assert.equal(
		optionContainsFocus(scoresChildren, nestedInsert, 'score_kind::large', null),
		true,
	);
	assert.equal(
		isBranchOptionOpen(
			{ kind: 'root' },
			null,
			'category',
			'scores',
			scoresChildren,
			'large_title',
		),
		true,
	);
});

test('setFieldHtml updates a note without touching siblings', () => {
	const fields = [
		{ id: 'note', type: 'static_html', label: 'Note', html: '<p>old</p>' },
		{ id: 'title', type: 'short_text', label: 'Title' },
	];
	const next = setFieldHtml(fields, 'note', '<p>new</p>');
	assert.equal(next[0].html, '<p>new</p>');
	assert.equal(next[1].label, 'Title');
});

test('moveFieldBefore reorders siblings and can move into another option', () => {
	const base = [
		{ id: 'applicant', type: 'applicant_pack', label: 'You' },
		{ id: 'bio', type: 'bio_file', label: 'Bio' },
		{
			id: 'category',
			type: 'branch',
			label: 'Category',
			options: [
				{
					id: 'poster',
					label: 'Poster',
					children: [{ id: 'poster_description', type: 'score_file', label: 'Desc' }],
				},
				{ id: 'papers', label: 'Papers', children: [] },
			],
		},
	];
	const swapped = moveFieldBefore(base, 'bio', 'applicant');
	assert.deepEqual(
		swapped.map((f) => f.id),
		['bio', 'applicant', 'category'],
	);

	const moved = moveField(base, 'poster_description', {
		kind: 'option',
		branchId: 'category',
		optionId: 'papers',
	});
	const category = moved.find((f) => f.id === 'category');
	const poster = category.options.find((o) => o.id === 'poster');
	const papers = category.options.find((o) => o.id === 'papers');
	assert.equal(poster.children.length, 0);
	assert.equal(papers.children[0].id, 'poster_description');
});

test('moveField refuses to nest a branch inside its own option', () => {
	const base = [
		{
			id: 'category',
			type: 'branch',
			label: 'Category',
			options: [{ id: 'poster', label: 'Poster', children: [] }],
		},
	];
	const next = moveField(base, 'category', {
		kind: 'option',
		branchId: 'category',
		optionId: 'poster',
	});
	assert.equal(next[0].id, 'category');
	assert.equal(next[0].options[0].children.length, 0);
});

test('insertField at root does not dump into first group', () => {
	const base = [
		{
			id: 'submission',
			type: 'group',
			label: 'Submission',
			children: [],
		},
	];
	const leaf = newField('short_text', base);
	const next = insertField(base, leaf, { kind: 'root' });
	assert.equal(next.length, 2);
	assert.equal(next[1].id, leaf.id);
	assert.equal(next[0].children.length, 0);
});

test('Start lead does not claim templates come only from ISJAC', () => {
	const markup = fs.readFileSync(START_STEP_PATH, 'utf8');
	const lead = startLead(markup);
	assert.doesNotMatch(lead, /ISJAC['’]s own past portals/);
	assert.doesNotMatch(lead, /Templates come from ISJAC/i);
	assert.doesNotMatch(lead, /not generic samples/i);
	assert.doesNotMatch(markup, /ISJAC['’]s own past portals/);
});

test('featured Start card is the generic starter, not a host prize', () => {
	const markup = fs.readFileSync(START_STEP_PATH, 'utf8');
	const featured = featuredStartCard(markup);
	assert.match(featured, /Recommended/);
	assert.doesNotMatch(featured, HOST_NOUNS);
	assert.doesNotMatch(featured, /Composer Prize/);
	assert.doesNotMatch(featured, /Call for Scores/);
	assert.match(featured, /onclick=\{useStarter\}/);
	assert.match(markup, /applyAndContinue\(genericStarterTemplate\(/);
});

test('Call for Scores stays a labeled optional template', () => {
	const markup = fs.readFileSync(START_STEP_PATH, 'utf8');
	const featured = featuredStartCard(markup);
	assert.doesNotMatch(featured, /Call for Scores/);
	assert.doesNotMatch(featured, /callForScoresTemplate|useCallForScores/);
	assert.match(markup, /<h3 class="dg-template-card-title">Call for Scores<\/h3>/);
	assert.match(markup, /onclick=\{useCallForScores\}/);
	assert.match(markup, /applyAndContinue\(callForScoresTemplate\(/);
});

test('genericStarterTemplate is applicant pack + one text + one file', async () => {
	const model = await import('../../src/wizard/definitionModel.js');
	assert.equal(typeof model.genericStarterTemplate, 'function');
	const def = model.genericStarterTemplate();
	assert.ok(Array.isArray(def.fields));
	const flat = flattenMappableFields(def.fields);
	const packs = flat.filter((f) => f.type === 'applicant_pack');
	const texts = flat.filter((f) => TEXT_FIELD_TYPES.has(f.type));
	const files = flat.filter((f) => FILE_FIELD_TYPES.has(f.type));
	assert.equal(packs.length, 1);
	assert.equal(texts.length, 1);
	assert.equal(files.length, 1);
	assert.equal(flat.length, 3);
	assert.doesNotMatch(JSON.stringify(def.fields), HOST_NOUNS);
});
