<script>
	import { Combobox, Label } from 'bits-ui';
	import { createEventDispatcher } from 'svelte';

	/**
	 * @typedef {Object} Props
	 * @property {any} [options]
	 * @property {any} [selected]
	 * @property {string} [placeholder]
	 * @property {string} [label]
	 * @property {any} [displayValue]
	 * @property {any} [filterBy]
	 * @property {boolean} [allowCustom]
	 * @property {boolean} [disabled]
	 */

	/** @type {Props} */
	let {
		options = [],
		selected = $bindable(null),
		placeholder = 'Search...',
		label = '',
		displayValue = (item) => item?.name || '',
		filterBy = (option, query) => {
			if (!query) return true;
			const searchText = displayValue(option).toLowerCase();
			return searchText.includes(query.toLowerCase());
		},
		allowCustom = false,
		disabled = false,
	} = $props();

	const dispatch = createEventDispatcher();

	let query = $state('');
	let isOpen = $state(false);
	let customValue = $state('');
	let inputId = `combobox-${Math.random().toString(36).slice(2, 11)}`;
	let customId = `${inputId}-custom`;

	let filteredOptions = $derived(
		query === '' ? options : options.filter((option) => filterBy(option, query)),
	);

	function optionKey(option) {
		return String(option?.uuid || option?.value || displayValue(option));
	}

	function optionByKey(key) {
		return options.find((option) => optionKey(option) === key) || null;
	}

	function selectOption(option) {
		selected = option;
		query = '';
		customValue = '';
		isOpen = false;
		dispatch('change', option);
	}

	function handleValueChange(next) {
		const option = optionByKey(next);
		if (option) {
			selectOption(option);
		}
	}

	function addCustom() {
		const text = customValue.trim();
		if (!allowCustom || !text) {
			return;
		}
		selectOption({ name: text, value: text });
	}

	export function clear() {
		query = '';
		customValue = '';
		selected = null;
	}
</script>

<div class="combobox-container relative">
	{#if label}
		<Label.Root for={inputId} class="mb-2 block text-sm/6 font-medium text-gray-900">{label}</Label.Root>
	{/if}

	<Combobox.Root
		type="single"
		bind:open={isOpen}
		disabled={disabled}
		onValueChange={handleValueChange}
	>
		<div class="relative">
			<Combobox.Input
				id={inputId}
				class="block w-full rounded-md bg-white py-1.5 pl-3 pr-12 text-base text-gray-900 outline outline-1 -outline-offset-1 outline-gray-300 placeholder:text-gray-400 focus:outline focus:outline-2 focus:-outline-offset-2 focus:outline-indigo-600 sm:text-sm/6 {disabled
					? 'opacity-50 cursor-not-allowed'
					: ''}"
				placeholder={placeholder}
				value={query || displayValue(selected)}
				oninput={(e) => {
					query = e.currentTarget.value;
					isOpen = true;
				}}
			/>
			<Combobox.Trigger
				class="absolute inset-y-0 right-0 flex items-center rounded-r-md px-2 focus:outline-none {disabled
					? 'cursor-not-allowed'
					: ''}"
			>
				<svg
					class="size-5 text-gray-400 transition-transform {isOpen ? 'rotate-180' : ''}"
					fill="none"
					viewBox="0 0 24 24"
					stroke-width="1.5"
					stroke="currentColor"
				>
					<path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
				</svg>
			</Combobox.Trigger>
		</div>
		<Combobox.Portal>
			<Combobox.Content
				class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-md bg-white py-1 text-base shadow-lg outline outline-1 outline-black/5 sm:text-sm"
			>
				{#each filteredOptions as option (optionKey(option))}
					<Combobox.Item
						class="w-full cursor-default select-none px-3 py-2 text-left text-gray-900 data-highlighted:bg-indigo-600 data-highlighted:text-white"
						value={optionKey(option)}
						label={displayValue(option)}
					>
						<div class="flex flex-col">
							<span class="block truncate">{displayValue(option)}</span>
							{#if option.group}
								<span class="caption text-xs text-gray-500">{option.group}</span>
							{/if}
							{#if option.username}
								<span class="ml-2 block truncate text-gray-500">{option.username}</span>
							{/if}
						</div>
					</Combobox.Item>
				{/each}
				{#if filteredOptions.length === 0 && !allowCustom}
					<div class="px-3 py-2 text-gray-500">No options found</div>
				{/if}
			</Combobox.Content>
		</Combobox.Portal>
	</Combobox.Root>

	{#if allowCustom}
		<div class="mt-2">
			<Label.Root for={customId} class="mb-1 block text-xs font-medium text-gray-700"
				>Add custom</Label.Root
			>
			<input
				id={customId}
				type="text"
				class="block w-full rounded-md bg-white py-1.5 px-3 text-sm text-gray-900 outline outline-1 -outline-offset-1 outline-gray-300"
				placeholder="Type a custom value and press Enter"
				bind:value={customValue}
				onkeydown={(e) => {
					if (e.key === 'Enter') {
						e.preventDefault();
						addCustom();
					}
				}}
				{disabled}
			/>
		</div>
	{/if}
</div>
