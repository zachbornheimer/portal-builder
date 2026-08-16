<script>
	import {
		BUILTIN_ANONYMIZE_ACK,
		BUILTIN_ANONYMIZE_ENDPOINT,
		DEFAULT_TIMEZONE,
		countLeafFields,
		dualWritePublish,
		emptyToNull,
		flattenMappableFields,
		normalizeAccess,
		previewUrl,
		todayAtMidnight,
	} from '../definitionModel.js';

	/**
	 * @typedef {Object} Props
	 * @property {object} definition
	 * @property {(d: object) => void} onChange
	 * @property {() => void} onBack
	 * @property {() => Promise<void>} onSaveDraft
	 * @property {() => Promise<void>} onSavePortal
	 * @property {string} saveState
	 * @property {string} saveError
	 * @property {string} applicantStatus
	 * @property {string} publicUrl
	 * @property {string} [listUrl]
	 * @property {{ membershipPlans?: {id: string, name: string}[], profileFields?: {key: string, label: string}[], roles?: {id: string, name: string}[] }} [accessCatalog]
	 * @property {object} [siteDefaults]
	 */

	/** @type {Props} */
	let {
		definition,
		onChange,
		onBack,
		onSaveDraft,
		onSavePortal,
		saveState,
		saveError,
		applicantStatus,
		publicUrl,
		listUrl = '',
		accessCatalog = { membershipPlans: [], profileFields: [], roles: [] },
		siteDefaults = {},
	} = $props();

	let copyOk = $state(false);

	const WORDPRESS_ROLES = [
		{ id: 'administrator', name: 'Administrator' },
		{ id: 'editor', name: 'Editor' },
		{ id: 'author', name: 'Author' },
		{ id: 'contributor', name: 'Contributor' },
		{ id: 'subscriber', name: 'Subscriber' },
	];

	const fieldCount = $derived(countLeafFields(definition?.fields || []));
	const unmapped = $derived(
		flattenMappableFields(definition?.fields || []).filter((f) => {
			const dest = definition?.mapping?.fieldDest?.[f.id];
			return !dest;
		})
	);

	const publish = $derived(definition?.publish || {});
	const options = $derived(definition?.options || {});
	const access = $derived(normalizeAccess(definition?.access));
	const membershipPlans = $derived(
		Array.isArray(accessCatalog?.membershipPlans) ? accessCatalog.membershipPlans : [],
	);
	const profileFields = $derived(
		Array.isArray(accessCatalog?.profileFields) ? accessCatalog.profileFields : [],
	);
	const siteRoles = $derived(
		Array.isArray(accessCatalog?.roles) && accessCatalog.roles.length > 0
			? accessCatalog.roles
			: WORDPRESS_ROLES,
	);

	/**
	 * @param {unknown} portalValue
	 * @param {unknown} siteValue
	 */
	function effectiveBool(portalValue, siteValue) {
		if (portalValue === true || portalValue === false) return portalValue;
		return Boolean(siteValue);
	}

	/**
	 * @param {unknown} portalValue
	 * @param {boolean} siteOn
	 */
	function inheritCaption(portalValue, siteOn) {
		if (portalValue === true || portalValue === false) return '';
		return siteOn ? 'Using site default (on)' : 'Using site default (off)';
	}

	const anonymizeOn = $derived(effectiveBool(options.anonymize, siteDefaults.anonymize));
	const failClosedOn = $derived(
		effectiveBool(options.anonymizeFailClosed, siteDefaults.anonymizeFailClosed),
	);
	const freeForMembersOn = $derived(
		effectiveBool(options.freeForMembers, siteDefaults.freeForMembers),
	);
	const endpointPlaceholder = $derived(
		emptyToNull(siteDefaults.anonymizeEndpoint) || BUILTIN_ANONYMIZE_ENDPOINT,
	);
	const ackPlaceholder = $derived(
		emptyToNull(siteDefaults.anonymizeAck) || BUILTIN_ANONYMIZE_ACK,
	);
	const timezonePlaceholder = $derived(
		emptyToNull(siteDefaults.timezone) || DEFAULT_TIMEZONE,
	);
	const keyPlaceholder = $derived(
		options.anonymizeApiKey
			? ''
			: siteDefaults.anonymizeApiKeySet
				? `Using site default (${siteDefaults.anonymizeApiKeyHint || 'saved'})`
				: 'Using site default, then none',
	);

	/**
	 * @param {string} key
	 * @param {unknown} value
	 */
	function setPublish(key, value) {
		onChange({
			...definition,
			publish: dualWritePublish({ ...publish, [key]: value }),
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

	/**
	 * @param {object} patch
	 */
	function setAccess(patch) {
		onChange({
			...definition,
			access: normalizeAccess({ ...access, ...patch }),
		});
	}

	/**
	 * @param {string} planId
	 * @param {boolean} on
	 * @param {'access' | 'fee'} which
	 */
	function togglePlan(planId, on, which) {
		const key = which === 'fee' ? 'freeMembershipPlanIds' : 'membershipPlanIds';
		const current =
			which === 'fee'
				? Array.isArray(options.freeMembershipPlanIds)
					? options.freeMembershipPlanIds.map(String)
					: []
				: access.membershipPlanIds;
		const next = on
			? Array.from(new Set([...current, planId]))
			: current.filter((id) => id !== planId);
		if (which === 'fee') {
			setOption('freeMembershipPlanIds', next);
			return;
		}
		setAccess({ membershipPlanIds: next });
	}

	/**
	 * @param {string} roleId
	 * @param {boolean} on
	 */
	function toggleRole(roleId, on) {
		const current = access.roles;
		const next = on
			? Array.from(new Set([...current, roleId]))
			: current.filter((id) => id !== roleId);
		setAccess({ roles: next });
	}

	function addProfileRule() {
		const first = profileFields[0]?.key || 'COUNTRY';
		setAccess({
			profileRules: [...access.profileRules, { key: first, op: 'eq', value: '' }],
		});
	}

	/**
	 * @param {number} index
	 * @param {object} patch
	 */
	function patchProfileRule(index, patch) {
		setAccess({
			profileRules: access.profileRules.map((rule, i) =>
				i === index ? { ...rule, ...patch } : rule,
			),
		});
	}

	/**
	 * @param {number} index
	 */
	function removeProfileRule(index) {
		setAccess({
			profileRules: access.profileRules.filter((_, i) => i !== index),
		});
	}

	function deadlineLocalValue() {
		const d = publish.deadline;
		if (!d || typeof d !== 'string') return '';
		return d.length >= 16 ? d.slice(0, 16) : d;
	}

	function launchLocalValue() {
		const d = publish.launchAt;
		if (d && typeof d === 'string') {
			return d.length >= 16 ? d.slice(0, 16) : d;
		}
		return todayAtMidnight(publish.timezone || timezonePlaceholder).slice(0, 16);
	}

	/**
	 * @param {Event & { currentTarget: HTMLInputElement }} e
	 */
	function onDeadline(e) {
		const v = e.currentTarget.value;
		setPublish('deadline', v ? `${v}:00` : null);
	}

	/**
	 * @param {Event & { currentTarget: HTMLInputElement }} e
	 */
	function onLaunch(e) {
		const v = e.currentTarget.value;
		setPublish('launchAt', v ? `${v}:00` : null);
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
	Name the portal, set a launch date and deadline, then save. Applicants can start on the launch
	date while the portal is accepting submissions.
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
	<p class="dg-wizard-alert" role="alert">Add fields in Build before saving this portal.</p>
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
			<label class="dg-field-label" for="dg-launch">Launch date</label>
			<input
				id="dg-launch"
				type="datetime-local"
				class="dg-input"
				value={launchLocalValue()}
				oninput={onLaunch}
			/>
			<p class="dg-field-help">When applicants can start. Blank becomes today when you save.</p>
		</div>
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
	</div>
	<div class="dg-publish-block">
		<label class="dg-field-label" for="dg-tz">Timezone</label>
		<input
			id="dg-tz"
			type="text"
			class="dg-input"
			value={publish.timezone || ''}
			placeholder={timezonePlaceholder}
			oninput={(e) => setPublish('timezone', emptyToNull(e.currentTarget.value))}
		/>
		{#if !publish.timezone}
			<p class="dg-field-help">Using site default ({timezonePlaceholder})</p>
		{/if}
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
		<p class="dg-field-help">
			Shown as a label on the public form. Not charged in this form. Hosts may
			collect this outside DragonGate.
		</p>
		<label class="dg-check-row">
			<input
				type="checkbox"
				checked={freeForMembersOn}
				onchange={(e) => setOption('freeForMembers', e.currentTarget.checked)}
			/>
			<span>Free for members</span>
		</label>
		{#if inheritCaption(options.freeForMembers, Boolean(siteDefaults.freeForMembers))}
			<p class="dg-field-help">{inheritCaption(options.freeForMembers, Boolean(siteDefaults.freeForMembers))}</p>
		{:else}
			<button
				type="button"
				class="dg-btn dg-btn-ghost dg-btn-sm"
				onclick={() => setOption('freeForMembers', null)}
			>
				Use site default
			</button>
		{/if}
		{#if freeForMembersOn && membershipPlans.length > 0}
			<p class="dg-field-help">Leave every plan unchecked to waive the fee for any active membership.</p>
			<div class="dg-plan-list">
				{#each membershipPlans as plan (plan.id)}
					<label class="dg-check-row">
						<input
							type="checkbox"
							checked={ (options.freeMembershipPlanIds || []).map(String).includes(String(plan.id)) }
							onchange={(e) => togglePlan(String(plan.id), e.currentTarget.checked, 'fee')}
						/>
						<span>{plan.name}</span>
					</label>
				{/each}
			</div>
		{/if}
	</div>
	<div class="dg-publish-block">
		<label class="dg-check-row">
			<input
				type="checkbox"
				checked={anonymizeOn}
				onchange={(e) => setOption('anonymize', e.currentTarget.checked)}
			/>
			<span>Anonymize files for adjudicators</span>
		</label>
		{#if inheritCaption(options.anonymize, Boolean(siteDefaults.anonymize))}
			<p class="dg-field-help">{inheritCaption(options.anonymize, Boolean(siteDefaults.anonymize))}</p>
		{:else}
			<button
				type="button"
				class="dg-btn dg-btn-ghost dg-btn-sm"
				onclick={() => setOption('anonymize', null)}
			>
				Use site default
			</button>
		{/if}
		{#if anonymizeOn}
			<div class="dg-publish-grid">
				<div>
					<label class="dg-field-label" for="dg-anonymize-endpoint">Anonymize API URL</label>
					<input
						id="dg-anonymize-endpoint"
						type="url"
						class="dg-input"
						placeholder={endpointPlaceholder}
						value={options.anonymizeEndpoint || ''}
						oninput={(e) => setOption('anonymizeEndpoint', emptyToNull(e.currentTarget.value))}
					/>
				</div>
				<div>
					<label class="dg-field-label" for="dg-anonymize-key">API key</label>
					<input
						id="dg-anonymize-key"
						type="password"
						class="dg-input"
						autocomplete="off"
						placeholder={keyPlaceholder}
						value={options.anonymizeApiKey || ''}
						oninput={(e) => setOption('anonymizeApiKey', emptyToNull(e.currentTarget.value))}
					/>
				</div>
			</div>
			<label class="dg-check-row">
				<input
					type="checkbox"
					checked={failClosedOn}
					onchange={(e) => setOption('anonymizeFailClosed', e.currentTarget.checked)}
				/>
				<span>Do not store the original if anonymize fails</span>
			</label>
			{#if inheritCaption(options.anonymizeFailClosed, Boolean(siteDefaults.anonymizeFailClosed))}
				<p class="dg-field-help">{inheritCaption(options.anonymizeFailClosed, Boolean(siteDefaults.anonymizeFailClosed))}</p>
			{:else}
				<button
					type="button"
					class="dg-btn dg-btn-ghost dg-btn-sm"
					onclick={() => setOption('anonymizeFailClosed', null)}
				>
					Use site default
				</button>
			{/if}
			<div>
				<label class="dg-field-label" for="dg-anonymize-ack">Anonymize certification</label>
				<textarea
					id="dg-anonymize-ack"
					class="dg-input"
					rows="3"
					placeholder={ackPlaceholder}
					value={options.anonymizeAck || ''}
					oninput={(e) => setOption('anonymizeAck', emptyToNull(e.currentTarget.value))}
				></textarea>
				{#if !options.anonymizeAck}
					<p class="dg-field-help">Using site default</p>
				{:else}
					<button
						type="button"
						class="dg-btn dg-btn-ghost dg-btn-sm"
						onclick={() => setOption('anonymizeAck', null)}
					>
						Use site default
					</button>
				{/if}
			</div>
			<p class="dg-anonymize-help dg-field-help">
				A custom URL is allowed. Blank URL uses the site default, then All Intersections
				({BUILTIN_ANONYMIZE_ENDPOINT}). If the call fails we keep the original unless fail
				closed is on. The key is never shown on the public form. Certification text appears
				as a required checkbox on the public form. An empty key blocks submit.
			</p>
		{/if}
	</div>
	<div class="dg-publish-block">
		<p class="dg-field-label">Who can apply</p>
		<label class="dg-check-row">
			<input
				type="radio"
				name="dg-access-audience"
				checked={access.audience === 'anyone'}
				onchange={() => setAccess({ audience: 'anyone' })}
			/>
			<span>Anyone</span>
		</label>
		<label class="dg-check-row">
			<input
				type="radio"
				name="dg-access-audience"
				checked={access.audience === 'logged_in'}
				onchange={() => setAccess({ audience: 'logged_in' })}
			/>
			<span>Signed-in users</span>
		</label>
		<label class="dg-check-row">
			<input
				type="radio"
				name="dg-access-audience"
				checked={access.audience === 'members'}
				onchange={() => setAccess({ audience: 'members' })}
			/>
			<span>{membershipPlans.length > 0 ? 'Members only' : 'Specific WordPress role'}</span>
		</label>
		{#if access.audience === 'members' && membershipPlans.length > 0}
			<p class="dg-field-help">Leave every plan unchecked to allow any active membership.</p>
			<div class="dg-plan-list">
				{#each membershipPlans as plan (plan.id)}
					<label class="dg-check-row">
						<input
							type="checkbox"
							checked={access.membershipPlanIds.includes(String(plan.id))}
							onchange={(e) => togglePlan(String(plan.id), e.currentTarget.checked, 'access')}
						/>
						<span>{plan.name}</span>
					</label>
				{/each}
			</div>
		{:else if access.audience === 'members'}
			<p class="dg-field-help">
				Restrict to a WordPress role. Leave every role unchecked to allow any signed-in user.
			</p>
			<div class="dg-plan-list">
				{#each siteRoles as role (role.id)}
					<label class="dg-check-row">
						<input
							type="checkbox"
							checked={access.roles.includes(String(role.id))}
							onchange={(e) => toggleRole(String(role.id), e.currentTarget.checked)}
						/>
						<span>{role.name}</span>
					</label>
				{/each}
			</div>
		{/if}
	</div>
	<div class="dg-publish-block">
		<p class="dg-field-label">Profile requirements</p>
		<p class="dg-field-help">Optional. Limit by country, institution, occupation, age, or any profile field.</p>
		{#each access.profileRules as rule, i (i)}
			<div class="dg-profile-rule">
				<select
					class="dg-input"
					aria-label="Profile field"
					value={rule.key}
					onchange={(e) => patchProfileRule(i, { key: e.currentTarget.value })}
				>
					{#each profileFields as field (field.key)}
						<option value={field.key}>{field.label}</option>
					{/each}
					{#if !profileFields.some((f) => f.key === rule.key)}
						<option value={rule.key}>{rule.key}</option>
					{/if}
					<option value="age">Age</option>
				</select>
				<select
					class="dg-input"
					aria-label="Comparison"
					value={rule.op}
					onchange={(e) => patchProfileRule(i, { op: e.currentTarget.value })}
				>
					<option value="eq">is</option>
					<option value="neq">is not</option>
					<option value="contains">contains</option>
					<option value="in">is one of</option>
					<option value="gte">at least</option>
					<option value="lte">at most</option>
				</select>
				<input
					type="text"
					class="dg-input"
					aria-label="Value"
					placeholder={rule.op === 'in' ? 'Comma-separated values' : 'Value'}
					value={rule.value}
					oninput={(e) => patchProfileRule(i, { value: e.currentTarget.value })}
				/>
				<button
					type="button"
					class="dg-btn dg-btn-ghost dg-btn-sm"
					onclick={() => removeProfileRule(i)}
				>
					Remove
				</button>
			</div>
		{/each}
		<button type="button" class="dg-btn dg-btn-ghost dg-btn-sm" onclick={addProfileRule}>
			Add profile rule
		</button>
		<label class="dg-field-label" for="dg-access-deny">Denied message</label>
		<input
			id="dg-access-deny"
			type="text"
			class="dg-input"
			placeholder="Leave blank for a default message"
			value={access.denyMessage}
			oninput={(e) => setAccess({ denyMessage: e.currentTarget.value })}
		/>
	</div>
	<div class="dg-publish-block">
		<label class="dg-field-label" for="dg-guidelines">Guidelines URL</label>
		<input
			id="dg-guidelines"
			type="url"
			class="dg-input"
			placeholder={emptyToNull(siteDefaults.guidelinesUrl) || 'https://…'}
			value={options.guidelinesUrl || ''}
			oninput={(e) => setOption('guidelinesUrl', emptyToNull(e.currentTarget.value))}
		/>
		{#if !options.guidelinesUrl && siteDefaults.guidelinesUrl}
			<p class="dg-field-help">Using site default</p>
		{/if}
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
			<a
				class="dg-btn dg-btn-ghost dg-btn-sm"
				href={previewUrl(publicUrl)}
				target="_blank"
				rel="noopener"
				>Preview</a
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
			onclick={() => onSavePortal()}
			data-dg-save-portal
		>
			{saveState === 'saving' ? 'Saving…' : 'Save Portal'}
		</button>
	</div>
</div>

<p class="dg-wizard-footnote">
	Save Portal stores the form. If it is accepting submissions, it is also published for applicants.
	{#if saveState === 'saved'}
		· Changes saved.
	{/if}
</p>
