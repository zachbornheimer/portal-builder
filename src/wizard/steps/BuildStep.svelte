<script>
	import {
		FIELD_TYPE_CATALOG,
		addBranchOption,
		findField,
		flattenMappableFields,
		insertField,
		newField,
		removeBranchOption,
		removeField,
		renameBranchOption,
		renameField,
		setFieldRequired,
	} from '../definitionModel.js';
	import BuildFieldEditor from './BuildFieldEditor.svelte';
	import BuildFieldTree from './BuildFieldTree.svelte';

	/**
	 * @typedef {Object} Props
	 * @property {object} definition
	 * @property {(d: object) => void} onChange
	 * @property {() => void} onContinue
	 * @property {() => void} onBack
	 */

	/** @type {Props} */
	let { definition, onChange, onContinue, onBack } = $props();

	let selectedId = $state(/** @type {string|null} */ (null));
	/** @type {import('../definitionModel.js').InsertTarget} */
	let insertTarget = $state({ kind: 'root' });
	/** Selected branch option key `branchId::optionId` for option editor. */
	let selectedOptionKey = $state(/** @type {string|null} */ (null));

	const fields = $derived(Array.isArray(definition?.fields) ? definition.fields : []);
	const flat = $derived(flattenMappableFields(fields));
	const selected = $derived(selectedId ? findField(fields, selectedId) : null);

	const selectedOption = $derived.by(() => {
		if (!selectedOptionKey || !selected || selected.type !== 'branch') return null;
		const [branchId, optionId] = selectedOptionKey.split('::');
		if (selected.id !== branchId) return null;
		return (selected.options || []).find((o) => o.id === optionId) || null;
	});

	/**
	 * @param {object[]} nextFields
	 */
	function setFields(nextFields) {
		onChange({ ...definition, fields: nextFields });
	}

	/**
	 * @param {string} type
	 */
	function addField(type) {
		const created = newField(type, fields);
		// Always honor insert target — never dump into the first group.
		setFields(insertField(fields, created, insertTarget));
		selectedId = created.id;
		if (created.type === 'group') {
			insertTarget = { kind: 'group', fieldId: created.id };
		} else if (created.type === 'branch' && created.options?.[0]) {
			selectedOptionKey = `${created.id}::${created.options[0].id}`;
			insertTarget = {
				kind: 'option',
				branchId: created.id,
				optionId: created.options[0].id,
			};
		}
	}

	function addNestedBranch() {
		if (insertTarget.kind !== 'option') return;
		const created = newField('branch', fields);
		setFields(insertField(fields, created, insertTarget));
		selectedId = created.id;
		if (created.options?.[0]) {
			selectedOptionKey = `${created.id}::${created.options[0].id}`;
			insertTarget = {
				kind: 'option',
				branchId: created.id,
				optionId: created.options[0].id,
			};
		}
	}

	/**
	 * @param {string} id
	 */
	function onRemove(id) {
		setFields(removeField(fields, id));
		if (selectedId === id) selectedId = null;
		if (insertTarget.kind === 'group' && insertTarget.fieldId === id) {
			insertTarget = { kind: 'root' };
		}
		if (insertTarget.kind === 'option' && insertTarget.branchId === id) {
			insertTarget = { kind: 'root' };
			selectedOptionKey = null;
		}
	}

	/**
	 * @param {string} branchId
	 * @param {string} optionId
	 */
	function onSelectOption(branchId, optionId) {
		selectedOptionKey = `${branchId}::${optionId}`;
	}

	/**
	 * Preview root leaves + first option path of each branch.
	 * @param {import('../definitionModel.js').Field[]} list
	 * @param {number} budget
	 */
	function previewFields(list, budget = 10) {
		/** @type {import('../definitionModel.js').Field[]} */
		const out = [];
		for (const f of list || []) {
			if (out.length >= budget) break;
			if (f.type === 'group' && Array.isArray(f.children)) {
				out.push(...previewFields(f.children, budget - out.length));
				continue;
			}
			if (f.type === 'static_html') continue;
			if (f.type === 'branch') {
				out.push(f);
				const first = f.options?.[0];
				if (first?.children?.length) {
					out.push(...previewFields(first.children, budget - out.length));
				}
				continue;
			}
			out.push(f);
		}
		return out.slice(0, budget);
	}

	const preview = $derived(previewFields(fields));

	const insertHint = $derived.by(() => {
		if (insertTarget.kind === 'root') return 'Insert at root';
		if (insertTarget.kind === 'group') {
			const g = findField(fields, insertTarget.fieldId);
			return `Insert into ${g?.label || insertTarget.fieldId}`;
		}
		const b = findField(fields, insertTarget.branchId);
		const opt = (b?.options || []).find((o) => o.id === insertTarget.optionId);
		return `Insert when: ${opt?.label || insertTarget.optionId}`;
	});
</script>

<p class="dg-wizard-eyebrow">{definition?.title || 'Portal'}</p>
<h2 id="dg-wizard-step-title" class="dg-wizard-title">Build the form</h2>
<p class="dg-wizard-lead">
	Add fields applicants fill out. Use Branch for conditional paths (poster vs scores).
</p>

<div class="dg-build-layout">
	<div class="dg-build-canvas">
		{#if fields.length === 0}
			<div class="dg-empty-hint" role="status">
				No fields yet. Use a type below or go back to Start and pick a template.
			</div>
		{/if}

		<button
			type="button"
			class="dg-insert-target dg-insert-target--root"
			class:dg-insert-target--active={insertTarget.kind === 'root'}
			onclick={() => {
				insertTarget = { kind: 'root' };
				selectedOptionKey = null;
			}}
		>
			{insertHint}
		</button>

		<BuildFieldTree
			{fields}
			{selectedId}
			{insertTarget}
			{selectedOptionKey}
			onSelect={(id) => (selectedId = id)}
			{onRemove}
			onSetInsertTarget={(t) => (insertTarget = t)}
			{onSelectOption}
		/>

		{#if selected}
			<BuildFieldEditor
				field={selected}
				{selectedOption}
				{insertTarget}
				onRename={(label) => setFields(renameField(fields, selected.id, label))}
				onSetRequired={(required) =>
					setFields(setFieldRequired(fields, selected.id, required))}
				onRenameOption={(optionId, label) =>
					setFields(renameBranchOption(fields, selected.id, optionId, label))}
				onFocusOption={(optionId) => {
					selectedOptionKey = `${selected.id}::${optionId}`;
					insertTarget = {
						kind: 'option',
						branchId: selected.id,
						optionId,
					};
				}}
				onRemoveOption={(optionId) =>
					setFields(removeBranchOption(fields, selected.id, optionId))}
				onAddOption={() => setFields(addBranchOption(fields, selected.id))}
				onAddNestedBranch={addNestedBranch}
			/>
		{/if}

		<div class="dg-type-palette">
			<span class="dg-field-label">Add a field</span>
			<p class="dg-field-help">{insertHint}</p>
			<div class="dg-type-palette-row">
				{#each FIELD_TYPE_CATALOG as t (t.type)}
					<button
						type="button"
						class="dg-btn dg-btn-ghost dg-btn-sm"
						onclick={() => addField(t.type)}
					>
						{t.label}
					</button>
				{/each}
			</div>
		</div>
	</div>

	<aside class="dg-live-preview" aria-label="Live preview">
		<p class="dg-wizard-eyebrow">Live preview</p>
		<div class="dg-preview-card">
			<div class="dg-preview-card-title">
				{definition?.title || 'Portal'} — Application
			</div>
			{#if preview.length === 0}
				<p class="dg-field-help">Fields appear here as you add them.</p>
			{:else}
				{#each preview as f (f.id)}
					<span class="dg-field-label">
						{f.label}{#if f.required}<span class="dg-req">*</span>{/if}
					</span>
					{#if f.type === 'branch'}
						<div class="dg-preview-branch">
							{#each (f.options || []).slice(0, 4) as opt, i (opt.id)}
								<label class="dg-preview-radio">
									<input type="radio" disabled checked={i === 0} />
									{opt.label}
								</label>
							{/each}
						</div>
					{:else if f.type === 'score_file' || f.type === 'recording_file' || f.type === 'bio_file' || f.type === 'file'}
						<div class="dg-preview-file">Choose file…</div>
					{:else if f.type === 'applicant_pack'}
						<div class="dg-preview-file">Name, email, address…</div>
					{:else}
						<input type="text" class="dg-input" disabled placeholder="" />
					{/if}
				{/each}
			{/if}
		</div>
	</aside>
</div>

<div class="dg-wizard-actions">
	<button type="button" class="dg-btn dg-btn-ghost" onclick={onBack}>← Back</button>
	<button
		type="button"
		class="dg-btn dg-btn-primary"
		disabled={flat.length === 0}
		onclick={onContinue}
	>
		Continue to Map →
	</button>
</div>
{#if flat.length === 0}
	<p class="dg-wizard-footnote" role="status">Add at least one field before mapping.</p>
{/if}
