<script>
	import {
		accessSummary,
		countLeafFields,
		dualWritePublish,
		mappingSummaryNames,
		namesForIds,
	} from '../definitionModel.js';

	/**
	 * @typedef {Object} Props
	 * @property {object} definition
	 * @property {{ membershipPlans?: {id: string, name: string}[], roles?: {id: string, name: string}[] }} [accessCatalog]
	 * @property {object} [siteDefaults]
	 * @property {string} applicantStatus
	 * @property {boolean} accepting
	 * @property {string} publicUrl
	 * @property {(stepId: string) => void} onEdit
	 */

	/** @type {Props} */
	let {
		definition,
		accessCatalog = { membershipPlans: [], roles: [] },
		siteDefaults = {},
		applicantStatus,
		accepting,
		publicUrl,
		onEdit,
	} = $props();

	const fieldCount = $derived(countLeafFields(definition?.fields || []));
	const publish = $derived(dualWritePublish(definition?.publish));
	const who = $derived(accessSummary(definition?.access, accessCatalog));
	const destinations = $derived(mappingSummaryNames(definition?.mapping));
	const viewAsNames = $derived(effectiveViewAsNames(definition?.access, accessCatalog, siteDefaults));

	/**
	 * @param {unknown} access
	 * @param {{ roles?: {id: string, name: string}[] }} catalog
	 * @param {object} defaults
	 */
	function effectiveViewAsNames(access, catalog, defaults) {
		const override = Array.isArray(access?.viewAsRoles) ? access.viewAsRoles : [];
		const fallback = Array.isArray(defaults?.viewAsRoles) && defaults.viewAsRoles.length > 0
			? defaults.viewAsRoles
			: ['administrator'];
		const ids = override.length > 0 ? override : fallback;
		const roles = Array.isArray(catalog?.roles) ? catalog.roles : [];
		const names = namesForIds(ids, roles);
		const source = override.length > 0 ? 'This portal' : 'Default Settings';
		return { source, names: names.join(', ') || 'Administrator' };
	}

	/**
	 * @param {unknown} value
	 */
	function when(value) {
		if (!value || typeof value !== 'string') return 'Not set';
		const t = Date.parse(value);
		if (Number.isNaN(t)) return value;
		return new Date(t).toLocaleString(undefined, {
			dateStyle: 'medium',
			timeStyle: 'short',
		});
	}
</script>

<div class="dg-overview" data-dg-overview>
	<p class="dg-overview-lead">
		{applicantStatus}. {accepting ? 'Accepting submissions.' : 'Not accepting submissions.'}
		{fieldCount} form field{fieldCount === 1 ? '' : 's'}.
	</p>

	<section class="dg-overview-card cardish">
		<div class="dg-overview-card-head">
			<h2>Who can submit</h2>
			<button type="button" class="dg-overview-change" onclick={() => onEdit('publish')}>Change</button>
		</div>
		<p class="dg-overview-fact" data-dg-who-can-submit>{who.headline}</p>
		{#if definition?.access?.denyMessage}
			<p class="dg-field-help">{definition.access.denyMessage}</p>
		{/if}
	</section>

	<section class="dg-overview-card cardish">
		<div class="dg-overview-card-head">
			<h2>Schedule</h2>
			<button type="button" class="dg-overview-change" onclick={() => onEdit('publish')}>Change</button>
		</div>
		<dl class="dg-overview-rows">
			<div>
				<dt>Shown open</dt>
				<dd>{when(publish.launchDisplay) === 'Not set' ? 'Same as form open' : when(publish.launchDisplay)}</dd>
			</div>
			<div>
				<dt>Form opens</dt>
				<dd>{when(publish.launchAt)}</dd>
			</div>
			<div>
				<dt>Shown close</dt>
				<dd>{when(publish.deadlineDisplay) === 'Not set' ? 'Same as form close' : when(publish.deadlineDisplay)}</dd>
			</div>
			<div>
				<dt>Form closes</dt>
				<dd>{when(publish.deadline)}</dd>
			</div>
			<div>
				<dt>Timezone</dt>
				<dd>{publish.timezone || 'Not set'}</dd>
			</div>
		</dl>
	</section>

	<section class="dg-overview-card cardish">
		<div class="dg-overview-card-head">
			<h2>Form</h2>
			<button type="button" class="dg-overview-change" onclick={() => onEdit('build')}>Change</button>
		</div>
		<p class="dg-overview-fact">
			{fieldCount > 0 ? `${fieldCount} fields on the public form.` : 'No fields yet.'}
		</p>
		{#if fieldCount === 0}
			<button type="button" class="dg-btn dg-btn-primary" onclick={() => onEdit('start')}>
				Choose a template
			</button>
		{/if}
	</section>

	<section class="dg-overview-card cardish">
		<div class="dg-overview-card-head">
			<h2>Where submissions go</h2>
			<button type="button" class="dg-overview-change" onclick={() => onEdit('map')}>Change</button>
		</div>
		<dl class="dg-overview-rows">
			<div>
				<dt>Sheets</dt>
				<dd>{destinations.sheets}</dd>
			</div>
			<div>
				<dt>Drive</dt>
				<dd>{destinations.drive}</dd>
			</div>
		</dl>
	</section>

	<section class="dg-overview-card cardish">
		<div class="dg-overview-card-head">
			<h2>Who may use View Portal as</h2>
			<button type="button" class="dg-overview-change" onclick={() => onEdit('publish')}>Change</button>
		</div>
		<p class="dg-overview-fact">{viewAsNames.source}: {viewAsNames.names}.</p>
	</section>

	{#if publicUrl}
		<section class="dg-overview-card cardish">
			<div class="dg-overview-card-head">
				<h2>Public form</h2>
			</div>
			<p class="dg-mono dg-public-url">{publicUrl}</p>
			<div class="dg-link-actions">
				<a class="dg-btn dg-btn-ghost dg-btn-sm" href={publicUrl} target="_blank" rel="noopener">
					View public form
				</a>
			</div>
		</section>
	{/if}
</div>
