<script>
	import { Label } from 'bits-ui';

	/**
	 * @typedef {Object} Props
	 * @property {string} [id]
	 * @property {string} [name]
	 * @property {string} [label]
	 * @property {string} [type]
	 * @property {string} [value]
	 * @property {boolean} [multiline]
	 * @property {boolean} [required]
	 * @property {boolean} [disabled]
	 * @property {string} [placeholder]
	 * @property {string} [inputClass]
	 * @property {string} [labelClass]
	 * @property {string} [autocomplete]
	 * @property {number} [maxlength]
	 * @property {number} [rows]
	 * @property {string} [ariaLabel]
	 * @property {(e: Event) => void} [oninput]
	 */

	/** @type {Props} */
	let {
		id = '',
		name = '',
		label = '',
		type = 'text',
		value = '',
		multiline = false,
		required = false,
		disabled = false,
		placeholder = '',
		inputClass = 'dg-input',
		labelClass = 'dg-field-label',
		autocomplete = '',
		maxlength = undefined,
		rows = 5,
		ariaLabel = '',
		oninput,
		trailing,
	} = $props();

	let controlId = $derived(id || `dg-field-${name || label || 'input'}`);
</script>

{#if label}
	{#if trailing}
		<div class="mb-2 flex items-center justify-between gap-2">
			<Label.Root for={controlId} class={labelClass}>{label}</Label.Root>
			{@render trailing()}
		</div>
	{:else}
		<Label.Root for={controlId} class={labelClass}>{label}</Label.Root>
	{/if}
{/if}
{#if multiline}
	<textarea
		id={controlId}
		{name}
		class={inputClass}
		{required}
		{disabled}
		{placeholder}
		{rows}
		{value}
		aria-label={ariaLabel || undefined}
		{oninput}
	></textarea>
{:else}
	<input
		id={controlId}
		{name}
		{type}
		class={inputClass}
		{required}
		{disabled}
		{placeholder}
		{value}
		autocomplete={autocomplete || undefined}
		maxlength={maxlength}
		aria-label={ariaLabel || undefined}
		{oninput}
	/>
{/if}
