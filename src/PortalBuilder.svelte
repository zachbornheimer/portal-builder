<script>
  import { onMount } from 'svelte';
  import SegmentedControl from './SegmentedControl.svelte';
  import SheetCard from './SheetCard.svelte';
  import { SheetManager } from './SheetManager.js';
  import { globalStore } from './stores/GlobalStore.js';
  
  export let columns = [];
  export let initialData = [];
  export let type = 'sheets';
  export let metaKey = ''; // Configuration type: 'sheets' or 'drive'
  
  let sheetManager;
  let tabs = [];
  let activeSheet = null;
  let sheets = [];
  
  onMount(() => {
    // Initialize sheet manager
    sheetManager = new SheetManager(initialData, type);
    
    // Subscribe to reactive stores
    const unsubscribeTabs = sheetManager.tabs.subscribe($tabs => {
      tabs = $tabs;
    });
    
    const unsubscribeActiveSheet = sheetManager.activeSheet.subscribe($activeSheet => {
      activeSheet = $activeSheet;
    });
    
    const unsubscribeSheets = sheetManager.sheets.subscribe($sheets => {
      sheets = $sheets;
      
      // Sync with global store and update hidden input
      const exportedData = sheetManager.exportData();
      if (metaKey === '_portal_record_keeping') {
        globalStore.updateRecordKeeping(exportedData);
      } else if (metaKey === '_portal_file_backups') {
        globalStore.updateFileBackups(exportedData);
      }
      
      // Update the existing hidden input created by PHP
      const hiddenInput = document.querySelector(`input[name="${metaKey}"]`);
      if (hiddenInput) {
        hiddenInput.value = JSON.stringify(exportedData);
      }
      
      // Ensure first sheet is selected if none are selected
      if ($sheets.length > 0 && !activeSheet) {
        sheetManager.setActiveSheet($sheets[0].id);
      }
    });
    
    // Cleanup on destroy
    return () => {
      unsubscribeTabs();
      unsubscribeActiveSheet();
      unsubscribeSheets();
    };
  });
  
  function handleSheetSelected(event) {
    const { sheetId } = event.detail;
    sheetManager.setActiveSheet(sheetId);
  }
  
  function handleSheetAdded(event) {
    // Sheet is automatically added by SheetManager
    // No additional action needed
  }
  
  function handleDataChanged(event) {
    // Data is automatically updated by SheetManager
    // No additional action needed
  }
  
  function handleDeleteSheet(event) {
    // Sheet is automatically deleted by SheetManager
    // No additional action needed
  }
</script>

<div class="portal-builder">
  <SegmentedControl 
    {tabs}
    activeSheetId={activeSheet?.id}
    {sheetManager}
    on:sheetSelected={handleSheetSelected}
    on:sheetAdded={handleSheetAdded}
  />
  
  <div class="data-table">
    {#each sheets as sheet (sheet.id)}
      <SheetCard
        {sheet}
        {type}
        isVisible={activeSheet && sheet.id === activeSheet.id}
        {sheetManager}
        on:dataChanged={handleDataChanged}
        on:deleteSheet={handleDeleteSheet}
      />
    {/each}
  </div>
  
  <!-- Hidden input is created by PHP and updated by Svelte -->
</div>
