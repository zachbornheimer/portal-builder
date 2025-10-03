<script>
  import { createEventDispatcher, onMount } from 'svelte';
  import Combobox from '../Combobox.svelte';
  import { ContentParser } from '../services/ContentParser.js';
  import { WordPressContentProvider } from '../services/ContentProvider.js';
  import { TagManager } from '../services/TagManager.js';
  
  export let field;
  export let value = '';
  export let sheetManager = null;
  export let sheetId = null;
  export let content = '';
  
  const dispatch = createEventDispatcher();
  
  // Services
  const contentParser = new ContentParser();
  const contentProvider = new WordPressContentProvider();
  const tagManager = new TagManager(sheetManager, sheetId, dispatch, field.key);
  
  let comboboxRef;
  let draggedIndex = null;
  let dragOverIndex = null;
  
  onMount(() => {
    // Listen for content changes
    contentProvider.addChangeListener(() => {
      // Trigger reactive update
    });
    
    // Cleanup
    return () => {
      contentProvider.destroy();
    };
  });
  
  // Parse content to extract pb_* shortcodes
  $: parsedOptions = (() => {
    const contentToParse = content || contentProvider.getContent();
    if (!contentToParse) {
      console.log('TagsField: No content to parse');
      return [];
    }
    
    console.log('TagsField: Parsing content:', contentToParse.substring(0, 200) + '...');
    
    const options = contentParser.parse(contentToParse);
    console.log('TagsField: Parsed options:', options);
    return options;
  })();
  
  // Handle combobox selection
  function handleComboboxChange(event) {
    const selectedOption = event.detail;
    if (selectedOption) {
      const newTag = selectedOption.value;
      
      if (sheetManager && sheetId) {
        // Use addColumn for adding new columns/tags
        sheetManager.addColumn(sheetId, `{{ ${newTag} }}`);
      } else {
        // Fallback to old method if sheetManager not available
        const currentTags = value || '';
        const updatedTags = currentTags ? `${currentTags},${newTag}` : newTag;
        dispatch('change', { field: field.key, value: updatedTags });
      }
      
      // Clear the combobox after selection
      if (comboboxRef) {
        comboboxRef.clear();
      }
    }
  }
  
  function handleTagKeypress(event) {
    if (event.key === 'Enter' && event.target.value.trim() !== '') {
      event.preventDefault();
      const newTag = event.target.value.trim();
      
      if (sheetManager && sheetId) {
        // Use addColumn for adding new columns/tags
        sheetManager.addColumn(sheetId, `{{ ${newTag} }}`);
      } else {
        // Fallback to old method if sheetManager not available
        const currentTags = value || '';
        const updatedTags = currentTags ? `${currentTags},${newTag}` : newTag;
        dispatch('change', { field: field.key, value: updatedTags });
      }
      
      event.target.value = '';
    }
  }
  
  // Reactive tags array - handle Column objects, arrays, and strings
  let tags = [];
  
  // Update tags when value changes
  $: {
    if (Array.isArray(value)) {
      // Handle Column objects or string arrays
      tags = value.map(item => {
        if (typeof item === 'object' && item.uuid) {
          return { uuid: item.uuid, value: item.value, label: item.label };
        } else {
          return { uuid: null, value: item, label: item };
        }
      });
    } else if (typeof value === 'string' && value.trim()) {
      // Handle comma-separated string
      tags = value.split(',').filter(tag => tag.trim()).map(tag => ({
        uuid: null,
        value: tag.trim(),
        label: tag.trim()
      }));
    } else {
      tags = [];
    }
  }
  
  // Also listen to sheetManager changes if available
  $: if (sheetManager && sheetId) {
    let currentSheets;
    sheetManager.sheets.subscribe(sheets => currentSheets = sheets)();
    const currentSheet = currentSheets.find(s => s.id === sheetId);
    if (currentSheet && currentSheet.columns) {
      tags = currentSheet.columns.map(col => ({
        uuid: col.uuid,
        value: col.value,
        label: col.label || col.value
      }));
    }
  }
  
  // Tag removal - work with UUID-based removal
    // Tag removal - work with UUID-based removal
    function removeTag(tagToRemove) {
    console.log(`🏷️ Removing tag: ${tagToRemove}`);
    tagManager.removeTag(tagToRemove, tags);
  }

  // Generate Excel-style column letters (A, B, C, ..., Z, AA, AB, etc.)
  function getColumnLetter(index) {
    let result = '';
    let num = index;
    
    while (num >= 0) {
      result = String.fromCharCode(65 + (num % 26)) + result;
      num = Math.floor(num / 26) - 1;
    }
    
    return result;
  }

  // Drag and drop handlers
  function handleDragStart(event, index) {
    draggedIndex = index;
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/html', event.target.outerHTML);
    event.target.style.opacity = '0.5';
  }

  function handleDragEnd(event) {
    event.target.style.opacity = '1';
    draggedIndex = null;
    dragOverIndex = null;
  }

  function handleDragOver(event, index) {
    event.preventDefault();
    event.dataTransfer.dropEffect = 'move';
    dragOverIndex = index;
  }

  function handleDragLeave(event) {
    dragOverIndex = null;
  }

  function handleDrop(event, dropIndex) {
    event.preventDefault();
    
    if (draggedIndex === null || draggedIndex === dropIndex) {
      return;
    }

    // Reorder the tags array
    const newTags = [...tags];
    const draggedTag = newTags[draggedIndex];
    
    // Remove the dragged item
    newTags.splice(draggedIndex, 1);
    
    // Insert at new position
    const adjustedDropIndex = draggedIndex < dropIndex ? dropIndex - 1 : dropIndex;
    newTags.splice(adjustedDropIndex, 0, draggedTag);
    
    // Update the tags through the appropriate method
    if (sheetManager && sheetId) {
      // For sheetManager, we need to update the order
      // This might require a more complex implementation depending on your sheetManager
      console.log('Reordering tags in sheetManager:', newTags);
      // TODO: Implement sheetManager reordering if needed
    } else {
      // For legacy mode, dispatch the new order
      dispatch('change', { field: field.key, value: newTags });
    }
    
    draggedIndex = null;
    dragOverIndex = null;
  }
</script>

<div class="form-group">
  <label class="block text-sm font-medium text-gray-700 mb-2">{field.label}</label>
  
  <!-- Tags list with divs -->
  <div class="border border-gray-300 rounded-md">
    <!-- Header row -->
    <div class="flex items-center bg-gray-50 border-b border-gray-300 px-4 py-3 text-sm font-semibold text-gray-900">
      <div class="w-8 flex-shrink-0">
        <span class="sr-only">Drag Handle</span>
      </div>
      <div class="w-16 flex-shrink-0">
        Column
      </div>
      <div class="flex-1">
        Value
      </div>
      <div class="w-20 flex-shrink-0 text-right">
        <span class="sr-only">Actions</span>
      </div>
    </div>

    <!-- Tag rows -->
    {#each tags as tag, index (tag.uuid || tag.value)}
      <div 
        class="flex items-center border-b border-gray-200 hover:bg-gray-50 px-4 py-3 {dragOverIndex === index ? 'bg-blue-50 border-blue-300' : ''}"
        draggable="true"
        role="listitem"
        on:dragstart={(e) => handleDragStart(e, index)}
        on:dragend={handleDragEnd}
        on:dragover={(e) => handleDragOver(e, index)}
        on:dragleave={handleDragLeave}
        on:drop={(e) => handleDrop(e, index)}
      >
        <div class="w-8 flex-shrink-0">
          <div class="flex items-center">
            <svg class="h-4 w-4 text-gray-400 cursor-move" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
            </svg>
          </div>
        </div>
        <div class="w-16 flex-shrink-0 text-sm font-medium text-gray-900">
          {getColumnLetter(index)}
        </div>
        <div class="flex-1 text-sm text-gray-900">
          {tag.label || `{{ ${tag.value} }}`}
        </div>
        <div class="w-20 flex-shrink-0 text-right">
          <button
            type="button"
            class="text-red-600 hover:text-red-900 text-sm"
            on:click={() => removeTag(tag.value)}
            on:keydown={(e) => e.key === 'Enter' && removeTag(tag.value)}
          >
            Remove<span class="sr-only">, {tag.label || tag.value}</span>
          </button>
        </div>
      </div>
    {/each}

    <!-- Empty state -->
    {#if tags.length === 0}
      <div class="px-4 py-8 text-center text-sm text-gray-500">
        No tags added yet. Use the search box below to add tags.
      </div>
    {/if}

    <!-- Add new tag row (always present) -->
    <div class="flex items-center border-b border-gray-200 bg-gray-50 px-4 py-3">
      <div class="w-8 flex-shrink-0">
        <div class="flex items-center">
          <svg class="h-4 w-4 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
          </svg>
        </div>
      </div>
      <div class="w-16 flex-shrink-0 text-sm font-medium text-gray-500">
        {getColumnLetter(tags.length)}
      </div>
      <div class="flex-1">
        <Combobox
          bind:this={comboboxRef}
          options={parsedOptions}
          placeholder={field.placeholder || "Add tags..."}
          displayValue={(option) => option?.label || ''}
          filterBy={(option, query) => {
            if (!query) return true;
            return option.label.toLowerCase().includes(query.toLowerCase()) ||
                   option.value.toLowerCase().includes(query.toLowerCase()) ||
                   (option.group && option.group.toLowerCase().includes(query.toLowerCase()));
          }}
          allowCustom={true}
          on:change={handleComboboxChange}
        />
      </div>
      <div class="w-20 flex-shrink-0">
        <!-- Empty space for alignment -->
      </div>
    </div>
  </div>
  
  <input type="hidden" class="tag-hidden-field" name={field.key} {value} />
</div>
