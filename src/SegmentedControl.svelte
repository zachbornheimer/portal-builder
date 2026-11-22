<script>
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
  <div class="inline-flex rounded-lg border border-gray-200 bg-white p-1 shadow-sm" style="border: 1px solid #e5e7eb !important;">
    {#each tabs as tab (tab.value)}
      <button 
        type="button"
        class="px-4 py-1 text-sm font-medium transition-colors duration-200 rounded-md border-0 outline-none focus:outline-none focus:ring-0 focus:shadow-none cursor-pointer {activeSheetId === tab.value ? 'bg-zysys-blue-200 text-zysys-blue-950' : 'text-gray-700 hover:bg-zysys-blue-50 bg-transparent'}"
        onclick={() => selectSheet(tab.value)}
      >
        {tab.key}
      </button>
    {/each}
    <button 
      type="button" 
      class="cursor-pointer px-4 py-1 text-sm font-medium text-gray-700 hover:bg-zysys-blue-50 rounded-md transition-colors duration-200 ml-0 border-0 bg-transparent outline-none focus:outline-none focus:ring-0 focus:shadow-none"
      onclick={addNew}
    >
      Add New
    </button>
  </div>
</div>
