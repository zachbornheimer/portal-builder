<script>
  import TextField from './TextField.svelte';
  import IdField from './IdField.svelte';
  import TagsField from './TagsField.svelte';
  import { createEventDispatcher } from 'svelte';
  
  /**
   * @typedef {Object} Props
   * @property {any} field
   * @property {string} [value]
   * @property {boolean} [hasDeleteButton]
   * @property {any} [sheetManager]
   * @property {any} [sheetId]
   */

  /** @type {Props} */
  let {
    field,
    value = '',
    hasDeleteButton = false,
    sheetManager = null,
    sheetId = null
  } = $props();
  
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
