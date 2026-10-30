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
		rowClass = '',
		onchange,
	} = $props();

	let controlId = $derived(id || `dg-check-${name || label || 'box'}`);
</script>

<div class={`dg-check-row ${rowClass}`.trim()}>
  <input
    type="checkbox"
    id={controlId}
    name={name || undefined}
    {value}
    {checked}
    {required}
    {onchange}
  />
  <Label.Root for={controlId}>{label}</Label.Root>
</div>
