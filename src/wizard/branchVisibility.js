/**
 * Pure branch show/hide plan (no DOM).
 * Public form JS applies this inside each branch fieldset.
 */

/**
 * @param {string|null|undefined} selectedId
 * @param {string[]} optionIds
 * @returns {{ id: string, visible: boolean }[]}
 */
export function visibleBranchOptions(selectedId, optionIds) {
	const selected = selectedId == null ? '' : String(selectedId);
	return (optionIds || []).map((id) => ({
		id,
		visible: id === selected && selected !== '',
	}));
}
