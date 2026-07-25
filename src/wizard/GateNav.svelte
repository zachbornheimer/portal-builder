<script>
	import GateSeal from './GateSeal.svelte';
	import { WIZARD_STEPS, gateStateForStep } from './steps.js';

	/**
	 * @typedef {Object} Props
	 * @property {string} currentStepId
	 * @property {(stepId: string) => void} onSelect
	 */

	/** @type {Props} */
	let { currentStepId, onSelect } = $props();
</script>

<nav class="dg-wizard-sidebar" aria-label="Portal setup steps">
	{#each WIZARD_STEPS as step (step.id)}
		{@const state = gateStateForStep(step.id, currentStepId)}
		{@const isCurrent = step.id === currentStepId}
		<button
			type="button"
			class="dg-gate-item"
			data-current={isCurrent ? 'true' : 'false'}
			aria-current={isCurrent ? 'step' : undefined}
			onclick={() => onSelect(step.id)}
		>
			<GateSeal {state} label={`${step.label}: ${state === 'sealed' ? 'Complete' : state === 'current' ? 'Current step' : 'Incomplete'}`} />
			<span>
				<span class="dg-gate-item-label">{step.label}</span>
				<span class="dg-gate-item-sub">{step.subtitle}</span>
			</span>
		</button>
	{/each}
</nav>
