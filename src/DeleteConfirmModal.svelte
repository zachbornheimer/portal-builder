<script>
  import ConfirmModal from './ConfirmModal.svelte';
  import { createEventDispatcher } from 'svelte';
  
  export let isOpen = false;
  export let itemName = '';
  export let itemType = 'item';
  
  const dispatch = createEventDispatcher();
  
  function handleConfirm() {
    dispatch('confirm');
  }
  
  function handleCancel() {
    dispatch('cancel');
  }
  
  $: title = `Delete ${itemType}`;
  $: message = `Are you sure you want to delete "${itemName}"? This action cannot be undone.`;
</script>

<ConfirmModal
  {isOpen}
  {title}
  {message}
  confirmText="Delete"
  cancelText="Cancel"
  confirmButtonClass="bg-red-600 hover:bg-red-500 text-white"
  cancelButtonClass="bg-white hover:bg-gray-50 text-gray-900 ring-1 ring-inset ring-gray-300"
  on:confirm={handleConfirm}
  on:cancel={handleCancel}
/>

