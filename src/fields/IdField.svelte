<script>
	import { createEventDispatcher } from 'svelte';
	import Field from '../ui/Field.svelte';

	/**
	 * @typedef {Object} Props
	 * @property {any} field
	 * @property {string} [value]
	 */

	/** @type {Props} */
	let { field, value = '' } = $props();

	const dispatch = createEventDispatcher();

	function handleInput(event) {
		const newValue = event.target.value;
		dispatch('change', { field: field.key, value: newValue });
	}

	let hasValue = $derived(value && value.trim() !== '');
	let linkUrl = $derived(hasValue ? field.linkUrl(value) : '');
</script>

<div class="form-group">
	<Field
		id={`dg-id-${field.key}`}
		name={field.key}
		label={field.label}
		{value}
		oninput={handleInput}
		labelClass="text-sm font-medium text-gray-700"
		inputClass="form-control w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-zysys-blue-500"
	>
		{#snippet trailing()}
			{#if hasValue}
				<a
					href={linkUrl}
					target="_blank"
					rel="noopener noreferrer"
					class="inline-flex items-center gap-1 text-zysys-blue-600 hover:text-zysys-blue-800"
				>
					{field.linkText}
					<svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path
							stroke-linecap="round"
							stroke-linejoin="round"
							stroke-width="2"
							d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"
						></path>
					</svg>
				</a>
			{/if}
		{/snippet}
	</Field>
</div>
