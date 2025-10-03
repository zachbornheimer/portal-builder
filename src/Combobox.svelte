<script>
  import { createEventDispatcher } from 'svelte';
  import { onMount } from 'svelte';
  
  /**
   * @component Combobox
   * @fileoverview
   * A reusable combobox component for searchable dropdown selections.
   *
   * Responsibilities:
   * - Provides searchable dropdown functionality
   * - Handles keyboard navigation and accessibility
   * - Supports custom display values and filtering
   * - Manages focus and blur states
   *
   * @example
   * <Combobox 
   *   options={people} 
   *   selected={selectedPerson} 
   *   on:change={(e) => setSelectedPerson(e.detail)}
   *   displayValue={(person) => person?.name}
   *   filterBy={(option, query) => option.name.toLowerCase().includes(query.toLowerCase())}
   * />
   *
   * @returns {JSX.Element} Rendered combobox component
   *
   * @see TagsField
   * @see FieldFactory
   */

  export let options = [];
  export let selected = null;
  export let placeholder = 'Search...';
  export let label = '';
  export let displayValue = (item) => item?.name || '';
  export let filterBy = (option, query) => {
    if (!query) return true;
    const searchText = displayValue(option).toLowerCase();
    return searchText.includes(query.toLowerCase());
  };
  export let allowCustom = false;
  export let disabled = false;

  const dispatch = createEventDispatcher();
  
  // Expose clear method
  export function clear() {
    query = '';
    selected = null;
  }
  
  let query = '';
  let isOpen = false;
  let inputElement;
  let optionsElement;
  let focusedIndex = -1;
  let inputId = `combobox-${Math.random().toString(36).substr(2, 9)}`;

  // Filter options based on query
  $: filteredOptions = query === '' 
    ? options 
    : options.filter(option => filterBy(option, query));

  // Handle input changes
  function handleInput(event) {
    query = event.target.value;
    isOpen = true;
    focusedIndex = -1;
  }

  // Handle option selection
  function selectOption(option) {
    selected = option;
    query = '';
    isOpen = false;
    focusedIndex = -1;
    dispatch('change', option);
  }

  // Handle input blur
  function handleBlur() {
    // Delay to allow option clicks to register
    setTimeout(() => {
      isOpen = false;
      query = '';
      focusedIndex = -1;
    }, 150);
  }

  // Handle input focus
  function handleFocus() {
    isOpen = true;
  }

  // Handle keyboard navigation
  function handleKeydown(event) {
    if (!isOpen) {
      if (event.key === 'ArrowDown' || event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        isOpen = true;
        return;
      }
    }

    switch (event.key) {
      case 'ArrowDown':
        event.preventDefault();
        focusedIndex = Math.min(focusedIndex + 1, filteredOptions.length - 1);
        break;
      case 'ArrowUp':
        event.preventDefault();
        focusedIndex = Math.max(focusedIndex - 1, -1);
        break;
      case 'Enter':
        event.preventDefault();
        if (focusedIndex >= 0 && focusedIndex < filteredOptions.length) {
          selectOption(filteredOptions[focusedIndex]);
        } else if (allowCustom && query.trim()) {
          selectOption({ name: query.trim(), value: query.trim() });
        }
        break;
      case 'Escape':
        isOpen = false;
        query = '';
        focusedIndex = -1;
        inputElement?.blur();
        break;
    }
  }

  // Handle custom option creation
  function handleCustomOption() {
    if (allowCustom && query.trim()) {
      const customOption = { name: query.trim(), value: query.trim() };
      selectOption(customOption);
    }
  }

  // Click outside to close
  function handleClickOutside(event) {
    if (!event.target.closest('.combobox-container')) {
      isOpen = false;
      query = '';
      focusedIndex = -1;
    }
  }

  onMount(() => {
    document.addEventListener('click', handleClickOutside);
    return () => {
      document.removeEventListener('click', handleClickOutside);
    };
  });
</script>

<div class="combobox-container relative">
  {#if label}
    <label for={inputId} class="block text-sm/6 font-medium text-gray-900 mb-2">{label}</label>
  {/if}
  
  <div class="relative">
    <input
      id={inputId}
      bind:this={inputElement}
      type="text"
      class="block w-full rounded-md bg-white py-1.5 pl-3 pr-12 text-base text-gray-900 outline outline-1 -outline-offset-1 outline-gray-300 placeholder:text-gray-400 focus:outline focus:outline-2 focus:-outline-offset-2 focus:outline-indigo-600 sm:text-sm/6 {disabled ? 'opacity-50 cursor-not-allowed' : ''}"
      placeholder={placeholder}
      value={query || displayValue(selected)}
      on:input={handleInput}
      on:focus={handleFocus}
      on:blur={handleBlur}
      on:keydown={handleKeydown}
      {disabled}
    />
    
    <button
      type="button"
      class="absolute inset-y-0 right-0 flex items-center rounded-r-md px-2 focus:outline-none {disabled ? 'cursor-not-allowed' : ''}"
      on:click={() => isOpen = !isOpen}
      {disabled}
    >
      <svg 
        class="size-5 text-gray-400 transition-transform {isOpen ? 'rotate-180' : ''}" 
        fill="none" 
        viewBox="0 0 24 24" 
        stroke-width="1.5" 
        stroke="currentColor"
      >
        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
      </svg>
    </button>

    {#if isOpen && !disabled}
      <div 
        bind:this={optionsElement}
        class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-md bg-white py-1 text-base shadow-lg outline outline-1 outline-black/5 sm:text-sm"
      >
        {#if allowCustom && query.length > 0}
          <button
            type="button"
            class="w-full text-left cursor-default select-none px-3 py-2 text-gray-900 hover:bg-indigo-600 hover:text-white focus:bg-indigo-600 focus:text-white focus:outline-none {focusedIndex === -1 ? 'bg-indigo-600 text-white' : ''}"
            on:click={handleCustomOption}
            on:mouseenter={() => focusedIndex = -1}
          >
            Create "{query}"
          </button>
        {/if}
        
        {#each filteredOptions as option, index (option.uuid || option.value || index)}
          <button
            type="button"
            class="w-full text-left cursor-default select-none px-3 py-2 text-gray-900 hover:bg-indigo-600 hover:text-white focus:bg-indigo-600 focus:text-white focus:outline-none {focusedIndex === index ? 'bg-indigo-600 text-white' : ''}"
            on:click={() => selectOption(option)}
            on:mouseenter={() => focusedIndex = index}
          >
            <div class="flex flex-col">
              <span class="block truncate">{displayValue(option)}</span>
              {#if option.group}
                <span class="caption text-xs text-gray-500 {focusedIndex === index ? 'text-white' : ''}">{option.group}</span>
              {/if}
              {#if option.username}
                <span class="ml-2 block truncate text-gray-500 {focusedIndex === index ? 'text-white' : ''}">{option.username}</span>
              {/if}
            </div>
          </button>
        {/each}
        
        {#if filteredOptions.length === 0 && !allowCustom}
          <div class="px-3 py-2 text-gray-500">No options found</div>
        {/if}
      </div>
    {/if}
  </div>
</div>
