<script>

  import { createEventDispatcher, onMount } from 'svelte';
  import { Label } from 'bits-ui';
  import Combobox from '../Combobox.svelte';
  import { ContentParser } from '../services/ContentParser.js';
  import { WordPressContentProvider } from '../services/ContentProvider.js';
  import { TagManager } from '../services/TagManager.js';
  
  /**
   * @typedef {Object} Props
   * @property {any} field
   * @property {string} [value]
   * @property {any} [sheetManager]
   * @property {any} [sheetId]
   * @property {string} [content]
   */

  /** @type {Props} */
  let {
    field,
    value = '',
    sheetManager = null,
    sheetId = null,
    content = ''
  } = $props();
  
  const dispatch = createEventDispatcher();
  
  // Services
  const contentParser = new ContentParser();
  const contentProvider = new WordPressContentProvider();
  const tagManager = new TagManager(sheetManager, sheetId, dispatch, field.key);
  
  let comboboxRef = $state();
  let draggedIndex = $state(null);
  let dragOverIndex = $state(null);
  
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
  let parsedOptions = $derived((() => {
    const contentToParse = content || contentProvider.getContent();
    if (!contentToParse) {
      console.log('TagsField: No content to parse');
      return [];
    }
    
    console.log('TagsField: Parsing content:', contentToParse.substring(0, 200) + '...');
    
    const options = contentParser.parse(contentToParse);
    console.log('TagsField: Parsed options:', options);
    return options;
  })());
  
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
  let tags = $state([]);
  
  // Initialize tags immediately
  function initializeTags() {
    // Priority: sheetManager over value prop
    if (sheetManager && sheetId && sheetManager.sheets) {
      try {
        // Read the store value directly to make it reactive
        const currentSheets = $sheetManager.sheets;
        const currentSheet = currentSheets.find(s => s.id === sheetId);
        if (currentSheet && currentSheet.columns) {
          return currentSheet.columns.map(col => ({
            uuid: col.uuid,
            value: col.value,
            label: col.label || col.value
          }));
        }
      } catch (error) {
        console.warn('TagsField: Error reading sheetManager.sheets:', error);
      }
    }
    
    if (Array.isArray(value)) {
      // Handle Column objects or string arrays
      return value.map(item => {
        if (typeof item === 'object' && item.uuid) {
          return { uuid: item.uuid, value: item.value, label: item.label };
        } else {
          return { uuid: null, value: item, label: item };
        }
      });
    } else if (typeof value === 'string' && value.trim()) {
      // Handle comma-separated string
      return value.split(',').filter(tag => tag.trim()).map(tag => ({
        uuid: null,
        value: tag.trim(),
        label: tag.trim()
      }));
    }
    
    return [];
  }
  
  // Initialize tags on mount - with fallback
  try {
    tags = initializeTags();
  } catch (error) {
    console.warn('TagsField: Error initializing tags:', error);
    tags = [];
  }
  
  // Update tags when value or sheetManager changes
  $effect(() => {
    try {
      tags = initializeTags();
    } catch (error) {
      console.warn('TagsField: Error updating tags:', error);
      tags = [];
    }
  });
  
  // Tag removal - work with UUID-based removal
  function removeTag(tagToRemove) {
    console.log(`🏷️ Removing tag: ${tagToRemove}`);
    
    // Find the tag to remove
    const tagIndex = tags.findIndex(tag => tag.value === tagToRemove);
    if (tagIndex === -1) {
      console.warn(`Tag not found: ${tagToRemove}`);
      return;
    }
    
    const tagToRemoveObj = tags[tagIndex];
    
    // Remove from sheetManager if available
    if (sheetManager && sheetId && tagToRemoveObj.uuid) {
      sheetManager.removeColumn(sheetId, tagToRemoveObj.uuid);
    } else {
      // Fallback: remove from tags array and dispatch change
      const newTags = tags.filter(tag => tag.value !== tagToRemove);
      tags = newTags;
      dispatch('change', { field: field.key, value: newTags });
    }
  }

  function moveTag(tagA, tagB) {
    
    // Find the tag to remove
    let tagAIndex = tags.findIndex(tag => tag.uuid === tagA.uuid);
    let tagBIndex = tags.findIndex(tag => tag.uuid === tagB.uuid);

    console.log('tagAIndex', tagAIndex, tagA);
    console.log('tagBIndex', tagBIndex, tagB);
    
    // Remove from sheetManager if available
    if (sheetManager && sheetId) {

      sheetManager.moveColumn(sheetId, tagAIndex, tagBIndex);
    } else {
      // Fallback: remove from tags array and dispatch change
      tags[tagAIndex] = tagB;
      tags[tagBIndex] = tagA;
      dispatch('change', { field: field.key, value: [...tags] });
    }
  }


  // Tag position update - work with UUID-based reordering
  function updateTagPosition(newTags) {
    console.log(`🔄 Updating tag positions:`, newTags);
    
    // Update through the appropriate method - mirror the removal logic
    if (sheetManager && sheetId) {
      // For sheetManager, we need to reorder the columns
      console.log('Reordering columns in sheetManager:', newTags);
      
      // Get the current sheet
      const currentSheets = $sheetManager.sheets;
      const currentSheet = currentSheets.find(s => s.id === sheetId);
      
      if (currentSheet && currentSheet.columns) {
        // Create new columns array in the new order
        const reorderedColumns = newTags.map(tag => {
          // Find the original column by UUID
          const originalColumn = currentSheet.columns.find(col => col.uuid === tag.uuid);
          return originalColumn || { uuid: tag.uuid, value: tag.value, label: tag.label };
        });
        
        // Update the sheet with reordered columns
        sheetManager.updateSheetColumns(sheetId, reorderedColumns);
      }
    } else {
      // Fallback: update tags array and dispatch change
      tags = newTags;
      dispatch('change', { field: field.key, value: newTags });
    }
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

    // Find the two tags to swap
    const draggedTag = tags[draggedIndex];
    const dropTag = tags[dropIndex];
    
    if (!draggedTag || !dropTag) {
      console.warn('Could not find tags to swap');
      return;
    }

    // Create new array with swapped positions
    moveTag(draggedTag, dropTag);
    
    draggedIndex = null;
    dragOverIndex = null;
  }
</script>

<div class="form-group">
  <Label.Root class="mb-2 block text-sm font-medium text-gray-700">{field.label}</Label.Root>

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
        ondragstart={(e) => handleDragStart(e, index)}
        ondragend={handleDragEnd}
        ondragover={(e) => handleDragOver(e, index)}
        ondragleave={handleDragLeave}
        ondrop={(e) => handleDrop(e, index)}
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
            onclick={() => removeTag(tag.value)}
            onkeydown={(e) => e.key === 'Enter' && removeTag(tag.value)}
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
</div>
