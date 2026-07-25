<script>
	import GateNav from './GateNav.svelte';
	import { WIZARD_STEPS } from './steps.js';

	let currentStepId = $state('start');

	const currentStep = $derived(
		WIZARD_STEPS.find((s) => s.id === currentStepId) ?? WIZARD_STEPS[0]
	);

	/**
	 * @param {string} stepId
	 */
	function selectStep(stepId) {
		currentStepId = stepId;
	}
</script>

<div class="dg-wizard-shell">
	<header class="dg-wizard-topbar">
		<div class="dg-wizard-brand">
			<svg
				class="dg-wizard-brand-mark"
				viewBox="0 0 24 24"
				fill="none"
				aria-hidden="true"
			>
				<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="1.5" />
				<path
					d="M8 13.5l2.5 2.5L16 9"
					stroke="currentColor"
					stroke-width="1.75"
					stroke-linecap="round"
					stroke-linejoin="round"
				/>
			</svg>
			<span>DragonGate</span>
		</div>
		<div class="dg-wizard-crumbs">
			Portals / Setup / {currentStep.label}
		</div>
	</header>

	<div class="dg-wizard-body">
		<GateNav {currentStepId} onSelect={selectStep} />

		<main class="dg-wizard-main" aria-labelledby="dg-wizard-step-title">
			{#if currentStepId === 'start'}
				<p class="dg-wizard-eyebrow">New portal</p>
				<h2 id="dg-wizard-step-title" class="dg-wizard-title">
					How do you want to start?
				</h2>
				<p class="dg-wizard-lead">
					Templates come from past portals — not generic samples.
				</p>
				<div class="dg-wizard-placeholder">
					Template cards will live here (clone a past portal or start blank).
				</div>
			{:else if currentStepId === 'build'}
				<p class="dg-wizard-eyebrow">Form</p>
				<h2 id="dg-wizard-step-title" class="dg-wizard-title">Build the form</h2>
				<p class="dg-wizard-lead">
					Add fields applicants will fill out. Field types and mapping come next.
				</p>
				<div class="dg-wizard-placeholder">
					Form builder canvas will live here.
				</div>
			{:else if currentStepId === 'map'}
				<p class="dg-wizard-eyebrow">Data</p>
				<h2 id="dg-wizard-step-title" class="dg-wizard-title">Map data</h2>
				<p class="dg-wizard-lead">
					Connect form answers to Google Sheets columns and Drive folders.
				</p>
				<div class="dg-wizard-placeholder">
					Existing Sheets/Drive meta boxes remain below until migrated into this
					step.
				</div>
			{:else if currentStepId === 'publish'}
				<p class="dg-wizard-eyebrow">Open</p>
				<h2 id="dg-wizard-step-title" class="dg-wizard-title">
					Last details before this opens
				</h2>
				<p class="dg-wizard-lead">
					Deadline, fee, timezone, and guidelines — then publish.
				</p>
				<div class="dg-wizard-placeholder">
					Publish fields (deadline, fee, notifications) will live here.
				</div>
			{/if}
		</main>
	</div>
</div>
