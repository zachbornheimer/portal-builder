<script>
	import { ToggleGroup } from 'bits-ui';
	import { createEventDispatcher } from 'svelte';

	/**
	 * @typedef {Object} Props
	 * @property {any} [tabs]
	 * @property {any} [activeSheetId]
	 * @property {any} [sheetManager]
	 */

	/** @type {Props} */
	let { tabs = [], activeSheetId = $bindable(null), sheetManager = null } = $props();

	const dispatch = createEventDispatcher();

	function selectSheet(sheetId) {
		activeSheetId = sheetId;
		if (sheetManager) {
			sheetManager.setActiveSheet(sheetId);
		}
		dispatch('sheetSelected', { sheetId });
	}

	function addNew() {
		if (sheetManager) {
			const newSheet = sheetManager.addSheet();
			dispatch('sheetAdded', { sheet: newSheet });
		} else {
			dispatch('addNew');
		}
	}
</script>

<div class="mb-6">
	<div
		class="inline-flex rounded-lg border border-gray-200 bg-white p-1 shadow-sm"
		style="border: 1px solid #e5e7eb !important;"
	>
		<ToggleGroup.Root
			type="single"
			value={activeSheetId}
			onValueChange={(next) => {
				if (next) selectSheet(next);
			}}
			class="inline-flex"
		>
			{#each tabs as tab (tab.value)}
				<ToggleGroup.Item
					value={tab.value}
					class="cursor-pointer rounded-md border-0 px-4 py-1 text-sm font-medium outline-none transition-colors duration-200 {activeSheetId ===
					tab.value
						? 'bg-zysys-blue-200 text-zysys-blue-950'
						: 'bg-transparent text-gray-700 hover:bg-zysys-blue-50'}"
				>
					{tab.key}
				</ToggleGroup.Item>
			{/each}
		</ToggleGroup.Root>
		<button
			type="button"
			class="ml-0 cursor-pointer rounded-md border-0 bg-transparent px-4 py-1 text-sm font-medium text-gray-700 outline-none hover:bg-zysys-blue-50"
			onclick={addNew}
		>
			Add New
		</button>
	</div>
</div>
