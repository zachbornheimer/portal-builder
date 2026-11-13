/**
 * Pure portal definition helpers (no I/O).
 */

/** @typedef {{ id: string, type: string, label: string, required?: boolean, help?: string, children?: object[], fileSuffix?: string, text?: string }} Field */

/**
 * How the wizard should start given an optional host-embedded seed.
 * `undefined` — host did not embed a seed (legacy); fetch.
 * `null` — host said there is no definition; ready, blank.
 * object with fields — hydrate now; do not fetch.
 *
 * @param {unknown} seed
 * @returns {{ mode: 'fetch'|'blank'|'hydrate', definition: object|null }}
 */
export function planDefinitionLoad(seed) {
	if (seed === undefined) {
		return { mode: 'fetch', definition: null };
	}
	if (seed && typeof seed === 'object' && !Array.isArray(seed) && Array.isArray(seed.fields)) {
		return { mode: 'hydrate', definition: seed };
	}
	return { mode: 'blank', definition: null };
}

/**
 * Empty definition for "start blank".
 * @param {string} [title]
 */
export function blankDefinition(title = 'New portal') {
	return {
		version: 1,
		title: title || 'New portal',
		fields: [],
		mapping: { sheets: [], drive: [] },
		publish: {
			deadline: null,
			timezone: 'America/New_York',
			applicationFee: null,
			forceClosed: false,
		},
		options: {
			anonymize: false,
			skipHeader: false,
			guidelinesUrl: null,
			applicantNotificationDate: null,
			freeForMembers: false,
		},
	};
}

/**
 * Composer Prize template (Herbolzheimer-class).
 * @param {string} [title]
 */
export function composerPrizeTemplate(title = 'Composer Prize') {
	const def = blankDefinition(title);
	def.fields = [
		{
			id: 'applicant',
			type: 'applicant_pack',
			label: 'Your Information',
			required: true,
		},
		{
			id: 'submission',
			type: 'group',
			label: 'Submission Information',
			children: [
				{
					id: 'work_title',
					type: 'short_text',
					label: 'Title of Work',
					required: true,
				},
				{
					id: 'score',
					type: 'score_file',
					label: 'Full Score',
					required: true,
					help: 'PDF only',
					fileSuffix: '_SCORE',
				},
				{
					id: 'recording',
					type: 'recording_file',
					label: 'Recording (MP3)',
					required: true,
					help: 'MP3 only',
					fileSuffix: '_REC',
				},
			],
		},
	];
	return def;
}

/**
 * Call for Scores–style template (simpler branch-free starter).
 * @param {string} [title]
 */
export function callForScoresTemplate(title = 'Call for Scores') {
	const def = blankDefinition(title);
	def.fields = [
		{
			id: 'applicant',
			type: 'applicant_pack',
			label: 'Your Information',
			required: true,
		},
		{
			id: 'submission',
			type: 'group',
			label: 'Submission',
			children: [
				{
					id: 'work_title',
					type: 'short_text',
					label: 'Title of Work',
					required: true,
				},
				{
					id: 'score',
					type: 'score_file',
					label: 'Score (PDF)',
					required: true,
					fileSuffix: '_SCORE',
				},
				{
					id: 'bio',
					type: 'bio_file',
					label: 'Bio (PDF)',
					required: false,
					fileSuffix: '_BIO',
				},
			],
		},
	];
	return def;
}

/** Field type catalog for Build step. */
export const FIELD_TYPE_CATALOG = [
	{ type: 'short_text', label: 'Short text', pill: 'Short text' },
	{ type: 'score_file', label: 'Score upload', pill: 'Score upload' },
	{ type: 'recording_file', label: 'Recording upload', pill: 'Recording upload' },
	{ type: 'bio_file', label: 'Bio upload', pill: 'Bio upload' },
	{ type: 'disclaimer', label: 'Disclaimer acknowledgment', pill: 'Disclaimer' },
	{ type: 'applicant_pack', label: 'Applicant pack', pill: 'Applicant pack' },
	{ type: 'group', label: 'Group', pill: 'Group' },
];

/**
 * @param {string} type
 */
export function pillForType(type) {
	const hit = FIELD_TYPE_CATALOG.find((t) => t.type === type);
	return hit ? hit.pill : type;
}

/**
 * Flatten fields for mapping UI (skip pure containers without map keys).
 * @param {Field[]} fields
 * @returns {Field[]}
 */
export function flattenMappableFields(fields) {
	/** @type {Field[]} */
	const out = [];
	for (const f of fields || []) {
		if (f.type === 'group' && Array.isArray(f.children)) {
			out.push(...flattenMappableFields(f.children));
			continue;
		}
		if (f.type === 'static_html') {
			continue;
		}
		if (f.type === 'applicant_pack') {
			out.push(f);
			continue;
		}
		out.push(f);
	}
	return out;
}

/**
 * Count leaf fields for gate subtitle.
 * @param {Field[]} fields
 */
export function countLeafFields(fields) {
	return flattenMappableFields(fields).length;
}

/**
 * @param {string} label
 */
export function slugFromLabel(label) {
	const base = String(label || 'field')
		.toLowerCase()
		.replace(/[^a-z0-9]+/g, '_')
		.replace(/^_|_$/g, '')
		.slice(0, 40);
	return base || 'field';
}

/**
 * @param {string} type
 * @param {Field[]} existing
 */
export function newField(type, existing = []) {
	const used = new Set();
	const walk = (list) => {
		for (const f of list) {
			used.add(f.id);
			if (f.children) walk(f.children);
		}
	};
	walk(existing);

	const defaults = {
		short_text: { label: 'Short text', required: false },
		score_file: {
			label: 'Full Score',
			required: true,
			help: 'PDF only',
			fileSuffix: '_SCORE',
		},
		recording_file: {
			label: 'Recording (MP3)',
			required: true,
			help: 'MP3 only',
			fileSuffix: '_REC',
		},
		bio_file: { label: 'Bio (PDF)', required: false, fileSuffix: '_BIO' },
		disclaimer: {
			label: 'I agree',
			required: true,
			text: 'I certify the statements above.',
		},
		applicant_pack: { label: 'Your Information', required: true },
		group: { label: 'Group', children: [] },
	};
	const d = defaults[type] || { label: type, required: false };
	let id = slugFromLabel(d.label);
	let n = 2;
	while (used.has(id)) {
		id = `${slugFromLabel(d.label)}_${n++}`;
	}
	return { id, type, ...d };
}

/**
 * Deep clone JSON-safe object.
 * @template T
 * @param {T} value
 * @returns {T}
 */
export function cloneDefinition(value) {
	return JSON.parse(JSON.stringify(value));
}
