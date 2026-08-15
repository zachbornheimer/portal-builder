<script>
	import {
		FIELD_TYPE_CATALOG,
		addBranchOption,
		findField,
		flattenMappableFields,
		insertField,
		locateField,
		moveField,
		moveFieldBefore,
		newField,
		removeBranchOption,
		removeField,
		renameBranchOption,
		renameField,
		setFieldHtml,
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
	/** Sidebar layout (≥901px): keep every path open. Narrow screens collapse siblings. */
	let expandAll = $state(true);

	$effect(() => {
		if (typeof window === 'undefined' || !window.matchMedia) return;
		const mq = window.matchMedia('(min-width: 901px)');
		const sync = () => {
			expandAll = mq.matches;
		};
		sync();
		mq.addEventListener('change', sync);
		return () => mq.removeEventListener('change', sync);
	});

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
	/**
	 * @param {string} type
	 * @param {import('../definitionModel.js').InsertTarget} [target]
	 * @param {string|null} [beforeId]
	 */
	function addField(type, target = insertTarget, beforeId = null) {
		const created = newField(type, fields);
		if (beforeId) {
			const dest = locateField(fields, beforeId);
			if (dest) {
				setFields(insertField(fields, created, dest.parent, dest.index));
				selectedId = created.id;
				return created;
			}
		}
		setFields(insertField(fields, created, target));
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
		return created;
	}

	/**
	 * @param {string} branchId
	 * @param {string} optionId
	 */
	function viewOption(branchId, optionId) {
		selectedId = branchId;
		selectedOptionKey = `${branchId}::${optionId}`;
		insertTarget = { kind: 'option', branchId, optionId };
		queueMicrotask(() => {
			const key = `${branchId}::${optionId}`;
			const el = document.querySelector(`[data-dg-option="${key}"]`);
			el?.scrollIntoView({ block: 'center', behavior: 'smooth' });
		});
	}

	/**
	 * @param {string} type
	 * @param {import('../definitionModel.js').InsertTarget} target
	 * @param {string|null} [beforeId]
	 */
	function onDropType(type, target, beforeId = null) {
		if (target.kind === 'option') {
			selectedOptionKey = `${target.branchId}::${target.optionId}`;
			insertTarget = target;
		} else if (target.kind === 'group') {
			insertTarget = target;
		}
		addField(type, target, beforeId);
	}

	/**
	 * @param {string} fieldId
	 * @param {import('../definitionModel.js').InsertTarget} target
	 * @param {string|null} [beforeId]
	 */
	function onDropField(fieldId, target, beforeId = null) {
		if (beforeId) {
			setFields(moveFieldBefore(fields, fieldId, beforeId));
			selectedId = fieldId;
			return;
		}
		setFields(moveField(fields, fieldId, target));
		selectedId = fieldId;
		if (target.kind === 'option') {
			selectedOptionKey = `${target.branchId}::${target.optionId}`;
			insertTarget = target;
		}
	}

	/**
	 * @param {DragEvent} event
	 * @param {string} type
	 */
	function onPaletteDragStart(event, type) {
		if (!event.dataTransfer) return;
		event.dataTransfer.setData('application/x-dg-field-type', type);
		event.dataTransfer.effectAllowed = 'copyMove';
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
			ondragover={(e) => {
				e.preventDefault();
			}}
			ondrop={(e) => {
				e.preventDefault();
				const type = e.dataTransfer?.getData('application/x-dg-field-type');
				const fieldId = e.dataTransfer?.getData('application/x-dg-field-id');
				if (type) onDropType(type, { kind: 'root' });
				else if (fieldId) onDropField(fieldId, { kind: 'root' });
			}}
		>
			Insert at root
		</button>

		<BuildFieldTree
			{fields}
			{selectedId}
			{insertTarget}
			{selectedOptionKey}
			{expandAll}
			onSelect={(id) => (selectedId = id)}
			{onRemove}
			onSetInsertTarget={(t) => (insertTarget = t)}
			{onSelectOption}
			{onDropType}
			{onDropField}
		/>
	</div>

	<aside class="dg-build-inspector" aria-label="Field inspector">
		<p class="dg-wizard-eyebrow">Selected field</p>
		{#if selected}
			<BuildFieldEditor
				field={selected}
				{selectedOption}
				{insertTarget}
				onRename={(label) => setFields(renameField(fields, selected.id, label))}
				onSetRequired={(required) =>
					setFields(setFieldRequired(fields, selected.id, required))}
				onSetHtml={(html) => setFields(setFieldHtml(fields, selected.id, html))}
				onRenameOption={(optionId, label) =>
					setFields(renameBranchOption(fields, selected.id, optionId, label))}
				onFocusOption={(optionId) => viewOption(selected.id, optionId)}
				onRemoveOption={(optionId) =>
					setFields(removeBranchOption(fields, selected.id, optionId))}
				onAddOption={() => setFields(addBranchOption(fields, selected.id))}
				onAddNestedBranch={addNestedBranch}
				onAddFieldToOption={() => {
					if (!selectedOption) return;
					viewOption(selected.id, selectedOption.id);
					queueMicrotask(() => {
						document.getElementById('dg-palette-first')?.focus();
					});
				}}
			/>
		{:else}
			<p class="dg-field-help">Select a field in the tree to rename it or edit its options.</p>
		{/if}

		<div class="dg-type-palette" id="dg-type-palette">
			<span class="dg-field-label">Add a field</span>
			<p class="dg-field-help">{insertHint}. Click to add, or drag onto a path.</p>
			<div class="dg-type-palette-row">
				{#each FIELD_TYPE_CATALOG as t, i (t.type)}
					<button
						type="button"
						class="dg-btn dg-btn-ghost dg-btn-sm"
						id={i === 0 ? 'dg-palette-first' : undefined}
						draggable="true"
						ondragstart={(e) => onPaletteDragStart(e, t.type)}
						onclick={() => addField(t.type)}
					>
						{t.label}
					</button>
				{/each}
			</div>
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
