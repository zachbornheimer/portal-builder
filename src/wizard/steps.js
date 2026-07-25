/** Stable wizard step ids and labels (DragonGate checklist). */
export const WIZARD_STEPS = [
	{ id: 'start', label: 'Start', subtitle: 'template' },
	{ id: 'build', label: 'Build form', subtitle: 'fields' },
	{ id: 'map', label: 'Map data', subtitle: 'sheets & drive' },
	{ id: 'publish', label: 'Publish', subtitle: 'deadline & open' },
];

/** @typedef {'open' | 'current' | 'sealed'} GateState */

/**
 * Resolve gate-seal state for a step relative to the current step.
 * Previous steps are sealed (complete), current is current, later are open.
 *
 * @param {string} stepId
 * @param {string} currentStepId
 * @returns {GateState}
 */
export function gateStateForStep(stepId, currentStepId) {
	const stepIndex = WIZARD_STEPS.findIndex((s) => s.id === stepId);
	const currentIndex = WIZARD_STEPS.findIndex((s) => s.id === currentStepId);

	if (stepIndex < 0 || currentIndex < 0) {
		return 'open';
	}
	if (stepIndex < currentIndex) {
		return 'sealed';
	}
	if (stepIndex === currentIndex) {
		return 'current';
	}
	return 'open';
}
