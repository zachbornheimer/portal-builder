/**
 * Waitlist form: idle → working → done | error.
 * The user always knows which of those four is true.
 */
const JOIN_PATH = '/api/waitlist';
const LABEL_IDLE = 'Join the list';
const LABEL_WORKING = 'Joining…';

const copy = {
	working: 'Sending your address…',
	network: 'Could not reach the list. Check your connection and try again.',
	fallback: 'Could not join the list. Try again.',
};

document.querySelectorAll('[data-waitlist]').forEach(bindWaitlist);

/**
 * @param {HTMLFormElement} form
 */
function bindWaitlist(form) {
	const input = form.querySelector('input[name="email"]');
	const button = form.querySelector('button[type="submit"]');
	const status = form.querySelector('[data-waitlist-status]');
	if (!input || !button || !status) {
		return;
	}

	setState(form, status, button, input, 'idle', '');

	form.addEventListener('submit', async (event) => {
		event.preventDefault();
		setState(form, status, button, input, 'working', copy.working);
		try {
			const response = await fetch(JOIN_PATH, {
				method: 'POST',
				headers: { 'content-type': 'application/json' },
				body: JSON.stringify({ email: input.value }),
			});
			const payload = await readJson(response);
			if (!response.ok) {
				setState(
					form,
					status,
					button,
					input,
					'error',
					String(payload.error || copy.fallback),
				);
				return;
			}
			setState(form, status, button, input, 'done', String(payload.message || "You're on the list."));
		} catch {
			setState(form, status, button, input, 'error', copy.network);
		}
	});
}

/**
 * @param {HTMLFormElement} form
 * @param {HTMLElement} status
 * @param {HTMLButtonElement} button
 * @param {HTMLInputElement} input
 * @param {'idle' | 'working' | 'done' | 'error'} state
 * @param {string} message
 */
function setState(form, status, button, input, state, message) {
	form.dataset.state = state;
	status.textContent = message;
	const working = state === 'working';
	const done = state === 'done';
	button.disabled = working || done;
	button.textContent = working ? LABEL_WORKING : LABEL_IDLE;
	input.disabled = working || done;
	input.setAttribute('aria-invalid', state === 'error' ? 'true' : 'false');
}

/**
 * @param {Response} response
 * @returns {Promise<Record<string, unknown>>}
 */
async function readJson(response) {
	try {
		return await response.json();
	} catch {
		return {};
	}
}
