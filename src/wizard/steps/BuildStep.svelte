<script>
	import {
		FIELD_TYPE_CATALOG,
		flattenMappableFields,
		newField,
		pillForType,
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

	let selectedId = $state(/** @type {string|null} */ (null));

	const fields = $derived(Array.isArray(definition?.fields) ? definition.fields : []);
	const flat = $derived(flattenMappableFields(fields));

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
		// Prefer appending into first group if adding leaf types
		if (
			type !== 'group' &&
			type !== 'applicant_pack' &&
			fields.some((f) => f.type === 'group')
		) {
			const next = fields.map((f) => {
				if (f.type !== 'group') return f;
				const children = Array.isArray(f.children) ? [...f.children, created] : [created];
				return { ...f, children };
			});
			setFields(next);
			selectedId = created.id;
			return;
		}
		setFields([...fields, created]);
		selectedId = created.id;
	}

	/**
	 * @param {string} id
	 */
	function removeField(id) {
		const strip = (list) =>
			list
				.filter((f) => f.id !== id)
				.map((f) =>
					f.children ? { ...f, children: strip(f.children) } : f
				);
		setFields(strip(fields));
		if (selectedId === id) selectedId = null;
	}

	/**
	 * @param {string} id
	 * @param {string} label
	 */
	function renameField(id, label) {
		const map = (list) =>
			list.map((f) => {
				if (f.id === id) return { ...f, label };
				if (f.children) return { ...f, children: map(f.children) };
				return f;
			});
		setFields(map(fields));
	}

	/**
	 * @param {import('../definitionModel.js').Field} f
	 */
	function rowHelp(f) {
		if (f.help) return f.help;
		if (f.type === 'score_file') return '.pdf · routes to Drive';
		if (f.type === 'recording_file') return '.mp3 · routes to Drive';
		if (f.type === 'bio_file') return '.pdf';
		return '';
	}
</script>

<p class="dg-wizard-eyebrow">{definition?.title || 'Portal'}</p>
<h2 id="dg-wizard-step-title" class="dg-wizard-title">Build the form</h2>
<p class="dg-wizard-lead">
	Add fields applicants fill out. Types like Score upload carry PDF accept rules automatically.
</p>

<div class="dg-build-layout">
	<div class="dg-build-canvas">
		{#if fields.length === 0}
			<div class="dg-empty-hint" role="status">
				No fields yet. Use a type below or go back to Start and pick a template.
			</div>
		{/if}

		<ul class="dg-field-list" data-dg-field-list>
			{#each flat as f (f.id)}
				<li
					class="dg-field-row"
					class:dg-field-row--selected={selectedId === f.id}
					data-field-id={f.id}
				>
					<button
						type="button"
						class="dg-field-row-main"
						onclick={() => (selectedId = f.id)}
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
						onclick={() => removeField(f.id)}
					>
						×
					</button>
				</li>
			{/each}
		</ul>

		{#if selectedId}
			{@const sel = flat.find((x) => x.id === selectedId)}
			{#if sel}
				<div class="dg-field-editor cardish">
					<label class="dg-field-label" for="dg-field-label-input">Label</label>
					<input
						id="dg-field-label-input"
						type="text"
						class="dg-input"
						value={sel.label}
						oninput={(e) => renameField(sel.id, e.currentTarget.value)}
					/>
					<p class="dg-field-help">Field id: <code class="dg-mono">{sel.id}</code> (stable for mapping)</p>
				</div>
			{/if}
		{/if}

		<div class="dg-type-palette">
			<span class="dg-field-label">Add a field</span>
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
			{#if flat.length === 0}
				<p class="dg-field-help">Fields appear here as you add them.</p>
			{:else}
				{#each flat.slice(0, 8) as f (f.id)}
					<span class="dg-field-label">
						{f.label}{#if f.required}<span class="dg-req">*</span>{/if}
					</span>
					{#if f.type === 'score_file' || f.type === 'recording_file' || f.type === 'bio_file' || f.type === 'file'}
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
