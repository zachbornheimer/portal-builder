<script>
	import { flattenMappableFields, pillForType } from '../definitionModel.js';

	/**
	 * @typedef {Object} Props
	 * @property {object} definition
	 * @property {(d: object) => void} onChange
	 * @property {() => void} onContinue
	 * @property {() => void} onBack
	 */

	/** @type {Props} */
	let { definition, onChange, onContinue, onBack } = $props();

	const fields = $derived(flattenMappableFields(definition?.fields || []));

	const sheetName = $derived(
		definition?.mapping?.sheets?.[0]?.name || 'All Records'
	);
	const driveName = $derived(
		definition?.mapping?.drive?.[0]?.name || 'SUBMISSIONS'
	);

	/**
	 * mapKey on field or default destinations stored in mapping.column hints.
	 * We store simple map: fieldId -> destination key on mapping.fieldDest[id]
	 */
	const destMap = $derived(
		definition?.mapping?.fieldDest && typeof definition.mapping.fieldDest === 'object'
			? definition.mapping.fieldDest
			: {}
	);

	/**
	 * @param {import('../definitionModel.js').Field} f
	 */
	function defaultDest(f) {
		if (f.type === 'score_file') {
			return `drive:${driveName}|sheet:${sheetName}:Score Link`;
		}
		if (f.type === 'recording_file') {
			return `drive:${driveName}|sheet:${sheetName}:Rec Link`;
		}
		if (f.type === 'bio_file') {
			return `drive:${driveName}|sheet:${sheetName}:Bio Link`;
		}
		if (f.type === 'applicant_pack') {
			return `sheet:${sheetName}:Applicant`;
		}
		return `sheet:${sheetName}:${f.label || f.id}`;
	}

	/**
	 * @param {import('../definitionModel.js').Field} f
	 */
	function currentDest(f) {
		return destMap[f.id] || '';
	}

	/**
	 * @param {string} fieldId
	 * @param {string} value
	 */
	function setDest(fieldId, value) {
		const fieldDest = { ...destMap, [fieldId]: value };
		const mapping = {
			...(definition.mapping || { sheets: [], drive: [] }),
			fieldDest,
			sheets: definition.mapping?.sheets?.length
				? definition.mapping.sheets
				: [
						{
							id: 'sheet_main',
							name: sheetName,
							spreadsheetId: '',
							columns: [],
						},
					],
			drive: definition.mapping?.drive?.length
				? definition.mapping.drive
				: [{ id: 'drive_main', name: driveName, folderId: '' }],
		};
		onChange({ ...definition, mapping });
	}

	function autoMapAll() {
		const fieldDest = { ...destMap };
		for (const f of fields) {
			if (!fieldDest[f.id]) {
				fieldDest[f.id] = defaultDest(f);
			}
		}
		const mapping = {
			...(definition.mapping || {}),
			fieldDest,
			sheets: definition.mapping?.sheets?.length
				? definition.mapping.sheets
				: [
						{
							id: 'sheet_main',
							name: sheetName,
							spreadsheetId: '',
							columns: [],
						},
					],
			drive: definition.mapping?.drive?.length
				? definition.mapping.drive
				: [{ id: 'drive_main', name: driveName, folderId: '' }],
		};
		onChange({ ...definition, mapping });
	}

	const unmapped = $derived(fields.filter((f) => !currentDest(f)));

	/**
	 * @param {import('../definitionModel.js').Field} f
	 */
	function optionsFor(f) {
		const opts = [
			{ value: '', label: '— choose a destination —' },
			{ value: defaultDest(f), label: labelForDest(defaultDest(f)) },
			{
				value: `sheet:${sheetName}:${f.label || f.id}`,
				label: `${sheetName} → column “${f.label || f.id}”`,
			},
		];
		if (f.type.includes('file') || f.type.endsWith('_file')) {
			opts.push({
				value: `drive:${driveName}`,
				label: `${driveName} (Drive folder only)`,
			});
		}
		return opts;
	}

	/**
	 * @param {string} v
	 */
	function labelForDest(v) {
		if (!v) return '— choose a destination —';
		if (v.startsWith('drive:') && v.includes('|sheet:')) {
			const [d, s] = v.split('|');
			return `${d.replace('drive:', '')} (Drive) + ${s.replace('sheet:', '').replace(':', ' → column “')}”`;
		}
		if (v.startsWith('sheet:')) {
			const rest = v.slice('sheet:'.length);
			const i = rest.indexOf(':');
			if (i >= 0) {
				return `${rest.slice(0, i)} → column “${rest.slice(i + 1)}”`;
			}
		}
		return v;
	}
</script>

<p class="dg-wizard-eyebrow">{definition?.title || 'Portal'}</p>
<h2 id="dg-wizard-step-title" class="dg-wizard-title">Where does each field go?</h2>
<p class="dg-wizard-lead">
	Map form answers to Google Sheets columns and Drive folders. Unmapped fields are flagged —
	don’t leave them blank before publish.
</p>

<div class="dg-map-toolbar">
	<button type="button" class="dg-btn dg-btn-ghost dg-btn-sm" onclick={autoMapAll}>
		Auto-map defaults
	</button>
	<span class="dg-field-help">
		Sheet: <strong>{sheetName}</strong> · Drive: <strong>{driveName}</strong>
	</span>
</div>

{#if fields.length === 0}
	<div class="dg-empty-hint" role="status">Add fields in Build first.</div>
{:else}
	<div class="dg-map-table cardish" data-dg-map-table>
		<div class="dg-map-head">
			<span>Form field</span>
			<span></span>
			<span>Destination</span>
		</div>
		{#each fields as f (f.id)}
			{@const mapped = Boolean(currentDest(f))}
			<div
				class="dg-map-row"
				class:dg-map-row--warn={!mapped}
				data-mapped={mapped ? 'true' : 'false'}
			>
				<div class="dg-map-field">
					{f.label}
					<span class="dg-field-type-pill">{pillForType(f.type)}</span>
				</div>
				<div class="dg-map-arrow" aria-hidden="true">→</div>
				<div>
					<select
						class="dg-input"
						value={currentDest(f)}
						onchange={(e) => setDest(f.id, e.currentTarget.value)}
					>
						{#each optionsFor(f) as opt (opt.value + opt.label)}
							<option value={opt.value}>{opt.label}</option>
						{/each}
					</select>
				</div>
			</div>
		{/each}
	</div>
{/if}

{#if unmapped.length > 0}
	<p class="dg-wizard-alert" role="status">
		⚠ {unmapped.length} field{unmapped.length === 1 ? '' : 's'} unmapped — map them or auto-map
		before publish.
	</p>
{/if}

<div class="dg-wizard-actions">
	<button type="button" class="dg-btn dg-btn-ghost" onclick={onBack}>← Back to form</button>
	<button type="button" class="dg-btn dg-btn-primary" onclick={onContinue}>
		Continue to Publish →
	</button>
</div>
