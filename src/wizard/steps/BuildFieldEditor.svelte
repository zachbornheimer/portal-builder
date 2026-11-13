<script>
	/**
	 * Selected-field inspector for Build (label, required, branch options).
	 * @typedef {Object} Props
	 * @property {import('../definitionModel.js').Field} field
	 * @property {import('../definitionModel.js').BranchOption|null} [selectedOption]
	 * @property {import('../definitionModel.js').InsertTarget} insertTarget
	 * @property {(label: string) => void} onRename
	 * @property {(required: boolean) => void} onSetRequired
	 * @property {(optionId: string, label: string) => void} onRenameOption
	 * @property {(optionId: string) => void} onFocusOption
	 * @property {(optionId: string) => void} onRemoveOption
	 * @property {() => void} onAddOption
	 * @property {() => void} onAddNestedBranch
	 */

	/** @type {Props} */
	let {
		field,
		selectedOption = null,
		insertTarget,
		onRename,
		onSetRequired,
		onRenameOption,
		onFocusOption,
		onRemoveOption,
		onAddOption,
		onAddNestedBranch,
	} = $props();
</script>

<div class="dg-field-editor cardish">
	<label class="dg-field-label" for="dg-field-label-input">Label</label>
	<input
		id="dg-field-label-input"
		type="text"
		class="dg-input"
		value={field.label}
		oninput={(e) => onRename(e.currentTarget.value)}
	/>
	<label class="dg-field-check">
		<input
			type="checkbox"
			checked={!!field.required}
			onchange={(e) => onSetRequired(e.currentTarget.checked)}
		/>
		Required
	</label>
	<p class="dg-field-help">
		Field id: <code class="dg-mono">{field.id}</code> (stable for mapping)
	</p>

	{#if field.type === 'branch'}
		<div class="dg-branch-editor">
			<p class="dg-field-label">Options</p>
			{#each field.options || [] as opt (opt.id)}
				<div class="dg-branch-editor-option">
					<input
						type="text"
						class="dg-input"
						value={opt.label}
						aria-label={`Option label ${opt.id}`}
						oninput={(e) => onRenameOption(opt.id, e.currentTarget.value)}
					/>
					<button
						type="button"
						class="dg-btn dg-btn-ghost dg-btn-sm"
						onclick={() => onFocusOption(opt.id)}
					>
						Add field here
					</button>
					<button
						type="button"
						class="dg-btn dg-btn-ghost dg-btn-sm"
						disabled={(field.options || []).length <= 2}
						onclick={() => onRemoveOption(opt.id)}
					>
						Remove
					</button>
				</div>
			{/each}
			<div class="dg-branch-editor-actions">
				<button type="button" class="dg-btn dg-btn-ghost dg-btn-sm" onclick={onAddOption}>
					Add option
				</button>
				{#if insertTarget.kind === 'option' && insertTarget.branchId === field.id}
					<button
						type="button"
						class="dg-btn dg-btn-ghost dg-btn-sm"
						onclick={onAddNestedBranch}
					>
						Add nested branch
					</button>
				{/if}
			</div>
		</div>
	{/if}

	{#if selectedOption}
		<p class="dg-field-help">
			Editing option “{selectedOption.label}” — new fields go under this path.
		</p>
	{/if}
</div>
