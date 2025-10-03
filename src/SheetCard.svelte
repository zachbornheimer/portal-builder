<script>
  import { run } from 'svelte/legacy';

  import { createEventDispatcher } from 'svelte';
  import FieldFactory from './fields/FieldFactory.svelte';
  import { getFieldConfig } from './FieldConfig.js';
  
  /**
   * @typedef {Object} Props
   * @property {any} [sheet]
   * @property {string} [type] - Configuration type: 'sheets' or 'drive'
   * @property {boolean} [isVisible]
   * @property {any} [sheetManager]
   */

  /** @type {Props} */
  let {
    sheet = null,
    type = 'sheets',
    isVisible = true,
    sheetManager = null
  } = $props();
  
  
  const dispatch = createEventDispatcher();
  
  // Get field configuration based on type
  let fieldConfig = $derived(getFieldConfig(type));
  let fields = $derived(fieldConfig.fields);
  
  function handleFieldChange(event) {
    const { field, value } = event.detail;
    
    if (sheetManager && sheet) {
      console.log(`📝 SheetCard: ${field} = ${value} for sheet ${sheet.id}`);
      sheetManager.updateSheetField(sheet.id, field, value);
      
      // If this is the name field, update the sheet name
      if (field === 'name') {
        sheetManager.updateSheetName(sheet.id, value);
      }
    }
    
    dispatch('dataChanged', {
      sheetId: sheet?.id,
      field,
      value
    });
  }
  
  function handleDelete() {
    if (sheetManager && sheet) {
      sheetManager.deleteSheet(sheet.id);
    }
    dispatch('deleteSheet', { sheetId: sheet?.id });
  }
  
  // Reactive field values that update when store changes
  let fieldValues = $state({});
  
  // Subscribe to store changes to keep fieldValues updated
  run(() => {
    if (sheetManager && sheet) {
      sheetManager.sheets.subscribe(sheets => {
        const currentSheet = sheets.find(s => s.id === sheet.id);
        if (currentSheet) {
          fieldValues = {
            name: currentSheet.name || '',
            sheetId: currentSheet.sheetId || '',
            folderId: currentSheet.folderId || '',
            columns: currentSheet.columns || [], // Pass columns array directly
            ...currentSheet.fields || {}
          };
        }
      });
    }
  });
  
</script>

<div 
  class="grow lg:rounded-lg lg:bg-white mb-4"
  style:display={isVisible ? 'block' : 'none'}
>
  <div class="space-y-4">
    {#each fields as field (field.key)}
      <FieldFactory 
        {field}
        value={fieldValues[field.key] || ''}
        hasDeleteButton={field.hasDeleteButton}
        {sheetManager}
        sheetId={sheet?.id}
        on:change={handleFieldChange}
        on:delete={handleDelete}
      />
    {/each}
  </div>
</div>
