<script>
	import { Select, Label } from 'bits-ui';

	/**
	 * @typedef {{ value: string, label: string }} SelectOption
	 * @typedef {Object} Props
	 * @property {string} [id]
	 * @property {string} [name]
	 * @property {string} [label]
	 * @property {string} [value]
	 * @property {SelectOption[]} [options]
	 * @property {string} [placeholder]
	 * @property {string} [triggerClass]
	 * @property {string} [labelClass]
	 * @property {string} [ariaLabel]
	 * @property {(value: string) => void} [onValueChange]
	 */

	/** @type {Props} */
	let {
		id = '',
		name = '',
		label = '',
		value = $bindable(''),
		options = [],
		placeholder = 'Select…',
		triggerClass = 'dg-input',
		labelClass = 'dg-field-label',
		ariaLabel = '',
		onValueChange,
	} = $props();

	let controlId = $derived(id || `dg-select-${name || label || 'field'}`);

	function handleChange(next) {
		value = next;
		if (typeof onValueChange === 'function') {
			onValueChange(next);
		}
	}

	let selectedLabel = $derived(
		options.find((opt) => opt.value === value)?.label || placeholder,
	);
</script>

{#if label}
	<Label.Root for={controlId} class={labelClass}>{label}</Label.Root>
{/if}
<Select.Root type="single" {name} {value} onValueChange={handleChange}>
	<Select.Trigger
		id={controlId}
		class={triggerClass}
		aria-label={ariaLabel || label || undefined}
	>
		<Select.Value placeholder={placeholder}>{selectedLabel}</Select.Value>
	</Select.Trigger>
	<Select.Portal>
		<Select.Content class="dg-select-content z-50 max-h-60 overflow-auto rounded-md bg-white py-1 shadow-lg outline outline-1 outline-black/5">
			{#each options as opt (opt.value)}
				<Select.Item
					class="cursor-default select-none px-3 py-2 text-sm data-highlighted:bg-zysys-blue-50"
					value={opt.value}
					label={opt.label}
				>
					{opt.label}
				</Select.Item>
			{/each}
		</Select.Content>
	</Select.Portal>
</Select.Root>
