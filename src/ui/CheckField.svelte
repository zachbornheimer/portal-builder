<script>
	import { Label } from 'bits-ui';

	/**
	 * @typedef {Object} Props
	 * @property {string} [id]
	 * @property {string} [name]
	 * @property {string} [label]
	 * @property {boolean} [checked]
	 * @property {string} [value]
	 * @property {boolean} [required]
	 * @property {string} [rowClass]
	 * @property {string} [inputValue]
	 * @property {(e: Event) => void} [onchange]
	 */

	/** @type {Props} */
	let {
		id = '',
		name = '',
		label = '',
		checked = false,
		value = '1',
		required = false,
		rowClass = 'dg-check-row',
		onchange,
	} = $props();

	let controlId = $derived(id || `dg-check-${name || label || 'box'}`);
	let inputEl = $state(null);

	$effect(() => {
		if (inputEl) inputEl.checked = Boolean(checked);
	});
</script>

<div class={rowClass}>
	<input
		type="checkbox"
		id={controlId}
		bind:this={inputEl}
		{name}
		{value}
		checked={Boolean(checked)}
		data-on={checked ? 'true' : 'false'}
		aria-checked={checked ? 'true' : 'false'}
		{required}
		{onchange}
	/>
	<Label.Root for={controlId}>{label}</Label.Root>
</div>
