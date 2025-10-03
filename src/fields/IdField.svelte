<script>
  import { createEventDispatcher } from 'svelte';
  
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
  <div class="flex items-center gap-2 mb-2">
    <label class="text-sm font-medium text-gray-700">
      {field.label}
    </label>
    {#if hasValue}
      <a 
        href={linkUrl}
        target="_blank" 
        rel="noopener noreferrer" 
        class="text-zysys-blue-600 hover:text-zysys-blue-800 inline-flex items-center gap-1"
        style="margin-bottom:5px"
      >
        {field.linkText}
        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
        </svg>
      </a>
    {/if}
  </div>
  <input 
    type="text" 
    name={field.key}
    class="form-control w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-zysys-blue-500"
    {value}
    oninput={handleInput}
  />
</div>
