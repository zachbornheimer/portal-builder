<script>
	import { isBranchOptionOpen, pillForType } from '../definitionModel.js';
	import BuildFieldTree from './BuildFieldTree.svelte';

	/**
	 * Recursive field tree for Build canvas.
	 * @typedef {Object} Props
	 * @property {import('../definitionModel.js').Field[]} fields
	 * @property {string|null} selectedId
	 * @property {import('../definitionModel.js').InsertTarget} insertTarget
	 * @property {(id: string) => void} onSelect
	 * @property {(id: string) => void} onRemove
	 * @property {(target: import('../definitionModel.js').InsertTarget) => void} onSetInsertTarget
	 * @property {(branchId: string, optionId: string) => void} [onSelectOption]
	 * @property {string|null} [selectedOptionKey]
	 * @property {number} [depth]
	 * @property {boolean} [expandAll]
	 * @property {(type: string, target: import('../definitionModel.js').InsertTarget, beforeId?: string|null) => void} [onDropType]
	 * @property {(fieldId: string, target: import('../definitionModel.js').InsertTarget, beforeId?: string|null) => void} [onDropField]
	 */

	/** @type {Props} */
	let {
		fields,
		selectedId,
		insertTarget,
		onSelect,
		onRemove,
		onSetInsertTarget,
		onSelectOption = () => {},
		selectedOptionKey = null,
		depth = 0,
		expandAll = false,
		onDropType = () => {},
		onDropField = () => {},
	} = $props();

	const TYPE_MIME = 'application/x-dg-field-type';
	const FIELD_MIME = 'application/x-dg-field-id';

	/**
	 * @param {DragEvent} event
	 * @param {import('../definitionModel.js').InsertTarget} target
	 * @param {string|null} [beforeId]
	 */
	function onDrop(event, target, beforeId = null) {
		event.preventDefault();
		event.stopPropagation();
		const type = event.dataTransfer?.getData(TYPE_MIME);
		const fieldId = event.dataTransfer?.getData(FIELD_MIME);
		if (type) onDropType(type, target, beforeId);
		else if (fieldId) onDropField(fieldId, target, beforeId);
	}

	/**
	 * @param {DragEvent} event
	 */
	function allowDrop(event) {
		event.preventDefault();
		if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
	}

	/**
	 * @param {DragEvent} event
	 * @param {string} fieldId
	 */
	function onFieldDragStart(event, fieldId) {
		if (!event.dataTransfer) return;
		event.dataTransfer.setData(FIELD_MIME, fieldId);
		event.dataTransfer.effectAllowed = 'move';
	}

	/**
	 * @param {import('../definitionModel.js').Field} f
	 */
	function rowHelp(f) {
		if (f.help) return f.help;
		if (f.type === 'score_file') return '.pdf · routes to Drive';
		if (f.type === 'recording_file') return '.mp3 · routes to Drive';
		if (f.type === 'bio_file') return '.pdf';
		if (f.type === 'branch') return 'Conditional path';
		if (f.type === 'group') return 'Section';
		if (f.type === 'static_html') return 'Applicant-facing note';
		return '';
	}

	/**
	 * @param {import('../definitionModel.js').InsertTarget} t
	 */
	function isInsertHere(t) {
		if (t.kind !== insertTarget.kind) return false;
		if (t.kind === 'root') return true;
		if (t.kind === 'group') return t.fieldId === insertTarget.fieldId;
		return (
			t.branchId === insertTarget.branchId && t.optionId === insertTarget.optionId
		);
	}

	/**
	 * @param {string} branchId
	 * @param {string} optionId
	 */
	function optionKey(branchId, optionId) {
		return `${branchId}::${optionId}`;
	}
</script>

<ul class="dg-field-list" class:dg-field-list--nested={depth > 0} data-dg-field-list={depth === 0 ? '' : undefined}>
	{#each fields as f (f.id)}
		<li
			class="dg-field-row"
			class:dg-field-row--selected={selectedId === f.id}
			class:dg-field-row--container={f.type === 'group' || f.type === 'branch'}
			data-field-id={f.id}
			draggable="true"
			ondragstart={(e) => onFieldDragStart(e, f.id)}
			ondragover={allowDrop}
			ondrop={(e) => onDrop(e, { kind: 'root' }, f.id)}
		>
			<span class="dg-drag-handle" aria-hidden="true">⋮⋮</span>
			<button
				type="button"
				class="dg-field-row-main"
				onclick={() => {
					onSelect(f.id);
					if (f.type === 'group') {
						onSetInsertTarget({ kind: 'group', fieldId: f.id });
					}
				}}
			>
				<span class="dg-field-row-label">{f.label || f.id}</span>
				{#if rowHelp(f)}
					<span class="dg-field-row-help">{rowHelp(f)}</span>
				{/if}
			</button>
			<span class="dg-field-type-pill">{pillForType(f.type)}</span>
			<button
				type="button"
				class="dg-btn dg-btn-ghost dg-btn-icon"
				aria-label={`Remove ${f.label}`}
				onclick={() => onRemove(f.id)}
			>
				×
			</button>
		</li>

		{#if f.type === 'group' && Array.isArray(f.children)}
			<li
				class="dg-field-nest"
				class:dg-field-nest--insert={isInsertHere({ kind: 'group', fieldId: f.id })}
				ondragover={allowDrop}
				ondrop={(e) => onDrop(e, { kind: 'group', fieldId: f.id })}
			>
				<button
					type="button"
					class="dg-insert-target"
					class:dg-insert-target--active={isInsertHere({ kind: 'group', fieldId: f.id })}
					onclick={() => onSetInsertTarget({ kind: 'group', fieldId: f.id })}
				>
					Drop fields here
				</button>
				{#if f.children.length > 0}
					<BuildFieldTree
						fields={f.children}
						{selectedId}
						{insertTarget}
						{onSelect}
						{onRemove}
						{onSetInsertTarget}
						{onSelectOption}
						{selectedOptionKey}
						{expandAll}
						{onDropType}
						{onDropField}
						depth={depth + 1}
					/>
				{/if}
			</li>
		{/if}

		{#if f.type === 'branch' && Array.isArray(f.options)}
			<li class="dg-field-nest dg-field-nest--branch">
				{#each f.options as opt (opt.id)}
					{@const target = /** @type {const} */ ({ kind: 'option', branchId: f.id, optionId: opt.id })}
					{@const key = optionKey(f.id, opt.id)}
					{@const open = isBranchOptionOpen(
						insertTarget,
						selectedOptionKey,
						f.id,
						opt.id,
						opt.children || [],
						selectedId,
						expandAll,
					)}
					{@const childCount = Array.isArray(opt.children) ? opt.children.length : 0}
					<div
						class="dg-branch-option-row"
						class:dg-branch-option-row--selected={selectedOptionKey === key}
						class:dg-branch-option-row--insert={isInsertHere(target)}
						class:dg-branch-option-row--collapsed={!open}
						data-dg-option={key}
						role="group"
						aria-label={`When ${opt.label || opt.id}`}
						ondragover={allowDrop}
						ondrop={(e) => onDrop(e, target)}
					>
						<button
							type="button"
							class="dg-branch-option-head"
							aria-expanded={open}
							onclick={() => {
								onSelect(f.id);
								onSelectOption(f.id, opt.id);
								onSetInsertTarget(target);
							}}
						>
							<span class="dg-field-row-label">When: {opt.label || opt.id}</span>
							<span class="dg-field-row-help">
								{open
									? `Option id: ${opt.id}`
									: childCount === 1
										? '1 field'
										: `${childCount} fields`}
							</span>
						</button>
						{#if open}
							{#if childCount > 0}
								<BuildFieldTree
									fields={opt.children}
									{selectedId}
									{insertTarget}
									{onSelect}
									{onRemove}
									{onSetInsertTarget}
									{onSelectOption}
									{selectedOptionKey}
									{expandAll}
									{onDropType}
									{onDropField}
									depth={depth + 1}
								/>
							{:else}
								<p class="dg-field-help">Drop a field here, or add one from the rail.</p>
							{/if}
						{/if}
					</div>
				{/each}
			</li>
		{/if}
	{/each}
</ul>
