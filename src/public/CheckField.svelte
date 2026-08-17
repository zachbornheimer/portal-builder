<script>
	import { Label } from 'bits-ui';

	/**
	 * @typedef {Object} Props
	 * @property {string} [id]
	 * @property {string} [name]
	 * @property {string} [label]
	 * @property {boolean} [required]
	 * @property {boolean} [checked]
	 */

	/** @type {Props} */
	let { id = '', name = '', label = '', required = false, checked = false } = $props();
</script>

<label class="dg-check" for={id}>
	<input type="checkbox" {id} {name} value="1" {required} {checked} />
	<Label.Root for={id}><span>{label}</span></Label.Root>
</label>
