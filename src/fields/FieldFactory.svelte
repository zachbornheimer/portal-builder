<script>
  import TextField from './TextField.svelte';
  import IdField from './IdField.svelte';
  import TagsField from './TagsField.svelte';
  import { createEventDispatcher } from 'svelte';
  
  export let field;
  export let value = '';
  export let hasDeleteButton = false;
  export let sheetManager = null;
  export let sheetId = null;
  
  const dispatch = createEventDispatcher();
  
  function handleFieldChange(event) {
    dispatch('change', event.detail);
  }
  
  function handleDelete() {
    dispatch('delete');
  }
</script>

{#if field.type === 'text'}
  <TextField 
    {field}
    {value}
    {hasDeleteButton}
    on:change={handleFieldChange}
    on:delete={handleDelete}
  />
{:else if field.type === 'id'}
  <IdField 
    {field}
    {value}
    on:change={handleFieldChange}
  />
{:else if field.type === 'tags'}
  <TagsField 
    {field}
    {value}
    {sheetManager}
    {sheetId}
    on:change={handleFieldChange}
  />
{/if}
