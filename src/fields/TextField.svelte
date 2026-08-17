<script>
	import { createEventDispatcher } from 'svelte';
	import DeleteConfirmModal from '../DeleteConfirmModal.svelte';
	import Field from '../ui/Field.svelte';

	/**
	 * @typedef {Object} Props
	 * @property {any} field
	 * @property {string} [value]
	 * @property {boolean} [hasDeleteButton]
	 */

	/** @type {Props} */
	let { field, value = '', hasDeleteButton = false } = $props();

	const dispatch = createEventDispatcher();

	let showDeleteModal = $state(false);

	function handleInput(event) {
		const newValue = event.target.value;
		dispatch('change', { field: field.key, value: newValue });
	}

	function handleDeleteClick() {
		showDeleteModal = true;
	}

	function handleDeleteConfirm() {
		dispatch('delete');
		showDeleteModal = false;
	}

	function handleDeleteCancel() {
		showDeleteModal = false;
	}
</script>

<div class="form-group">
	<Field
		id={`dg-text-${field.key}`}
		label={field.label}
		{value}
		oninput={handleInput}
		labelClass="block text-sm font-medium text-gray-700"
		inputClass="form-control w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-zysys-blue-500"
	>
		{#snippet trailing()}
			{#if hasDeleteButton}
				<button
					type="button"
					class="delete-row cursor-pointer rounded-md border-none bg-transparent p-2 text-gray-400 transition-colors duration-200 hover:text-red-600"
					onclick={handleDeleteClick}
					title="Delete row"
				>
					<span class="dashicons dashicons-trash"></span>
				</button>
			{/if}
		{/snippet}
	</Field>
</div>

<DeleteConfirmModal
	isOpen={showDeleteModal}
	itemName={value || '[New Sheet]'}
	itemType="sheet"
	on:confirm={handleDeleteConfirm}
	on:cancel={handleDeleteCancel}
/>
