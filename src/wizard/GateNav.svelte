<script>
	import { WIZARD_STEPS, gateStateForStep } from './steps.js';

	/**
	 * @typedef {Object} Props
	 * @property {string} currentStepId
	 * @property {(stepId: string) => void} onSelect
	 * @property {Record<string, string>} [subtitles]
	 */

	/** @type {Props} */
	let { currentStepId, onSelect, subtitles = {} } = $props();
</script>

<nav class="dg-wizard-steps" aria-label="Portal setup steps">
	<ol class="dg-wizard-steps-list">
		{#each WIZARD_STEPS as step, i (step.id)}
			{@const state = gateStateForStep(step.id, currentStepId)}
			{@const isCurrent = step.id === currentStepId}
			{@const sub = subtitles[step.id] ?? step.subtitle}
			<li class="dg-wizard-steps-item">
				<button
					type="button"
					class="dg-step-pill"
					data-state={state}
					data-current={isCurrent ? 'true' : 'false'}
					data-step={step.id}
					aria-current={isCurrent ? 'step' : undefined}
					title={sub}
					onclick={() => onSelect(step.id)}
				>
					<span class="dg-step-pill-n">{i + 1}</span>
					<span class="dg-step-pill-label">{step.label}</span>
				</button>
			</li>
		{/each}
	</ol>
</nav>
