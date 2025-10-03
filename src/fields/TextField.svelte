<script>
  import { createEventDispatcher } from 'svelte';
  import DeleteConfirmModal from '../DeleteConfirmModal.svelte';
  
  export let field;
  export let value = '';
  export let hasDeleteButton = false;
  
  const dispatch = createEventDispatcher();
  
  let showDeleteModal = false;
  
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
  <div class="flex items-center justify-between mb-2">
    <label class="block text-sm font-medium text-gray-700">{field.label}</label>
    {#if hasDeleteButton}
      <button 
        type="button" 
        class="delete-row bg-transparent text-gray-400 hover:text-red-600 border-none p-2 cursor-pointer rounded-md transition-colors duration-200"
        on:click={handleDeleteClick}
        title="Delete row"
      >
        <span class="dashicons dashicons-trash"></span>
      </button>
    {/if}
  </div>
  <input 
    type="text" 
    class="form-control w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-zysys-blue-500"
    {value}
    on:input={handleInput}
  />
</div>

<DeleteConfirmModal
  isOpen={showDeleteModal}
  itemName={value || '[New Sheet]'}
  itemType="sheet"
  on:confirm={handleDeleteConfirm}
  on:cancel={handleDeleteCancel}
/>
