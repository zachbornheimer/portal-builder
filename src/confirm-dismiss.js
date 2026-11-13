/**
 * Whether Overlay/Close/Escape should notify cancel.
 * Parent-driven open never fires Bits onOpenChange(true), so this
 * must not require a prior open callback.
 *
 * @param {boolean} nextOpen
 * @param {boolean} confirmed
 * @returns {boolean}
 */
export function shouldDispatchCancel(nextOpen, confirmed) {
	return !nextOpen && !confirmed;
}

/**
 * A newly opened dialog starts unconfirmed.
 * Parent-driven open never fires Bits onOpenChange(true).
 *
 * @param {boolean} isOpen
 * @param {boolean} confirmed
 * @returns {boolean}
 */
export function confirmedForOpen(isOpen, confirmed) {
	return isOpen ? false : confirmed;
}
