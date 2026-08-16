<script>
	import GateNav from './GateNav.svelte';
	import { WIZARD_STEPS } from './steps.js';
	import {
		applicantFacingStatus,
		applyLaunchDefault,
		blankDefinition,
		DEFAULT_TIMEZONE,
		countLeafFields,
		dualWritePublish,
		flattenMappableFields,
		normalizeLoaded,
		planDefinitionLoad,
	} from './definitionModel.js';
	import { getDefinition, saveDefinition, updatePortalPost } from './definitionApi.js';
	import StartStep from './steps/StartStep.svelte';
	import BuildStep from './steps/BuildStep.svelte';
	import MapStep from './steps/MapStep.svelte';
	import PublishStep from './steps/PublishStep.svelte';

	/**
	 * @typedef {Object} Props
	 * @property {number} [portalId]
	 * @property {string} [restRoot]
	 * @property {string} [restNonce]
	 * @property {string} [wpRestRoot]
	 * @property {string} [portalTitle]
	 * @property {string} [publicUrl]
	 * @property {string} [postStatus]
	 * @property {boolean} [productMode]
	 * @property {string} [listUrl]
	 * @property {string} [logoUrl]
	 * @property {unknown} [definitionSeed]
	 * @property {{ membershipPlans?: {id: string, name: string}[], profileFields?: {key: string, label: string}[], roles?: {id: string, name: string}[] }} [accessCatalog]
	 * @property {object} [siteDefaults]
	 */

	/** @type {Props} */
	let {
		portalId = 0,
		restRoot = '',
		restNonce = '',
		wpRestRoot = '',
		portalTitle = '',
		publicUrl = '',
		postStatus = 'draft',
		productMode = false,
		listUrl = '',
		logoUrl = '',
		definitionSeed,
		accessCatalog = { membershipPlans: [], profileFields: [] },
		siteDefaults = {},
	} = $props();

	const titleFallback =
		portalTitle === 'Auto Draft' || !portalTitle ? 'New portal' : portalTitle;
	const loadPlan = planDefinitionLoad(definitionSeed);

	let currentStepId = $state(
		loadPlan.mode === 'hydrate' && countLeafFields(loadPlan.definition?.fields || []) > 0
			? 'build'
			: 'start'
	);
	let definition = $state(
		loadPlan.mode === 'hydrate'
			? normalizeLoaded(loadPlan.definition, titleFallback)
			: blankDefinition(titleFallback)
	);
	let loadState = $state(
		/** @type {'idle'|'loading'|'ready'|'error'} */ (
			loadPlan.mode === 'fetch' && portalId && restRoot && restNonce ? 'loading' : 'ready'
		)
	);
	let loadError = $state('');
	let saveState = $state(/** @type {'idle'|'saving'|'saved'|'error'} */ ('idle'));
	let saveError = $state('');
	let started = $state(
		loadPlan.mode === 'hydrate' && countLeafFields(loadPlan.definition?.fields || []) > 0
	);
	let liveStatus = $state(postStatus || 'draft');
	let livePublicUrl = $state(publicUrl || '');

	const api = $derived({
		restRoot,
		nonce: restNonce,
		portalId,
		wpRestRoot: wpRestRoot || '/wp-json/',
	});

	const applicantStatus = $derived(applicantFacingStatus(liveStatus, definition.publish));
	const accepting = $derived(dualWritePublish(definition.publish).enabled);

	const stepSubtitles = $derived({
		start: started || countLeafFields(definition.fields) > 0 ? 'chosen' : 'template',
		build: `${countLeafFields(definition.fields)} fields`,
		map: mapSubtitle(definition),
		publish: applicantStatus.toLowerCase(),
	});

	$effect(() => {
		if (typeof window === 'undefined' || !portalId) return;
		const url = new URL(window.location.href);
		if (url.searchParams.get('portal_id')) return;
		url.searchParams.set('portal_id', String(portalId));
		window.history.replaceState({}, '', url);
	});

	$effect(() => {
		if (loadPlan.mode !== 'fetch') {
			return;
		}
		if (!portalId || !restRoot || !restNonce) {
			loadState = 'ready';
			return;
		}
		let cancelled = false;
		loadState = 'loading';
		const timeout = window.setTimeout(() => {
			if (cancelled || loadState !== 'loading') return;
			definition = blankDefinition(titleFallback);
			loadState = 'ready';
		}, 8000);
		(async () => {
			try {
				const existing = await getDefinition({
					restRoot,
					nonce: restNonce,
					portalId,
				});
				if (cancelled) return;
				if (existing && typeof existing === 'object' && Array.isArray(existing.fields)) {
					definition = normalizeLoaded(existing, titleFallback);
					started = countLeafFields(definition.fields) > 0;
					if (started) currentStepId = 'build';
				} else {
					definition = blankDefinition(titleFallback);
				}
				loadState = 'ready';
			} catch (e) {
				if (cancelled) return;
				loadError = '';
				definition = blankDefinition(titleFallback);
				loadState = 'ready';
				console.warn('definition load', e);
			} finally {
				window.clearTimeout(timeout);
			}
		})();
		return () => {
			cancelled = true;
			window.clearTimeout(timeout);
		};
	});

	/**
	 * @param {object} def
	 */
	function mapSubtitle(def) {
		const n = countLeafFields(def.fields || []);
		if (n === 0) return 'not started';
		const dest = def.mapping?.fieldDest || {};
		const fields = flattenMappableFields(def.fields || []);
		const unmapped = fields.filter((f) => !dest[f.id]).length;
		if (unmapped > 0) return `${unmapped} unmapped`;
		return 'all mapped';
	}

	/**
	 * @param {string} stepId
	 */
	function selectStep(stepId) {
		currentStepId = stepId;
	}

	/**
	 * @param {object} next
	 */
	function applyDefinition(next) {
		definition = normalizeLoaded(next, portalTitle || 'New portal');
		started = true;
	}

	/**
	 * @param {object} next
	 */
	function changeDefinition(next) {
		definition = next;
		started = countLeafFields(next.fields || []) > 0;
		saveState = 'idle';
	}

	/**
	 * @param {{ status?: string }} [opts]
	 */
	async function persist(opts = {}) {
		if (!portalId || !restRoot || !restNonce) {
			saveError = 'Missing portal id or REST credentials.';
			saveState = 'error';
			return;
		}
		saveState = 'saving';
		saveError = '';
		try {
			const inheritedTimezone =
				typeof siteDefaults?.timezone === 'string' && siteDefaults.timezone
					? siteDefaults.timezone
					: DEFAULT_TIMEZONE;
			const toSave = {
				...definition,
				publish: applyLaunchDefault(definition.publish, new Date(), inheritedTimezone),
			};
			definition = toSave;
			const postUpdate = {
				wpRestRoot: wpRestRoot || '/wp-json/',
				nonce: restNonce,
				portalId,
				title: toSave.title,
			};
			if (opts.status) {
				postUpdate.status = opts.status;
			}
			const [saved, post] = await Promise.all([
				saveDefinition({
					restRoot,
					nonce: restNonce,
					portalId,
					definition: toSave,
				}),
				updatePortalPost(postUpdate),
			]);
			if (saved) {
				definition = normalizeLoaded(saved, definition.title);
			}
			if (post?.status) liveStatus = post.status;
			if (post?.link) livePublicUrl = post.link;
			saveState = 'saved';
			window.setTimeout(() => {
				if (saveState === 'saved') saveState = 'idle';
			}, 2500);
		} catch (e) {
			saveError = e instanceof Error ? e.message : 'Save failed';
			saveState = 'error';
		}
	}

	async function savePortal() {
		const enabled = dualWritePublish(definition.publish).enabled;
		if (enabled) {
			await persist({ status: 'publish' });
			return;
		}
		await persist();
	}

	async function saveDraft() {
		await persist({ status: 'draft' });
	}

	async function toggleAccepting() {
		const nextEnabled = !dualWritePublish(definition.publish).enabled;
		definition = {
			...definition,
			publish: dualWritePublish({
				...definition.publish,
				enabled: nextEnabled,
			}),
		};
		await persist();
	}

	function go(stepId) {
		currentStepId = stepId;
	}
</script>

<div
	class="dg-wizard"
	class:dg-wizard--product={productMode}
	data-dg-wizard
	data-load-state={loadState}
	data-product-mode={productMode ? '1' : '0'}
>
	<!-- Real page header (full bleed of content column) — not a card chrome strip -->
	<header class="dg-wizard-header" role="banner">
		<nav class="dg-wizard-header-nav" aria-label="Portal setup">
			{#if listUrl}
				<a class="dg-wizard-nav-back" href={listUrl}>← All portals</a>
			{/if}
			<div class="dg-wizard-header-brand">
				{#if logoUrl}
					<img
						class="dg-wizard-brand-logo"
						src={logoUrl}
						alt=""
						width="40"
						height="40"
						decoding="async"
					/>
				{/if}
				<span class="dg-wizard-brand-name">DragonGate</span>
			</div>
			<span class="dg-wizard-header-title">
				{definition.title || 'New portal'}
			</span>
			<GateNav {currentStepId} onSelect={selectStep} subtitles={stepSubtitles} />
			<div class="dg-wizard-header-meta">
				<button
					type="button"
					class="dg-accepting-switch"
					role="switch"
					aria-checked={accepting ? 'true' : 'false'}
					aria-label={accepting ? 'Accepting submissions' : 'Not accepting'}
					disabled={saveState === 'saving'}
					onclick={toggleAccepting}
				>
					<span class="dg-accepting-switch-track" aria-hidden="true">
						<span class="dg-accepting-switch-thumb"></span>
					</span>
					<span class="dg-accepting-switch-label">
						{accepting ? 'Accepting submissions' : 'Not accepting'}
					</span>
				</button>
				<span
					class="dg-status-pill"
					data-status={applicantStatus.toLowerCase()}
					title="Applicant-facing status"
				>
					{applicantStatus}
				</span>
				<span class="dg-wizard-status" aria-live="polite">
					{#if loadState === 'loading'}
						Loading…
					{:else if saveState === 'saving'}
						Saving…
					{:else if saveState === 'saved'}
						Saved
					{:else if saveState === 'error'}
						Save error
					{/if}
				</span>
			</div>
		</nav>
	</header>

	{#if loadError}
		<p class="dg-wizard-alert" role="alert">{loadError}</p>
	{/if}

	<!-- Open content on paper ground — no nested content box -->
	<main class="dg-wizard-main" aria-labelledby="dg-wizard-step-title">
		{#if logoUrl}
			<img
				class="dg-wizard-mark"
				src={logoUrl}
				alt="DragonGate"
				width="180"
				height="180"
				decoding="async"
			/>
		{/if}
		{#if loadState === 'loading'}
			<p class="dg-wizard-lead">Loading portal…</p>
		{:else if currentStepId === 'start'}
			<StartStep
				{definition}
				{api}
				onApply={applyDefinition}
				onContinue={() => go('build')}
			/>
		{:else if currentStepId === 'build'}
			<BuildStep
				{definition}
				onChange={changeDefinition}
				onContinue={() => go('map')}
				onBack={() => go('start')}
			/>
		{:else if currentStepId === 'map'}
			<MapStep
				{definition}
				onChange={changeDefinition}
				onContinue={() => go('publish')}
				onBack={() => go('build')}
			/>
		{:else if currentStepId === 'publish'}
			<PublishStep
				{definition}
				{accessCatalog}
				{siteDefaults}
				onChange={changeDefinition}
				onBack={() => go('map')}
				onSaveDraft={saveDraft}
				onSavePortal={savePortal}
				{saveState}
				{saveError}
				applicantStatus={applicantStatus}
				publicUrl={livePublicUrl}
				listUrl={listUrl}
			/>
		{/if}
	</main>
</div>
