<script>
	/** @typedef {'open' | 'current' | 'sealed'} GateState */

	/**
	 * @typedef {Object} Props
	 * @property {GateState} [state]
	 * @property {string} [label] - Accessible name (shape is not enough alone)
	 */

	/** @type {Props} */
	let { state = 'open', label = 'Incomplete' } = $props();

	const STATE_LABELS = {
		open: 'Incomplete',
		current: 'Current step',
		sealed: 'Complete',
	};

	const accessibleName = $derived(label || STATE_LABELS[state] || STATE_LABELS.open);
</script>

<span class="dg-gate-seal" data-state={state} aria-hidden="true"></span>
<span class="sr-only">{accessibleName}</span>
