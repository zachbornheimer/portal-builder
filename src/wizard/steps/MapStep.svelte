<script>
	import {
		acceptFolderInput,
		acceptSpreadsheetInput,
		addSheet,
		addSheetsForBranchOptions,
		defaultFieldDest,
		ensureMapping,
		flattenMappableFields,
		isFileField,
		mappingSummaryNames,
		parseDestParts,
		parseFolderId,
		parseSpreadsheetId,
		pillForType,
		renameSheetInDests,
		removeSheet,
		toggleDriveDest,
		toggleSheetDest,
	} from '../definitionModel.js';

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
	const mapping = $derived(ensureMapping(definition?.mapping));
	const destSheets = $derived(mapping.sheets);
	const destDrive = $derived(mapping.drive[0]);
	const summary = $derived(mappingSummaryNames(mapping));

	const destMap = $derived(
		mapping.fieldDest && typeof mapping.fieldDest === 'object' ? mapping.fieldDest : {}
	);

	/**
	 * @param {object} next
	 */
	function writeMapping(next) {
		onChange({ ...definition, mapping: next });
	}

	/**
	 * @param {number} index
	 * @param {object} patch
	 */
	function updateSheet(index, patch) {
		const next = ensureMapping(definition.mapping);
		const prev = next.sheets[index];
		const sheets = next.sheets.map((sheet, i) =>
			i === index ? { ...sheet, ...patch } : sheet
		);
		const fieldDest =
			patch.name && prev?.name && patch.name !== prev.name
				? renameSheetInDests(destMap, prev.name, patch.name)
				: destMap;
		writeMapping({ ...next, sheets, fieldDest });
	}

	/**
	 * @param {object} patch
	 */
	function updateDrive(patch) {
		const next = ensureMapping(definition.mapping);
		const drive = next.drive.map((folder, i) =>
			i === 0 ? { ...folder, ...patch } : folder
		);
		writeMapping({ ...next, drive });
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
		writeMapping({
			...ensureMapping(definition.mapping),
			fieldDest: { ...destMap, [fieldId]: value },
		});
	}

	function autoMapAll() {
		const fieldDest = { ...destMap };
		const next = ensureMapping(definition.mapping);
		for (const f of fields) {
			if (!fieldDest[f.id]) {
				fieldDest[f.id] = defaultFieldDest(f, next);
			}
		}
		writeMapping({ ...next, fieldDest });
	}

	function onAddSheet() {
		writeMapping(addSheet(definition.mapping, 'Extra sheet'));
	}

	/**
	 * @param {string} sheetId
	 */
	function onRemoveSheet(sheetId) {
		writeMapping(removeSheet(definition.mapping, sheetId));
	}

	/**
	 * @param {import('../definitionModel.js').Field} field
	 */
	function onAddSheetsForBranch(field) {
		writeMapping(addSheetsForBranchOptions(definition.mapping, field));
	}

	/**
	 * @param {import('../definitionModel.js').Field} field
	 * @param {string} sheetName
	 */
	function onToggleSheet(field, sheetName) {
		const column = field.label || field.id;
		setDest(field.id, toggleSheetDest(currentDest(field), sheetName, column));
	}

	/**
	 * @param {import('../definitionModel.js').Field} field
	 * @param {string} folderName
	 */
	function onToggleDrive(field, folderName) {
		setDest(field.id, toggleDriveDest(currentDest(field), folderName));
	}

	/**
	 * @param {object} sheet
	 */
	function canRemoveSheet(sheet) {
		return sheet.role !== 'housekeeping' && sheet.role !== 'adjudicator';
	}

	const unmapped = $derived(fields.filter((f) => !currentDest(f)));

	/**
	 * @param {object} sheet
	 * @param {number} index
	 */
	function sheetKicker(sheet, index) {
		if (sheet.role === 'housekeeping') return 'Housekeeping sheet';
		if (sheet.role === 'adjudicator') return 'Adjudicator sheet';
		if (index === 0) return 'Housekeeping sheet';
		if (index === 1 && destSheets[0]?.role === 'housekeeping') return 'Adjudicator sheet';
		return 'Extra sheet';
	}

	const SHEET_OPEN_PREFIX = 'https://docs.google.com/spreadsheets/d/';
	const FOLDER_OPEN_PREFIX = 'https://drive.google.com/drive/folders/';
</script>

<p class="dg-wizard-eyebrow">{definition?.title || 'Portal'}</p>
<h2 id="dg-wizard-step-title" class="dg-wizard-title">Where does each field go?</h2>
<p class="dg-wizard-lead">
	Map form answers to Google Sheets columns and Drive folders. Unmapped fields are flagged —
	don’t leave them blank before publish.
</p>

<div class="dg-dest-grid">
	{#each destSheets as sheet, i (sheet.id || i)}
		{@const sheetId = parseSpreadsheetId(sheet.spreadsheetId)}
		<div class="dg-dest-card cardish">
			<p class="dg-dest-card-kicker">{sheetKicker(sheet, i)}</p>
			<label class="dg-field-label" for={`dg-dest-sheet-name-${i}`}>Name</label>
			<input
				id={`dg-dest-sheet-name-${i}`}
				type="text"
				class="dg-input"
				value={sheet.name}
				oninput={(e) => updateSheet(i, { name: e.currentTarget.value })}
			/>
			<label class="dg-field-label" for={`dg-dest-sheet-id-${i}`}>Spreadsheet</label>
			<input
				id={`dg-dest-sheet-id-${i}`}
				type="text"
				class="dg-input"
				placeholder="Paste a Google Sheets URL or id"
				value={sheet.spreadsheetId}
				oninput={(e) =>
					updateSheet(i, {
						spreadsheetId: acceptSpreadsheetInput(e.currentTarget.value),
						role: sheet.role || (i === 0 ? 'housekeeping' : 'adjudicator'),
					})}
			/>
			{#if sheetId}
				<p class="dg-dest-status dg-dest-status--set">
					<a href={`${SHEET_OPEN_PREFIX}${sheetId}`} target="_blank" rel="noopener"
						>Open sheet</a
					>
				</p>
			{:else}
				<p class="dg-dest-status">Not set — paste a sheet URL</p>
			{/if}
			{#if canRemoveSheet(sheet)}
				<button
					type="button"
					class="dg-btn dg-btn-ghost dg-btn-sm"
					onclick={() => onRemoveSheet(sheet.id)}
				>
					Remove sheet
				</button>
			{/if}
		</div>
	{/each}
	<button type="button" class="dg-dest-add cardish" onclick={onAddSheet}>
		<span class="dg-dest-add-plus" aria-hidden="true">+</span>
		Add another sheet
	</button>
	{#if destDrive}
		{@const folderId = parseFolderId(destDrive.folderId)}
		<div class="dg-dest-card cardish">
			<p class="dg-dest-card-kicker">Drive folder</p>
			<label class="dg-field-label" for="dg-dest-drive-name">Name</label>
			<input
				id="dg-dest-drive-name"
				type="text"
				class="dg-input"
				value={destDrive.name}
				oninput={(e) => updateDrive({ name: e.currentTarget.value })}
			/>
			<label class="dg-field-label" for="dg-dest-drive-id">Folder</label>
			<input
				id="dg-dest-drive-id"
				type="text"
				class="dg-input"
				placeholder="Paste a Drive folder URL or id"
				value={destDrive.folderId}
				oninput={(e) =>
					updateDrive({ folderId: acceptFolderInput(e.currentTarget.value) })}
			/>
			{#if folderId}
				<p class="dg-dest-status dg-dest-status--set">
					<a href={`${FOLDER_OPEN_PREFIX}${folderId}`} target="_blank" rel="noopener"
						>Open folder</a
					>
				</p>
			{:else}
				<p class="dg-dest-status">Not set — paste a folder URL</p>
			{/if}
		</div>
	{/if}
</div>

<div class="dg-map-toolbar">
	<button type="button" class="dg-btn dg-btn-ghost dg-btn-sm" onclick={autoMapAll}>
		Auto-map defaults
	</button>
	<span class="dg-field-help">
		Sheet: <strong>{summary.sheets}</strong> · Drive: <strong>{summary.drive}</strong>
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
				<div class="dg-map-dests">
					{#each destSheets as sheet (sheet.id)}
						<label class="dg-check-row">
							<input
								type="checkbox"
								checked={parseDestParts(currentDest(f)).sheets.some(
									(s) => s.name === sheet.name,
								)}
								onchange={() => onToggleSheet(f, sheet.name)}
							/>
							<span>{sheet.name}</span>
						</label>
					{/each}
					{#if isFileField(f) && destDrive}
						<label class="dg-check-row">
							<input
								type="checkbox"
								checked={parseDestParts(currentDest(f)).drive === destDrive.name}
								onchange={() => onToggleDrive(f, destDrive.name)}
							/>
							<span>{destDrive.name} (Drive)</span>
						</label>
					{/if}
					{#if f.type === 'branch' && (f.options || []).length > 0}
						<button
							type="button"
							class="dg-btn dg-btn-ghost dg-btn-sm"
							onclick={() => onAddSheetsForBranch(f)}
						>
							Add a sheet per option
						</button>
					{/if}
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
		Continue to Portal Configuration →
	</button>
</div>
