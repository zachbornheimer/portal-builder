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
export const DEFAULT_TIMEZONE = 'America/New_York';
export const BUILTIN_ANONYMIZE_ENDPOINT = 'https://api.allintersections.com';
export const BUILTIN_ANONYMIZE_ACK =
	'I certify that my scores and recordings exclude any information that might identify the composer but do include title of work, instrumentation, and duration.';

/**
 * Dual-write enabled / forceClosed. enabled wins when both are present.
 * @param {unknown} publish
 */
export function dualWritePublish(publish) {
	const src = publish && typeof publish === 'object' && !Array.isArray(publish) ? publish : {};
	const hasEnabled = Object.prototype.hasOwnProperty.call(src, 'enabled');
	const enabled = hasEnabled ? Boolean(src.enabled) : !src.forceClosed;
	const launchAt =
		typeof src.launchAt === 'string' && src.launchAt.trim() !== '' ? src.launchAt : null;
	return {
		deadline: Object.prototype.hasOwnProperty.call(src, 'deadline') ? src.deadline : null,
		timezone:
			typeof src.timezone === 'string' && src.timezone.trim() !== ''
				? src.timezone.trim()
				: null,
		applicationFee: Object.prototype.hasOwnProperty.call(src, 'applicationFee')
			? src.applicationFee
			: null,
		enabled,
		forceClosed: !enabled,
		launchAt,
	};
}

/**
 * Today at 00:00 in the portal timezone (`YYYY-MM-DDTHH:mm:ss`).
 * @param {string} [timezone]
 * @param {Date} [now]
 */
export function todayAtMidnight(timezone = DEFAULT_TIMEZONE, now = new Date()) {
	const opts = {
		timeZone: timezone || DEFAULT_TIMEZONE,
		year: 'numeric',
		month: '2-digit',
		day: '2-digit',
	};
	let date;
	try {
		date = new Intl.DateTimeFormat('en-CA', opts).format(now);
	} catch {
		date = new Intl.DateTimeFormat('en-CA', { ...opts, timeZone: DEFAULT_TIMEZONE }).format(now);
	}
	return `${date}T00:00:00`;
}

/**
 * Blank launchAt becomes today 00:00 in the portal timezone.
 * @param {unknown} publish
 * @param {Date} [now]
 */
export function applyLaunchDefault(publish, now = new Date(), inheritedTimezone = DEFAULT_TIMEZONE) {
	const next = dualWritePublish(publish);
	if (next.launchAt) return next;
	const timezone = next.timezone || inheritedTimezone || DEFAULT_TIMEZONE;
	return { ...next, launchAt: todayAtMidnight(timezone, now) };
}

/**
 * Public form URL with the editor preview flag.
 * @param {string} [publicUrl]
 */
export function previewUrl(publicUrl) {
	if (!publicUrl || typeof publicUrl !== 'string') return '';
	return publicUrl.includes('?') ? `${publicUrl}&preview=true` : `${publicUrl}?preview=true`;
}

/**
 * Applicant-facing Draft / Closed / Open label.
 * @param {string} postStatus
 * @param {unknown} publish
 * @param {Date} [now]
 */
export function applicantFacingStatus(postStatus, publish, now = new Date()) {
	if (postStatus !== 'publish') return 'Draft';
	const p = dualWritePublish(publish);
	if (!p.enabled) return 'Closed';
	if (instantIsFuture(p.launchAt, now)) return 'Closed';
	if (instantIsPast(p.deadline, now)) return 'Closed';
	return 'Open';
}

/**
 * @param {unknown} value
 * @param {Date} now
 */
function instantIsFuture(value, now) {
	if (!value || typeof value !== 'string') return false;
	const t = Date.parse(value);
	return !Number.isNaN(t) && now.getTime() < t;
}

/**
 * @param {unknown} value
 * @param {Date} now
 */
function instantIsPast(value, now) {
	if (!value || typeof value !== 'string') return false;
	const t = Date.parse(value);
	return !Number.isNaN(t) && now.getTime() > t;
}

/** @returns {object} */
export function defaultOptions() {
	return {
		anonymize: null,
		skipHeader: false,
		guidelinesUrl: null,
		applicantNotificationDate: null,
		freeForMembers: null,
		freeMembershipPlanIds: [],
		anonymizeEndpoint: null,
		anonymizeApiKey: null,
		anonymizeAck: null,
	};
}

/**
 * Missing / empty / non-bool → null (inherit). true/false stay.
 * @param {unknown} value
 * @returns {boolean|null}
 */
export function triStateBool(value) {
	if (value === true || value === false) return value;
	if (value === 1 || value === '1' || value === 'true') return true;
	if (value === 0 || value === '0' || value === 'false') return false;
	return null;
}

/**
 * Blank string → null so a cleared field inherits.
 * @param {unknown} value
 * @returns {string|null}
 */
export function emptyToNull(value) {
	if (typeof value !== 'string') return null;
	const trimmed = value.trim();
	return trimmed === '' ? null : trimmed;
}

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
		publish: dualWritePublish({}),
		access: defaultAccess(),
		options: defaultOptions(),
	};
}

/** Who may apply, plus fee-waiver membership plans. */
export function defaultAccess() {
	return {
		audience: 'anyone',
		membershipPlanIds: [],
		profileRules: [],
		denyMessage: '',
	};
}

/**
 * @param {unknown} raw
 */
export function normalizeAccess(raw) {
	const src = raw && typeof raw === 'object' && !Array.isArray(raw) ? raw : {};
	const audience = src.audience;
	const allowed = audience === 'logged_in' || audience === 'members' ? audience : 'anyone';
	const planIds = Array.isArray(src.membershipPlanIds)
		? src.membershipPlanIds.map((id) => String(id)).filter(Boolean)
		: [];
	const profileRules = Array.isArray(src.profileRules)
		? src.profileRules
				.filter((rule) => rule && typeof rule === 'object' && rule.key)
				.map((rule) => ({
					key: String(rule.key),
					op: normalizeAccessOp(rule.op),
					value: rule.value == null ? '' : String(rule.value),
				}))
		: [];
	return {
		audience: allowed,
		membershipPlanIds: planIds,
		profileRules,
		denyMessage: typeof src.denyMessage === 'string' ? src.denyMessage : '',
	};
}

/**
 * @param {unknown} op
 */
function normalizeAccessOp(op) {
	const raw = String(op || 'eq');
	if (raw === 'neq' || raw === 'in' || raw === 'gte' || raw === 'lte' || raw === 'contains') {
		return raw;
	}
	return 'eq';
}

/**
 * Generic starter: applicant pack, one title field, one file.
 * @param {string} [title]
 */
export function genericStarterTemplate(title = 'New portal') {
	const def = blankDefinition(title);
	def.fields = [
		{
			id: 'applicant',
			type: 'applicant_pack',
			label: 'Your Information',
			required: true,
		},
		{
			id: 'work_title',
			type: 'short_text',
			label: 'Title',
			required: true,
		},
		{
			id: 'file',
			type: 'score_file',
			label: 'File',
			required: true,
			help: 'PDF',
			fileSuffix: '_FILE',
		},
	];
	return def;
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
 * Labels match the ISJAC Call for Scores shortcodes.
 * @param {string} prefix
 * @param {{ score?: string, rec?: string }} [suffixes]
 * @returns {Field[]}
 */
function scoreKindChildren(prefix, suffixes = {}) {
	return [
		{
			id: `${prefix}_title`,
			type: 'short_text',
			label: 'Title of Work or Presentation',
			required: true,
			help: 'to appear in program',
		},
		{
			id: `${prefix}_score`,
			type: 'score_file',
			label: 'Score',
			required: true,
			help: 'PDF only',
			fileSuffix: suffixes.score || `_${prefix.toUpperCase()}_SCORE`,
		},
		{
			id: `${prefix}_rec`,
			type: 'recording_file',
			label: 'Recording Submitted',
			required: true,
			help: 'MP3 only',
			fileSuffix: suffixes.rec || `_${prefix.toUpperCase()}_REC`,
		},
	];
}

/**
 * Call for Scores template: applicant + bio, then category branch with
 * poster / papers / nested score_kind paths (2027 ISJAC Call for Scores).
 * @param {string} [title]
 */
export function callForScoresTemplate(title = '2027 Call for Scores and Papers') {
	const def = blankDefinition(title);
	const scoreKinds = [
		{
			id: 'large',
			label: 'New Music Masterclass Workshop (Large Ensemble)',
			score: '_NMML_SCORE',
			rec: '_NMML_REC',
		},
		{
			id: 'small',
			label: 'New Music Masterclass Workshop (Small Ensemble)',
			score: '_NMMS_SCORE',
			rec: '_NMMS_REC',
		},
		{
			id: 'arrangement',
			label: 'New Music Masterclass Workshop (Arrangement)',
			score: '_NMMA_SCORE',
			rec: '_NMMA_REC',
		},
		{
			id: 'first_takes',
			label: 'First Takes',
			score: '_FT_SCORE',
			rec: '_FT_REC',
		},
		{
			id: 'student',
			label: 'Student/Young Artist',
			score: '_STUDENT_SCORE',
			rec: '_STUDENT_REC',
		},
	];
	def.fields = [
		{
			id: 'applicant',
			type: 'applicant_pack',
			label: 'Your Information',
			required: true,
		},
		{
			id: 'filetype_notice',
			type: 'static_html',
			label: 'Accepted filetypes',
			html: '<p><strong>Accepted filetypes are .mp3 for audio and .pdf for text/score.</strong></p>',
		},
		{
			id: 'checklist_heading',
			type: 'static_html',
			label: 'Support Materials Checklist',
			html: '<p><em>Support Materials Checklist</em></p>',
		},
		{
			id: 'bio',
			type: 'bio_file',
			label: 'Bio',
			required: true,
			help: '100 word maximum',
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
							label: 'Brief Description',
							required: true,
							help: '250 words',
							fileSuffix: '_POSTER_DESC',
						},
					],
				},
				{
					id: 'papers',
					label: 'Research/Analysis Papers',
					children: [
						{
							id: 'paper_title',
							type: 'short_text',
							label: 'Title of Work or Presentation',
							required: true,
							help: 'to appear in program',
						},
						{
							id: 'paper_abstract',
							type: 'score_file',
							label: 'Abstract',
							required: true,
							help: 'PDF only',
							fileSuffix: '_ABSTRACT',
						},
					],
				},
				{
					id: 'scores',
					label: 'Scores/Recordings',
					children: [
						{
							id: 'scores_note',
							type: 'static_html',
							label: 'Scores note',
							html: '<p>For Composition Master Class and ISJAC Awards consideration. Please attach a PDF of the score as well as an MP3 of each work. Make sure your name does not appear on either the score or the recording. Applicants may only submit one work.</p>',
						},
						{
							id: 'score_kind',
							type: 'branch',
							label: 'Select a Category',
							required: true,
							options: scoreKinds.map((kind) => {
								const children = scoreKindChildren(kind.id, {
									score: kind.score,
									rec: kind.rec,
								});
								if (kind.id === 'student') {
									children.unshift({
										id: 'student_eligibility',
										type: 'static_html',
										label: 'Student eligibility',
										html: '<p>Must be 26 or younger OR actively enrolled in a university degree program.</p>',
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
	{ type: 'static_html', label: 'Note', pill: 'Note' },
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
		static_html: {
			label: 'Note',
			html: '<p>Note for applicants.</p>',
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
export function insertField(fields, field, target = { kind: 'root' }, index = null) {
	const list = Array.isArray(fields) ? fields : [];
	if (!target || target.kind === 'root') {
		return insertAt(list, field, index);
	}
	if (target.kind === 'group') {
		return mapFields(list, (f) => {
			if (f.id !== target.fieldId || f.type !== 'group') return f;
			const children = insertAt(f.children || [], field, index);
			return { ...f, children };
		});
	}
	if (target.kind === 'option') {
		return mapFields(list, (f) => {
			if (f.id !== target.branchId || f.type !== 'branch') return f;
			const options = (f.options || []).map((opt) => {
				if (opt.id !== target.optionId) return opt;
				return { ...opt, children: insertAt(opt.children || [], field, index) };
			});
			return { ...f, options };
		});
	}
	return insertAt(list, field, index);
}

/**
 * @param {Field[]} list
 * @param {Field} field
 * @param {number|null} index
 */
function insertAt(list, field, index) {
	const next = Array.isArray(list) ? [...list] : [];
	const at = index == null || index < 0 || index > next.length ? next.length : index;
	next.splice(at, 0, field);
	return next;
}

/**
 * Remove a field by id and return it with the remaining tree.
 * @param {Field[]} fields
 * @param {string} id
 * @returns {{ field: Field|null, fields: Field[] }}
 */
export function extractField(fields, id) {
	/** @type {Field|null} */
	let extracted = null;
	const walk = (list) => {
		/** @type {Field[]} */
		const out = [];
		for (const f of list || []) {
			if (f.id === id) {
				extracted = f;
				continue;
			}
			/** @type {Field} */
			const next = { ...f };
			if (Array.isArray(f.children)) next.children = walk(f.children);
			if (Array.isArray(f.options)) {
				next.options = f.options.map((opt) => ({
					...opt,
					children: Array.isArray(opt.children) ? walk(opt.children) : [],
				}));
			}
			out.push(next);
		}
		return out;
	};
	const remaining = walk(fields);
	return { field: extracted, fields: remaining };
}

/**
 * Where a field sits (parent container + index).
 * @param {Field[]} fields
 * @param {string} id
 * @param {InsertTarget} [parent]
 * @returns {{ parent: InsertTarget, index: number }|null}
 */
export function locateField(fields, id, parent = { kind: 'root' }) {
	const list = fields || [];
	for (let i = 0; i < list.length; i++) {
		const f = list[i];
		if (f.id === id) return { parent, index: i };
		if (f.type === 'group' && Array.isArray(f.children)) {
			const hit = locateField(f.children, id, { kind: 'group', fieldId: f.id });
			if (hit) return hit;
		}
		if (f.type === 'branch' && Array.isArray(f.options)) {
			for (const opt of f.options) {
				const hit = locateField(opt.children || [], id, {
					kind: 'option',
					branchId: f.id,
					optionId: opt.id,
				});
				if (hit) return hit;
			}
		}
	}
	return null;
}

/**
 * True if `haystack` is `id` or contains it.
 * @param {Field} haystack
 * @param {string} id
 */
export function fieldContainsId(haystack, id) {
	if (!haystack || !id) return false;
	if (haystack.id === id) return true;
	if (Array.isArray(haystack.children) && haystack.children.some((c) => fieldContainsId(c, id))) {
		return true;
	}
	if (Array.isArray(haystack.options)) {
		return haystack.options.some((opt) =>
			(opt.children || []).some((c) => fieldContainsId(c, id)),
		);
	}
	return false;
}

/**
 * Move an existing field into a container (append, or at index).
 * Refuses to nest a field inside its own subtree.
 * @param {Field[]} fields
 * @param {string} id
 * @param {InsertTarget} target
 * @param {number|null} [index]
 */
export function moveField(fields, id, target, index = null) {
	const pulled = extractField(fields, id);
	if (!pulled.field) return fields;
	if (target?.kind === 'group' && fieldContainsId(pulled.field, target.fieldId)) {
		return fields;
	}
	if (target?.kind === 'option' && fieldContainsId(pulled.field, target.branchId)) {
		return fields;
	}
	return insertField(pulled.fields, pulled.field, target, index);
}

/**
 * Move `id` so it sits immediately before `beforeId` (same or new parent).
 * @param {Field[]} fields
 * @param {string} id
 * @param {string} beforeId
 */
export function moveFieldBefore(fields, id, beforeId) {
	if (!id || !beforeId || id === beforeId) return fields;
	const pulled = extractField(fields, id);
	if (!pulled.field) return fields;
	if (fieldContainsId(pulled.field, beforeId)) return fields;
	const dest = locateField(pulled.fields, beforeId);
	if (!dest) return fields;
	return insertField(pulled.fields, pulled.field, dest.parent, dest.index);
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
 * Set static_html markup on a note field.
 * @param {Field[]} fields
 * @param {string} id
 * @param {string} html
 * @returns {Field[]}
 */
export function setFieldHtml(fields, id, html) {
	return mapFields(fields, (f) => (f.id === id ? { ...f, html } : f));
}

/**
 * Whether a branch option's children should be shown in the Build tree.
 * Desktop (expandAll) keeps every path open. On a narrow screen only the
 * focused path and its ancestors stay open — siblings collapse.
 * @param {InsertTarget} insertTarget
 * @param {string|null} selectedOptionKey
 * @param {string} branchId
 * @param {string} optionId
 * @param {Field[]} [optionChildren]
 * @param {string|null} [selectedId]
 * @param {boolean} [expandAll]
 */
export function isBranchOptionOpen(
	insertTarget,
	selectedOptionKey,
	branchId,
	optionId,
	optionChildren = [],
	selectedId = null,
	expandAll = false,
) {
	if (expandAll) return true;
	const key = `${branchId}::${optionId}`;
	if (selectedOptionKey === key) return true;
	if (
		insertTarget?.kind === 'option' &&
		insertTarget.branchId === branchId &&
		insertTarget.optionId === optionId
	) {
		return true;
	}
	return optionContainsFocus(optionChildren, insertTarget, selectedOptionKey, selectedId);
}

/**
 * True when the focused field or option lives under these children.
 * @param {Field[]} children
 * @param {InsertTarget} insertTarget
 * @param {string|null} selectedOptionKey
 * @param {string|null} selectedId
 */
export function optionContainsFocus(children, insertTarget, selectedOptionKey, selectedId) {
	for (const field of children || []) {
		if (selectedId && field.id === selectedId) return true;
		if (field.type === 'group' && Array.isArray(field.children)) {
			if (optionContainsFocus(field.children, insertTarget, selectedOptionKey, selectedId)) {
				return true;
			}
		}
		if (field.type === 'branch' && Array.isArray(field.options)) {
			for (const opt of field.options) {
				const key = `${field.id}::${opt.id}`;
				if (selectedOptionKey === key) return true;
				if (
					insertTarget?.kind === 'option' &&
					insertTarget.branchId === field.id &&
					insertTarget.optionId === opt.id
				) {
					return true;
				}
				if (optionContainsFocus(opt.children || [], insertTarget, selectedOptionKey, selectedId)) {
					return true;
				}
			}
		}
	}
	return false;
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
	d.publish = dualWritePublish(d.publish);
	if (!d.options || typeof d.options !== 'object') {
		d.options = defaultOptions();
	} else {
		const incoming = d.options;
		d.options = {
			...defaultOptions(),
			...incoming,
			anonymize: triStateBool(
				Object.prototype.hasOwnProperty.call(incoming, 'anonymize')
					? incoming.anonymize
					: null,
			),
			freeForMembers: triStateBool(
				Object.prototype.hasOwnProperty.call(incoming, 'freeForMembers')
					? incoming.freeForMembers
					: null,
			),
			anonymizeEndpoint: emptyToNull(incoming.anonymizeEndpoint),
			anonymizeApiKey: emptyToNull(incoming.anonymizeApiKey),
			anonymizeAck: emptyToNull(incoming.anonymizeAck),
			guidelinesUrl: emptyToNull(incoming.guidelinesUrl),
			freeMembershipPlanIds: Array.isArray(incoming.freeMembershipPlanIds)
				? incoming.freeMembershipPlanIds
				: [],
		};
	}
	d.access = normalizeAccess(d.access);
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
 * Split a dest string into sheet targets and an optional Drive folder.
 * @param {string} value
 * @returns {{ sheets: { name: string, column: string }[], drive: string|null }}
 */
export function parseDestParts(value) {
	/** @type {{ name: string, column: string }[]} */
	const sheets = [];
	let drive = null;
	for (const part of String(value || '').split('|')) {
		if (part.startsWith('drive:')) {
			drive = part.slice('drive:'.length) || null;
			continue;
		}
		if (!part.startsWith('sheet:')) continue;
		const rest = part.slice('sheet:'.length);
		const i = rest.indexOf(':');
		if (i < 0) continue;
		sheets.push({ name: rest.slice(0, i), column: rest.slice(i + 1) });
	}
	return { sheets, drive };
}

/**
 * @param {{ sheets?: { name: string, column: string }[], drive?: string|null }} parts
 */
export function encodeDestParts(parts) {
	const bits = [];
	if (parts?.drive) bits.push(`drive:${parts.drive}`);
	for (const sheet of parts?.sheets || []) {
		if (!sheet?.name) continue;
		bits.push(`sheet:${sheet.name}:${sheet.column || 'Value'}`);
	}
	return bits.join('|');
}

/**
 * Add or remove a sheet target from a dest string.
 * @param {string} value
 * @param {string} sheetName
 * @param {string} column
 */
export function toggleSheetDest(value, sheetName, column) {
	const parts = parseDestParts(value);
	const has = parts.sheets.some((s) => s.name === sheetName);
	const sheets = has
		? parts.sheets.filter((s) => s.name !== sheetName)
		: [...parts.sheets, { name: sheetName, column }];
	return encodeDestParts({ ...parts, sheets });
}

/**
 * @param {string} value
 * @param {string} folderName
 */
export function toggleDriveDest(value, folderName) {
	const parts = parseDestParts(value);
	const drive = parts.drive === folderName ? null : folderName;
	return encodeDestParts({ ...parts, drive });
}

/**
 * Append an extra sheet card. Housekeeping and Adjudicator stay.
 * @param {object} [mapping]
 * @param {string} [name]
 */
export function addSheet(mapping, name = 'Extra sheet') {
	const next = ensureMapping(mapping);
	const used = new Set((next.sheets || []).map((s) => s.id));
	const id = uniqueId(`sheet_${slugFromLabel(name)}`, used);
	const label = unusedSheetName(next.sheets, name);
	return {
		...next,
		sheets: [
			...next.sheets,
			{ id, name: label, spreadsheetId: '' },
		],
	};
}

/**
 * @param {{ name?: string }[]} sheets
 * @param {string} name
 */
function unusedSheetName(sheets, name) {
	const used = new Set((sheets || []).map((s) => s.name));
	if (!used.has(name)) return name;
	let n = 2;
	while (used.has(`${name} ${n}`)) n += 1;
	return `${name} ${n}`;
}

/**
 * Remove an extra sheet. Housekeeping / Adjudicator cards stay.
 * @param {object} [mapping]
 * @param {string} sheetId
 */
export function removeSheet(mapping, sheetId) {
	const next = ensureMapping(mapping);
	const sheets = (next.sheets || []).filter((s) => {
		if (s.id !== sheetId) return true;
		return s.role === SHEET_ROLE_HOUSEKEEPING || s.role === SHEET_ROLE_ADJUDICATOR;
	});
	return { ...next, sheets };
}

/**
 * Add one extra sheet per branch option (named after the option).
 * @param {object} [mapping]
 * @param {Field} branch
 */
/**
 * Rewrite dest strings when a sheet card is renamed.
 * @param {Record<string, string>} fieldDest
 * @param {string} fromName
 * @param {string} toName
 */
export function renameSheetInDests(fieldDest, fromName, toName) {
	if (!fromName || !toName || fromName === toName) return fieldDest || {};
	/** @type {Record<string, string>} */
	const out = {};
	for (const [id, value] of Object.entries(fieldDest || {})) {
		const parts = parseDestParts(value);
		const sheets = parts.sheets.map((s) =>
			s.name === fromName ? { ...s, name: toName } : s,
		);
		out[id] = encodeDestParts({ ...parts, sheets });
	}
	return out;
}

export function addSheetsForBranchOptions(mapping, branch) {
	let next = ensureMapping(mapping);
	for (const opt of branch?.options || []) {
		const name = String(opt.label || opt.id || '').trim();
		if (!name) continue;
		if (next.sheets.some((s) => s.name === name)) continue;
		next = addSheet(next, name);
	}
	return next;
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
