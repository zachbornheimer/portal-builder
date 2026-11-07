/**
 * Clock facade — injectable for deterministic mail filenames in tests.
 */

/**
 * @typedef {object} Clock
 * @property {() => number} nowMs
 * @property {() => string} nowIso
 */

/** @type {Clock} */
export const systemClock = {
	nowMs() {
		return Date.now();
	},
	nowIso() {
		return new Date().toISOString();
	},
};
