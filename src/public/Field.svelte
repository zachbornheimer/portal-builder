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
	 * @property {string} [autocomplete]
	 * @property {number} [maxlength]
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
		autocomplete = '',
		maxlength = undefined,
	} = $props();
</script>

{#if label}
	<Label.Root for={id}>{label}</Label.Root>
{/if}
{#if multiline}
	<textarea class="dg-control" {id} {name} rows="5" {required} {value}></textarea>
{:else}
	<input
		class="dg-control"
		{id}
		{name}
		{type}
		{required}
		{value}
		autocomplete={autocomplete || undefined}
		maxlength={maxlength}
	/>
{/if}
