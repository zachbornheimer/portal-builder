<script>
	import { countLeafFields, flattenMappableFields } from '../definitionModel.js';

	/**
	 * @typedef {Object} Props
	 * @property {object} definition
	 * @property {(d: object) => void} onChange
	 * @property {() => void} onBack
	 * @property {() => Promise<void>} onSaveDraft
	 * @property {() => Promise<void>} onOpenGate
	 * @property {string} saveState
	 * @property {string} saveError
	 * @property {string} applicantStatus
	 * @property {string} publicUrl
	 * @property {string} [listUrl]
	 */

	/** @type {Props} */
	let {
		definition,
		onChange,
		onBack,
		onSaveDraft,
		onOpenGate,
		saveState,
		saveError,
		applicantStatus,
		publicUrl,
		listUrl = '',
	} = $props();

	let copyOk = $state(false);

	const fieldCount = $derived(countLeafFields(definition?.fields || []));
	const unmapped = $derived(
		flattenMappableFields(definition?.fields || []).filter((f) => {
			const dest = definition?.mapping?.fieldDest?.[f.id];
			return !dest;
		})
	);

	const publish = $derived(definition?.publish || {});
	const options = $derived(definition?.options || {});

	/**
	 * @param {string} key
	 * @param {unknown} value
	 */
	function setPublish(key, value) {
		onChange({
			...definition,
			publish: { ...publish, [key]: value },
		});
	}

	/**
	 * @param {string} key
	 * @param {unknown} value
	 */
	function setOption(key, value) {
		onChange({
			...definition,
			options: { ...options, [key]: value },
		});
	}

	/**
	 * @param {string} title
	 */
	function setTitle(title) {
		onChange({ ...definition, title });
	}

	function deadlineLocalValue() {
		const d = publish.deadline;
		if (!d || typeof d !== 'string') return '';
		return d.length >= 16 ? d.slice(0, 16) : d;
	}

	/**
	 * @param {Event & { currentTarget: HTMLInputElement }} e
	 */
	function onDeadline(e) {
		const v = e.currentTarget.value;
		setPublish('deadline', v ? `${v}:00` : null);
	}

	const canOpen = $derived(fieldCount > 0 && unmapped.length === 0);
	const saving = $derived(saveState === 'saving');

	async function copyLink() {
		if (!publicUrl) return;
		try {
			await navigator.clipboard.writeText(publicUrl);
			copyOk = true;
			window.setTimeout(() => {
				copyOk = false;
			}, 2000);
		} catch {
			window.prompt('Copy public URL:', publicUrl);
		}
	}
</script>

<p class="dg-wizard-eyebrow">{definition?.title || 'Portal'}</p>
<h2 id="dg-wizard-step-title" class="dg-wizard-title">Last details before this opens</h2>
<p class="dg-wizard-lead">
	Name the portal, set deadline and fee, then open the gate for applicants — or save a draft
	until you’re ready.
</p>

<div class="dg-status-banner" data-status={applicantStatus.toLowerCase()} role="status">
	<strong>Status for applicants:</strong>
	{applicantStatus}
	{#if applicantStatus === 'Draft'}
		— not visible as an open form.
	{:else if applicantStatus === 'Closed'}
		— not accepting submissions.
	{:else}
		— accepting submissions.
	{/if}
</div>

{#if fieldCount === 0}
	<p class="dg-wizard-alert" role="alert">Add fields in Build before opening this portal.</p>
{:else if unmapped.length > 0}
	<p class="dg-wizard-alert" role="alert">
		{unmapped.length} field{unmapped.length === 1 ? '' : 's'} still unmapped. Go back to Map
		data.
	</p>
{/if}

<div class="dg-publish-card cardish">
	<div class="dg-publish-block" style="margin-top: 0;">
		<label class="dg-field-label" for="dg-portal-name">Portal name</label>
		<input
			id="dg-portal-name"
			type="text"
			class="dg-input"
			value={definition?.title || ''}
			oninput={(e) => setTitle(e.currentTarget.value)}
			placeholder="e.g. Herbolzheimer Prize 2026"
		/>
		<p class="dg-field-help">Shown to applicants and in the portal list.</p>
	</div>
	<div class="dg-publish-grid">
		<div>
			<label class="dg-field-label" for="dg-deadline">Deadline</label>
			<input
				id="dg-deadline"
				type="datetime-local"
				class="dg-input"
				value={deadlineLocalValue()}
				oninput={onDeadline}
			/>
			<p class="dg-field-help">Leave empty for no deadline.</p>
		</div>
		<div>
			<label class="dg-field-label" for="dg-tz">Timezone</label>
			<input
				id="dg-tz"
				type="text"
				class="dg-input"
				value={publish.timezone || 'America/New_York'}
				oninput={(e) => setPublish('timezone', e.currentTarget.value)}
			/>
		</div>
	</div>
	<div class="dg-publish-block">
		<label class="dg-field-label" for="dg-fee">Application fee</label>
		<input
			id="dg-fee"
			type="text"
			class="dg-input"
			placeholder="Leave blank if free"
			value={publish.applicationFee ?? ''}
			oninput={(e) => {
				const v = e.currentTarget.value.trim();
				setPublish('applicationFee', v === '' ? null : v);
			}}
		/>
	</div>
	<label class="dg-check-row">
		<input
			type="checkbox"
			checked={Boolean(publish.forceClosed)}
			onchange={(e) => setPublish('forceClosed', e.currentTarget.checked)}
		/>
		<span>Force closed (no submissions even if published)</span>
	</label>
	<div class="dg-publish-block">
		<label class="dg-field-label" for="dg-guidelines">Guidelines URL</label>
		<input
			id="dg-guidelines"
			type="url"
			class="dg-input"
			placeholder="https://…"
			value={options.guidelinesUrl || ''}
			oninput={(e) => setOption('guidelinesUrl', e.currentTarget.value || null)}
		/>
	</div>
</div>

<div class="dg-publish-card cardish">
	<p class="dg-field-label" style="margin-bottom: 8px;">Ready checklist</p>
	<ul class="dg-checklist">
		<li data-ok={fieldCount > 0 ? 'true' : 'false'}>
			{fieldCount > 0 ? '✓' : '○'} {fieldCount} form field{fieldCount === 1 ? '' : 's'}
		</li>
		<li data-ok={unmapped.length === 0 && fieldCount > 0 ? 'true' : 'false'}>
			{unmapped.length === 0 && fieldCount > 0 ? '✓' : '○'} Mapping complete
		</li>
		<li data-ok={definition?.title ? 'true' : 'false'}>
			{definition?.title ? '✓' : '○'} Portal named
		</li>
	</ul>
</div>

{#if publicUrl}
	<div class="dg-publish-card cardish dg-public-link-card">
		<p class="dg-field-label">Public form</p>
		<p class="dg-mono dg-public-url">{publicUrl}</p>
		<div class="dg-link-actions">
			<button type="button" class="dg-btn dg-btn-ghost dg-btn-sm" onclick={copyLink}>
				{copyOk ? 'Copied ✓' : 'Copy link'}
			</button>
			<a class="dg-btn dg-btn-ghost dg-btn-sm" href={publicUrl} target="_blank" rel="noopener"
				>View public form</a
			>
		</div>
	</div>
{/if}

{#if saveError}
	<p class="dg-wizard-alert" role="alert">{saveError}</p>
{/if}

<div class="dg-wizard-actions dg-wizard-actions--spread">
	<button type="button" class="dg-btn dg-btn-ghost" onclick={onBack} disabled={saving}>
		← Back to mapping
	</button>
	<div class="dg-wizard-actions-right">
		<button
			type="button"
			class="dg-btn dg-btn-ghost"
			disabled={saving}
			onclick={() => onSaveDraft()}
			data-dg-save-draft
		>
			{saveState === 'saving' ? 'Saving…' : saveState === 'saved' ? 'Saved ✓' : 'Save draft'}
		</button>
		<button
			type="button"
			class="dg-btn dg-btn-primary"
			disabled={saving || !canOpen}
			onclick={() => onOpenGate()}
			data-dg-open-gate
		>
			{applicantStatus === 'Open' ? 'Save & keep open' : 'Open the gate'}
		</button>
	</div>
</div>

<p class="dg-wizard-footnote">
	“Open the gate” saves the form and publishes it for applicants (unless force closed).
	{#if saveState === 'saved'}
		· Changes saved.
	{/if}
</p>
