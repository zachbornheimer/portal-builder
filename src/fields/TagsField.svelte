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
  const tagManager = new TagManager(sheetManager, sheetId, dispatch);
  
  let comboboxRef;
  
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
      tagManager.addTag(newTag, field.key, value);
      
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
      tagManager.addTag(newTag, field.key, value);
      event.target.value = '';
    }
  }
  
  // Reactive tags array - handle Column objects, arrays, and strings
  $: tags = tagManager.parseTags(value);
  
  // Tag removal - work with UUID-based removal
  function removeTag(tagToRemove) {
    console.log(`🏷️ Removing tag: ${tagToRemove}`);
    tagManager.removeTag(tagToRemove, tags);
  }
</script>

<div class="form-group">
  <label class="block text-sm font-medium text-gray-700 mb-2">{field.label}</label>
  <div class="columns-tag-container border border-gray-300 rounded-md p-2 min-h-[40px] flex flex-wrap items-center gap-1">
    {#each tags as tag (tag.uuid || tag.value)}
        <span class="inline-flex items-center gap-1 bg-zysys-blue-100 text-zysys-blue-800 px-2 py-1 rounded text-sm">
        {tag.label || `{{ ${tag.value} }}`}
        <button 
          type="button"
          class="ml-1 text-zysys-blue-600 hover:text-red-600 cursor-pointer border-0 outline-none focus:outline-none focus:ring-0 focus:shadow-none bg-transparent p-0"
          on:click={() => sheetManager.removeColumn(sheetId, tag.uuid)}
          on:keydown={(e) => e.key === 'Enter' && removeTag(tag.value)}
        >×</button>
      </span>
    {/each}
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
    <input type="hidden" class="tag-hidden-field" name={field.key} {value} />
  </div>
</div>
