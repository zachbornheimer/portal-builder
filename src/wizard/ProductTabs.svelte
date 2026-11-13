<script>
	/** Product tabs. Overview is information; the others edit. */
	export const PRODUCT_TABS = [
		{ id: 'overview', label: 'Overview' },
		{ id: 'build', label: 'Form' },
		{ id: 'map', label: 'Map' },
		{ id: 'publish', label: 'Publish' },
	];

	/**
	 * @typedef {Object} Props
	 * @property {string} currentStepId
	 * @property {(stepId: string) => void} onSelect
	 */

	/** @type {Props} */
	let { currentStepId, onSelect } = $props();

	const currentTab = $derived(
		PRODUCT_TABS.some((tab) => tab.id === currentStepId) ? currentStepId : 'overview',
	);
</script>

<nav class="dg-product-tabs" aria-label="Portal sections">
	{#each PRODUCT_TABS as tab (tab.id)}
		<button
			type="button"
			class="dg-product-tabs-tab"
			aria-current={currentTab === tab.id ? 'page' : undefined}
			onclick={() => onSelect(tab.id)}
		>
			{tab.label}
		</button>
	{/each}
</nav>
