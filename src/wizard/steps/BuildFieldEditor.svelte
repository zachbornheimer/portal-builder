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
	 * @property {() => void} [onAddFieldToOption]
	 * @property {(html: string) => void} [onSetHtml]
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
		onAddFieldToOption = () => {},
		onSetHtml = () => {},
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
	{#if field.type !== 'static_html'}
		<label class="dg-field-check">
			<input
				type="checkbox"
				checked={!!field.required}
				onchange={(e) => onSetRequired(e.currentTarget.checked)}
			/>
			Required
		</label>
	{/if}
	{#if field.type === 'static_html'}
		<label class="dg-field-label" for="dg-field-html-input">Note text</label>
		<textarea
			id="dg-field-html-input"
			class="dg-input"
			rows="4"
			value={field.html || ''}
			oninput={(e) => onSetHtml(e.currentTarget.value)}
		></textarea>
	{/if}
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
						View
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
				<button
					type="button"
					class="dg-btn dg-btn-ghost dg-btn-sm"
					disabled={!selectedOption}
					onclick={() => onAddFieldToOption()}
				>
					Add new field
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
			Adding to “{selectedOption.label}” — click a type below, or drag one onto the path.
		</p>
	{/if}
</div>
