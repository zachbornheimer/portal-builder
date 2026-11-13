/**
 * Pure portal definition helpers (no I/O).
 */

/**
 * @typedef {{ id: string, label: string, children?: Field[] }} BranchOption
 * @typedef {{
 *   id: string,
 *   type: string,
 *   label: string,
 *   required?: boolean,
 *   help?: string,
 *   children?: Field[],
 *   options?: BranchOption[],
 *   fileSuffix?: string,
 *   text?: string,
 *   html?: string,
 * }} Field
 */

/** @typedef {{ kind: 'root' } | { kind: 'group', fieldId: string } | { kind: 'option', branchId: string, optionId: string }} InsertTarget */

const MIN_BRANCH_OPTIONS = 2;

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
		mapping: { ...defaultMapping(), fieldDest: {} },
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
			anonymizeEndpoint: null,
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
 * Score-path children under a score_kind option (unique ids via prefix).
 * @param {string} prefix
 * @returns {Field[]}
 */
function scoreKindChildren(prefix) {
	const upper = prefix.toUpperCase();
	return [
		{
			id: `${prefix}_title`,
			type: 'short_text',
			label: 'Title of work',
			required: true,
		},
		{
			id: `${prefix}_score`,
			type: 'score_file',
			label: 'Score (PDF)',
			required: true,
			fileSuffix: `_${upper}_SCORE`,
		},
		{
			id: `${prefix}_rec`,
			type: 'recording_file',
			label: 'Recording (MP3)',
			required: true,
			fileSuffix: `_${upper}_REC`,
		},
	];
}

/**
 * Call for Scores template: always-visible applicant + bio, then category branch
 * with poster / papers / nested score_kind paths (ISJAC-shaped).
 * @param {string} [title]
 */
export function callForScoresTemplate(title = 'Call for Scores') {
	const def = blankDefinition(title);
	const scoreKinds = [
		{ id: 'large', label: 'Large ensemble masterclass' },
		{ id: 'small', label: 'Small ensemble masterclass' },
		{ id: 'arrangement', label: 'Arrangement masterclass' },
		{ id: 'first_takes', label: 'First Takes' },
		{ id: 'student', label: 'Student / Young Artist' },
	];
	def.fields = [
		{
			id: 'applicant',
			type: 'applicant_pack',
			label: 'Your Information',
			required: true,
		},
		{
			id: 'bio',
			type: 'bio_file',
			label: 'Bio (PDF)',
			required: true,
			fileSuffix: '_BIO',
		},
		{
			id: 'category',
			type: 'branch',
			label: 'Application Category',
			required: true,
			options: [
				{
					id: 'poster',
					label: 'Poster Sessions',
					children: [
						{
							id: 'poster_description',
							type: 'score_file',
							label: 'Session description (PDF)',
							required: true,
							fileSuffix: '_POSTER',
						},
					],
				},
				{
					id: 'papers',
					label: 'Research / Analysis Papers',
					children: [
						{
							id: 'paper_title',
							type: 'short_text',
							label: 'Paper title',
							required: true,
						},
						{
							id: 'paper_abstract',
							type: 'score_file',
							label: 'Abstract (PDF)',
							required: true,
							fileSuffix: '_ABSTRACT',
						},
					],
				},
				{
					id: 'scores',
					label: 'Scores / Recordings',
					children: [
						{
							id: 'score_kind',
							type: 'branch',
							label: 'Score category',
							required: true,
							options: scoreKinds.map((kind) => {
								const children = scoreKindChildren(kind.id);
								if (kind.id === 'student') {
									children.push({
										id: 'student_enrollment',
										type: 'short_text',
										label: 'Enrollment note',
										required: true,
										help: 'Student / Young Artist entries should include a brief note of current enrollment.',
									});
								}
								return {
									id: kind.id,
									label: kind.label,
									children,
								};
							}),
						},
					],
				},
			],
		},
	];
	// Branch filters land on both sheets so Auto-map is not required.
	if (!def.mapping.fieldDest || typeof def.mapping.fieldDest !== 'object') {
		def.mapping.fieldDest = {};
	}
	for (const field of flattenMappableFields(def.fields)) {
		if (field.type === 'branch') {
			def.mapping.fieldDest[field.id] = defaultFieldDest(field, def.mapping);
		}
	}
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
	{ type: 'branch', label: 'Branch', pill: 'Branch' },
];

/**
 * @param {string} type
 */
export function pillForType(type) {
	const hit = FIELD_TYPE_CATALOG.find((t) => t.type === type);
	return hit ? hit.pill : type;
}

/**
 * Collect every field id in the tree (groups + branch option children).
 * @param {Field[]} fields
 * @returns {Set<string>}
 */
export function collectFieldIds(fields) {
	/** @type {Set<string>} */
	const used = new Set();
	const walk = (list) => {
		for (const f of list || []) {
			if (f?.id) used.add(f.id);
			if (Array.isArray(f.children)) walk(f.children);
			if (Array.isArray(f.options)) {
				for (const opt of f.options) {
					if (opt?.id) used.add(opt.id);
					if (Array.isArray(opt.children)) walk(opt.children);
				}
			}
		}
	};
	walk(fields);
	return used;
}

/**
 * Flatten fields for mapping UI.
 * Walks groups and branch options; includes branch fields (value = option id);
 * skips static_html and group containers.
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
		if (f.type === 'branch') {
			out.push(f);
			if (Array.isArray(f.options)) {
				for (const opt of f.options) {
					if (Array.isArray(opt.children)) {
						out.push(...flattenMappableFields(opt.children));
					}
				}
			}
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
 * Mint a unique id from a label against a used set.
 * @param {string} label
 * @param {Set<string>} used
 */
export function uniqueId(label, used) {
	let id = slugFromLabel(label);
	let n = 2;
	while (used.has(id)) {
		id = `${slugFromLabel(label)}_${n++}`;
	}
	used.add(id);
	return id;
}

/**
 * @param {string} type
 * @param {Field[]} existing
 * @returns {Field}
 */
export function newField(type, existing = []) {
	const used = collectFieldIds(existing);

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
		branch: {
			label: 'Application Category',
			required: true,
			options: [],
		},
	};
	const d = defaults[type] || { label: type, required: false };
	const id = uniqueId(d.label, used);
	/** @type {Field} */
	const field = { id, type, ...d };
	if (type === 'branch') {
		const optA = uniqueId('option_a', used);
		const optB = uniqueId('option_b', used);
		field.options = [
			{ id: optA, label: 'Option A', children: [] },
			{ id: optB, label: 'Option B', children: [] },
		];
	}
	return field;
}

/**
 * Insert a field at root, inside a group, or under a branch option.
 * @param {Field[]} fields
 * @param {Field} field
 * @param {InsertTarget} [target]
 * @returns {Field[]}
 */
export function insertField(fields, field, target = { kind: 'root' }) {
	const list = Array.isArray(fields) ? fields : [];
	if (!target || target.kind === 'root') {
		return [...list, field];
	}
	if (target.kind === 'group') {
		return mapFields(list, (f) => {
			if (f.id !== target.fieldId || f.type !== 'group') return f;
			const children = Array.isArray(f.children) ? [...f.children, field] : [field];
			return { ...f, children };
		});
	}
	if (target.kind === 'option') {
		return mapFields(list, (f) => {
			if (f.id !== target.branchId || f.type !== 'branch') return f;
			const options = (f.options || []).map((opt) => {
				if (opt.id !== target.optionId) return opt;
				const children = Array.isArray(opt.children)
					? [...opt.children, field]
					: [field];
				return { ...opt, children };
			});
			return { ...f, options };
		});
	}
	return [...list, field];
}

/**
 * Remove a field (and its subtree) by id anywhere in the tree.
 * @param {Field[]} fields
 * @param {string} id
 * @returns {Field[]}
 */
export function removeField(fields, id) {
	return (fields || [])
		.filter((f) => f.id !== id)
		.map((f) => {
			/** @type {Field} */
			const next = { ...f };
			if (Array.isArray(f.children)) {
				next.children = removeField(f.children, id);
			}
			if (Array.isArray(f.options)) {
				next.options = f.options.map((opt) => ({
					...opt,
					children: Array.isArray(opt.children)
						? removeField(opt.children, id)
						: [],
				}));
			}
			return next;
		});
}

/**
 * Rename a field label by id.
 * @param {Field[]} fields
 * @param {string} id
 * @param {string} label
 * @returns {Field[]}
 */
export function renameField(fields, id, label) {
	return mapFields(fields, (f) => (f.id === id ? { ...f, label } : f));
}

/**
 * Toggle required on a field.
 * @param {Field[]} fields
 * @param {string} id
 * @param {boolean} required
 * @returns {Field[]}
 */
export function setFieldRequired(fields, id, required) {
	return mapFields(fields, (f) => (f.id === id ? { ...f, required } : f));
}

/**
 * Add an empty option to a branch (unique id).
 * @param {Field[]} fields
 * @param {string} branchId
 * @returns {Field[]}
 */
export function addBranchOption(fields, branchId) {
	const used = collectFieldIds(fields);
	const optionId = uniqueId('option', used);
	return mapFields(fields, (f) => {
		if (f.id !== branchId || f.type !== 'branch') return f;
		const options = [
			...(f.options || []),
			{ id: optionId, label: 'New option', children: [] },
		];
		return { ...f, options };
	});
}

/**
 * Remove a branch option when at least MIN_BRANCH_OPTIONS would remain.
 * @param {Field[]} fields
 * @param {string} branchId
 * @param {string} optionId
 * @returns {Field[]}
 */
export function removeBranchOption(fields, branchId, optionId) {
	return mapFields(fields, (f) => {
		if (f.id !== branchId || f.type !== 'branch') return f;
		const options = f.options || [];
		if (options.length <= MIN_BRANCH_OPTIONS) return f;
		return {
			...f,
			options: options.filter((opt) => opt.id !== optionId),
		};
	});
}

/**
 * Rename a branch option label.
 * @param {Field[]} fields
 * @param {string} branchId
 * @param {string} optionId
 * @param {string} label
 * @returns {Field[]}
 */
export function renameBranchOption(fields, branchId, optionId, label) {
	return mapFields(fields, (f) => {
		if (f.id !== branchId || f.type !== 'branch') return f;
		const options = (f.options || []).map((opt) =>
			opt.id === optionId ? { ...opt, label } : opt
		);
		return { ...f, options };
	});
}

/**
 * Find a field by id anywhere in the tree.
 * @param {Field[]} fields
 * @param {string} id
 * @returns {Field|null}
 */
export function findField(fields, id) {
	for (const f of fields || []) {
		if (f.id === id) return f;
		if (Array.isArray(f.children)) {
			const hit = findField(f.children, id);
			if (hit) return hit;
		}
		if (Array.isArray(f.options)) {
			for (const opt of f.options) {
				if (Array.isArray(opt.children)) {
					const hit = findField(opt.children, id);
					if (hit) return hit;
				}
			}
		}
	}
	return null;
}

/**
 * Deep-map every field in the tree (groups + branch option children).
 * @param {Field[]} fields
 * @param {(f: Field) => Field} fn
 * @returns {Field[]}
 */
function mapFields(fields, fn) {
	return (fields || []).map((f) => {
		let next = fn(f);
		if (Array.isArray(next.children)) {
			next = { ...next, children: mapFields(next.children, fn) };
		}
		if (Array.isArray(next.options)) {
			next = {
				...next,
				options: next.options.map((opt) => ({
					...opt,
					children: Array.isArray(opt.children)
						? mapFields(opt.children, fn)
						: [],
				})),
			};
		}
		return next;
	});
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

const GOOGLE_RESOURCE_ID = /^[a-zA-Z0-9-_]{20,}$/;
const STRIPPED_UNICODE = /u00[0-9a-f]{2}/i;
const SHEET_ROLE_HOUSEKEEPING = 'housekeeping';
const SHEET_ROLE_ADJUDICATOR = 'adjudicator';

/**
 * Default destinations every portal writes: housekeeping + adjudicator sheets, submissions Drive.
 */
export function defaultMapping() {
	return {
		sheets: [
			{
				id: 'sheet_housekeeping',
				name: 'Housekeeping',
				role: SHEET_ROLE_HOUSEKEEPING,
				spreadsheetId: '',
			},
			{
				id: 'sheet_adjudicator',
				name: 'Adjudicator',
				role: SHEET_ROLE_ADJUDICATOR,
				spreadsheetId: '',
			},
		],
		drive: [{ id: 'drive_submissions', name: 'Submissions', folderId: '' }],
	};
}

/**
 * @param {unknown} input
 * @returns {string}
 */
export function parseSpreadsheetId(input) {
	const raw = String(input ?? '').trim();
	if (!raw) return '';
	const fromUrl = raw.match(/docs\.google\.com\/spreadsheets\/d\/([a-zA-Z0-9-_]+)/i);
	if (fromUrl) return fromUrl[1];
	return GOOGLE_RESOURCE_ID.test(raw) ? raw : '';
}

/**
 * @param {unknown} input
 * @returns {string}
 */
export function parseFolderId(input) {
	const raw = String(input ?? '').trim();
	if (!raw) return '';
	const fromFolder = raw.match(/drive\.google\.com\/drive\/folders\/([a-zA-Z0-9-_]+)/i);
	if (fromFolder) return fromFolder[1];
	if (/drive\.google\.com/i.test(raw)) {
		const fromQuery = raw.match(/[?&]id=([a-zA-Z0-9-_]+)/i);
		if (fromQuery) return fromQuery[1];
	}
	return GOOGLE_RESOURCE_ID.test(raw) ? raw : '';
}

/**
 * Repair titles whose JSON unicode escape lost its backslash (`Dohnu00e1nyi`).
 * @param {unknown} title
 * @returns {string}
 */
export function repairStrippedUnicodeTitle(title) {
	const raw = String(title ?? '');
	if (!STRIPPED_UNICODE.test(raw)) return raw;
	return raw.replace(/(^|[^\\])u00([0-9a-f]{2})/gi, (full, prefix, hex) => {
		return prefix + String.fromCharCode(parseInt(hex, 16));
	});
}

/**
 * Seed missing dest cards without wiping existing spreadsheet/folder ids.
 * @param {object} [mapping]
 */
export function ensureMapping(mapping) {
	const defaults = defaultMapping();
	const src = mapping && typeof mapping === 'object' ? mapping : {};
	const existingSheets = Array.isArray(src.sheets)
		? src.sheets.filter((s) => s && typeof s === 'object')
		: [];
	const existingDrive = Array.isArray(src.drive)
		? src.drive.filter((d) => d && typeof d === 'object')
		: [];

	const byRole = (role) => existingSheets.find((s) => s.role === role);
	let house = byRole(SHEET_ROLE_HOUSEKEEPING);
	let adj = byRole(SHEET_ROLE_ADJUDICATOR);

	if (!house) {
		house = existingSheets.find((s) => s !== adj && !s.role) || defaults.sheets[0];
	}
	if (!adj) {
		adj =
			existingSheets.find((s) => s !== house && !s.role) || defaults.sheets[1];
	}

	const kept = new Set([house, adj]);
	const extras = existingSheets.filter((s) => !kept.has(s));

	return {
		...src,
		sheets: [house, adj, ...extras],
		drive: existingDrive.length > 0 ? existingDrive : defaults.drive,
		fieldDest:
			src.fieldDest && typeof src.fieldDest === 'object' ? src.fieldDest : {},
	};
}

/**
 * Hydrate a loaded/cloned definition: title repair + dest-card seed.
 * @param {object} raw
 * @param {string} [fallbackTitle]
 */
export function normalizeLoaded(raw, fallbackTitle = 'Portal') {
	const d = cloneDefinition(raw && typeof raw === 'object' ? raw : {});
	if (!d.version) d.version = 1;
	const incomingTitle = d.title ? String(d.title) : '';
	d.title = incomingTitle
		? repairStrippedUnicodeTitle(incomingTitle)
		: fallbackTitle || 'Portal';
	if (!Array.isArray(d.fields)) d.fields = [];
	d.mapping = ensureMapping(d.mapping);
	if (!d.publish) {
		d.publish = {
			deadline: null,
			timezone: 'America/New_York',
			applicationFee: null,
			forceClosed: false,
		};
	}
	if (!d.options || typeof d.options !== 'object') {
		d.options = {
			anonymize: false,
			skipHeader: false,
			guidelinesUrl: null,
			anonymizeEndpoint: null,
		};
	} else if (!Object.prototype.hasOwnProperty.call(d.options, 'anonymizeEndpoint')) {
		d.options.anonymizeEndpoint = null;
	}
	return d;
}

/**
 * @param {object} [mapping]
 */
export function mappingSummaryNames(mapping) {
	const sheets = (ensureMapping(mapping).sheets || [])
		.map((s) => s.name)
		.filter(Boolean);
	const drives = (ensureMapping(mapping).drive || [])
		.map((d) => d.name)
		.filter(Boolean);
	return {
		sheets: sheets.join(', ') || 'Sheets',
		drive: drives.join(', ') || 'Submissions',
	};
}

/**
 * @param {Field} field
 */
export function isFileField(field) {
	const type = field?.type || '';
	return type.includes('file') || type.endsWith('_file');
}

/**
 * @param {Field} field
 * @param {object} [mapping]
 */
export function defaultFieldDest(field, mapping) {
	const ensured = ensureMapping(mapping);
	const house =
		ensured.sheets.find((s) => s.role === SHEET_ROLE_HOUSEKEEPING) ||
		ensured.sheets[0];
	const adj =
		ensured.sheets.find((s) => s.role === SHEET_ROLE_ADJUDICATOR) ||
		ensured.sheets[1];
	const drive = ensured.drive[0];
	const sheetName = house?.name || 'Housekeeping';
	const adjName = adj?.name || 'Adjudicator';
	const driveName = drive?.name || 'Submissions';
	const column = field.label || field.id;
	if (field.type === 'branch') {
		return `sheet:${sheetName}:${column}|sheet:${adjName}:${column}`;
	}
	if (field.type === 'score_file') {
		return `drive:${driveName}|sheet:${sheetName}:Score Link`;
	}
	if (field.type === 'recording_file') {
		return `drive:${driveName}|sheet:${sheetName}:Rec Link`;
	}
	if (field.type === 'bio_file') {
		return `drive:${driveName}|sheet:${sheetName}:Bio Link`;
	}
	if (field.type === 'applicant_pack') {
		return `sheet:${sheetName}:Applicant`;
	}
	return `sheet:${sheetName}:${column}`;
}

/**
 * @param {Field} field
 * @param {object} [mapping]
 * @returns {{ value: string, label: string }[]}
 */
export function fieldDestOptions(field, mapping) {
	const ensured = ensureMapping(mapping);
	const fallback = defaultFieldDest(field, ensured);
	const opts = [
		{ value: '', label: '— choose a destination —' },
		{ value: fallback, label: labelForDest(fallback) },
	];
	const seen = new Set(['', fallback]);
	for (const sheet of ensured.sheets) {
		const value = `sheet:${sheet.name}:${field.label || field.id}`;
		if (seen.has(value)) continue;
		seen.add(value);
		opts.push({
			value,
			label: `${sheet.name} → column “${field.label || field.id}”`,
		});
	}
	if (isFileField(field)) {
		for (const folder of ensured.drive) {
			const value = `drive:${folder.name}`;
			if (seen.has(value)) continue;
			seen.add(value);
			opts.push({
				value,
				label: `${folder.name} (Drive folder only)`,
			});
		}
	}
	return opts;
}

/**
 * @param {string} value
 */
export function labelForDest(value) {
	if (!value) return '— choose a destination —';
	if (value.startsWith('drive:') && value.includes('|sheet:')) {
		const [d, s] = value.split('|');
		return `${d.replace('drive:', '')} (Drive) + ${s.replace('sheet:', '').replace(':', ' → column “')}”`;
	}
	// Dual sheet dest: sheet:Housekeeping:Col|sheet:Adjudicator:Col
	if (value.startsWith('sheet:') && value.includes('|sheet:')) {
		const sheets = [];
		let column = '';
		for (const part of value.split('|')) {
			if (!part.startsWith('sheet:')) continue;
			const rest = part.slice('sheet:'.length);
			const i = rest.indexOf(':');
			if (i < 0) continue;
			sheets.push(rest.slice(0, i));
			column = rest.slice(i + 1);
		}
		if (sheets.length >= 2) {
			return `${sheets.join(' + ')} → column “${column}”`;
		}
	}
	if (value.startsWith('sheet:')) {
		const rest = value.slice('sheet:'.length);
		const i = rest.indexOf(':');
		if (i >= 0) {
			return `${rest.slice(0, i)} → column “${rest.slice(i + 1)}”`;
		}
	}
	return value;
}

/**
 * Persist a pasted Sheets URL or raw id.
 * @param {unknown} raw
 * @returns {string}
 */
export function acceptSpreadsheetInput(raw) {
	const parsed = parseSpreadsheetId(raw);
	if (parsed) return parsed;
	const trimmed = String(raw ?? '').trim();
	if (!trimmed) return '';
	if (/^https?:\/\//i.test(trimmed) || /docs\.google\.com/i.test(trimmed)) {
		return '';
	}
	return trimmed;
}

/**
 * Persist a pasted Drive URL or raw id.
 * @param {unknown} raw
 * @returns {string}
 */
export function acceptFolderInput(raw) {
	const parsed = parseFolderId(raw);
	if (parsed) return parsed;
	const trimmed = String(raw ?? '').trim();
	if (!trimmed) return '';
	if (/^https?:\/\//i.test(trimmed) || /drive\.google\.com/i.test(trimmed)) {
		return '';
	}
	return trimmed;
}
